// Package app is the composition root: the only place where adapters, the use case and the configuration are wired.
package app

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"time"

	"go.temporal.io/sdk/worker"

	"order-saga-symfony/worker-go/internal/adapter/clock"
	"order-saga-symfony/worker-go/internal/adapter/gateway/breaker"
	"order-saga-symfony/worker-go/internal/adapter/gateway/httpgateway"
	"order-saga-symfony/worker-go/internal/adapter/health"
	"order-saga-symfony/worker-go/internal/adapter/temporal/activity"
	"order-saga-symfony/worker-go/internal/adapter/temporal/workerhost"
	"order-saga-symfony/worker-go/internal/config"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

// NewLogger builds the process logger from the log settings.
func NewLogger(cfg config.Log, w io.Writer) *slog.Logger {
	var level slog.Level
	_ = level.UnmarshalText([]byte(cfg.Level))
	opts := &slog.HandlerOptions{Level: level}

	if cfg.Format == "text" {
		return slog.New(slog.NewTextHandler(w, opts))
	}

	return slog.New(slog.NewJSONHandler(w, opts))
}

// WorkerApp is the assembled worker process.
type WorkerApp struct {
	host   *workerhost.Host
	health *health.Server
	logger *slog.Logger
}

// BuildWorker wires everything and connects to Temporal. The returned close function releases all resources
// (health server, Temporal client) and is safe to call once after Run returned.
func BuildWorker(ctx context.Context, cfg config.Config, logger *slog.Logger) (*WorkerApp, func(), error) {
	var closers []func() error
	closeAll := func() {
		for i := len(closers) - 1; i >= 0; i-- {
			if err := closers[i](); err != nil {
				logger.Warn("close failed", "error", err.Error())
			}
		}
	}
	fail := func(err error) (*WorkerApp, func(), error) {
		closeAll()

		return nil, nil, err
	}

	gw, closeGW, err := httpgateway.New(httpgateway.Config{
		BaseURL:      cfg.Gateway.BaseURL,
		Timeout:      cfg.Gateway.Timeout,
		MaxBodyBytes: cfg.Gateway.MaxBodyBytes,
	}, httpgateway.Deps{})
	if err != nil {
		return fail(err)
	}
	closers = append(closers, closeGW)

	guarded, closeBreaker, err := breaker.New(breaker.Config{
		Name:     "gateway-inspect",
		Failures: cfg.Breaker.Failures,
		OpenFor:  cfg.Breaker.OpenFor,
	}, breaker.Deps{Next: gw, Logger: logger})
	if err != nil {
		return fail(err)
	}
	closers = append(closers, closeBreaker)

	act, closeAct, err := activity.New(activity.Config{}, activity.Deps{
		UseCase: inspectcharge.New(guarded, clock.New()),
		Logger:  logger,
	})
	if err != nil {
		return fail(err)
	}
	closers = append(closers, closeAct)

	host, closeHost, err := workerhost.New(workerhost.Config{
		Address:                 cfg.Temporal.Address,
		Namespace:               cfg.Temporal.Namespace,
		TaskQueue:               cfg.Worker.TaskQueue,
		MaxConcurrentActivities: cfg.Worker.MaxConcurrentActivities,
		StopTimeout:             cfg.Worker.StopTimeout,
		HealthInterval:          10 * time.Second,
	}, workerhost.Deps{
		Logger:   logger,
		Register: func(w worker.Worker) { act.Register(w) },
	})
	if err != nil {
		return fail(err)
	}
	closers = append(closers, closeHost)

	hs, closeHealth, err := health.New(health.Config{Addr: cfg.Health.Addr}, health.Deps{Probe: host.Ready, Logger: logger})
	if err != nil {
		return fail(err)
	}
	closers = append(closers, closeHealth)

	return &WorkerApp{host: host, health: hs, logger: logger}, closeAll, nil
}

// Run serves until ctx is done (signal) and then stops the worker gracefully. It returns nil on a clean stop.
func (a *WorkerApp) Run(ctx context.Context) error {
	return a.host.Run(ctx)
}

// HealthAddr is the bound address of the health endpoint.
func (a *WorkerApp) HealthAddr() string { return a.health.Addr() }

// Healthcheck is the readiness probe used by the healthcheck binary.
type Healthcheck struct {
	url    string
	client *http.Client
}

// BuildHealthcheck prepares a probe against the local health endpoint.
func BuildHealthcheck(cfg config.Health) (*Healthcheck, error) {
	host, port, err := net.SplitHostPort(cfg.Addr)
	if err != nil {
		return nil, fmt.Errorf("healthcheck: %w", err)
	}
	if host == "" || host == "0.0.0.0" || host == "::" {
		host = "127.0.0.1"
	}

	return &Healthcheck{
		url:    "http://" + net.JoinHostPort(host, port) + "/readyz",
		client: &http.Client{Timeout: 2 * time.Second},
	}, nil
}

// Check returns nil when /readyz answers 200.
func (h *Healthcheck) Check(ctx context.Context) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, h.url, nil)
	if err != nil {
		return err
	}

	resp, err := h.client.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1024))

	if resp.StatusCode != http.StatusOK {
		return errors.New("readyz returned " + resp.Status)
	}

	return nil
}
