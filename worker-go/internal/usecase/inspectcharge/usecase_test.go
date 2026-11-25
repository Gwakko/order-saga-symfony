package inspectcharge_test

import (
	"context"
	"errors"
	"fmt"
	"reflect"
	"strings"
	"testing"
	"time"

	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

const (
	validOrderID = "0190a5c4-0000-7000-8000-000000000020"
	validKey     = "0190a5c4-0000-7000-8000-0000000000a1"
)

type fakeLookup struct {
	obs   charge.Observation
	found bool
	err   error

	calls int
	key   string
}

func (f *fakeLookup) FindCharge(_ context.Context, key string) (charge.Observation, bool, error) {
	f.calls++
	f.key = key

	return f.obs, f.found, f.err
}

type fakeClock struct{ now time.Time }

func (c fakeClock) Now() time.Time { return c.now }

func validInput() inspectcharge.InspectChargeInput {
	return inspectcharge.InspectChargeInput{
		ContractVersion: 1,
		OrderID:         validOrderID,
		IdempotencyKey:  validKey,
		Expected:        charge.Money{Amount: 1999, Currency: "EUR"},
	}
}

// Group: T-GO-01
func TestValidate(t *testing.T) {
	cases := []struct {
		name    string
		mutate  func(*inspectcharge.InspectChargeInput)
		wantErr error // nil: valid
	}{
		{"valid", func(*inspectcharge.InspectChargeInput) {}, nil},
		{"amount lower bound", func(i *inspectcharge.InspectChargeInput) { i.Expected.Amount = 1 }, nil},
		{"amount upper bound", func(i *inspectcharge.InspectChargeInput) { i.Expected.Amount = 100_000_000 }, nil},
		{"key 64 chars", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = strings.Repeat("a", 64) }, nil},
		{"each currency USD", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "USD" }, nil},
		{"each currency PLN", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "PLN" }, nil},
		{"each currency UAH", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "UAH" }, nil},
		{"uppercase uuid", func(i *inspectcharge.InspectChargeInput) { i.OrderID = strings.ToUpper(validOrderID) }, nil},

		{"version 0", func(i *inspectcharge.InspectChargeInput) { i.ContractVersion = 0 }, inspectcharge.ErrUnsupportedContractVersion},
		{"version 2", func(i *inspectcharge.InspectChargeInput) { i.ContractVersion = 2 }, inspectcharge.ErrUnsupportedContractVersion},
		{"version negative", func(i *inspectcharge.InspectChargeInput) { i.ContractVersion = -1 }, inspectcharge.ErrUnsupportedContractVersion},
		{"order id not uuid", func(i *inspectcharge.InspectChargeInput) { i.OrderID = "order-1" }, inspectcharge.ErrInvalidInput},
		{"order id empty", func(i *inspectcharge.InspectChargeInput) { i.OrderID = "" }, inspectcharge.ErrInvalidInput},
		{"order id uuid with suffix", func(i *inspectcharge.InspectChargeInput) { i.OrderID = validOrderID + "x" }, inspectcharge.ErrInvalidInput},
		{"key empty", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = "" }, inspectcharge.ErrInvalidInput},
		{"key with slash", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = "a/b" }, inspectcharge.ErrInvalidInput},
		{"key with space", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = "a b" }, inspectcharge.ErrInvalidInput},
		{"key with newline", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = "abc\n" }, inspectcharge.ErrInvalidInput},
		{"key 65 chars", func(i *inspectcharge.InspectChargeInput) { i.IdempotencyKey = strings.Repeat("a", 65) }, inspectcharge.ErrInvalidInput},
		{"amount 0", func(i *inspectcharge.InspectChargeInput) { i.Expected.Amount = 0 }, inspectcharge.ErrInvalidInput},
		{"amount -1", func(i *inspectcharge.InspectChargeInput) { i.Expected.Amount = -1 }, inspectcharge.ErrInvalidInput},
		{"amount 100000001", func(i *inspectcharge.InspectChargeInput) { i.Expected.Amount = 100_000_001 }, inspectcharge.ErrInvalidInput},
		{"currency lower case", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "eur" }, inspectcharge.ErrInvalidInput},
		{"currency XYZ", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "XYZ" }, inspectcharge.ErrInvalidInput},
		{"currency empty", func(i *inspectcharge.InspectChargeInput) { i.Expected.Currency = "" }, inspectcharge.ErrInvalidInput},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			in := validInput()
			tc.mutate(&in)

			err := in.Validate()
			if tc.wantErr == nil {
				if err != nil {
					t.Fatalf("Validate() = %v, want nil", err)
				}

				return
			}

			if !errors.Is(err, tc.wantErr) {
				t.Fatalf("Validate() = %v, want errors.Is %v", err, tc.wantErr)
			}
		})
	}
}

// Group: T-GO-01
func TestValidateCollectsAllProblems(t *testing.T) {
	in := inspectcharge.InspectChargeInput{ContractVersion: 1, OrderID: "x", IdempotencyKey: "a/b", Expected: charge.Money{Amount: 0, Currency: "xx"}}

	var invalid *inspectcharge.InvalidInputError
	if err := in.Validate(); !errors.As(err, &invalid) || len(invalid.Problems) != 4 {
		t.Fatalf("Validate() = %v, want InvalidInputError with 4 problems", err)
	}
}

