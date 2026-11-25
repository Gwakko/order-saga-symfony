package app_test

import (
	"bytes"
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"order-saga-symfony/worker-go/internal/app"
	"order-saga-symfony/worker-go/internal/config"
)

func TestNewLoggerFormatsAndLevels(t *testing.T) {
	var buf bytes.Buffer
	app.NewLogger(config.Log{Level: "warn", Format: "json"}, &buf).Info("hidden")
	app.NewLogger(config.Log{Level: "warn", Format: "json"}, &buf).Warn("shown")

	if strings.Contains(buf.String(), "hidden") || !strings.Contains(buf.String(), `"msg":"shown"`) {
		t.Fatalf("unexpected log output: %s", buf.String())
	}

	buf.Reset()
	app.NewLogger(config.Log{Level: "info", Format: "text"}, &buf).Info("plain")
	if !strings.Contains(buf.String(), "msg=plain") {
		t.Fatalf("not text format: %s", buf.String())
	}
}

func TestHealthcheckProbesReadyz(t *testing.T) {
	status := http.StatusOK
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/readyz" {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		w.WriteHeader(status)
	}))
	defer srv.Close()

	_, port, _ := net.SplitHostPort(srv.Listener.Addr().String())
	hc, err := app.BuildHealthcheck(config.Health{Addr: ":" + port})
	if err != nil {
		t.Fatal(err)
	}

	if err := hc.Check(context.Background()); err != nil {
		t.Fatalf("ready: %v", err)
	}

	status = http.StatusServiceUnavailable
	if err := hc.Check(context.Background()); err == nil {
		t.Fatal("expected an error for 503")
	}

	srv.Close()
	if err := hc.Check(context.Background()); err == nil {
		t.Fatal("expected an error when nothing listens")
	}
}

func TestBuildHealthcheckRejectsBadAddress(t *testing.T) {
	if _, err := app.BuildHealthcheck(config.Health{Addr: "nonsense"}); err == nil {
		t.Fatal("expected an error")
	}
}
