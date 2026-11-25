package health_test

import (
	"errors"
	"io"
	"net/http"
	"sync/atomic"
	"testing"

	"order-saga-symfony/worker-go/internal/adapter/health"
)

func get(t *testing.T, addr, path string) (int, string) {
	t.Helper()

	resp, err := http.Get("http://" + addr + path)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = resp.Body.Close() }()
	body, _ := io.ReadAll(resp.Body)

	return resp.StatusCode, string(body)
}

func TestLivezAlwaysOkReadyzFollowsProbe(t *testing.T) {
	var ready atomic.Bool
	srv, closeFn, err := health.New(health.Config{Addr: "127.0.0.1:0"}, health.Deps{Probe: func() error {
		if ready.Load() {
			return nil
		}

		return errors.New("worker not started")
	}})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = closeFn() }()

	if code, _ := get(t, srv.Addr(), "/livez"); code != http.StatusOK {
		t.Fatalf("/livez = %d", code)
	}
	if code, body := get(t, srv.Addr(), "/readyz"); code != http.StatusServiceUnavailable || body == "" {
		t.Fatalf("/readyz before ready = %d %q", code, body)
	}

	ready.Store(true)
	if code, _ := get(t, srv.Addr(), "/readyz"); code != http.StatusOK {
		t.Fatalf("/readyz after ready = %d", code)
	}
	if code, _ := get(t, srv.Addr(), "/unknown"); code != http.StatusNotFound {
		t.Fatalf("/unknown = %d", code)
	}
}

func TestNewRequiresProbeAndFreeAddress(t *testing.T) {
	if _, _, err := health.New(health.Config{Addr: "127.0.0.1:0"}, health.Deps{}); err == nil {
		t.Fatal("expected an error without a probe")
	}

	srv, closeFn, err := health.New(health.Config{Addr: "127.0.0.1:0"}, health.Deps{Probe: func() error { return nil }})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = closeFn() }()

	if _, _, err := health.New(health.Config{Addr: srv.Addr()}, health.Deps{Probe: func() error { return nil }}); err == nil {
		t.Fatal("expected an error for an address already in use")
	}
}
