// Package charge holds the domain types of a gateway charge as the inspection sees it.
// The types carry no serialization tags: the boundaries (gateway HTTP, Temporal) own their DTOs and map explicitly.
package charge

// Money is an amount in integer minor units. Floating point is never used for money.
type Money struct {
	Amount   int64
	Currency string
}

// Status is the state of a charge as reported by the gateway.
type Status string

const (
	StatusSucceeded Status = "succeeded"
	StatusDeclined  Status = "declined"
	StatusPending   Status = "pending"
)

// Outcome is the business result of an inspection.
type Outcome string

const (
	OutcomeSucceeded Outcome = "succeeded"
	OutcomeDeclined  Outcome = "declined"
	OutcomeNotFound  Outcome = "not_found"
)

// Observation is what the gateway reports about one charge.
type Observation struct {
	ChargeID      string
	Money         Money
	Status        Status
	DeclineReason *string
}

// Matches reports whether the observed charge has exactly the expected amount and currency.
func (o Observation) Matches(expected Money) bool {
	return o.Money.Amount == expected.Amount && o.Money.Currency == expected.Currency
}
