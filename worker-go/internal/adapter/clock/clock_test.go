package clock_test

import (
	"testing"
	"time"

	"order-saga-symfony/worker-go/internal/adapter/clock"
)

func TestSystemNowIsUTC(t *testing.T) {
	before := time.Now()
	got := clock.New().Now()

	if got.Location() != time.UTC {
		t.Fatalf("location = %v, want UTC", got.Location())
	}
	if got.Before(before.Add(-time.Second)) || got.After(time.Now().Add(time.Second)) {
		t.Fatalf("Now() = %v is not close to the wall clock", got)
	}
}
