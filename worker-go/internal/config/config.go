// Package config is the only place that knows the environment variable names. It loads, defaults and validates them,
// and reports every problem at once. Adapters receive their own Config built from these values and never read the environment.
package config

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Lookup reads one variable; os.LookupEnv fits. Tests pass a map-backed function.
type Lookup func(key string) (string, bool)

// Config is the validated configuration of the Go worker.
type Config struct {
	Temporal Temporal
	Worker   Worker
	Gateway  Gateway
	Breaker  Breaker
	Health   Health
	Log      Log
}

type Temporal struct {
	Address   string
	Namespace string
}

type Worker struct {
	TaskQueue               string
	MaxConcurrentActivities int
	StopTimeout             time.Duration
}

type Gateway struct {
	BaseURL      string
	Timeout      time.Duration
	MaxBodyBytes int64
}

type Breaker struct {
	Failures uint32
	OpenFor  time.Duration
}

type Health struct {
	Addr string
}

type Log struct {
	Level  string
	Format string
}

var taskQueuePattern = regexp.MustCompile(`^[a-z0-9-]{1,100}$`)

const (
	minGatewayTimeout = 100 * time.Millisecond
	maxGatewayTimeout = 10 * time.Second
	maxStopTimeout    = 60 * time.Second
	minBodyBytes      = 1 << 10
	maxBodyBytes      = 1 << 20
)

// Load reads the environment through lookup, applies defaults and validates. All errors are returned together (errors.Join).
func Load(lookup Lookup) (Config, error) {
	r := &reader{lookup: lookup}

	cfg := Config{
		Temporal: Temporal{
			Address:   r.str("TEMPORAL_ADDRESS", "temporal:7233"),
			Namespace: r.str("TEMPORAL_NAMESPACE", "default"),
		},
		Worker: Worker{
			TaskQueue:               r.required("GO_WORKER_TASK_QUEUE"),
			MaxConcurrentActivities: r.intInRange("WORKER_MAX_CONCURRENT_ACTIVITIES", 8, 1, 256),
			StopTimeout:             r.duration("WORKER_STOP_TIMEOUT", 10*time.Second, time.Nanosecond, maxStopTimeout),
		},
		Gateway: Gateway{
			BaseURL:      r.required("GATEWAY_BASE_URL"),
			Timeout:      r.duration("GATEWAY_TIMEOUT", 5*time.Second, minGatewayTimeout, maxGatewayTimeout),
			MaxBodyBytes: int64(r.intInRange("GATEWAY_MAX_BODY_BYTES", 65536, minBodyBytes, maxBodyBytes)),
		},
		Breaker: Breaker{
			Failures: uint32(r.intInRange("BREAKER_FAILURES", 5, 1, 1<<20)),
			OpenFor:  r.duration("BREAKER_OPEN_FOR", 30*time.Second, time.Nanosecond, 24*time.Hour),
		},
		Health: Health{Addr: r.str("HEALTH_ADDR", ":8090")},
		Log: Log{
			Level:  r.oneOf("LOG_LEVEL", "info", "debug", "info", "warn", "error"),
			Format: r.oneOf("LOG_FORMAT", "json", "json", "text"),
		},
	}

	cfg.validate(r)

	if len(r.errs) > 0 {
		return Config{}, errors.Join(r.errs...)
	}

	return cfg, nil
}

