package httpgateway_test

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
	"unicode/utf8"

	"order-saga-symfony/worker-go/internal/adapter/gateway/httpgateway"
	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

const key = "0190a5c4-0000-7000-8000-0000000000a1"

func newClient(t *testing.T, url string, timeout time.Duration, maxBody int64) *httpgateway.Client {
	t.Helper()

	client, closeFn, err := httpgateway.New(httpgateway.Config{BaseURL: url, Timeout: timeout, MaxBodyBytes: maxBody}, httpgateway.Deps{})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = closeFn() })

	return client
}

func serve(t *testing.T, status int, body string) *httptest.Server {
	t.Helper()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)

	return srv
}

func okBody(status, amount, currency string) string {
	return fmt.Sprintf(`{"chargeId":"ch_01","idempotencyKey":%q,"status":%q,"amount":%s,"currency":%q}`, key, status, amount, currency)
}

// Group: T-GO-06
func TestFindChargeReadableAnswers(t *testing.T) {
	t.Run("succeeded", func(t *testing.T) {
		srv := serve(t, 200, okBody("succeeded", "1999", "EUR"))

		obs, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || !found {
			t.Fatalf("found=%v err=%v", found, err)
		}
		want := charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusSucceeded}
		if obs != want {
			t.Fatalf("obs = %+v, want %+v", obs, want)
		}
	})

	t.Run("pending", func(t *testing.T) {
		srv := serve(t, 200, okBody("pending", "1999", "EUR"))

		obs, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || !found || obs.Status != charge.StatusPending {
			t.Fatalf("obs=%+v found=%v err=%v", obs, found, err)
		}
	})

	t.Run("declined with reason", func(t *testing.T) {
		body := `{"chargeId":"ch_01","idempotencyKey":"` + key + `","status":"declined","amount":1999,"currency":"EUR","reason":"insufficient_funds"}`
		srv := serve(t, 200, body)

		obs, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || !found || obs.Status != charge.StatusDeclined || obs.DeclineReason == nil || *obs.DeclineReason != "insufficient_funds" {
			t.Fatalf("obs=%+v found=%v err=%v", obs, found, err)
		}
	})

	t.Run("foreign amount and currency are data, not errors", func(t *testing.T) {
		srv := serve(t, 200, okBody("succeeded", "2000", "USD"))

		obs, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || !found || obs.Money != (charge.Money{Amount: 2000, Currency: "USD"}) {
			t.Fatalf("obs=%+v found=%v err=%v", obs, found, err)
		}
	})

	t.Run("not found", func(t *testing.T) {
		srv := serve(t, 404, `{"error":"charge_not_found"}`)

		_, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || found {
			t.Fatalf("found=%v err=%v, want not found without error", found, err)
		}
	})

	t.Run("decline reason is cut to 256 bytes on a rune boundary", func(t *testing.T) {
		reason := strings.Repeat("я", 200) // 400 bytes, 2 bytes per rune
		body := `{"chargeId":"ch_01","idempotencyKey":"` + key + `","status":"declined","amount":1999,"currency":"EUR","reason":"` + reason + `"}`
		srv := serve(t, 200, body)

		obs, _, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || obs.DeclineReason == nil {
			t.Fatalf("obs=%+v err=%v", obs, err)
		}
		if len(*obs.DeclineReason) != 256 || !utf8.ValidString(*obs.DeclineReason) {
			t.Fatalf("reason: %d bytes, valid=%v", len(*obs.DeclineReason), utf8.ValidString(*obs.DeclineReason))
		}
	})

	t.Run("decline reason cut never splits a three byte rune", func(t *testing.T) {
		reason := "a" + strings.Repeat("€", 100) // 1 + 300 bytes, boundary at byte 256 falls inside a rune
		body := `{"chargeId":"ch_01","idempotencyKey":"` + key + `","status":"declined","amount":1999,"currency":"EUR","reason":"` + reason + `"}`
		srv := serve(t, 200, body)

		obs, _, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
		if err != nil || obs.DeclineReason == nil || len(*obs.DeclineReason) > 256 || !utf8.ValidString(*obs.DeclineReason) {
			t.Fatalf("obs=%+v err=%v", obs, err)
		}
	})

	t.Run("body of exactly the limit is accepted", func(t *testing.T) {
		body := okBody("succeeded", "1999", "EUR")
		srv := serve(t, 200, body)

		_, found, err := newClient(t, srv.URL, time.Second, int64(len(body))).FindCharge(context.Background(), key)
		if err != nil || !found {
			t.Fatalf("found=%v err=%v", found, err)
		}
	})
}

