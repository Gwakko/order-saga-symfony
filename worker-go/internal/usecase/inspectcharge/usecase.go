// Package inspectcharge is the InspectGatewayCharge use case: ask the gateway what it knows about a charge
// and compare it with the expected money. It has no knowledge of Temporal, HTTP or the environment.
package inspectcharge

import (
	"context"
	"fmt"
	"regexp"
	"slices"
	"time"

	"order-saga-symfony/worker-go/internal/domain/charge"
)

// SupportedContractVersions lists the contract versions this build understands. The answer is in the version of the request.
var SupportedContractVersions = []int{1}

const (
	maxKeyLength = 64
	minAmount    = 1
	maxAmount    = 100_000_000 // Money::MAX_AMOUNT on the PHP side
)

var (
	uuidPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	keyPattern  = regexp.MustCompile(`^[A-Za-z0-9-]+$`)
	currencies  = []string{"EUR", "USD", "PLN", "UAH"}
)

// InspectChargeInput is the use case input. No serialization tags: the boundary DTO maps into it.
type InspectChargeInput struct {
	ContractVersion int
	OrderID         string
	IdempotencyKey  string
	Expected        charge.Money
}

// InspectChargeOutput is the use case result.
type InspectChargeOutput struct {
	Outcome         charge.Outcome
	Charge          *charge.Observation
	MatchesExpected bool
	CheckedAt       time.Time
}

// Validate checks the stateless rules. An unsupported version is reported first, alone, as UnsupportedVersionError;
// everything else is collected into one InvalidInputError.
func (in InspectChargeInput) Validate() error {
	if !slices.Contains(SupportedContractVersions, in.ContractVersion) {
		return &UnsupportedVersionError{Requested: in.ContractVersion, Supported: slices.Clone(SupportedContractVersions)}
	}

	var problems []string

	if !uuidPattern.MatchString(in.OrderID) {
		problems = append(problems, "orderId must be a UUID")
	}
	if len(in.IdempotencyKey) < 1 || len(in.IdempotencyKey) > maxKeyLength || !keyPattern.MatchString(in.IdempotencyKey) {
		problems = append(problems, "idempotencyKey must be 1..64 characters of [A-Za-z0-9-]")
	}
	if in.Expected.Amount < minAmount || in.Expected.Amount > maxAmount {
		problems = append(problems, fmt.Sprintf("expected.amount must be %d..%d", minAmount, maxAmount))
	}
	if !slices.Contains(currencies, in.Expected.Currency) {
		problems = append(problems, "expected.currency must be one of EUR, USD, PLN, UAH")
	}

	if len(problems) > 0 {
		return &InvalidInputError{Problems: problems}
	}

	return nil
}

// UseCase inspects a gateway charge.
type UseCase struct {
	lookup ChargeLookup
	clock  Clock
}

// New builds the use case from its ports.
func New(lookup ChargeLookup, clock Clock) *UseCase {
	return &UseCase{lookup: lookup, clock: clock}
}

// Execute validates the input, reads the charge and classifies it. Port errors are returned unchanged.
func (u *UseCase) Execute(ctx context.Context, in InspectChargeInput) (InspectChargeOutput, error) {
	if err := in.Validate(); err != nil {
		return InspectChargeOutput{}, err
	}

	obs, found, err := u.lookup.FindCharge(ctx, in.IdempotencyKey)
	if err != nil {
		return InspectChargeOutput{}, err
	}

	checkedAt := u.clock.Now().UTC()

	if !found {
		return InspectChargeOutput{Outcome: charge.OutcomeNotFound, CheckedAt: checkedAt}, nil
	}

	out := InspectChargeOutput{Charge: &obs, CheckedAt: checkedAt}

	switch obs.Status {
	case charge.StatusPending:
		return InspectChargeOutput{}, ErrChargePending
	case charge.StatusSucceeded:
		out.Outcome = charge.OutcomeSucceeded
		out.MatchesExpected = obs.Matches(in.Expected)
	case charge.StatusDeclined:
		out.Outcome = charge.OutcomeDeclined
	default:
		return InspectChargeOutput{}, fmt.Errorf("%w: unknown charge status %q", ErrGatewayUnavailable, obs.Status)
	}

	return out, nil
}
