package breaker_test

import (
	"context"
	"errors"
	"fmt"
	"sync/atomic"
	"testing"
	"time"

	"github.com/sony/gobreaker/v2"

	"order-saga-symfony/worker-go/internal/adapter/gateway/breaker"
	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

type scripted struct {
	calls atomic.Int32
	next  func(call int32) (charge.Observation, bool, error)
}

func (s *scripted) FindCharge(context.Context, string) (charge.Observation, bool, error) {
	return s.next(s.calls.Add(1))
}

func newBreaker(t *testing.T, failures uint32, openFor time.Duration, next *scripted) *breaker.Breaker {
	t.Helper()

	b, closeFn, err := breaker.New(breaker.Config{Name: "test", Failures: failures, OpenFor: openFor}, breaker.Deps{Next: next})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = closeFn() })

	return b
}

func failing() *scripted {
	return &scripted{next: func(int32) (charge.Observation, bool, error) {
		return charge.Observation{}, false, fmt.Errorf("%w: boom", inspectcharge.ErrGatewayUnavailable)
	}}
}

// Group: T-GO-07
func TestOpensAfterConsecutiveFailuresWithoutCallingTheGateway(t *testing.T) {
	next := failing()
	b := newBreaker(t, 5, time.Hour, next)

	for i := 0; i < 5; i++ {
		if _, _, err := b.FindCharge(context.Background(), "k"); !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
			t.Fatalf("call %d: err = %v", i, err)
		}
	}
	if b.State() != gobreaker.StateOpen {
		t.Fatalf("state = %v, want open", b.State())
	}

	_, _, err := b.FindCharge(context.Background(), "k")
	var open *inspectcharge.CircuitOpenError
	if !errors.Is(err, inspectcharge.ErrCircuitOpen) || !errors.As(err, &open) {
		t.Fatalf("err = %v, want CircuitOpenError", err)
	}
	if next.calls.Load() != 5 {
		t.Fatalf("gateway calls = %d, want 5 (the sixth must not reach the gateway)", next.calls.Load())
	}
	if open.RetryAfter <= 0 || open.RetryAfter > time.Hour {
		t.Fatalf("RetryAfter = %v, want the rest of the open interval", open.RetryAfter)
	}
}

// Group: T-GO-07
func TestFourFailuresKeepItClosedAndASuccessResetsTheCount(t *testing.T) {
	var call atomic.Int32
	next := &scripted{next: func(int32) (charge.Observation, bool, error) {
		n := call.Add(1)
		if n == 5 {
			return charge.Observation{ChargeID: "ch"}, true, nil
		}

		return charge.Observation{}, false, inspectcharge.ErrGatewayUnavailable
	}}
	b := newBreaker(t, 5, time.Hour, next)

	for i := 0; i < 4; i++ {
		_, _, _ = b.FindCharge(context.Background(), "k")
	}
	if b.State() != gobreaker.StateClosed {
		t.Fatalf("state = %v after 4 failures", b.State())
	}
	if _, found, err := b.FindCharge(context.Background(), "k"); err != nil || !found {
		t.Fatalf("found=%v err=%v", found, err)
	}
	for i := 0; i < 4; i++ {
		_, _, _ = b.FindCharge(context.Background(), "k")
	}
	if b.State() != gobreaker.StateClosed {
		t.Fatalf("state = %v: the success must have reset the consecutive count", b.State())
	}
}

// Group: T-GO-07
func TestHalfOpenTrialClosesOrReopens(t *testing.T) {
	var healthy atomic.Bool
	next := &scripted{next: func(int32) (charge.Observation, bool, error) {
		if healthy.Load() {
			return charge.Observation{ChargeID: "ch"}, true, nil
		}

		return charge.Observation{}, false, inspectcharge.ErrGatewayUnavailable
	}}
	b := newBreaker(t, 2, 80*time.Millisecond, next)

	for i := 0; i < 2; i++ {
		_, _, _ = b.FindCharge(context.Background(), "k")
	}
	if b.State() != gobreaker.StateOpen {
		t.Fatalf("state = %v, want open", b.State())
	}

	// After the open interval one trial request goes through; it fails, the breaker opens again.
	time.Sleep(120 * time.Millisecond)
	before := next.calls.Load()
	if _, _, err := b.FindCharge(context.Background(), "k"); !errors.Is(err, inspectcharge.ErrGatewayUnavailable) {
		t.Fatalf("trial err = %v", err)
	}
	if next.calls.Load() != before+1 || b.State() != gobreaker.StateOpen {
		t.Fatalf("calls=%d state=%v, want one trial call and open again", next.calls.Load()-before, b.State())
	}

	// The next trial succeeds and closes the breaker.
	healthy.Store(true)
	time.Sleep(120 * time.Millisecond)
	if _, found, err := b.FindCharge(context.Background(), "k"); err != nil || !found {
		t.Fatalf("trial found=%v err=%v", found, err)
	}
	if b.State() != gobreaker.StateClosed {
		t.Fatalf("state = %v, want closed after a successful trial", b.State())
	}
}