// Group: T-GO-06
func TestFindChargeErrors(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		want   error
	}{
		{"404 other error", 404, `{"error":"route_not_found"}`, inspectcharge.ErrGatewayUnavailable},
		{"404 not json", 404, `<html>nope</html>`, inspectcharge.ErrGatewayUnavailable},
		{"400", 400, `{"error":"bad"}`, inspectcharge.ErrRequestRejected},
		{"401", 401, ``, inspectcharge.ErrRequestRejected},
		{"409", 409, `{"error":"conflict"}`, inspectcharge.ErrRequestRejected},
		{"422", 422, `{"error":"invalid_request"}`, inspectcharge.ErrRequestRejected},
		{"500", 500, ``, inspectcharge.ErrGatewayUnavailable},
		{"502", 502, ``, inspectcharge.ErrGatewayUnavailable},
		{"503", 503, ``, inspectcharge.ErrGatewayUnavailable},
		{"redirect is not followed", 302, ``, inspectcharge.ErrGatewayUnavailable},
		{"200 not json", 200, `ok`, inspectcharge.ErrGatewayUnavailable},
		{"200 empty body", 200, ``, inspectcharge.ErrGatewayUnavailable},
		{"200 truncated json", 200, `{"chargeId":"ch_01","idempotencyKey":"` + key + `","stat`, inspectcharge.ErrGatewayUnavailable},
		{"200 trailing data", 200, okBody("succeeded", "1999", "EUR") + `{}`, inspectcharge.ErrGatewayUnavailable},
		{"200 json array", 200, `[]`, inspectcharge.ErrGatewayUnavailable},
		{"200 without chargeId", 200, `{"idempotencyKey":"` + key + `","status":"succeeded","amount":1999,"currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"200 empty chargeId", 200, `{"chargeId":"","idempotencyKey":"` + key + `","status":"succeeded","amount":1999,"currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"200 unknown status weird", 200, okBody("weird", "1999", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"200 missing status", 200, `{"chargeId":"ch_01","idempotencyKey":"` + key + `","amount":1999,"currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"200 key mismatch", 200, `{"chargeId":"ch_01","idempotencyKey":"other","status":"succeeded","amount":1999,"currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"200 key missing", 200, `{"chargeId":"ch_01","status":"succeeded","amount":1999,"currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"amount fractional", 200, okBody("succeeded", "19.99", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount with zero fraction", 200, okBody("succeeded", "1999.0", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount exponent", 200, okBody("succeeded", "1.999e3", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount string", 200, okBody("succeeded", `"1999"`, "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount zero", 200, okBody("succeeded", "0", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount negative", 200, okBody("succeeded", "-5", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount null", 200, okBody("succeeded", "null", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount beyond int64", 200, okBody("succeeded", "9223372036854775808", "EUR"), inspectcharge.ErrGatewayUnavailable},
		{"amount missing", 200, `{"chargeId":"ch_01","idempotencyKey":"` + key + `","status":"succeeded","currency":"EUR"}`, inspectcharge.ErrGatewayUnavailable},
		{"currency lower case", 200, okBody("succeeded", "1999", "eur"), inspectcharge.ErrGatewayUnavailable},
		{"currency too long", 200, okBody("succeeded", "1999", "EURO"), inspectcharge.ErrGatewayUnavailable},
		{"currency missing", 200, `{"chargeId":"ch_01","idempotencyKey":"` + key + `","status":"succeeded","amount":1999}`, inspectcharge.ErrGatewayUnavailable},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			srv := serve(t, tc.status, tc.body)

			_, found, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
			if found || !errors.Is(err, tc.want) {
				t.Fatalf("found=%v err=%v, want %v", found, err, tc.want)
			}
		})
	}
}

// Group: T-GO-06
func TestFindChargeOversizedBody(t *testing.T) {
	// 64 KiB of valid JSON padding plus one byte over the limit.
	limit := int64(64 * 1024)
	pad := strings.Repeat(" ", int(limit)+1)
	srv := serve(t, 200, okBody("succeeded", "1999", "EUR")+pad)

	_, _, err := newClient(t, srv.URL, time.Second, limit).FindCharge(context.Background(), key)
	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable", err)
	}
}

// Group: T-GO-06
func TestFindChargeTimeout(t *testing.T) {
	release := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-release:
		case <-r.Context().Done():
		}
	}))
	t.Cleanup(func() { close(release); srv.Close() })

	started := time.Now()
	_, _, err := newClient(t, srv.URL, 150*time.Millisecond, 65536).FindCharge(context.Background(), key)

	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable", err)
	}
	if elapsed := time.Since(started); elapsed > 3*time.Second {
		t.Fatalf("timeout took %v", elapsed)
	}
}

