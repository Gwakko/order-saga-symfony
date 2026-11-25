// Package workerhost owns the Temporal client and worker: it dials the server, registers the activities on the task
// queue, runs the worker until the context ends and then stops it gracefully.
package workerhost

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"sync/atomic"
	"time"

	"go.temporal.io/sdk/client"
	sdklog "go.temporal.io/sdk/log"
	"go.temporal.io/sdk/worker"
)

// Config of the host.
type Config struct {
	Address                 string
	Namespace               string
	TaskQueue               string
	MaxConcurrentActivities int
	// StopTimeout is how long Stop waits for running activities.
	StopTimeout time.Duration
	// HealthInterval is how often the server is probed for readiness.
	HealthInterval time.Duration
	// Identity is optional; empty means the SDK default.
	Identity string
}

// Deps of the host.
type Deps struct {
	Logger *slog.Logger
	// Register is called once with the worker so that the caller registers its activities.
	Register func(w worker.Worker)
	// DialTimeout bounds the initial connection (default 10s).
	DialTimeout time.Duration
}

// Host runs one worker.
type Host struct {
	cfg    Config
	logger *slog.Logger
	client client.Client
	worker worker.Worker

	started atomic.Bool
	healthy atomic.Bool
	fatal   chan error
}

// New dials the Temporal server and creates the worker. The close function closes the client.
func New(cfg Config, deps Deps) (*Host, func() error, error) {
	if cfg.Address == "" || cfg.Namespace == "" || cfg.TaskQueue == "" {
		return nil, nil, errors.New("workerhost: Address, Namespace and TaskQueue are required")
	}
	if cfg.MaxConcurrentActivities < 1 {
		return nil, nil, errors.New("workerhost: MaxConcurrentActivities must be at least 1")
	}
	if cfg.HealthInterval <= 0 {
		cfg.HealthInterval = 10 * time.Second
	}
	if deps.Register == nil {
		return nil, nil, errors.New("workerhost: Register is required")
	}

	logger := deps.Logger
	if logger == nil {
		logger = slog.New(slog.DiscardHandler)
	}

	dialCtx, cancel := context.WithTimeout(context.Background(), dialTimeout(deps))
	defer cancel()

	c, err := client.DialContext(dialCtx, client.Options{
		HostPort:  cfg.Address,
		Namespace: cfg.Namespace,
		Identity:  cfg.Identity,
		Logger:    sdklog.NewStructuredLogger(logger),
	})
	if err != nil {
		return nil, nil, fmt.Errorf("workerhost: dial temporal %s: %w", cfg.Address, err)
	}

	h := &Host{cfg: cfg, logger: logger, client: c, fatal: make(chan error, 1)}
	h.worker = worker.New(c, cfg.TaskQueue, worker.Options{
		MaxConcurrentActivityExecutionSize: cfg.MaxConcurrentActivities,
		WorkerStopTimeout:                  cfg.StopTimeout,
		OnFatalError: func(err error) {
			select {
			case h.fatal <- err:
			default:
			}
		},
	})
	deps.Register(h.worker)

	return h, func() error { c.Close(); return nil }, nil
}

func dialTimeout(deps Deps) time.Duration {
	if deps.DialTimeout > 0 {
		return deps.DialTimeout
	}

	return 10 * time.Second
}

// Ready is nil when the worker is started and the last server probe succeeded.
func (h *Host) Ready() error {
	if !h.started.Load() {
		return errors.New("worker not started")
	}
	if !h.healthy.Load() {
		return errors.New("temporal server health check failed")
	}

	return nil
}

// Run starts the worker and blocks until ctx is done or the worker fails, then stops it gracefully:
// no new tasks are polled and running activities get StopTimeout to finish.
func (h *Host) Run(ctx context.Context) error {
	if err := h.worker.Start(); err != nil {
		return fmt.Errorf("workerhost: start worker: %w", err)
	}
	h.started.Store(true)
	h.probe(ctx)
	h.logger.Info("worker started", "task_queue", h.cfg.TaskQueue, "address", h.cfg.Address, "namespace", h.cfg.Namespace)

	ticker := time.NewTicker(h.cfg.HealthInterval)
	defer ticker.Stop()

	var runErr error
loop:
	for {
		select {
		case <-ctx.Done():
			break loop
		case runErr = <-h.fatal:
			runErr = fmt.Errorf("workerhost: worker failed: %w", runErr)
			break loop
		case <-ticker.C:
			h.probe(ctx)
		}
	}

	h.started.Store(false)
	h.logger.Info("worker stopping", "stop_timeout", h.cfg.StopTimeout.String())
	h.worker.Stop()
	h.logger.Info("worker stopped")

	return runErr
}

func (h *Host) probe(ctx context.Context) {
	probeCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	if _, err := h.client.CheckHealth(probeCtx, &client.CheckHealthRequest{}); err != nil {
		if ctx.Err() == nil && h.healthy.Swap(false) {
			h.logger.Warn("temporal health check failed", "error", err.Error())
		}

		return
	}
	if !h.healthy.Swap(true) {
		h.logger.Info("temporal health check ok")
	}
}