// Group: T-GO-07
func TestHalfOpenAllowsOneTrialAtATime(t *testing.T) {
	release := make(chan struct{})
	entered := make(chan struct{}, 1)
	var calls atomic.Int32
	next := &scripted{next: func(int32) (charge.Observation, bool, error) {
		if calls.Add(1) <= 2 {
			return charge.Observation{}, false, inspectcharge.ErrGatewayUnavailable
		}
		entered <- struct{}{}
		<-release

		return charge.Observation{ChargeID: "ch"}, true, nil
	}}
	b := newBreaker(t, 2, 60*time.Millisecond, next)

	for i := 0; i < 2; i++ {
		_, _, _ = b.FindCharge(context.Background(), "k")
	}
	time.Sleep(100 * time.Millisecond)

	done := make(chan error, 1)
	go func() {
		_, _, err := b.FindCharge(context.Background(), "k")
		done <- err
	}()
	<-entered

	_, _, err := b.FindCharge(context.Background(), "k")
	if !errors.Is(err, inspectcharge.ErrCircuitOpen) {
		t.Fatalf("second concurrent call err = %v, want ErrCircuitOpen", err)
	}
	close(release)
	if err := <-done; err != nil {
		t.Fatalf("trial err = %v", err)
	}
}

// Group: T-GO-07
func TestNotFoundAndRejectedDoNotOpenTheBreaker(t *testing.T) {
	cases := map[string]func(int32) (charge.Observation, bool, error){
		"not found": func(int32) (charge.Observation, bool, error) { return charge.Observation{}, false, nil },
		"rejected": func(int32) (charge.Observation, bool, error) {
			return charge.Observation{}, false, fmt.Errorf("%w: status 422", inspectcharge.ErrRequestRejected)
		},
		"pending": func(int32) (charge.Observation, bool, error) {
			return charge.Observation{}, false, inspectcharge.ErrChargePending
		},
	}

	for name, fn := range cases {
		t.Run(name, func(t *testing.T) {
			next := &scripted{next: fn}
			b := newBreaker(t, 2, time.Hour, next)

			for i := 0; i < 20; i++ {
				_, _, _ = b.FindCharge(context.Background(), "k")
			}
			if b.State() != gobreaker.StateClosed || next.calls.Load() != 20 {
				t.Fatalf("state=%v calls=%d, want closed and 20 calls", b.State(), next.calls.Load())
			}
		})
	}
}

// Group: T-GO-07
func TestCancelledCallsDoNotCount(t *testing.T) {
	next := &scripted{next: func(int32) (charge.Observation, bool, error) {
		return charge.Observation{}, false, fmt.Errorf("%w: %w", inspectcharge.ErrGatewayUnavailable, context.Canceled)
	}}
	b := newBreaker(t, 2, time.Hour, next)

	for i := 0; i < 10; i++ {
		_, _, _ = b.FindCharge(context.Background(), "k")
	}
	if b.State() != gobreaker.StateClosed {
		t.Fatalf("state = %v: cancellations must not open the breaker", b.State())
	}
}

// Group: T-GO-07
func TestErrorsFromTheGatewayPassThroughUnchanged(t *testing.T) {
	sentinel := fmt.Errorf("%w: status 500", inspectcharge.ErrGatewayUnavailable)
	next := &scripted{next: func(int32) (charge.Observation, bool, error) { return charge.Observation{}, false, sentinel }}

	if _, _, err := newBreaker(t, 5, time.Hour, next).FindCharge(context.Background(), "k"); err != sentinel { //nolint:errorlint // identity is the point
		t.Fatalf("err = %v, want the same error value", err)
	}
}

// Group: T-GO-07
func TestNewRejectsBadConfig(t *testing.T) {
	ok := failing()
	cases := map[string]struct {
		cfg  breaker.Config
		deps breaker.Deps
	}{
		"zero failures": {breaker.Config{Failures: 0, OpenFor: time.Second}, breaker.Deps{Next: ok}},
		"zero open":     {breaker.Config{Failures: 1, OpenFor: 0}, breaker.Deps{Next: ok}},
		"no next":       {breaker.Config{Failures: 1, OpenFor: time.Second}, breaker.Deps{}},
	}

	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			if _, _, err := breaker.New(tc.cfg, tc.deps); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}
