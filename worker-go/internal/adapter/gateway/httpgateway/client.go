// Package httpgateway reads charges from the payment gateway over HTTP (GET /charges/{idempotencyKey}).
// It only reads: no POST /charges, no POST /refunds. It does not retry; Temporal retries the activity.
// Money is parsed as integer minor units; a fractional, string, zero, negative or out-of-range amount is an unreadable answer.
package httpgateway

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

const maxDeclineReasonBytes = 256

var currencyPattern = regexp.MustCompile(`^[A-Z]{3}$`)

// Config is owned by this adapter; the composition root fills it from internal/config. The adapter never reads the environment.
type Config struct {
	// BaseURL is the gateway root, e.g. http://gateway-emulator:8081.
	BaseURL string
	// Timeout bounds one whole request (connect, headers, body).
	Timeout time.Duration
	// MaxBodyBytes is the largest response body accepted.
	MaxBodyBytes int64
}

// Deps are optional collaborators.
type Deps struct {
	// Transport replaces the default transport; nil keeps http.DefaultTransport semantics with a private clone.
	Transport http.RoundTripper
}

// Client implements inspectcharge.ChargeLookup.
type Client struct {
	base    *url.URL
	client  *http.Client
	maxBody int64
}

// New validates the config and builds the client. The returned function releases idle connections.
func New(cfg Config, deps Deps) (*Client, func() error, error) {
	base, err := url.Parse(cfg.BaseURL)
	if err != nil || (base.Scheme != "http" && base.Scheme != "https") || base.Host == "" {
		return nil, nil, fmt.Errorf("httpgateway: BaseURL %q must be an http or https URL", cfg.BaseURL)
	}
	if cfg.Timeout <= 0 {
		return nil, nil, errors.New("httpgateway: Timeout must be positive")
	}
	if cfg.MaxBodyBytes <= 0 {
		return nil, nil, errors.New("httpgateway: MaxBodyBytes must be positive")
	}

	transport := deps.Transport
	if transport == nil {
		transport = http.DefaultTransport.(*http.Transport).Clone()
	}

	client := &http.Client{
		Timeout:   cfg.Timeout,
		Transport: transport,
		// A redirect is not part of the gateway contract: report it as an unreadable answer instead of following it.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}

	closeFn := func() error {
		client.CloseIdleConnections()

		return nil
	}

	return &Client{base: base, client: client, maxBody: cfg.MaxBodyBytes}, closeFn, nil
}

// FindCharge implements inspectcharge.ChargeLookup.
func (c *Client) FindCharge(ctx context.Context, idempotencyKey string) (charge.Observation, bool, error) {
	target := c.base.JoinPath("charges", idempotencyKey).String()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return charge.Observation{}, false, fmt.Errorf("%w: build request: %w", inspectcharge.ErrGatewayUnavailable, err)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Idempotency-Key", idempotencyKey)

	resp, err := c.client.Do(req)
	if err != nil {
		return charge.Observation{}, false, fmt.Errorf("%w: %w", inspectcharge.ErrGatewayUnavailable, err)
	}
	defer func() { _ = resp.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(resp.Body, c.maxBody+1))
	if err != nil {
		return charge.Observation{}, false, fmt.Errorf("%w: read body: %w", inspectcharge.ErrGatewayUnavailable, err)
	}
	if int64(len(body)) > c.maxBody {
		return charge.Observation{}, false, fmt.Errorf("%w: response body exceeds %d bytes", inspectcharge.ErrGatewayUnavailable, c.maxBody)
	}

	switch {
	case resp.StatusCode == http.StatusOK:
		obs, err := parseCharge(body, idempotencyKey)
		if err != nil {
			return charge.Observation{}, false, err
		}

		return obs, true, nil
	case resp.StatusCode == http.StatusNotFound:
		if isChargeNotFound(body) {
			return charge.Observation{}, false, nil
		}

		return charge.Observation{}, false, fmt.Errorf("%w: status 404 without charge_not_found", inspectcharge.ErrGatewayUnavailable)
	case resp.StatusCode >= 400 && resp.StatusCode < 500:
		return charge.Observation{}, false, fmt.Errorf("%w: status %d", inspectcharge.ErrRequestRejected, resp.StatusCode)
	default:
		return charge.Observation{}, false, fmt.Errorf("%w: status %d", inspectcharge.ErrGatewayUnavailable, resp.StatusCode)
	}
}

// chargeDTO is the gateway response (the boundary type, with tags). The amount stays raw so that it can be checked as an integer.
type chargeDTO struct {
	ChargeID       *string         `json:"chargeId"`
	IdempotencyKey *string         `json:"idempotencyKey"`
	Status         *string         `json:"status"`
	Amount         json.RawMessage `json:"amount"`
	Currency       *string         `json:"currency"`
	Reason         *string         `json:"reason"`
}

type errorDTO struct {
	Error string `json:"error"`
}

func isChargeNotFound(body []byte) bool {
	var dto errorDTO

	return json.Unmarshal(body, &dto) == nil && dto.Error == "charge_not_found"
}

func parseCharge(body []byte, wantKey string) (charge.Observation, error) {
	unreadable := func(format string, args ...any) (charge.Observation, error) {
		return charge.Observation{}, fmt.Errorf("%w: "+format, append([]any{inspectcharge.ErrGatewayUnavailable}, args...)...)
	}

	dec := json.NewDecoder(bytes.NewReader(body))

	var dto chargeDTO
	if err := dec.Decode(&dto); err != nil {
		return unreadable("decode body: %v", err)
	}
	if dec.More() {
		return unreadable("trailing data after the JSON document")
	}

	if dto.ChargeID == nil || *dto.ChargeID == "" {
		return unreadable("chargeId is missing")
	}
	if dto.IdempotencyKey == nil || *dto.IdempotencyKey != wantKey {
		return unreadable("idempotencyKey in the response does not match the request")
	}

	amount, err := parseAmount(dto.Amount)
	if err != nil {
		return unreadable("%v", err)
	}
	if dto.Currency == nil || !currencyPattern.MatchString(*dto.Currency) {
		return unreadable("currency is missing or not ISO-4217 upper case")
	}

	obs := charge.Observation{
		ChargeID: *dto.ChargeID,
		Money:    charge.Money{Amount: amount, Currency: *dto.Currency},
	}

	if dto.Status == nil {
		return unreadable("status is missing")
	}
	switch charge.Status(*dto.Status) {
	case charge.StatusSucceeded, charge.StatusDeclined, charge.StatusPending:
		obs.Status = charge.Status(*dto.Status)
	default:
		return unreadable("unknown status %q", *dto.Status)
	}

	if dto.Reason != nil {
		reason := truncateOnRune(*dto.Reason, maxDeclineReasonBytes)
		obs.DeclineReason = &reason
	}

	return obs, nil
}

// parseAmount accepts only a JSON integer literal that fits int64 and is positive.
func parseAmount(raw json.RawMessage) (int64, error) {
	text := strings.TrimSpace(string(raw))
	if text == "" {
		return 0, errors.New("amount is missing")
	}

	// strconv.ParseInt rejects '.', exponents, quotes, null and out-of-range values.
	amount, err := strconv.ParseInt(text, 10, 64)
	if err != nil {
		return 0, fmt.Errorf("amount %q is not an integer in minor units", text)
	}
	if amount <= 0 {
		return 0, fmt.Errorf("amount %d is not positive", amount)
	}

	return amount, nil
}

// truncateOnRune cuts s to at most limit bytes without splitting a UTF-8 sequence.
func truncateOnRune(s string, limit int) string {
	if len(s) <= limit {
		return s
	}

	cut := limit
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}

	return s[:cut]
}
