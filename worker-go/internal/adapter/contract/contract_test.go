package contract_test

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"testing"
	"time"
	_ "time/tzdata"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"order-saga-symfony/worker-go/internal/adapter/contract"
	"order-saga-symfony/worker-go/internal/contracttest"
	"order-saga-symfony/worker-go/internal/domain/charge"
	"order-saga-symfony/worker-go/internal/usecase/inspectcharge"
)

var fixedNow = time.Date(2026, 11, 25, 10, 15, 30, 123_000_000, time.UTC)

type lookup struct {
	obs   charge.Observation
	found bool
}

func (l lookup) FindCharge(context.Context, string) (charge.Observation, bool, error) {
	return l.obs, l.found, nil
}

type clock struct{ now time.Time }

func (c clock) Now() time.Time { return c.now }

func compileSchema(t *testing.T, file string) *jsonschema.Schema {
	t.Helper()

	doc, err := jsonschema.UnmarshalJSON(strings.NewReader(string(contracttest.Read(t, file))))
	if err != nil {
		t.Fatal(err)
	}

	id := doc.(map[string]any)["$id"].(string)

	c := jsonschema.NewCompiler()
	c.AssertFormat()
	if err := c.AddResource(id, doc); err != nil {
		t.Fatal(err)
	}

	schema, err := c.Compile(id)
	if err != nil {
		t.Fatal(err)
	}

	return schema
}

func validate(t *testing.T, schema *jsonschema.Schema, raw []byte) error {
	t.Helper()

	doc, err := jsonschema.UnmarshalJSON(strings.NewReader(string(raw)))
	if err != nil {
		t.Fatal(err)
	}

	return schema.Validate(doc)
}

func decodeRequest(t *testing.T, raw []byte) contract.Request {
	t.Helper()

	var req contract.Request
	if err := json.Unmarshal(raw, &req); err != nil {
		t.Fatal(err)
	}

	return req
}

func mustMarshal(t *testing.T, v any) []byte {
	t.Helper()

	raw, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}

	return raw
}

// Group: T-GO-12
func TestGoldenFilesMatchTheirSchemas(t *testing.T) {
	inputSchema := compileSchema(t, "input.schema.json")
	outputSchema := compileSchema(t, "output.schema.json")

	if err := validate(t, inputSchema, contracttest.Read(t, "input.v1.json")); err != nil {
		t.Fatalf("input.v1.json: %v", err)
	}
	for _, name := range []string{"succeeded", "mismatch", "declined", "not_found"} {
		if err := validate(t, outputSchema, contracttest.Read(t, "output."+name+".json")); err != nil {
			t.Fatalf("output.%s.json: %v", name, err)
		}
	}
}

// Group: T-GO-12
func TestSchemasRejectBrokenDocuments(t *testing.T) {
	outputSchema := compileSchema(t, "output.schema.json")

	good := `{"contractVersion":1,"outcome":"succeeded","charge":{"chargeId":"ch_01","amount":1999,"currency":"EUR","declineReason":null},"matchesExpected":true,"checkedAt":"2026-11-25T10:15:30.123+00:00"}`
	if err := validate(t, outputSchema, []byte(good)); err != nil {
		t.Fatalf("baseline must be valid: %v", err)
	}

	broken := map[string]string{
		"checkedAt with Z":        strings.Replace(good, "+00:00", "Z", 1),
		"checkedAt no millis":     strings.Replace(good, ".123", "", 1),
		"float amount":            strings.Replace(good, `"amount":1999`, `"amount":19.99`, 1),
		"string amount":           strings.Replace(good, `"amount":1999`, `"amount":"1999"`, 1),
		"zero amount":             strings.Replace(good, `"amount":1999`, `"amount":0`, 1),
		"unknown outcome":         strings.Replace(good, `"succeeded"`, `"weird"`, 1),
		"contract version 2":      strings.Replace(good, `"contractVersion":1`, `"contractVersion":2`, 1),
		"lower case currency":     strings.Replace(good, `"EUR"`, `"eur"`, 1),
		"missing matchesExpected": strings.Replace(good, `"matchesExpected":true,`, ``, 1),
		"missing charge":          strings.Replace(good, `"charge":{"chargeId":"ch_01","amount":1999,"currency":"EUR","declineReason":null},`, ``, 1),
		"reason too long":         strings.Replace(good, `"declineReason":null`, `"declineReason":"`+strings.Repeat("x", 257)+`"`, 1),
	}

	for name, doc := range broken {
		t.Run(name, func(t *testing.T) {
			if err := validate(t, outputSchema, []byte(doc)); err == nil {
				t.Fatalf("schema accepted a broken document: %s", doc)
			}
		})
	}
}

