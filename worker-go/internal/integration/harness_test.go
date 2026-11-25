//go:build integration

// Package integration_test runs the worker against a real Temporal dev server (TEMPORAL_ADDRESS, compose service
// `temporal`). Every test uses its own random task queues and its own fake gateway, so runs do not see each other.
package integration_test

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"go.temporal.io/sdk/client"
	"go.temporal.io/sdk/temporal"
	"go.temporal.io/sdk/worker"
	"go.temporal.io/sdk/workflow"

	"order-saga-symfony/worker-go/internal/adapter/clock"
	"order-saga-symfony/worker-go/internal/adapter/contract"
	"order-saga-symfony/worker-go/internal/adapter/gateway/breaker"
	"order-saga-symfony/worker-go/internal/adapter/gateway/httpgateway"
	"order-saga-symfony/worker-go/internal/adapter/temporal/activity"
	"order-saga-symfony/worker-go/internal/adapter/temporal/workerhost"
	"order-saga-symfony/worker-go/internal/contracttest"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

var workerBinary string

func TestMain(m *testing.M) {
	if os.Getenv("TEMPORAL_ADDRESS") == "" {
		os.Exit(m.Run()) // every test skips
	}

	dir, err := os.MkdirTemp("", "worker-go-it")
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	workerBinary = filepath.Join(dir, "worker")
	build := exec.Command("go", "build", "-buildvcs=false", "-o", workerBinary, "order-saga-symfony/worker-go/cmd/worker")
	if out, err := build.CombinedOutput(); err != nil {
		fmt.Fprintf(os.Stderr, "build worker: %v\n%s", err, out)
		_ = os.RemoveAll(dir)
		os.Exit(1)
	}

	code := m.Run()
	_ = os.RemoveAll(dir)
	os.Exit(code)
}

func temporalAddress(t *testing.T) string {
	t.Helper()

	addr := os.Getenv("TEMPORAL_ADDRESS")
	if addr == "" {
		t.Skip("TEMPORAL_ADDRESS is not set; run `make go-test-int` against the compose temporal service")
	}

	return addr
}

func randomSuffix() string {
	b := make([]byte, 6)
	_, _ = rand.Read(b)

	return hex.EncodeToString(b)
}

// env is one test's Temporal connection plus the test workflow worker on its own queue.
type env struct {
	t         *testing.T
	address   string
	client    client.Client
	wfQueue   string
	taskQueue string // the Go activity queue
}

func newEnv(t *testing.T) *env {
	t.Helper()

	address := temporalAddress(t)
	c, err := client.Dial(client.Options{HostPort: address})
	if err != nil {
		t.Fatalf("dial temporal %s: %v", address, err)
	}
	t.Cleanup(c.Close)

	suffix := randomSuffix()
	e := &env{t: t, address: address, client: c, wfQueue: "payment-go-gotest-wf-" + suffix, taskQueue: "payment-go-gotest-" + suffix}

	w := worker.New(c, e.wfQueue, worker.Options{})
	w.RegisterWorkflow(InspectWorkflow)
	if err := w.Start(); err != nil {
		t.Fatalf("start workflow worker: %v", err)
	}
	t.Cleanup(w.Stop)

	return e
}

// callOptions are the timeouts the PHP profile would set; tests shorten them.
type callOptions struct {
	ScheduleToStart time.Duration
	StartToClose    time.Duration
	ScheduleToClose time.Duration
}

type workflowInput struct {
	Request   contract.Request
	TaskQueue string
	Options   callOptions
}

