package activity_test

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"reflect"
	"testing"
	"time"

	commonpb "go.temporal.io/api/common/v1"
	"go.temporal.io/sdk/converter"
	"go.temporal.io/sdk/temporal"
	"go.temporal.io/sdk/testsuite"

	"order-saga-symfony/worker-go/internal/adapter/contract"
	"order-saga-symfony/worker-go/internal/adapter/temporal/activity"
	"order-saga-symfony/worker-go/internal/contracttest"
	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

var fixedNow = time.Date(2026, 11, 25, 10, 15, 30, 123_000_000, time.UTC)

type fixedClock struct{}

func (fixedClock) Now() time.Time { return fixedNow }

type lookupFunc func(ctx context.Context, key string) (charge.Observation, bool, error)

func (f lookupFunc) FindCharge(ctx context.Context, key string) (charge.Observation, bool, error) {
	return f(ctx, key)
}

func succeededLookup() lookupFunc {
	return func(context.Context, string) (charge.Observation, bool, error) {
		return charge.Observation{
			ChargeID: "ch_01",
			Money:    charge.Money{Amount: 1999, Currency: "EUR"},
			Status:   charge.StatusSucceeded,
		}, true, nil
	}
}

func newEnv(t *testing.T, lookup inspectcharge.ChargeLookup) *testsuite.TestActivityEnvironment {
	t.Helper()

	act, _, err := activity.New(activity.Config{}, activity.Deps{
		UseCase: inspectcharge.New(lookup, fixedClock{}),
		Logger:  slog.New(slog.DiscardHandler),
	})
	if err != nil {
		t.Fatal(err)
	}

	env := (&testsuite.WorkflowTestSuite{}).NewTestActivityEnvironment()
	act.Register(env)

	return env
}

func goldenRequest(t *testing.T) contract.Request {
	t.Helper()

	var req contract.Request
	if err := json.Unmarshal(contracttest.Read(t, "input.v1.json"), &req); err != nil {
		t.Fatal(err)
	}

	return req
}

func applicationError(t *testing.T, err error) *temporal.ApplicationError {
	t.Helper()

	var app *temporal.ApplicationError
	if !errors.As(err, &app) {
		t.Fatalf("expected *temporal.ApplicationError in the chain, got %T: %v", err, err)
	}

	return app
}

// Group: T-GO-09
func TestActivityReturnsGoldenOutput(t *testing.T) {
	env := newEnv(t, succeededLookup())

	val, err := env.ExecuteActivity(activity.Name, goldenRequest(t))
	if err != nil {
		t.Fatal(err)
	}

	var resp contract.Response
	if err := val.Get(&resp); err != nil {
		t.Fatal(err)
	}

	got, err := json.Marshal(resp)
	if err != nil {
		t.Fatal(err)
	}

	want := contracttest.Decode(t, contracttest.Read(t, "output.succeeded.json"))
	if !reflect.DeepEqual(contracttest.Decode(t, got), want) {
		t.Fatalf("output differs from output.succeeded.json:\n got %s", got)
	}
}

// Group: T-GO-09
func TestActivityMismatchAndNotFoundGolden(t *testing.T) {
	cases := map[string]lookupFunc{
		"output.mismatch.json": func(context.Context, string) (charge.Observation, bool, error) {
			return charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 2000, Currency: "EUR"}, Status: charge.StatusSucceeded}, true, nil
		},
		"output.not_found.json": func(context.Context, string) (charge.Observation, bool, error) {
			return charge.Observation{}, false, nil
		},
		"output.declined.json": func(context.Context, string) (charge.Observation, bool, error) {
			reason := "insufficient_funds"

			return charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusDeclined, DeclineReason: &reason}, true, nil
		},
	}

	for file, lookup := range cases {
		t.Run(file, func(t *testing.T) {
			val, err := newEnv(t, lookup).ExecuteActivity(activity.Name, goldenRequest(t))
			if err != nil {
				t.Fatal(err)
			}

			var resp contract.Response
			if err := val.Get(&resp); err != nil {
				t.Fatal(err)
			}
			got, _ := json.Marshal(resp)

			if !reflect.DeepEqual(contracttest.Decode(t, got), contracttest.Decode(t, contracttest.Read(t, file))) {
				t.Fatalf("output differs from %s:\n got %s", file, got)
			}
		})
	}
}