// validate holds the rules that span several values or need more than a range.
func (c Config) validate(r *reader) {
	if c.Temporal.Namespace == "" {
		r.fail("TEMPORAL_NAMESPACE must not be empty")
	}
	if err := checkHostPort(c.Temporal.Address, false); err != nil {
		r.fail("TEMPORAL_ADDRESS %q: %v", c.Temporal.Address, err)
	}
	if err := checkHostPort(c.Health.Addr, true); err != nil {
		r.fail("HEALTH_ADDR %q: %v", c.Health.Addr, err)
	}
	if c.Worker.TaskQueue != "" && !taskQueuePattern.MatchString(c.Worker.TaskQueue) {
		r.fail("GO_WORKER_TASK_QUEUE %q must match %s", c.Worker.TaskQueue, taskQueuePattern)
	}
	if c.Gateway.BaseURL != "" {
		if u, err := url.Parse(c.Gateway.BaseURL); err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			r.fail("GATEWAY_BASE_URL %q must be an http or https URL", c.Gateway.BaseURL)
		}
	}
	// The worker must be able to wait out one gateway call while stopping.
	if c.Worker.StopTimeout < c.Gateway.Timeout && !r.failed("WORKER_STOP_TIMEOUT") && !r.failed("GATEWAY_TIMEOUT") {
		r.fail("WORKER_STOP_TIMEOUT (%s) must be at least GATEWAY_TIMEOUT (%s)", c.Worker.StopTimeout, c.Gateway.Timeout)
	}
}

// checkHostPort accepts host:port; an empty host is allowed only when allowEmptyHost (":8090").
func checkHostPort(addr string, allowEmptyHost bool) error {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return errors.New("must be host:port")
	}
	if host == "" && !allowEmptyHost {
		return errors.New("host must not be empty")
	}
	if n, err := strconv.Atoi(port); err != nil || n < 1 || n > 65535 {
		return errors.New("port must be 1..65535")
	}

	return nil
}

// reader collects the values and the errors; bad values fall back to the default so that later rules keep working.
type reader struct {
	lookup Lookup
	errs   []error
	bad    map[string]bool
}

func (r *reader) fail(format string, args ...any) {
	r.errs = append(r.errs, fmt.Errorf(format, args...))
}

func (r *reader) failKey(key, format string, args ...any) {
	if r.bad == nil {
		r.bad = map[string]bool{}
	}
	r.bad[key] = true
	r.fail("%s: "+format, append([]any{key}, args...)...)
}

func (r *reader) failed(key string) bool { return r.bad[key] }

func (r *reader) raw(key string) (string, bool) {
	v, ok := r.lookup(key)
	v = strings.TrimSpace(v)
	if !ok || v == "" {
		return "", false
	}

	return v, true
}

func (r *reader) str(key, def string) string {
	if v, ok := r.raw(key); ok {
		return v
	}

	return def
}

func (r *reader) required(key string) string {
	v, ok := r.raw(key)
	if !ok {
		r.failKey(key, "is required")
	}

	return v
}

func (r *reader) intInRange(key string, def, minV, maxV int) int {
	v, ok := r.raw(key)
	if !ok {
		return def
	}

	n, err := strconv.Atoi(v)
	if err != nil || n < minV || n > maxV {
		r.failKey(key, "%q must be an integer in %d..%d", v, minV, maxV)

		return def
	}

	return n
}

func (r *reader) duration(key string, def, minV, maxV time.Duration) time.Duration {
	v, ok := r.raw(key)
	if !ok {
		return def
	}

	d, err := time.ParseDuration(v)
	if err != nil || d < minV || d > maxV {
		r.failKey(key, "%q must be a duration in %s..%s", v, minV, maxV)

		return def
	}

	return d
}

func (r *reader) oneOf(key, def string, allowed ...string) string {
	v, ok := r.raw(key)
	if !ok {
		return def
	}

	for _, a := range allowed {
		if v == a {
			return v
		}
	}
	r.failKey(key, "%q must be one of %s", v, strings.Join(allowed, ", "))

	return def
}

// LoadHealth reads only the health settings. The healthcheck binary uses it so that it does not need the rest of the
// worker configuration.
func LoadHealth(lookup Lookup) (Health, error) {
	r := &reader{lookup: lookup}
	h := Health{Addr: r.str("HEALTH_ADDR", ":8090")}

	if err := checkHostPort(h.Addr, true); err != nil {
		r.fail("HEALTH_ADDR %q: %v", h.Addr, err)
	}
	if len(r.errs) > 0 {
		return Health{}, errors.Join(r.errs...)
	}

	return h, nil
}
