package inspectcharge

import (
	"context"
	"time"

	"order-saga-symfony/worker-go/internal/domain/charge"
)

// ChargeLookup reads a charge at the payment gateway by idempotency key. It never writes.
// found is false when the gateway has no charge for the key. Failures are the sentinel errors of this package.
type ChargeLookup interface {
	FindCharge(ctx context.Context, idempotencyKey string) (obs charge.Observation, found bool, err error)
}

// Clock gives the current time.
type Clock interface {
	Now() time.Time
}