type errorsFile struct {
	Errors []struct {
		Type         string `json:"type"`
		NonRetryable bool   `json:"nonRetryable"`
	} `json:"errors"`
}

// Group: T-GO-10
func TestActivityMapsUseCaseErrorsToApplicationErrors(t *testing.T) {
	var file errorsFile
	if err := json.Unmarshal(contracttest.Read(t, "errors.json"), &file); err != nil {
		t.Fatal(err)
	}
	nonRetryable := map[string]bool{}
	for _, e := range file.Errors {
		nonRetryable[e.Type] = e.NonRetryable
	}

	cases := []struct {
		name       string
		err        error
		wantType   string
		retryAfter time.Duration
	}{
		{"rejected", fmt.Errorf("wrapped: %w", inspectcharge.ErrRequestRejected), "GatewayRequestRejected", 0},
		{"unavailable", inspectcharge.ErrGatewayUnavailable, "GatewayUnavailable", 0},
		{"pending", inspectcharge.ErrChargePending, "ChargePending", 0},
		{"circuit open with delay", &inspectcharge.CircuitOpenError{RetryAfter: 7 * time.Second}, "CircuitOpen", 7 * time.Second},
		{"circuit open without delay", &inspectcharge.CircuitOpenError{}, "CircuitOpen", 0},
		{"not in the table", errors.New("something odd"), "GatewayUnavailable", 0},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			lookup := func(context.Context, string) (charge.Observation, bool, error) {
				return charge.Observation{}, false, tc.err
			}

			_, err := newEnv(t, lookupFunc(lookup)).ExecuteActivity(activity.Name, goldenRequest(t))
			if err == nil {
				t.Fatal("expected an error")
			}

			app := applicationError(t, err)
			want, known := nonRetryable[tc.wantType]
			if !known {
				t.Fatalf("type %s is not in errors.json", tc.wantType)
			}
			if app.Type() != tc.wantType || app.NonRetryable() != want {
				t.Fatalf("got type=%s nonRetryable=%v, want type=%s nonRetryable=%v", app.Type(), app.NonRetryable(), tc.wantType, want)
			}
			if app.NextRetryDelay() != tc.retryAfter {
				t.Fatalf("NextRetryDelay = %s, want %s", app.NextRetryDelay(), tc.retryAfter)
			}
		})
	}
}

// Group: T-GO-10
func TestActivityRejectsInvalidInputWithoutCallingGateway(t *testing.T) {
	called := false
	lookup := lookupFunc(func(context.Context, string) (charge.Observation, bool, error) {
		called = true

		return charge.Observation{}, false, nil
	})

	req := goldenRequest(t)
	req.IdempotencyKey = "bad/key"
	req.Expected.Amount = 0

	_, err := newEnv(t, lookup).ExecuteActivity(activity.Name, req)
	app := applicationError(t, err)

	if app.Type() != "InvalidInspectionInput" || !app.NonRetryable() {
		t.Fatalf("got type=%s nonRetryable=%v", app.Type(), app.NonRetryable())
	}
	if called {
		t.Fatal("the gateway must not be called for an invalid input")
	}
}

// Group: T-GO-11
func TestActivityRejectsUnsupportedContractVersion(t *testing.T) {
	req := goldenRequest(t)
	req.ContractVersion = 2

	_, err := newEnv(t, succeededLookup()).ExecuteActivity(activity.Name, req)
	app := applicationError(t, err)

	if app.Type() != "UnsupportedContractVersion" || !app.NonRetryable() {
		t.Fatalf("got type=%s nonRetryable=%v", app.Type(), app.NonRetryable())
	}

	var details map[string]any
	if err := app.Details(&details); err != nil {
		t.Fatalf("details: %v", err)
	}
	supported, ok := details["supported"].([]any)
	if !ok || len(supported) != 1 || supported[0].(float64) != 1 {
		t.Fatalf("details.supported = %v, want [1]", details["supported"])
	}
	if details["requested"].(float64) != 2 {
		t.Fatalf("details.requested = %v, want 2", details["requested"])
	}
}

