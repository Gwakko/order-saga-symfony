# Contract: InspectGatewayCharge v1

Source of truth for the PHP saga (caller) and the Go worker (implementation). Both sides test against these files;
changing one without the other breaks T-M9-01..04 (PHP) or T-GO-09..13 (Go). Full rationale: `docs/spec/09_go_worker.md`, section 4.

## Identifiers

| What | Value |
|---|---|
| Activity type | `InspectGatewayCharge` |
| Task queue | `payment-go` (dev), `payment-go-test` (workflow suite), `payment-go-test-<hex>` (integration, e2e) |
| Namespace | `default` |
| Contract version | `contractVersion: 1` in input and output; the answer is always in the version of the request |

## Encoding

Default Temporal data converter on both sides: payload metadata `encoding = json/plain`, `data` = UTF-8 JSON object.
One activity = one argument = one payload. Unknown fields are ignored by the reader; within a version only optional
fields may be added (`additionalProperties: true` at the top level of input and output).

## Rules

- Money: integer minor units (JSON integer), never float, never a string. `amount` in input is 1..100000000; currency is one of EUR, USD, PLN, UAH.
- Output `currency` is any `^[A-Z]{3}$` (the gateway may answer in a foreign currency; that is `matchesExpected = false`, not an error).
- Time: `checkedAt` is UTC, RFC 3339 with milliseconds and the literal offset `+00:00` (never `Z`). The workflow does not decide on it, logs only.
- `idempotencyKey` is the `paymentId` PHP used for `POST /charges`; `orderId` is for logs and correlation.
- `charge` is `null` if and only if `outcome = not_found`.
- `matchesExpected = true` only for `outcome = succeeded` with equal amount and currency.
- Business outcomes (`succeeded`, `declined`, `not_found`) are results, not errors. An error means "no answer".

## Files

| File | Content |
|---|---|
| `input.schema.json`, `output.schema.json` | JSON Schema 2020-12 |
| `input.v1.json` | input example |
| `output.succeeded.json` | charge exists, amount and currency match |
| `output.mismatch.json` | charge exists, amount differs (`matchesExpected = false`) |
| `output.declined.json` | gateway proved a decline |
| `output.not_found.json` | no charge for the key (`charge = null`) |
| `errors.json` | error types and the non-retryable flag |

Compare golden files structurally (as parsed JSON), not byte by byte: PHP escapes `/` as `\/`.

## Errors

Go returns an application error with the type below; PHP sees `ActivityFailure` -> `ApplicationFailure` (`getType()`, `isNonRetryable()`).
The PHP activity profile lists the three non-retryable types as well (safety net); both lists are checked against `errors.json`.

| Type | When | Non-retryable |
|---|---|---|
| `InvalidInspectionInput` | input failed validation | yes |
| `UnsupportedContractVersion` | `contractVersion` not supported; details `{"requested":2,"supported":[1]}` | yes |
| `GatewayRequestRejected` | gateway answered 4xx other than `404 charge_not_found` | yes |
| `GatewayUnavailable` | timeout, connection loss, 5xx, unreadable or oversized response, unknown status, wrong key in response | no |
| `ChargePending` | gateway reports the charge as `pending` | no |
| `CircuitOpen` | breaker open, no call made | no |

Temporal-level timeouts (`SCHEDULE_TO_START` is always non-retryable) are not Go errors. Any error or timeout leads
the saga to the pre-M9 behavior.
