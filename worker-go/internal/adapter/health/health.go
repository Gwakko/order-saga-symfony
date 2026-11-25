// Package health serves the liveness and readiness endpoints used by the container healthcheck.
package health

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"time"
)

// Config of the health server.
type Config struct {
	// Addr is host:port; port 0 picks a free port (tests).
	Addr string
}

// Deps of the health server.
type Deps struct {
	// Probe returns nil when the worker is ready to take tasks.
	Probe  func() error
	Logger *slog.Logger
}

// Server is the HTTP health endpoint.
type Server struct {
	srv *http.Server
	ln  net.Listener
}

// New binds the listener and starts serving. The close function shuts the server down.
func New(cfg Config, deps Deps) (*Server, func() error, error) {
	if deps.Probe == nil {
		return nil, nil, errors.New("health: Probe is required")
	}
	logger := deps.Logger
	if logger == nil {
		logger = slog.New(slog.DiscardHandler)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /livez", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok\n"))
	})
	mux.HandleFunc("GET /readyz", func(w http.ResponseWriter, _ *http.Request) {
		if err := deps.Probe(); err != nil {
			http.Error(w, "not ready: "+err.Error(), http.StatusServiceUnavailable)

			return
		}
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ready\n"))
	})

	ln, err := net.Listen("tcp", cfg.Addr)
	if err != nil {
		return nil, nil, fmt.Errorf("health: listen %s: %w", cfg.Addr, err)
	}

	s := &Server{
		srv: &http.Server{Handler: mux, ReadHeaderTimeout: 2 * time.Second},
		ln:  ln,
	}
	go func() {
		if err := s.srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("health server stopped", "error", err.Error())
		}
	}()

	closeFn := func() error {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()

		return s.srv.Shutdown(ctx)
	}

	return s, closeFn, nil
}

// Addr is the bound address.
func (s *Server) Addr() string { return s.ln.Addr().String() }