// InspectWorkflow is a stub of the PHP saga branch: it calls InspectGatewayCharge by name on the Go queue.
func InspectWorkflow(ctx workflow.Context, in workflowInput) (contract.Response, error) {
	ctx = workflow.WithActivityOptions(ctx, workflow.ActivityOptions{
		TaskQueue:              in.TaskQueue,
		ScheduleToStartTimeout: in.Options.ScheduleToStart,
		StartToCloseTimeout:    in.Options.StartToClose,
		ScheduleToCloseTimeout: in.Options.ScheduleToClose,
		RetryPolicy: &temporal.RetryPolicy{
			InitialInterval:    200 * time.Millisecond,
			BackoffCoefficient: 1,
			MaximumInterval:    200 * time.Millisecond,
		},
	})

	var out contract.Response
	err := workflow.ExecuteActivity(ctx, activity.Name, in.Request).Get(ctx, &out)

	return out, err
}

func (e *env) start(req contract.Request, opts callOptions) client.WorkflowRun {
	e.t.Helper()

	run, err := e.client.ExecuteWorkflow(context.Background(), client.StartWorkflowOptions{
		ID:                       "go-it-" + randomSuffix(),
		TaskQueue:                e.wfQueue,
		WorkflowExecutionTimeout: 90 * time.Second,
	}, InspectWorkflow, workflowInput{Request: req, TaskQueue: e.taskQueue, Options: opts})
	if err != nil {
		e.t.Fatalf("start workflow: %v", err)
	}

	return run
}

func (e *env) result(run client.WorkflowRun) (contract.Response, error) {
	e.t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 75*time.Second)
	defer cancel()

	var out contract.Response
	err := run.Get(ctx, &out)

	return out, err
}

// startedAttempts returns the attempt number of every ActivityTaskStarted event of the run.
func (e *env) startedAttempts(run client.WorkflowRun) []int32 {
	e.t.Helper()

	var attempts []int32
	iter := e.client.GetWorkflowHistory(context.Background(), run.GetID(), run.GetRunID(), false, 0)
	for iter.HasNext() {
		ev, err := iter.Next()
		if err != nil {
			e.t.Fatalf("history: %v", err)
		}
		if attrs := ev.GetActivityTaskStartedEventAttributes(); attrs != nil {
			attempts = append(attempts, attrs.GetAttempt())
		}
	}

	return attempts
}

func goldenRequest(t *testing.T, key string) contract.Request {
	t.Helper()

	req := contract.Request{
		ContractVersion: 1,
		OrderID:         "0190a5c4-0000-7000-8000-000000000020",
		IdempotencyKey:  key,
		Expected:        contract.MoneyDTO{Amount: 1999, Currency: "EUR"},
	}
	_ = contracttest.Dir(t) // fails early when the contract directory is missing

	return req
}

// fakeGateway is an httptest gateway that records every request.
type fakeGateway struct {
	srv     *httptest.Server
	handler func(n int, w http.ResponseWriter, r *http.Request)

	mu      sync.Mutex
	methods []string
	keys    []string
}

func newFakeGateway(t *testing.T, handler func(n int, w http.ResponseWriter, r *http.Request)) *fakeGateway {
	t.Helper()

	g := &fakeGateway{handler: handler}
	g.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		g.mu.Lock()
		g.methods = append(g.methods, r.Method)
		g.keys = append(g.keys, strings.TrimPrefix(r.URL.Path, "/charges/"))
		n := len(g.methods)
		g.mu.Unlock()

		g.handler(n, w, r)
	}))
	t.Cleanup(g.srv.Close)

	return g
}

func (g *fakeGateway) hits() int {
	g.mu.Lock()
	defer g.mu.Unlock()

	return len(g.methods)
}

func (g *fakeGateway) nonGet() int {
	g.mu.Lock()
	defer g.mu.Unlock()

	n := 0
	for _, m := range g.methods {
		if m != http.MethodGet {
			n++
		}
	}

	return n
}

func (g *fakeGateway) distinctKeys() map[string]bool {
	g.mu.Lock()
	defer g.mu.Unlock()

	out := map[string]bool{}
	for _, k := range g.keys {
		out[k] = true
	}

	return out
}

