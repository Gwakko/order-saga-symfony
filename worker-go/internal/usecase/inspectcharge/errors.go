package inspectcharge

import (
	"errors"
	"fmt"
	"strings"
	"time"
)

// Sentinel errors of the use case and its ports. Business outcomes (succeeded, declined, not_found) are results, not errors.
var (
	ErrInvalidInput               = errors.New("invalid inspection input")
	ErrUnsupportedContractVersion = errors.New("unsupported contract version")
	ErrRequestRejected            = errors.New("gateway rejected the request")
	ErrGatewayUnavailable         = errors.New("gateway unavailable")
	ErrChargePending              = errors.New("charge is pending")
	ErrCircuitOpen                = errors.New("circuit breaker is open")
)

// InvalidInputError lists every rule the input broke. It matches ErrInvalidInput.
type InvalidInputError struct {
	Problems []string
}

func (e *InvalidInputError) Error() string {
	return fmt.Sprintf("%s: %s", ErrInvalidInput, strings.Join(e.Problems, "; "))
}

func (e *InvalidInputError) Unwrap() error { return ErrInvalidInput }

// UnsupportedVersionError carries the requested version and the supported list. It matches ErrUnsupportedContractVersion.
type UnsupportedVersionError struct {
	Requested int
	Supported []int
}

func (e *UnsupportedVersionError) Error() string {
	return fmt.Sprintf("%s: requested %d, supported %v", ErrUnsupportedContractVersion, e.Requested, e.Supported)
}

func (e *UnsupportedVersionError) Unwrap() error { return ErrUnsupportedContractVersion }

// CircuitOpenError says how long the breaker stays open, when the breaker knows it. It matches ErrCircuitOpen.
type CircuitOpenError struct {
	// RetryAfter is the rest of the open interval; zero when unknown.
	RetryAfter time.Duration
}

func (e *CircuitOpenError) Error() string {
	if e.RetryAfter > 0 {
		return fmt.Sprintf("%s (retry after %s)", ErrCircuitOpen, e.RetryAfter.Round(time.Millisecond))
	}

	return ErrCircuitOpen.Error()
}

func (e *CircuitOpenError) Unwrap() error { return ErrCircuitOpen }

// Error types of contracts/inspect-gateway-charge/v1/errors.json.
const (
	TypeInvalidInspectionInput     = "InvalidInspectionInput"
	TypeUnsupportedContractVersion = "UnsupportedContractVersion"
	TypeGatewayRequestRejected     = "GatewayRequestRejected"
	TypeGatewayUnavailable         = "GatewayUnavailable"
	TypeChargePending              = "ChargePending"
	TypeCircuitOpen                = "CircuitOpen"
)

// ErrorInfo is the contract view of an error: the type string PHP sees and whether Temporal must not retry it.
type ErrorInfo struct {
	Type         string
	NonRetryable bool
	// Details is set for UnsupportedContractVersion: {"requested":2,"supported":[1]}.
	Details map[string]any
	// RetryAfter is the rest of the breaker open interval for CircuitOpen; zero when unknown.
	RetryAfter time.Duration
}

// Classify maps a use case error to the contract error type (§4.6). ok is false for an error outside the table.
// The Temporal adapter turns ErrorInfo into an ApplicationError; the use case does not depend on Temporal.
func Classify(err error) (info ErrorInfo, ok bool) {
	var version *UnsupportedVersionError
	var open *CircuitOpenError

	switch {
	case errors.Is(err, ErrInvalidInput):
		return ErrorInfo{Type: TypeInvalidInspectionInput, NonRetryable: true}, true
	case errors.Is(err, ErrUnsupportedContractVersion):
		info = ErrorInfo{Type: TypeUnsupportedContractVersion, NonRetryable: true}
		if errors.As(err, &version) {
			info.Details = map[string]any{"requested": version.Requested, "supported": version.Supported}
		}

		return info, true
	case errors.Is(err, ErrRequestRejected):
		return ErrorInfo{Type: TypeGatewayRequestRejected, NonRetryable: true}, true
	case errors.Is(err, ErrCircuitOpen):
		info = ErrorInfo{Type: TypeCircuitOpen}
		if errors.As(err, &open) {
			info.RetryAfter = open.RetryAfter
		}

		return info, true
	case errors.Is(err, ErrChargePending):
		return ErrorInfo{Type: TypeChargePending}, true
	case errors.Is(err, ErrGatewayUnavailable):
		return ErrorInfo{Type: TypeGatewayUnavailable}, true
	}

	return ErrorInfo{}, false
}
