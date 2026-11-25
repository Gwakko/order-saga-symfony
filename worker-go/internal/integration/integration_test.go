//go:build integration

package integration_test

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os/exec"
	"reflect"
	"strings"
	"testing"
	"time"

	enumspb "go.temporal.io/api/enums/v1"
	"go.temporal.io/sdk/temporal"

	"order-saga-symfony/worker-go/internal/contracttest"
)

const key = "0190a5c4-0000-7000-8000-0000000000a1"

var defaultCall = callOptions{ScheduleToStart: 20 * time.Second, StartToClose: 15 * time.Second, ScheduleToClose: 60 * time.Second}

// Group: T-GO-20
func TestIntegrationSuccessMatchesGolden(t *testing.T) {
	e := newEnv(t)
	gw := newFakeGateway(t, func(_ int, w http.ResponseWriter, r *http.Request) { writeSucceeded(w, r) })
	e.startHost(gw.srv.URL, 5*time.Second)

	out, err := e.result(e.start(goldenRequest(t, key), defaultCall))
	if err != nil {
		t.Fatal(err)
	}

	// checkedAt comes from the real clock: compare everything else with the golden file and check the format.
	got, _ := json.Marshal(out)
	var gotMap, wantMap map[string]any
	_ = json.Unmarshal(got, &gotMap)
	_ = json.Unmarshal(contracttest.Read(t, "output.succeeded.json"), &wantMap)

	checkedAt, _ := gotMap["checkedAt"].(string)
	if _, err := time.Parse("2006-01-02T15:04:05.000-07:00", checkedAt); err != nil || !strings.HasSuffix(checkedAt, "+00:00") {
		t.Fatalf("checkedAt %q is not RFC 3339 with milliseconds and +00:00", checkedAt)
	}
	delete(gotMap, "checkedAt")
	delete(wantMap, "checkedAt")
	if !reflect.DeepEqual(gotMap, wantMap) {
		t.Fatalf("result differs from output.succeeded.json:\n got %s", got)
	}
	if gw.hits() != 1 || gw.nonGet() != 0 {
		t.Fatalf("gateway hits=%d nonGet=%d, want 1 and 0", gw.hits(), gw.nonGet())
	}
}

// Group: T-GO-21
func TestIntegrationNonRetryableErrorIsOneAttempt(t *testing.T) {
	e := newEnv(t)
	gw := newFakeGateway(t, func(_ int, w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		_, _ = w.Write([]byte(`{"error":"bad_request"}`))
	})
	e.startHost(gw.srv.URL, 5*time.Second)

	run := e.start(goldenRequest(t, key), defaultCall)
	_, err := e.result(run)

	var app *temporal.ApplicationError
	if !errors.As(err, &app) || app.Type() != "GatewayRequestRejected" || !app.NonRetryable() {
		t.Fatalf("want non-retryable GatewayRequestRejected, got %v", err)
	}
	if gw.hits() != 1 {
		t.Fatalf("gateway hits = %d, want 1", gw.hits())
	}
	if attempts := e.startedAttempts(run); !reflect.DeepEqual(attempts, []int32{1}) {
		t.Fatalf("attempts = %v, want [1]", attempts)
	}
}

// Group: T-GO-22
func TestIntegrationRetryableErrorIsRetriedWithSameKey(t *testing.T) {
	e := newEnv(t)
	gw := newFakeGateway(t, func(n int, w http.ResponseWriter, r *http.Request) {
		if n <= 2 {
			w.WriteHeader(http.StatusServiceUnavailable)

			return
		}
		writeSucceeded(w, r)
	})
	e.startHost(gw.srv.URL, 5*time.Second)

	run := e.start(goldenRequest(t, key), defaultCall)
	out, err := e.result(run)
	if err != nil {
		t.Fatal(err)
	}
	if out.Outcome != "succeeded" || !out.MatchesExpected {
		t.Fatalf("unexpected result %+v", out)
	}
	if gw.hits() != 3 {
		t.Fatalf("gateway hits = %d, want 3", gw.hits())
	}
	if keys := gw.distinctKeys(); len(keys) != 1 || !keys[key] {
		t.Fatalf("keys = %v, want only %s", keys, key)
	}
	if attempts := e.startedAttempts(run); !reflect.DeepEqual(attempts, []int32{3}) {
		t.Fatalf("started attempts = %v, want [3] (the history keeps the started event of the last attempt)", attempts)
	}
}

// Group: T-GO-23
func TestIntegrationSlowFirstAttemptIsRetriedAndLateResultDropped(t *testing.T) {
	e := newEnv(t)
	gw := newFakeGateway(t, func(n int, w http.ResponseWriter, r *http.Request) {
		if n == 1 {
			time.Sleep(4 * time.Second)
		}
		writeSucceeded(w, r)
	})
	e.startHost(gw.srv.URL, 5*time.Second)

	opts := callOptions{ScheduleToStart: 20 * time.Second, StartToClose: 2 * time.Second, ScheduleToClose: 60 * time.Second}
	run := e.start(goldenRequest(t, key), opts)
	out, err := e.result(run)
	if err != nil {
		t.Fatal(err)
	}
	if out.Outcome != "succeeded" {
		t.Fatalf("unexpected result %+v", out)
	}

	time.Sleep(3 * time.Second) // let the first (late) attempt finish and be rejected by the server
	if gw.hits() != 2 || gw.nonGet() != 0 {
		t.Fatalf("gateway hits=%d nonGet=%d, want 2 and 0", gw.hits(), gw.nonGet())
	}
	if attempts := e.startedAttempts(run); !reflect.DeepEqual(attempts, []int32{2}) {
		t.Fatalf("started attempts = %v, want [2] (the history keeps the started event of the last attempt)", attempts)
	}
}

