// Package activity is the Temporal boundary of the worker: it exposes the InspectGatewayCharge use case as an activity
// with the name and the payload of the PHP <-> Go contract (docs/spec/09_go_worker.md §4) and maps use case errors to
// Temporal application errors (§4.6).
//
// No heartbeat is sent (§4.7): one attempt is a single HTTP call of at most GATEWAY_TIMEOUT, far below StartToClose.
package activity

import (
	"context"
	"errors"
	"fmt"
	"log/slog"

	sdkactivity "go.temporal.io/sdk/activity"
	"go.temporal.io/sdk/temporal"

	"order-saga-symfony/worker-go/internal/adapter/contract"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

// Name is the activity type PHP workflows call (contract §4.1).
const Name = "InspectGatewayCharge"

// Executor is the use case port the activity needs.
type Executor interface {
	Execute(ctx context.Context, in inspectcharge.InspectChargeInput) (inspectcharge.InspectChargeOutput, error)
}

// Registry is the part of a Temporal worker (or a test environment) that registers activities.
type Registry interface {
	RegisterActivityWithOptions(a interface{}, options sdkactivity.RegisterOptions)
}

// Config has no fields yet: the name is fixed by the contract. It stays for the New(cfg, deps) convention.
type Config struct{}

// Deps are the collaborators of the activity.
type Deps struct {
	UseCase Executor
	Logger  *slog.Logger
}

// Activity wraps the use case.
type Activity struct {
	useCase Executor
	logger  *slog.Logger
}

// New builds the activity. The close function is a no-op.
func New(_ Config, deps Deps) (*Activity, func() error, error) {
	if deps.UseCase == nil {
		return nil, nil, errors.New("activity: UseCase is required")
	}

	logger := deps.Logger
	if logger == nil {
		logger = slog.New(slog.DiscardHandler)
	}

	return &Activity{useCase: deps.UseCase, logger: logger}, func() error { return nil }, nil
}

// Register registers the activity under the contract name.
func (a *Activity) Register(r Registry) {
	r.RegisterActivityWithOptions(a.Inspect, sdkactivity.RegisterOptions{Name: Name})
}

// Inspect is the activity function. Business outcomes are results; errors mean "no answer" and are typed (§4.6).
func (a *Activity) Inspect(ctx context.Context, req contract.Request) (contract.Response, error) {
	info := sdkactivity.GetInfo(ctx)
	log := a.logger.With(
		"order_id", req.OrderID,
		"idempotency_key", req.IdempotencyKey,
		"attempt", info.Attempt,
		"workflow_id", info.WorkflowExecution.ID,
	)

	out, err := a.useCase.Execute(ctx, req.ToInput())
	if err != nil {
		appErr := MapError(err)
		var typed *temporal.ApplicationError
		errType := ""
		if errors.As(appErr, &typed) {
			errType = typed.Type()
		}

		level := slog.LevelWarn
		if typed != nil && typed.NonRetryable() {
			level = slog.LevelError
		}
		log.Log(ctx, level, "inspect gateway charge failed", "error_type", errType, "error", err.Error())

		return contract.Response{}, appErr
	}

	log.Info("inspect gateway charge done", "outcome", string(out.Outcome), "matches_expected", out.MatchesExpected)

	return contract.FromOutput(req.ContractVersion, out), nil
}

// MapError converts a use case error to a *temporal.ApplicationError following contracts/.../errors.json.
// A circuit open error carries its remaining open time as NextRetryDelay. An error that is not in the table is treated
// as GatewayUnavailable (retryable), never as a new type outside the contract.
func MapError(err error) error {
	info, ok := inspectcharge.Classify(err)
	if !ok {
		info = inspectcharge.ErrorInfo{Type: inspectcharge.TypeGatewayUnavailable}
		err = fmt.Errorf("%w: %v", inspectcharge.ErrGatewayUnavailable, err)
	}

	opts := temporal.ApplicationErrorOptions{
		NonRetryable:   info.NonRetryable,
		Cause:          err,
		NextRetryDelay: info.RetryAfter,
	}
	if info.Details != nil {
		opts.Details = []interface{}{info.Details}
	}

	return temporal.NewApplicationErrorWithOptions(err.Error(), info.Type, opts)
}