// Group: T-GO-12
// The schema and Input.Validate must agree on the stateless rules (contractVersion, uuid, key, amount, currency).
func TestInputSchemaAgreesWithValidate(t *testing.T) {
	inputSchema := compileSchema(t, "input.schema.json")
	base := decodeRequest(t, contracttest.Read(t, "input.v1.json"))

	cases := []struct {
		name   string
		mutate func(*contract.Request)
	}{
		{"golden", func(*contract.Request) {}},
		{"amount 1", func(r *contract.Request) { r.Expected.Amount = 1 }},
		{"amount max", func(r *contract.Request) { r.Expected.Amount = 100_000_000 }},
		{"amount 0", func(r *contract.Request) { r.Expected.Amount = 0 }},
		{"amount -1", func(r *contract.Request) { r.Expected.Amount = -1 }},
		{"amount max+1", func(r *contract.Request) { r.Expected.Amount = 100_000_001 }},
		{"currency eur", func(r *contract.Request) { r.Expected.Currency = "eur" }},
		{"currency XYZ", func(r *contract.Request) { r.Expected.Currency = "XYZ" }},
		{"currency PLN", func(r *contract.Request) { r.Expected.Currency = "PLN" }},
		{"key with slash", func(r *contract.Request) { r.IdempotencyKey = "a/b" }},
		{"key empty", func(r *contract.Request) { r.IdempotencyKey = "" }},
		{"key 64", func(r *contract.Request) { r.IdempotencyKey = strings.Repeat("a", 64) }},
		{"key 65", func(r *contract.Request) { r.IdempotencyKey = strings.Repeat("a", 65) }},
		{"order id not uuid", func(r *contract.Request) { r.OrderID = "order-1" }},
		{"version 0", func(r *contract.Request) { r.ContractVersion = 0 }},
		{"version 2", func(r *contract.Request) { r.ContractVersion = 2 }},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := base
			tc.mutate(&req)

			schemaOK := validate(t, inputSchema, mustMarshal(t, req)) == nil
			validateOK := req.ToInput().Validate() == nil
			if schemaOK != validateOK {
				t.Fatalf("schema valid = %v, Validate valid = %v: the two rules drifted apart", schemaOK, validateOK)
			}
		})
	}
}

// Group: T-GO-12
// The input golden decodes into the DTO; unknown fields are ignored (additionalProperties: true).
func TestRequestDecodesGoldenAndIgnoresUnknownFields(t *testing.T) {
	req := decodeRequest(t, contracttest.Read(t, "input.v1.json"))
	want := contract.Request{
		ContractVersion: 1,
		OrderID:         "0190a5c4-0000-7000-8000-000000000020",
		IdempotencyKey:  "0190a5c4-0000-7000-8000-0000000000a1",
		Expected:        contract.MoneyDTO{Amount: 1999, Currency: "EUR"},
	}
	if req != want {
		t.Fatalf("req = %+v, want %+v", req, want)
	}

	extended := strings.Replace(string(contracttest.Read(t, "input.v1.json")), `"contractVersion": 1,`, `"contractVersion": 1, "futureField": {"a": [1, 2]},`, 1)
	if got := decodeRequest(t, []byte(extended)); got != want {
		t.Fatalf("a new optional field changed the decoded request: %+v", got)
	}
}

// Group: T-GO-12
// Cross-language contract: golden input -> DTO -> use case -> DTO equals the golden output (structurally, not byte by byte).
func TestUseCaseProducesTheGoldenOutputs(t *testing.T) {
	reason := "insufficient_funds"
	cases := []struct {
		golden string
		lookup lookup
	}{
		{"succeeded", lookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusSucceeded}}},
		{"mismatch", lookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 2000, Currency: "EUR"}, Status: charge.StatusSucceeded}}},
		{"declined", lookup{found: true, obs: charge.Observation{ChargeID: "ch_01", Money: charge.Money{Amount: 1999, Currency: "EUR"}, Status: charge.StatusDeclined, DeclineReason: &reason}}},
		{"not_found", lookup{found: false}},
	}

	outputSchema := compileSchema(t, "output.schema.json")
	req := decodeRequest(t, contracttest.Read(t, "input.v1.json"))

	for _, tc := range cases {
		t.Run(tc.golden, func(t *testing.T) {
			out, err := inspectcharge.New(tc.lookup, clock{now: fixedNow}).Execute(context.Background(), req.ToInput())
			if err != nil {
				t.Fatal(err)
			}

			raw := mustMarshal(t, contract.FromOutput(req.ContractVersion, out))

			if err := validate(t, outputSchema, raw); err != nil {
				t.Fatalf("produced output violates the schema: %v\n%s", err, raw)
			}

			got := contracttest.Decode(t, raw)
			want := contracttest.Decode(t, contracttest.Read(t, "output."+tc.golden+".json"))
			if !reflect.DeepEqual(got, want) {
				t.Fatalf("output differs from output.%s.json\n got: %s\nwant: %s", tc.golden, raw, contracttest.Read(t, "output."+tc.golden+".json"))
			}
		})
	}
}