// Group: T-GO-24
func TestIntegrationWorkerKilledMidActivityIsRetriedOnAnotherInstance(t *testing.T) {
	e := newEnv(t)

	firstSeen := make(chan struct{})
	gw := newFakeGateway(t, func(n int, w http.ResponseWriter, r *http.Request) {
		if n == 1 {
			close(firstSeen)
			select { // hold the request until worker A dies (the connection closes)
			case <-r.Context().Done():
			case <-time.After(30 * time.Second):
			}

			return
		}
		writeSucceeded(w, r)
	})

	a := e.startProcess(gw.srv.URL)
	a.waitReady()

	opts := callOptions{ScheduleToStart: 20 * time.Second, StartToClose: 3 * time.Second, ScheduleToClose: 60 * time.Second}
	run := e.start(goldenRequest(t, key), opts)

	select {
	case <-firstSeen:
	case <-time.After(20 * time.Second):
		t.Fatalf("worker A never called the gateway; output:\n%s", a.out.String())
	}

	a.signal(9) // SIGKILL: a crash, nothing is reported to the server
	<-a.exited

	e.startHost(gw.srv.URL, 5*time.Second) // worker B on the same queue

	out, err := e.result(run)
	if err != nil {
		t.Fatal(err)
	}
	if out.Outcome != "succeeded" || !out.MatchesExpected {
		t.Fatalf("unexpected result %+v", out)
	}
	if gw.hits() != 2 || gw.nonGet() != 0 {
		t.Fatalf("gateway hits=%d nonGet=%d, want 2 and 0", gw.hits(), gw.nonGet())
	}
	if keys := gw.distinctKeys(); len(keys) != 1 || !keys[key] {
		t.Fatalf("keys = %v, want only %s", keys, key)
	}
	if attempts := e.startedAttempts(run); !reflect.DeepEqual(attempts, []int32{2}) {
		t.Fatalf("started attempts = %v, want [2]", attempts)
	}
}

// Group: T-GO-25
func TestIntegrationNoPollersIsScheduleToStartTimeout(t *testing.T) {
	e := newEnv(t) // no host on the Go queue

	opts := callOptions{ScheduleToStart: 2 * time.Second, StartToClose: 15 * time.Second, ScheduleToClose: 60 * time.Second}
	run := e.start(goldenRequest(t, key), opts)
	_, err := e.result(run)

	var timeout *temporal.TimeoutError
	if !errors.As(err, &timeout) || timeout.TimeoutType() != enumspb.TIMEOUT_TYPE_SCHEDULE_TO_START {
		t.Fatalf("want a SCHEDULE_TO_START timeout, got %v", err)
	}
	if attempts := e.startedAttempts(run); len(attempts) != 0 {
		t.Fatalf("started attempts = %v, want none", attempts)
	}
}

// Group: T-GO-26
func TestIntegrationSigtermFinishesRunningActivityAndExitsZero(t *testing.T) {
	e := newEnv(t)

	seen := make(chan struct{})
	gw := newFakeGateway(t, func(n int, w http.ResponseWriter, r *http.Request) {
		if n == 1 {
			close(seen)
			time.Sleep(2 * time.Second)
		}
		writeSucceeded(w, r)
	})

	p := e.startProcess(gw.srv.URL, "WORKER_STOP_TIMEOUT=10s")
	p.waitReady()

	run := e.start(goldenRequest(t, key), defaultCall)

	select {
	case <-seen:
	case <-time.After(20 * time.Second):
		t.Fatalf("worker never called the gateway; output:\n%s", p.out.String())
	}

	begin := time.Now()
	p.signal(15) // SIGTERM

	select {
	case err := <-p.exited:
		if err != nil {
			t.Fatalf("worker exited with %v; output:\n%s", err, p.out.String())
		}
	case <-time.After(15 * time.Second):
		t.Fatalf("worker did not exit within 15 s; output:\n%s", p.out.String())
	}
	elapsed := time.Since(begin)
	if elapsed > 10*time.Second {
		t.Fatalf("shutdown took %s, want within WORKER_STOP_TIMEOUT (10s)", elapsed)
	}
	t.Logf("shutdown took %s", elapsed.Round(time.Millisecond))

	out, err := e.result(run)
	if err != nil {
		t.Fatalf("the running activity must complete during shutdown: %v", err)
	}
	if out.Outcome != "succeeded" {
		t.Fatalf("unexpected result %+v", out)
	}
	if attempts := e.startedAttempts(run); !reflect.DeepEqual(attempts, []int32{1}) {
		t.Fatalf("started attempts = %v, want [1] (no retry after a graceful stop)", attempts)
	}
	if !strings.Contains(p.out.String(), "worker stopped") {
		t.Fatalf("missing 'worker stopped' in output:\n%s", p.out.String())
	}
}

// Group: T-GO-05
func TestIntegrationInvalidConfigurationExitsWithCodeTwo(t *testing.T) {
	temporalAddress(t)

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, workerBinary)
	cmd.Env = []string{"GATEWAY_TIMEOUT=30s"}

	out, err := cmd.CombinedOutput()

	var exit *exec.ExitError
	if !errors.As(err, &exit) || exit.ExitCode() != 2 {
		t.Fatalf("want exit code 2, got %v; output:\n%s", err, out)
	}
	for _, want := range []string{"GO_WORKER_TASK_QUEUE", "GATEWAY_BASE_URL", "GATEWAY_TIMEOUT"} {
		if !strings.Contains(string(out), want) {
			t.Fatalf("output must name %s:\n%s", want, out)
		}
	}
}
