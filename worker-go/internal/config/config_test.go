package config_test

import (
	"strings"
	"testing"
	"time"

	"order-saga-symfony/worker-go/internal/config"
)

func env(pairs map[string]string) config.Lookup {
	return func(key string) (string, bool) {
		v, ok := pairs[key]

		return v, ok
	}
}

func minimal() map[string]string {
	return map[string]string{"GO_WORKER_TASK_QUEUE": "payment-go", "GATEWAY_BASE_URL": "http://gateway-emulator:8081"}
}

func with(base map[string]string, key, value string) map[string]string {
	out := map[string]string{}
	for k, v := range base {
		out[k] = v
	}
	out[key] = value

	return out
}

// Group: T-GO-05
func TestLoadDefaults(t *testing.T) {
	cfg, err := config.Load(env(minimal()))
	if err != nil {
		t.Fatal(err)
	}

	if cfg.Temporal.Address != "temporal:7233" || cfg.Temporal.Namespace != "default" ||
		cfg.Worker.TaskQueue != "payment-go" || cfg.Worker.MaxConcurrentActivities != 8 || cfg.Worker.StopTimeout != 10*time.Second ||
		cfg.Gateway.BaseURL != "http://gateway-emulator:8081" || cfg.Gateway.Timeout != 5*time.Second || cfg.Gateway.MaxBodyBytes != 65536 ||
		cfg.Breaker.Failures != 5 || cfg.Breaker.OpenFor != 30*time.Second ||
		cfg.Health.Addr != ":8090" || cfg.Log.Level != "info" || cfg.Log.Format != "json" {
		t.Fatalf("unexpected defaults: %+v", cfg)
	}
}

// Group: T-GO-05
func TestLoadOverrides(t *testing.T) {
	cfg, err := config.Load(env(map[string]string{
		"TEMPORAL_ADDRESS": "localhost:7233", "TEMPORAL_NAMESPACE": "prod",
		"GO_WORKER_TASK_QUEUE": "payment-go-test-ab12", "GATEWAY_BASE_URL": "https://gw.example",
		"GATEWAY_TIMEOUT": "2s", "GATEWAY_MAX_BODY_BYTES": "2048",
		"BREAKER_FAILURES": "3", "BREAKER_OPEN_FOR": "1m",
		"WORKER_MAX_CONCURRENT_ACTIVITIES": "256", "WORKER_STOP_TIMEOUT": "60s",
		"HEALTH_ADDR": "127.0.0.1:9000", "LOG_LEVEL": "debug", "LOG_FORMAT": "text",
	}))
	if err != nil {
		t.Fatal(err)
	}

	if cfg.Gateway.Timeout != 2*time.Second || cfg.Gateway.MaxBodyBytes != 2048 || cfg.Breaker.Failures != 3 ||
		cfg.Breaker.OpenFor != time.Minute || cfg.Worker.MaxConcurrentActivities != 256 || cfg.Log.Format != "text" {
		t.Fatalf("unexpected values: %+v", cfg)
	}
}

