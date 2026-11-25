package charge_test

import (
	"testing"

	"order-saga-symfony/worker-go/internal/domain/charge"
)

// Group: T-GO-04
func TestObservationMatches(t *testing.T) {
	obs := charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusSucceeded}

	cases := []struct {
		name     string
		expected charge.Money
		want     bool
	}{
		{"same amount and currency", charge.Money{Amount: 1999, Currency: "EUR"}, true},
		{"different amount", charge.Money{Amount: 2000, Currency: "EUR"}, false},
		{"amount one less", charge.Money{Amount: 1998, Currency: "EUR"}, false},
		{"different currency", charge.Money{Amount: 1999, Currency: "USD"}, false},
		{"both differ", charge.Money{Amount: 1, Currency: "PLN"}, false},
		{"currency case differs", charge.Money{Amount: 1999, Currency: "eur"}, false},
		{"zero value", charge.Money{}, false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := obs.Matches(tc.expected); got != tc.want {
				t.Fatalf("Matches(%+v) = %v, want %v", tc.expected, got, tc.want)
			}
		})
	}
}