// Group: T-GO-01
func TestValidateUnsupportedVersionCarriesSupportedList(t *testing.T) {
	in := validInput()
	in.ContractVersion = 2

	var unsupported *inspectcharge.UnsupportedVersionError
	if err := in.Validate(); !errors.As(err, &unsupported) {
		t.Fatalf("Validate() = %v, want UnsupportedVersionError", err)
	}
	if unsupported.Requested != 2 || !reflect.DeepEqual(unsupported.Supported, []int{1}) {
		t.Fatalf("got requested=%d supported=%v", unsupported.Requested, unsupported.Supported)
	}
}

func TestExecuteInvalidInputDoesNotCallTheGateway(t *testing.T) {
	lookup := &fakeLookup{}
	in := validInput()
	in.Expected.Amount = 0

	_, err := inspectcharge.New(lookup, fakeClock{}).Execute(context.Background(), in)
	if !errors.Is(err, inspectcharge.ErrInvalidInput) || lookup.calls != 0 {
		t.Fatalf("err = %v, gateway calls = %d; want ErrInvalidInput and 0 calls", err, lookup.calls)
	}
}

// Group: T-GO-02
func TestExecute(t *testing.T) {
	now := time.Date(2026, 11, 25, 10, 15, 30, 123_000_000, time.UTC)
	reason := "insufficient_funds"

	cases := []struct {
		name        string
		lookup      fakeLookup
		wantOutcome charge.Outcome
		wantMatch   bool
		wantCharge  bool
		wantErr     error
	}{
		{
			name:        "succeeded and matches",
			lookup:      fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusSucceeded}},
			wantOutcome: charge.OutcomeSucceeded, wantMatch: true, wantCharge: true,
		},
		{
			name:        "succeeded with foreign amount",
			lookup:      fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 2000, Currency: "EUR"}, Status: charge.StatusSucceeded}},
			wantOutcome: charge.OutcomeSucceeded, wantMatch: false, wantCharge: true,
		},
		{
			name:        "succeeded with foreign currency",
			lookup:      fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "USD"}, Status: charge.StatusSucceeded}},
			wantOutcome: charge.OutcomeSucceeded, wantMatch: false, wantCharge: true,
		},
		{
			name:        "declined never matches",
			lookup:      fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusDeclined, DeclineReason: &reason}},
			wantOutcome: charge.OutcomeDeclined, wantMatch: false, wantCharge: true,
		},
		{
			name:        "not found",
			lookup:      fakeLookup{found: false},
			wantOutcome: charge.OutcomeNotFound, wantMatch: false, wantCharge: false,
		},
		{
			name:    "pending",
			lookup:  fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusPending}},
			wantErr: inspectcharge.ErrChargePending,
		},
		{
			name:    "unknown status is an unreadable answer",
			lookup:  fakeLookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: "weird"}},
			wantErr: inspectcharge.ErrGatewayUnavailable,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			lookup := tc.lookup
			out, err := inspectcharge.New(&lookup, fakeClock{now: now}).Execute(context.Background(), validInput())

			if tc.wantErr != nil {
				if !errors.Is(err, tc.wantErr) {
					t.Fatalf("err = %v, want %v", err, tc.wantErr)
				}

				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if lookup.key != validKey {
				t.Fatalf("looked up key %q, want %q", lookup.key, validKey)
			}
			if out.Outcome != tc.wantOutcome || out.MatchesExpected != tc.wantMatch || (out.Charge != nil) != tc.wantCharge {
				t.Fatalf("out = %+v, want outcome=%s match=%v charge=%v", out, tc.wantOutcome, tc.wantMatch, tc.wantCharge)
			}
			if !out.CheckedAt.Equal(now) {
				t.Fatalf("CheckedAt = %v, want %v", out.CheckedAt, now)
			}
		})
	}
}

// Group: T-GO-02
func TestExecuteCheckedAtIsUTCWhateverTheClockZone(t *testing.T) {
	kyiv, err := time.LoadLocation("Europe/Kyiv")
	if err != nil {
		t.Skipf("no tzdata: %v", err)
	}

	now := time.Date(2026, 11, 25, 12, 15, 30, 0, kyiv)
	out, err := inspectcharge.New(&fakeLookup{}, fakeClock{now: now}).Execute(context.Background(), validInput())
	if err != nil {
		t.Fatal(err)
	}
	if out.CheckedAt.Location() != time.UTC || !out.CheckedAt.Equal(now) {
		t.Fatalf("CheckedAt = %v, want the same instant in UTC", out.CheckedAt)
	}
}

// Group: T-GO-03
func TestExecutePassesPortErrorsUnchanged(t *testing.T) {
	for _, sentinel := range []error{
		inspectcharge.ErrGatewayUnavailable,
		inspectcharge.ErrRequestRejected,
		inspectcharge.ErrCircuitOpen,
		inspectcharge.ErrChargePending,
		&inspectcharge.CircuitOpenError{RetryAfter: 3 * time.Second},
		fmt.Errorf("wrapped: %w", inspectcharge.ErrGatewayUnavailable),
	} {
		t.Run(sentinel.Error(), func(t *testing.T) {
			lookup := &fakeLookup{err: sentinel}

			_, err := inspectcharge.New(lookup, fakeClock{}).Execute(context.Background(), validInput())
			if err != sentinel { //nolint:errorlint // identity is the point: the error must pass through untouched
				t.Fatalf("err = %v, want the same error value %v", err, sentinel)
			}
			if !errors.Is(err, sentinel) {
				t.Fatal("errors.Is does not match")
			}
		})
	}
}
