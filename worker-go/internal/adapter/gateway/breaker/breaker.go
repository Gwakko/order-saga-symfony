// Package breaker decorates a ChargeLookup with a circuit breaker (github.com/sony/gobreaker/v2).
// Only ErrGatewayUnavailable counts as a failure: a charge that is not found and a request the gateway rejected
// prove that the gateway answers. The state lives in this process and is not shared with the PHP Redis breaker.
//
// Q-M9-5: gobreaker v2.4.0 has no accessor for the end of the open interval (the expiry field is private).
// This package records the moment the breaker opened (OnStateChange) and reports the rest of the interval itself.
package breaker

import (
	"context"
	"errors"
	"log/slog"
	"sync/atomic"
	"time"

	"github.com/sony/gobreaker/v2"

	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

// Config is owned by this adapter; the composition root fills it from internal/config.
type Config struct {
	// Name labels the breaker in logs.
	Name string
	// Failures is the number of consecutive failures that opens the breaker.
	Failures uint32
	// OpenFor is how long the breaker stays open before one trial request is allowed (half-open).
	OpenFor time.Duration
}

// Deps are the collaborators of the decorator.
type Deps struct {
	Next   inspectcharge.ChargeLookup
	Logger *slog.Logger
}

type result struct {
	obs   charge.Observation
	found bool
}

// Breaker implements inspectcharge.ChargeLookup.
type Breaker struct {
	next     inspectcharge.ChargeLookup
	cb       *gobreaker.CircuitBreaker[result]
	openFor  time.Duration
	openedAt atomic.Int64 // UnixNano of the last transition to open
	now      func() time.Time
}

// New builds the decorator. The returned function is a no-op: the breaker holds no resources.
func New(cfg Config, deps Deps) (*Breaker, func() error, error) {
	if cfg.Failures == 0 {
		return nil, nil, errors.New("breaker: Failures must be greater than zero")
	}
	if cfg.OpenFor <= 0 {
		return nil, nil, errors.New("breaker: OpenFor must be positive")
	}
	if deps.Next == nil {
		return nil, nil, errors.New("breaker: Next lookup is required")
	}

	logger := deps.Logger
	if logger == nil {
		logger = slog.New(slog.DiscardHandler)
	}

	b := &Breaker{next: deps.Next, openFor: cfg.OpenFor, now: time.Now}

	b.cb = gobreaker.NewCircuitBreaker[result](gobreaker.Settings{
		Name:        cfg.Name,
		MaxRequests: 1, // one trial request in half-open
		Timeout:     cfg.OpenFor,
		ReadyToTrip: func(counts gobreaker.Counts) bool { return counts.ConsecutiveFailures >= cfg.Failures },
		OnStateChange: func(name string, from, to gobreaker.State) {
			if to == gobreaker.StateOpen {
				b.openedAt.Store(b.now().UnixNano())
			}
			logger.Info("circuit breaker state changed", "breaker", name, "from", from.String(), "to", to.String())
		},
		// Only an unreadable or missing answer is a failure of the gateway.
		IsSuccessful: func(err error) bool { return !errors.Is(err, inspectcharge.ErrGatewayUnavailable) },
		// A cancelled call (worker stop, activity cancelled) says nothing about the gateway.
		IsExcluded: func(err error) bool { return errors.Is(err, context.Canceled) },
	})

	return b, func() error { return nil }, nil
}

// State returns the current breaker state (for tests and health reporting).
func (b *Breaker) State() gobreaker.State { return b.cb.State() }

// FindCharge calls the next lookup through the breaker. An open breaker returns *inspectcharge.CircuitOpenError without a call.
func (b *Breaker) FindCharge(ctx context.Context, idempotencyKey string) (charge.Observation, bool, error) {
	res, err := b.cb.Execute(func() (result, error) {
		obs, found, err := b.next.FindCharge(ctx, idempotencyKey)

		return result{obs: obs, found: found}, err
	})
	if err != nil {
		if errors.Is(err, gobreaker.ErrOpenState) {
			return charge.Observation{}, false, &inspectcharge.CircuitOpenError{RetryAfter: b.remainingOpen()}
		}
		if errors.Is(err, gobreaker.ErrTooManyRequests) {
			// Half-open and the trial request is in flight: the rest of the interval is unknown, retry soon.
			return charge.Observation{}, false, &inspectcharge.CircuitOpenError{}
		}

		return charge.Observation{}, false, err
	}

	return res.obs, res.found, nil
}

// remainingOpen is the rest of the open interval, reconstructed from the moment the breaker opened.
func (b *Breaker) remainingOpen() time.Duration {
	opened := b.openedAt.Load()
	if opened == 0 {
		return 0
	}

	remaining := b.openFor - b.now().Sub(time.Unix(0, opened))
	if remaining < 0 {
		return 0
	}

	return remaining
}