// Group: T-GO-12
func TestGoldenPayloadsRoundTripThroughDefaultDataConverter(t *testing.T) {
	dc := converter.GetDefaultDataConverter()

	// PHP sends the argument as one json/plain payload.
	payload := &commonpb.Payload{
		Metadata: map[string][]byte{converter.MetadataEncoding: []byte(converter.MetadataEncodingJSON)},
		Data:     contracttest.Read(t, "input.v1.json"),
	}

	var req contract.Request
	if err := dc.FromPayload(payload, &req); err != nil {
		t.Fatal(err)
	}
	if req.ContractVersion != 1 || req.Expected.Amount != 1999 || req.Expected.Currency != "EUR" {
		t.Fatalf("decoded request: %+v", req)
	}

	// An unknown field added in a later minor version is ignored.
	extended := []byte(`{"contractVersion":1,"orderId":"o","idempotencyKey":"k","expected":{"amount":1,"currency":"EUR"},"future":true}`)
	if err := dc.FromPayload(&commonpb.Payload{Metadata: payload.Metadata, Data: extended}, &req); err != nil {
		t.Fatalf("unknown fields must be ignored: %v", err)
	}

	// The result is encoded as json/plain and equals the golden file structurally.
	val, err := newEnv(t, succeededLookup()).ExecuteActivity(activity.Name, goldenRequest(t))
	if err != nil {
		t.Fatal(err)
	}
	var resp contract.Response
	if err := val.Get(&resp); err != nil {
		t.Fatal(err)
	}

	out, err := dc.ToPayload(resp)
	if err != nil {
		t.Fatal(err)
	}
	if string(out.Metadata[converter.MetadataEncoding]) != "json/plain" {
		t.Fatalf("encoding = %s", out.Metadata[converter.MetadataEncoding])
	}
	if !reflect.DeepEqual(contracttest.Decode(t, out.Data), contracttest.Decode(t, contracttest.Read(t, "output.succeeded.json"))) {
		t.Fatalf("payload differs from golden: %s", out.Data)
	}
}

// Group: T-GO-17
func TestWorkerStopLetsActivityFinishThenCancelledContextIsUnavailable(t *testing.T) {
	started := make(chan struct{})
	lookup := lookupFunc(func(ctx context.Context, _ string) (charge.Observation, bool, error) {
		close(started)
		<-ctx.Done()

		return charge.Observation{}, false, fmt.Errorf("%w: %v", inspectcharge.ErrGatewayUnavailable, ctx.Err())
	})

	act, _, err := activity.New(activity.Config{}, activity.Deps{
		UseCase: inspectcharge.New(lookup, fixedClock{}),
		Logger:  slog.New(slog.DiscardHandler),
	})
	if err != nil {
		t.Fatal(err)
	}

	// The SDK cancels the activity context only after WorkerStopTimeout; the test env reproduces it through the background context.
	bg, cancel := context.WithCancel(context.Background())
	defer cancel()

	env := (&testsuite.WorkflowTestSuite{}).NewTestActivityEnvironment()
	env.SetWorkerOptions(testWorkerOptions(bg))
	stop := make(chan struct{})
	env.SetWorkerStopChannel(stop)
	act.Register(env)

	done := make(chan error, 1)
	go func() {
		_, err := env.ExecuteActivity(activity.Name, goldenRequest(t))
		done <- err
	}()

	<-started
	close(stop) // worker.Stop(): no new tasks, running activity keeps going

	select {
	case err := <-done:
		t.Fatalf("activity must keep running after the stop signal until its context ends, got %v", err)
	case <-time.After(200 * time.Millisecond):
	}

	cancel() // StopTimeout elapsed

	select {
	case err := <-done:
		app := applicationError(t, err)
		if app.Type() != "GatewayUnavailable" || app.NonRetryable() {
			t.Fatalf("got type=%s nonRetryable=%v, want retryable GatewayUnavailable", app.Type(), app.NonRetryable())
		}
	case <-time.After(5 * time.Second):
		t.Fatal("activity did not return after its context was cancelled")
	}
}

func TestNewRequiresUseCase(t *testing.T) {
	if _, _, err := activity.New(activity.Config{}, activity.Deps{}); err == nil {
		t.Fatal("expected an error without a use case")
	}
}
