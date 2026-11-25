#!/usr/bin/env bash
# M8.2: end-to-end smoke over the real compose stack (nginx -> Symfony -> outbox -> Messenger -> Temporal -> gateway emulator).
#
#   tests/E2E/smoke.sh          (or: make smoke)
#
# Run from the HOST. There is no curl/wget on the host path: HTTP goes through `docker compose exec php php`
# (a small PHP client calling http://nginx and http://gateway-emulator:8081 inside the compose network).
#
# Scenarios (each with its own product and key):
#   1. happy path:   place order -> Completed; no key code in the POST answer, the list or the logs;
#                    same Idempotency-Key again -> `Idempotent-Replayed: true` and the same order.
#   2. decline:      gateway emulator in `decline` mode -> Failed, the key is Available again.
#   3. refund:       Completed order -> POST /refund -> Refunded.
#   4. cancel:       gateway emulator in `delay:5000` mode, cancel while the charge is in flight -> Cancelled.
#
# Workers (profile `workers`: outbox-relay, messenger-consumer, scheduler) are needed for the outbox -> saga path.
# Rule: workers that this script started are stopped at the end (the integration tests share the database and the
# Redis streams, and a running relay would publish the rows they expect to find unpublished). Workers that were
# already running are left alone. KEEP_WORKERS=1 leaves them running. temporal-worker is not in the profile and is
# always left running. The test data (products, keys, orders) stays in the dev database.
# Exit code: 0 = all checks passed, 1 = a check failed or a timeout.
set -euo pipefail
cd "$(dirname "$0")/../.."

dc() { docker compose "$@"; }
sql() { dc exec -T postgres psql -U app -d app -tAc "$1"; }
uuid() { uuidgen | tr 'A-Z' 'a-z'; }

PASSED=0
FAILED=0
step() { printf '\n== %s\n' "$*"; }
pass() { PASSED=$((PASSED + 1)); printf '  ok   %s\n' "$*"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL %s\n' "$*" >&2; }
check() { # check "<description>" "<actual>" "<expected>"
    if [ "$2" = "$3" ]; then pass "$1 ($2)"; else fail "$1: expected '$3', got '$2'"; fi
}
die() { printf 'ABORT: %s\n' "$*" >&2; summary; exit 1; }
summary() { printf '\nsmoke: %d passed, %d failed\n' "$PASSED" "$FAILED"; }

# http METHOD URL [BODY] [Header: value]...  -> line 1 = status, then response headers (lowercased), empty line, body.
http() {
    dc exec -T php php -r '
        [$_, $method, $url, $body] = $argv + [null, "GET", "", ""];
        $headers = array_slice($argv, 4);
        $ctx = stream_context_create(["http" => [
            "method" => $method, "ignore_errors" => true, "timeout" => 30,
            "header" => implode("\r\n", array_merge(["Accept: application/json"], $body === "" ? [] : ["Content-Type: application/json"], $headers)),
            "content" => $body,
        ]]);
        $answer = @file_get_contents($url, false, $ctx);
        $headers = http_get_last_response_headers();
        if ($headers === null) { fwrite(STDERR, "no response from $url\n"); exit(2); }
        preg_match("#HTTP/\S+ (\d+)#", $headers[0], $m);
        echo $m[1], "\n", strtolower(implode("\n", array_slice($headers, 1))), "\n\n", $answer;
    ' -- "$@"
}
status_of() { printf '%s\n' "$1" | sed -n 1p; }
header_of() { printf '%s\n' "$1" | sed -n '2,/^$/p' | grep -i "^$2:" | head -1 | cut -d: -f2- | tr -d ' \r'; }
body_of() { printf '%s\n' "$1" | sed '1,/^$/d'; }
json_get() { # json_get "<json>" "<php expression on $j>"
    printf '%s' "$1" | dc exec -T php php -r '$j = json_decode(stream_get_contents(STDIN), true); echo '"$2"' ?? "";'
}
emulator() { http POST "http://gateway-emulator:8081/$1" "${2:-}" >/dev/null; }
set_mode() { emulator __admin/mode "{\"mode\":\"$1\"}"; }

seed_product() { # seed_product <code>  -> prints product id
    local product; product=$(uuid)
    sql "INSERT INTO products (id, name, price_amount, price_currency, active) VALUES ('$product', 'Smoke product', 1999, 'EUR', true)" >/dev/null
    sql "INSERT INTO product_keys (id, product_id, code, status, version) VALUES ('$(uuid)', '$product', '$1', 'available', 1)" >/dev/null
    echo "$product"
}

# place_order <product> <customer> <idempotency-key>  -> prints the raw http() answer
place_order() {
    http POST http://nginx/api/orders \
        "{\"productId\":\"$1\",\"expectedPrice\":{\"amount\":1999,\"currency\":\"EUR\"}}" \
        "X-Customer-Id: $2" "Idempotency-Key: $3"
}
order_status() { # order_status <order> <customer>
    json_get "$(body_of "$(http GET "http://nginx/api/orders/$1" "" "X-Customer-Id: $2")")" '$j["status"]'
}
# wait_status <order> <customer> <expected> [timeout-seconds]
wait_status() {
    local deadline=$((SECONDS + ${4:-90})) current=""
    while [ $SECONDS -lt $deadline ]; do
        current=$(order_status "$1" "$2")
        [ "$current" = "$3" ] && { echo "$current"; return 0; }
        case "$current" in failed | cancelled | refunded | manual_review)
            [ "$current" != "$3" ] && { echo "$current"; return 0; } ;;
        esac
        sleep 1
    done
    echo "${current:-timeout}"
}