// Group: T-GO-12
func TestNotFoundSerializesChargeAsNullAndMoneyAsIntegers(t *testing.T) {
	raw := mustMarshal(t, contract.FromOutput(1, inspectcharge.InspectChargeOutput{Outcome: charge.OutcomeNotFound, CheckedAt: fixedNow}))
	if !strings.Contains(string(raw), `"charge":null`) {
		t.Fatalf("charge must be an explicit null: %s", raw)
	}

	out := inspectcharge.InspectChargeOutput{
		Outcome: charge.OutcomeSucceeded,
		Charge:  &charge.Observation{ChargeID: "ch", Money: charge.Money{Amount: 100_000_000, Currency: "EUR"}, Status: charge.StatusSucceeded},
	}
	raw = mustMarshal(t, contract.FromOutput(1, out))
	if !strings.Contains(string(raw), `"amount":100000000,`) {
		t.Fatalf("amount must be a plain integer: %s", raw)
	}
}

// Group: T-GO-12
func TestUnsupportedVersionIsRejectedBeforeAnyLookup(t *testing.T) {
	req := decodeRequest(t, contracttest.Read(t, "input.v1.json"))
	req.ContractVersion = 2

	_, err := inspectcharge.New(lookup{}, clock{now: fixedNow}).Execute(context.Background(), req.ToInput())
	var unsupported *inspectcharge.UnsupportedVersionError
	if !errors.As(err, &unsupported) {
		t.Fatalf("err = %v, want UnsupportedVersionError", err)
	}
}

// Group: T-GO-16
func TestCheckedAtIsUTCWithLiteralOffsetWhateverTheLocalZone(t *testing.T) {
	kyiv, err := time.LoadLocation("Europe/Kyiv")
	if err != nil {
		t.Fatal(err)
	}

	saved := time.Local
	time.Local = kyiv
	t.Cleanup(func() { time.Local = saved })

	// 12:15:30.123 in Kyiv (UTC+2 in November) is 10:15:30.123 UTC.
	local := time.Date(2026, 11, 25, 12, 15, 30, 123_456_789, kyiv)
	if got, want := contract.FormatCheckedAt(local), "2026-11-25T10:15:30.123+00:00"; got != want {
		t.Fatalf("FormatCheckedAt = %q, want %q", got, want)
	}

	// A clock that returns time.Now() in the local zone, run through the whole use case.
	req := decodeRequest(t, contracttest.Read(t, "input.v1.json"))
	out, err := inspectcharge.New(lookup{}, clock{now: local}).Execute(context.Background(), req.ToInput())
	if err != nil {
		t.Fatal(err)
	}
	raw := mustMarshal(t, contract.FromOutput(1, out))
	if !strings.Contains(string(raw), `"checkedAt":"2026-11-25T10:15:30.123+00:00"`) {
		t.Fatalf("checkedAt in %s", raw)
	}

	outputSchema := compileSchema(t, "output.schema.json")
	if err := validate(t, outputSchema, raw); err != nil {
		t.Fatalf("schema: %v", err)
	}
}

// Group: T-GO-16
func TestCheckedAtFormatEdgeCases(t *testing.T) {
	cases := map[string]time.Time{
		"2026-01-01T00:00:00.000+00:00": time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC),
		"2026-12-31T23:59:59.999+00:00": time.Date(2026, 12, 31, 23, 59, 59, 999_999_999, time.UTC),
		"2026-06-30T22:00:00.007+00:00": time.Date(2026, 7, 1, 1, 0, 0, 7_000_000, time.FixedZone("x", 3*3600)),
	}

	for want, in := range cases {
		if got := contract.FormatCheckedAt(in); got != want {
			t.Fatalf("FormatCheckedAt(%v) = %q, want %q", in, got, want)
		}
	}
}