// Group: T-GO-05
func TestLoadRejects(t *testing.T) {
	cases := []struct {
		name string
		env  map[string]string
		want string
	}{
		{"missing queue", map[string]string{"GATEWAY_BASE_URL": "http://g:1"}, "GO_WORKER_TASK_QUEUE: is required"},
		{"missing base url", map[string]string{"GO_WORKER_TASK_QUEUE": "q"}, "GATEWAY_BASE_URL: is required"},
		{"queue upper case", with(minimal(), "GO_WORKER_TASK_QUEUE", "Payment"), "GO_WORKER_TASK_QUEUE"},
		{"queue too long", with(minimal(), "GO_WORKER_TASK_QUEUE", strings.Repeat("a", 101)), "GO_WORKER_TASK_QUEUE"},
		{"base url scheme", with(minimal(), "GATEWAY_BASE_URL", "ftp://g"), "GATEWAY_BASE_URL"},
		{"base url no host", with(minimal(), "GATEWAY_BASE_URL", "http://"), "GATEWAY_BASE_URL"},
		{"gateway timeout 30s", with(minimal(), "GATEWAY_TIMEOUT", "30s"), "GATEWAY_TIMEOUT"},
		{"gateway timeout 99ms", with(minimal(), "GATEWAY_TIMEOUT", "99ms"), "GATEWAY_TIMEOUT"},
		{"gateway timeout garbage", with(minimal(), "GATEWAY_TIMEOUT", "soon"), "GATEWAY_TIMEOUT"},
		{"body too small", with(minimal(), "GATEWAY_MAX_BODY_BYTES", "1023"), "GATEWAY_MAX_BODY_BYTES"},
		{"body too large", with(minimal(), "GATEWAY_MAX_BODY_BYTES", "1048577"), "GATEWAY_MAX_BODY_BYTES"},
		{"breaker failures 0", with(minimal(), "BREAKER_FAILURES", "0"), "BREAKER_FAILURES"},
		{"breaker open 0", with(minimal(), "BREAKER_OPEN_FOR", "0s"), "BREAKER_OPEN_FOR"},
		{"concurrency 0", with(minimal(), "WORKER_MAX_CONCURRENT_ACTIVITIES", "0"), "WORKER_MAX_CONCURRENT_ACTIVITIES"},
		{"concurrency 257", with(minimal(), "WORKER_MAX_CONCURRENT_ACTIVITIES", "257"), "WORKER_MAX_CONCURRENT_ACTIVITIES"},
		{"stop timeout above 60s", with(minimal(), "WORKER_STOP_TIMEOUT", "61s"), "WORKER_STOP_TIMEOUT"},
		{"stop timeout below gateway timeout", with(minimal(), "WORKER_STOP_TIMEOUT", "3s"), "must be at least GATEWAY_TIMEOUT"},
		{"temporal address without port", with(minimal(), "TEMPORAL_ADDRESS", "temporal"), "TEMPORAL_ADDRESS"},
		{"temporal address bad port", with(minimal(), "TEMPORAL_ADDRESS", "temporal:99999"), "TEMPORAL_ADDRESS"},
		{"temporal address empty host", with(minimal(), "TEMPORAL_ADDRESS", ":7233"), "TEMPORAL_ADDRESS"},
		{"health addr garbage", with(minimal(), "HEALTH_ADDR", "8090"), "HEALTH_ADDR"},
		{"log level", with(minimal(), "LOG_LEVEL", "trace"), "LOG_LEVEL"},
		{"log format", with(minimal(), "LOG_FORMAT", "xml"), "LOG_FORMAT"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := config.Load(env(tc.env))
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to mention %q", err, tc.want)
			}
		})
	}
}

// Group: T-GO-05
func TestLoadStopTimeoutEqualToGatewayTimeoutIsValid(t *testing.T) {
	e := with(with(minimal(), "WORKER_STOP_TIMEOUT", "5s"), "GATEWAY_TIMEOUT", "5s")
	if _, err := config.Load(env(e)); err != nil {
		t.Fatal(err)
	}
}

// Group: T-GO-05
func TestLoadReportsAllErrorsAtOnce(t *testing.T) {
	_, err := config.Load(env(map[string]string{"GATEWAY_TIMEOUT": "30s", "LOG_LEVEL": "trace"}))
	if err == nil {
		t.Fatal("expected errors")
	}

	for _, want := range []string{"GO_WORKER_TASK_QUEUE", "GATEWAY_BASE_URL", "GATEWAY_TIMEOUT", "LOG_LEVEL"} {
		if !strings.Contains(err.Error(), want) {
			t.Fatalf("error %q does not mention %s", err, want)
		}
	}
}

// Group: T-GO-05
func TestLoadTreatsBlankAsUnset(t *testing.T) {
	cfg, err := config.Load(env(with(with(minimal(), "GATEWAY_TIMEOUT", "  "), "LOG_FORMAT", "")))
	if err != nil || cfg.Gateway.Timeout != 5*time.Second || cfg.Log.Format != "json" {
		t.Fatalf("cfg=%+v err=%v", cfg, err)
	}
}