func writeSucceeded(w http.ResponseWriter, r *http.Request) {
	key := strings.TrimPrefix(r.URL.Path, "/charges/")
	w.Header().Set("Content-Type", "application/json")
	_, _ = fmt.Fprintf(w, `{"chargeId":"ch_01","idempotencyKey":%q,"status":"succeeded","amount":1999,"currency":"EUR"}`, key)
}

// startHost runs an in-process worker host on the env's Go queue until the returned stop function is called.
func (e *env) startHost(gatewayURL string, stopTimeout time.Duration) (stop func()) {
	e.t.Helper()

	gw, closeGW, err := httpgateway.New(httpgateway.Config{BaseURL: gatewayURL, Timeout: 5 * time.Second, MaxBodyBytes: 65536}, httpgateway.Deps{})
	if err != nil {
		e.t.Fatal(err)
	}
	guarded, closeBr, err := breaker.New(breaker.Config{Name: "it", Failures: 100, OpenFor: time.Second}, breaker.Deps{Next: gw})
	if err != nil {
		e.t.Fatal(err)
	}
	act, _, err := activity.New(activity.Config{}, activity.Deps{UseCase: inspectcharge.New(guarded, clock.New())})
	if err != nil {
		e.t.Fatal(err)
	}

	host, closeHost, err := workerhost.New(workerhost.Config{
		Address:                 e.address,
		Namespace:               "default",
		TaskQueue:               e.taskQueue,
		MaxConcurrentActivities: 8,
		StopTimeout:             stopTimeout,
		HealthInterval:          time.Second,
	}, workerhost.Deps{Register: func(w worker.Worker) { act.Register(w) }})
	if err != nil {
		e.t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- host.Run(ctx) }()

	var once sync.Once
	stop = func() {
		once.Do(func() {
			cancel()
			if err := <-done; err != nil {
				e.t.Errorf("host.Run: %v", err)
			}
			_ = closeHost()
			_ = closeBr()
			_ = closeGW()
		})
	}
	e.t.Cleanup(stop)

	return stop
}

// proc is the built worker binary running as a child process.
type proc struct {
	t      *testing.T
	cmd    *exec.Cmd
	out    *syncBuffer
	health string
	exited chan error
}

type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.String()
}

func freeAddr(t *testing.T) string {
	t.Helper()

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = ln.Close() }()

	return ln.Addr().String()
}

func (e *env) startProcess(gatewayURL string, extra ...string) *proc {
	e.t.Helper()

	p := &proc{t: e.t, out: &syncBuffer{}, health: freeAddr(e.t), exited: make(chan error, 1)}
	p.cmd = exec.Command(workerBinary)
	p.cmd.Env = append([]string{
		"PATH=" + os.Getenv("PATH"),
		"TEMPORAL_ADDRESS=" + e.address,
		"GO_WORKER_TASK_QUEUE=" + e.taskQueue,
		"GATEWAY_BASE_URL=" + gatewayURL,
		"HEALTH_ADDR=" + p.health,
		"LOG_FORMAT=text",
	}, extra...)
	p.cmd.Stdout = p.out
	p.cmd.Stderr = p.out

	if err := p.cmd.Start(); err != nil {
		e.t.Fatalf("start worker process: %v", err)
	}
	go func() { p.exited <- p.cmd.Wait() }()

	e.t.Cleanup(func() {
		_ = p.cmd.Process.Kill()
		select {
		case <-p.exited:
		case <-time.After(5 * time.Second):
		}
	})

	return p
}

func (p *proc) waitReady() {
	p.t.Helper()

	deadline := time.Now().Add(20 * time.Second)
	for time.Now().Before(deadline) {
		resp, err := http.Get("http://" + p.health + "/readyz")
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return
			}
		}
		time.Sleep(100 * time.Millisecond)
	}
	p.t.Fatalf("worker process did not become ready; output:\n%s", p.out.String())
}

func (p *proc) signal(sig syscall.Signal) {
	p.t.Helper()

	if err := p.cmd.Process.Signal(sig); err != nil {
		p.t.Fatalf("signal %v: %v", sig, err)
	}
}
