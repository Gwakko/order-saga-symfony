package inspectcharge_test

import (
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"testing"
	"time"

	"order-saga-symfony/worker-go/internal/contracttest"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

// Group: T-GO-13
func TestClassifyMatchesErrorsJSON(t *testing.T) {
	var file struct {
		Errors []struct {
			Type         string `json:"type"`
			NonRetryable bool   `json:"nonRetryable"`
		} `json:"errors"`
	}
	if err := json.Unmarshal(contracttest.Read(t, "errors.json"), &file); err != nil {
		t.Fatal(err)
	}

	want := map[string]bool{}
	for _, e := range file.Errors {
		want[e.Type] = e.NonRetryable
	}

	// Every error the use case and its ports can produce, with the contract type it must map to.
	produced := map[string]error{
		"InvalidInspectionInput":     &inspectcharge.InvalidInputError{Problems: []string{"x"}},
		"UnsupportedContractVersion": &inspectcharge.UnsupportedVersionError{Requested: 2, Supported: []int{1}},
		"GatewayRequestRejected":     fmt.Errorf("status 422: %w", inspectcharge.ErrRequestRejected),
		"GatewayUnavailable":         fmt.Errorf("timeout: %w", inspectcharge.ErrGatewayUnavailable),
		"ChargePending":              inspectcharge.ErrChargePending,
		"CircuitOpen":                &inspectcharge.CircuitOpenError{RetryAfter: 5 * time.Second},
	}

	// Completeness both ways: no contract type without a producer, no produced type outside the contract.
	if len(produced) != len(want) {
		t.Fatalf("producers = %d, contract types = %d", len(produced), len(want))
	}

	seen := map[string]bool{}
	for expectedType, err := range produced {
		info, ok := inspectcharge.Classify(err)
		if !ok {
			t.Fatalf("%s: Classify did not recognise %v", expectedType, err)
		}
		if info.Type != expectedType {
			t.Fatalf("Classify(%v).Type = %s, want %s", err, info.Type, expectedType)
		}
		nonRetryable, inFile := want[info.Type]
		if !inFile {
			t.Fatalf("type %s is not in errors.json", info.Type)
		}
		if info.NonRetryable != nonRetryable {
			t.Fatalf("%s: NonRetryable = %v, errors.json says %v", info.Type, info.NonRetryable, nonRetryable)
		}
		seen[info.Type] = true
	}

	var missing []string
	for typ := range want {
		if !seen[typ] {
			missing = append(missing, typ)
		}
	}
	sort.Strings(missing)
	if len(missing) > 0 {
		t.Fatalf("contract types never produced: %v", missing)
	}
}

// Group: T-GO-13
func TestClassifyDetailsAndUnknown(t *testing.T) {
	info, _ := inspectcharge.Classify(&inspectcharge.UnsupportedVersionError{Requested: 2, Supported: []int{1}})
	raw, err := json.Marshal(info.Details)
	if err != nil || string(raw) != `{"requested":2,"supported":[1]}` {
		t.Fatalf("details = %s (%v)", raw, err)
	}

	info, _ = inspectcharge.Classify(&inspectcharge.CircuitOpenError{RetryAfter: 7 * time.Second})
	if info.RetryAfter != 7*time.Second {
		t.Fatalf("RetryAfter = %v", info.RetryAfter)
	}

	if _, ok := inspectcharge.Classify(errors.New("boom")); ok {
		t.Fatal("an unknown error must not be classified")
	}
}
