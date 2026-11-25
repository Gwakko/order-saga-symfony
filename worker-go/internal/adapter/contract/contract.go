// Package contract holds the DTOs of the PHP <-> Go contract InspectGatewayCharge v1 (contracts/inspect-gateway-charge/v1)
// and the explicit mapping to and from the use case types. Only this boundary carries json tags.
// The Temporal activity adapter (M9.3) wraps these functions; this package does not import Temporal.
package contract

import (
	"time"

	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

// checkedAtLayout writes the offset as the literal "+00:00" (Z07:00 would print "Z" for UTC).
const checkedAtLayout = "2006-01-02T15:04:05.000+00:00"

// MoneyDTO is money on the wire: integer minor units.
type MoneyDTO struct {
	Amount   int64  `json:"amount"`
	Currency string `json:"currency"`
}

// Request is the activity input. Unknown fields are ignored by encoding/json.
type Request struct {
	ContractVersion int      `json:"contractVersion"`
	OrderID         string   `json:"orderId"`
	IdempotencyKey  string   `json:"idempotencyKey"`
	Expected        MoneyDTO `json:"expected"`
}

// ChargeDTO is the observed charge in the activity result.
type ChargeDTO struct {
	ChargeID      string  `json:"chargeId"`
	Amount        int64   `json:"amount"`
	Currency      string  `json:"currency"`
	DeclineReason *string `json:"declineReason"`
}

// Response is the activity result. Charge is serialized as null (never omitted) for not_found.
type Response struct {
	ContractVersion int        `json:"contractVersion"`
	Outcome         string     `json:"outcome"`
	Charge          *ChargeDTO `json:"charge"`
	MatchesExpected bool       `json:"matchesExpected"`
	CheckedAt       string     `json:"checkedAt"`
}

// ToInput maps the wire request to the use case input.
func (r Request) ToInput() inspectcharge.InspectChargeInput {
	return inspectcharge.InspectChargeInput{
		ContractVersion: r.ContractVersion,
		OrderID:         r.OrderID,
		IdempotencyKey:  r.IdempotencyKey,
		Expected:        charge.Money{Amount: r.Expected.Amount, Currency: r.Expected.Currency},
	}
}

// FromOutput maps the use case output to the wire response. The answer is in the version of the request.
func FromOutput(requestVersion int, out inspectcharge.InspectChargeOutput) Response {
	resp := Response{
		ContractVersion: requestVersion,
		Outcome:         string(out.Outcome),
		MatchesExpected: out.MatchesExpected,
		CheckedAt:       FormatCheckedAt(out.CheckedAt),
	}

	if out.Charge != nil {
		resp.Charge = &ChargeDTO{
			ChargeID:      out.Charge.ChargeID,
			Amount:        out.Charge.Money.Amount,
			Currency:      out.Charge.Money.Currency,
			DeclineReason: out.Charge.DeclineReason,
		}
	}

	return resp
}

// FormatCheckedAt renders t in UTC as RFC 3339 with milliseconds and the literal offset +00:00, whatever time.Local is.
func FormatCheckedAt(t time.Time) string {
	return t.UTC().Format(checkedAtLayout)
}