// Group: T-GO-06
func TestFindChargeConnectionDropped(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, _, err := w.(http.Hijacker).Hijack()
		if err != nil {
			return
		}
		_ = conn.Close()
	}))
	t.Cleanup(srv.Close)

	_, _, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable", err)
	}
}

// Group: T-GO-06
func TestFindChargeBodyCutShort(t *testing.T) {
	// Content-Length promises more than is sent, then the connection closes (emulator mode drop-connection).
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, buf, err := w.(http.Hijacker).Hijack()
		if err != nil {
			return
		}
		_, _ = buf.WriteString("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 500\r\n\r\n{\"chargeId\":")
		_ = buf.Flush()
		_ = conn.Close()
	}))
	t.Cleanup(srv.Close)

	_, _, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(context.Background(), key)
	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable", err)
	}
}

// Group: T-GO-06
func TestFindChargeServerDown(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	url := srv.URL
	srv.Close()

	_, _, err := newClient(t, url, time.Second, 65536).FindCharge(context.Background(), key)
	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable", err)
	}
}

// Group: T-GO-06
func TestFindChargeCancelledContext(t *testing.T) {
	srv := serve(t, 200, okBody("succeeded", "1999", "EUR"))
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, _, err := newClient(t, srv.URL, time.Second, 65536).FindCharge(ctx, key)
	if !errors.Is(err, inspectcharge.ErrGatewayUnavailable) || !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v, want ErrGatewayUnavailable wrapping context.Canceled", err)
	}
}

// Group: T-GO-06
func TestFindChargeRequestShape(t *testing.T) {
	var gotPath, gotAccept, gotKey, gotMethod string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotAccept, gotKey, gotMethod = r.URL.EscapedPath(), r.Header.Get("Accept"), r.Header.Get("Idempotency-Key"), r.Method
		_, _ = w.Write([]byte(okBody("succeeded", "1999", "EUR")))
	}))
	t.Cleanup(srv.Close)

	if _, _, err := newClient(t, srv.URL+"/", time.Second, 65536).FindCharge(context.Background(), key); err != nil {
		t.Fatal(err)
	}
	if gotMethod != http.MethodGet || gotPath != "/charges/"+key || gotAccept != "application/json" || gotKey != key {
		t.Fatalf("method=%s path=%s accept=%s key=%s", gotMethod, gotPath, gotAccept, gotKey)
	}
}

// Group: T-GO-06
func TestNewRejectsBadConfig(t *testing.T) {
	cases := map[string]httpgateway.Config{
		"empty url":    {BaseURL: "", Timeout: time.Second, MaxBodyBytes: 1},
		"ftp scheme":   {BaseURL: "ftp://x", Timeout: time.Second, MaxBodyBytes: 1},
		"no host":      {BaseURL: "http://", Timeout: time.Second, MaxBodyBytes: 1},
		"zero timeout": {BaseURL: "http://x", Timeout: 0, MaxBodyBytes: 1},
		"zero body":    {BaseURL: "http://x", Timeout: time.Second, MaxBodyBytes: 0},
	}

	for name, cfg := range cases {
		t.Run(name, func(t *testing.T) {
			if _, _, err := httpgateway.New(cfg, httpgateway.Deps{}); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}

// Group: T-GO-08
func TestFindChargeSendsOnlyGet(t *testing.T) {
	var gets, others atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			others.Add(1)
			w.WriteHeader(http.StatusMethodNotAllowed)

			return
		}
		gets.Add(1)
		switch gets.Load() {
		case 1:
			_, _ = w.Write([]byte(okBody("succeeded", "1999", "EUR")))
		case 2:
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"error":"charge_not_found"}`))
		default:
			w.WriteHeader(http.StatusInternalServerError)
		}
	}))
	t.Cleanup(srv.Close)

	client := newClient(t, srv.URL, time.Second, 65536)
	for i := 0; i < 3; i++ {
		_, _, _ = client.FindCharge(context.Background(), key)
	}

	if gets.Load() != 3 || others.Load() != 0 {
		t.Fatalf("GET=%d, non-GET=%d; the adapter must send only GET and not retry by itself", gets.Load(), others.Load())
	}
}