# ---------------------------------------------------------------------------------------------------------------- stack
step "stack: compose up, migrations, workers"
WORKERS="outbox-relay messenger-consumer scheduler"
STARTED_WORKERS=""
for w in $WORKERS; do
    if [ -z "$(dc ps --status running --services | grep -x "$w" || true)" ]; then STARTED_WORKERS="$STARTED_WORKERS $w"; fi
done
cleanup() {
    set_mode ok >/dev/null 2>&1 || true
    if [ -n "$STARTED_WORKERS" ] && [ "${KEEP_WORKERS:-0}" != "1" ]; then
        # shellcheck disable=SC2086
        dc --profile workers stop $STARTED_WORKERS >/dev/null 2>&1 || true
        echo "workers stopped:$STARTED_WORKERS"
    elif [ -n "$STARTED_WORKERS" ]; then
        echo "workers left running (KEEP_WORKERS=1):$STARTED_WORKERS"
    fi
}
trap cleanup EXIT

dc up -d >/dev/null 2>&1
dc restart temporal-worker >/dev/null 2>&1 # the compose worker keeps old code until restarted
dc exec -T php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null
dc --profile workers up -d >/dev/null 2>&1

ready=0
for _ in $(seq 60); do
    answer=$(http GET http://nginx/health 2>/dev/null || true)
    if [ "$(status_of "$answer")" = "200" ] \
        && [ "$(status_of "$(http GET http://gateway-emulator:8081/health 2>/dev/null || true)")" = "200" ] \
        && dc exec -T temporal temporal operator cluster health --address localhost:7233 >/dev/null 2>&1; then ready=1; break; fi
    sleep 2
done
[ "$ready" = 1 ] || die "stack not ready within 120 s (nginx, gateway-emulator or temporal)"
sleep 5 # let temporal-worker (RoadRunner) register on the task queue
emulator __admin/reset
pass "stack is up (nginx, gateway-emulator, temporal ready)"

# ----------------------------------------------------------------------------------------------------- 1. happy path
step "1. happy path"
CODE="SMOKE-$(uuid | cut -c1-8 | tr a-z A-Z)-$(uuid | cut -c1-8 | tr a-z A-Z)"
PRODUCT=$(seed_product "$CODE")
CUSTOMER=$(uuid)
IDEM="smoke-$(uuid)"
SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)

answer=$(place_order "$PRODUCT" "$CUSTOMER" "$IDEM")
check "POST /api/orders status" "$(status_of "$answer")" "201"
ORDER=$(json_get "$(body_of "$answer")" '$j["orderId"]')
[ -n "$ORDER" ] || die "no orderId in the POST answer: $(body_of "$answer")"
echo "  order $ORDER"
if body_of "$answer" | grep -qF "$CODE"; then fail "key code in the POST answer"; else pass "no key code in the POST answer"; fi

check "order reaches Completed" "$(wait_status "$ORDER" "$CUSTOMER" completed 90)" "completed"

detail=$(body_of "$(http GET "http://nginx/api/orders/$ORDER" "" "X-Customer-Id: $CUSTOMER")")
check "GET order returns the key (Completed only, by spec)" "$(json_get "$detail" '$j["keyCode"]')" "$CODE"

list=$(body_of "$(http GET "http://nginx/api/orders" "" "X-Customer-Id: $CUSTOMER")")
if printf '%s' "$list" | grep -qF "$CODE"; then fail "key code in the order list"; else pass "no key code in the order list"; fi

# the code must not be in any container log written since the start of the scenario
leak=0
for svc in php nginx temporal-worker outbox-relay messenger-consumer scheduler gateway-emulator; do
    if dc logs --since "$SINCE" "$svc" 2>&1 | grep -qF "$CODE"; then fail "key code found in the $svc log"; leak=1; fi
done
[ "$leak" = 0 ] && pass "no key code in container logs"

replay=$(place_order "$PRODUCT" "$CUSTOMER" "$IDEM")
check "replay: Idempotent-Replayed header" "$(header_of "$replay" idempotent-replayed)" "true"
check "replay: same orderId" "$(json_get "$(body_of "$replay")" '$j["orderId"]')" "$ORDER"
check "replay: orders for this customer" "$(sql "SELECT count(*) FROM orders WHERE customer_id = '$CUSTOMER'")" "1"

# --------------------------------------------------------------------------------------------------------- 2. decline
step "2. decline: the order fails, the key is free again"
PRODUCT2=$(seed_product "SMOKE-DECLINE-$(uuid | cut -c1-8 | tr a-z A-Z)")
CUSTOMER2=$(uuid)
set_mode decline
answer=$(place_order "$PRODUCT2" "$CUSTOMER2" "smoke-$(uuid)")
ORDER2=$(json_get "$(body_of "$answer")" '$j["orderId"]')
[ -n "$ORDER2" ] || die "no orderId in the POST answer (decline)"
echo "  order $ORDER2"
check "order reaches Failed" "$(wait_status "$ORDER2" "$CUSTOMER2" failed 90)" "failed"
set_mode ok
check "key is Available again" "$(sql "SELECT status FROM product_keys WHERE product_id = '$PRODUCT2'")" "available"

# ---------------------------------------------------------------------------------------------------------- 3. refund
step "3. refund of the Completed order from scenario 1"
answer=$(http POST "http://nginx/api/orders/$ORDER/refund" "" "X-Customer-Id: $CUSTOMER" "Idempotency-Key: smoke-refund-$(uuid)")
check "POST refund status" "$(status_of "$answer")" "202"
check "order reaches Refunded" "$(wait_status "$ORDER" "$CUSTOMER" refunded 90)" "refunded"

# ----------------------------------------------------------------------------------------------------------- 4. cancel
step "4. cancel while the charge is in flight"
PRODUCT4=$(seed_product "SMOKE-CANCEL-$(uuid | cut -c1-8 | tr a-z A-Z)")
CUSTOMER4=$(uuid)
set_mode delay:5000
answer=$(place_order "$PRODUCT4" "$CUSTOMER4" "smoke-$(uuid)")
ORDER4=$(json_get "$(body_of "$answer")" '$j["orderId"]')
[ -n "$ORDER4" ] || die "no orderId in the POST answer (cancel)"
echo "  order $ORDER4"
for _ in $(seq 60); do # wait until the saga is under way (the charge is the slow step)
    [ "$(sql "SELECT count(*) FROM payments WHERE order_id = '$ORDER4'")" != "0" ] && break
    sleep 0.5
done
answer=$(http POST "http://nginx/api/orders/$ORDER4/cancel" "" "X-Customer-Id: $CUSTOMER4" "Idempotency-Key: smoke-cancel-$(uuid)")
echo "  cancel answer: HTTP $(status_of "$answer") $(body_of "$answer")"
final=$(wait_status "$ORDER4" "$CUSTOMER4" cancelled 90)
set_mode ok
check "order reaches Cancelled" "$final" "cancelled"
check "key is Available again" "$(sql "SELECT status FROM product_keys WHERE product_id = '$PRODUCT4'")" "available"

# --------------------------------------------------------------------------------------------------------------- result
summary
[ "$FAILED" = 0 ] || exit 1
echo "smoke: OK"
