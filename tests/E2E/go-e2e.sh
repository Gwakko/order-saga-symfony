#!/usr/bin/env bash
# M9.5: the PHP saga against the real Go worker (docs/spec/09_go_worker.md sections 7 and 9.4, T-M9-10...13).
#
#   tests/E2E/go-e2e.sh          (or: make test-go-e2e)
#
# Run from the HOST. Steps: build and start `worker-go` (compose profile `go`) on a queue of its own,
# `payment-go-test-<hex>`; wait until the container is `healthy`; run the PHPUnit group `go-e2e` in the php container with
# the same queue, so the saga's `InspectGatewayCharge` reaches this worker and no other; stop and remove the Go worker
# container if this script started it. A worker that was already running (make go-up) is left alone and not used: the
# test queue is unique, so the script always starts its own container.
#
# Not part of `make ci`: it builds an image and manages a container from the host (precedent: tests/E2E/smoke.sh).
# There is no curl/wget on the host path; every check goes through docker compose.
# Exit code: PHPUnit's (0 = green), 1 = the worker did not become healthy.
set -euo pipefail
cd "$(dirname "$0")/../.."

dc() { docker compose "$@"; }

SUFFIX=$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')
QUEUE="payment-go-test-${SUFFIX}"
STARTED=0

cleanup() {
    status=$?
    if [ "$STARTED" = "1" ]; then
        if [ "$status" != "0" ]; then
            echo "--- worker-go log (last 40 lines) ---" >&2
            dc --profile go logs --no-color --tail 40 worker-go >&2 || true
        fi
        dc --profile go stop worker-go >/dev/null 2>&1 || true
        dc --profile go rm -f worker-go >/dev/null 2>&1 || true
        echo "worker-go stopped and removed (queue ${QUEUE})"
    fi
    exit "$status"
}
trap cleanup EXIT

echo "== go-e2e: queue ${QUEUE}"
dc up -d temporal gateway-emulator php postgres redis >/dev/null 2>&1

if [ -n "$(dc --profile go ps --status running --services 2>/dev/null | grep -x worker-go || true)" ]; then
    echo "worker-go is already running (queue from its own environment); it is stopped for this run and started again on ${QUEUE}" >&2
    dc --profile go stop worker-go >/dev/null 2>&1
    dc --profile go rm -f worker-go >/dev/null 2>&1
fi

STARTED=1
GO_WORKER_TASK_QUEUE="$QUEUE" dc --profile go up -d --build worker-go >/dev/null

echo "== waiting for worker-go to become healthy"
healthy=0
for _ in $(seq 1 60); do
    state=$(dc --profile go ps --format '{{.Health}}' worker-go 2>/dev/null || true)
    if [ "$state" = "healthy" ]; then healthy=1; break; fi
    sleep 2
done
if [ "$healthy" != "1" ]; then
    echo "ABORT: worker-go did not become healthy in 120 s (state: ${state:-unknown})" >&2
    exit 1
fi
echo "worker-go is healthy"

echo "== phpunit --group go-e2e"
dc exec -T \
    -e TEMPORAL_GO_TASK_QUEUE="$QUEUE" \
    php vendor/bin/phpunit --testsuite integration --group go-e2e --fail-on-skipped
