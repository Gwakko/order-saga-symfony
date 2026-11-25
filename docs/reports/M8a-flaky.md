# M8a: flaky RefundRetryTest::testRefundExecutedOnceAcrossRetries

## Root cause (confirmed by reproduction)

The compose dev Temporal server keeps workflows between runs. Every workflow on the test task queue
`order-saga-test` is polled by the next `TemporalTestWorker` (RoadRunner, `.rr.yaml`: `activities.num_workers: 2`).
Two tests leak Running `OrderFulfillment` workflows into that queue and never terminate them:

- `Messaging/ProjectionEndToEndTest` and `Messaging/MessengerFailureTransportTest` run a real `messenger:consume`,
  whose `StartOrderSagaOnOrderPlaced` starts a real saga on `order-saga-test`.

Each `make test-int` leaves 2 of them (checked per directory and per file: only these two leak; all other Saga,
Worker and Functional tests terminate their workflows). The orders no longer exist (tables are truncated), so the
leaked sagas retry `MarkOrderManualReview` forever (`OrderNotFound`, retryable, backoff up to 10 s), which is exactly the
"Activity error" noise for foreign WorkflowIDs in the failing log. Leaks accumulate across runs and crashed runs.
With enough of them the two PHP workers have no time for the refund workflow: RoadRunner logs
`no free workers in the pool, wait timeout exceed` and the refund activity (#11 `ACTIVITY_TASK_SCHEDULED` = RefundPayment
in its retry backoff, STARTED is only written on completion) does not finish within the 60 s of the test.

Retry policy of the leaked activities: `order_step` (1 s, x2, max 10 s, unlimited attempts, 60 s ScheduleToClose per
activity, then the saga compensation re-enters it).

## Reproduction (before the fix)

- 12, 40, 228, 500 leaked workflows: test green (17.8 s, about 20 s at 500).
- About 3500 leaked workflows (`php var/leak.php 3000` starting `OrderFulfillment` for random order ids on the test
  queue), 3 runs of `phpunit tests/Integration/Saga/RefundRetryTest.php --filter testRefundExecutedOnceAcrossRetries`:
  2 of 3 FAILED with `deadline reached`, `status=Running history_length=11` (62.5 s each); the third run printed
  `no free workers in the pool`. So the hypothesis (shared queue plus leaked Running workflows plus a small RR pool) is
  confirmed, with load as the multiplier. Other hypotheses were not needed: no stale `rr` processes, the compose worker
  uses another queue (`order-saga`), the cache race was not observed.

## Changes

- `tests/Integration/Support/TemporalTestQueue.php` (new): `terminateRunning()` terminates every Running workflow of
  the test queue (visibility query, loops until empty).
- `TemporalTestWorker::start()` calls it before spawning RoadRunner, so a worker never inherits stale workflows.
- `ProjectionEndToEndTest` and `MessengerFailureTransportTest`: `tearDown()` terminates what their consumer started.
- No timeouts or assertions were changed. No production code, no `.rr.yaml` change.

## Results after the fix

- Same stress (3000 fresh leaks before each run): RefundRetryTest 3 of 3 green (25 s each, the extra seconds are the
  drain of stale tasks).
- `make test-int` x10: 9 green, 1 FAILED (run 1, immediately after I injected 3000 leaks): same test, timeout with
  `history_length=2` (the first workflow task was not taken within 60 s). Runs 2-10 green (239 tests, 2551 assertions).
- `make ci` x3: 3 of 3 green (941 unit, 239 integration, 160 functional, workflow suite OK).
- `make test-wf` x3: 3 of 3 green (1 skipped test, as before).
- cs, PHPStan max: clean. All compose services `running`.

## Unknown

- The one failure after the fix happened under the artificial 3000-workflow backlog: terminated workflows still have
  workflow tasks in the server's task queue, which the worker drains after start (about 7 s normally). Why it once took
  over 60 s was not isolated. With the leaks fixed the real backlog is 0-2 tasks, but a unique task queue per worker
  (the queue name is a compile-time container parameter, so it needs a bigger change) would remove the shared-state
  class of problems completely.
- I did not see the original failure's full worker log, only the facts given; I assumed it is the same mechanism.

## Second pass: the failure in `make ci` (history_length=2)

### Correction of the claims above

- "Root cause confirmed by reproduction" is only partly true. The 3000-leak stress reproduces a timeout, but it is an
  artificial load. In the failing `make ci` runs reported afterwards (workflow task never taken, `history_length=2`,
  worker output only "RoadRunner server started") the leaked-workflow explanation was not shown to apply. The report
  itself lists one such failure as "Unknown". The fix above (terminate leftovers) reduces, but does not remove, the
  shared-state class of problems.
- Whether `make ci` itself fails was not established by the first pass. In this pass it did not fail.

### What was tried

- `make ci` x4 on the code as found (before my change): 4 of 4 green. The failure reported as stable could not be
  reproduced, so the exact trigger of the `history_length=2` runs is NOT confirmed. I did not run the per-step
  bisection (cs, phpstan, unit before integration): nothing failed to bisect.
- Evidence that the shared queue was the weak point: the task queue `order-saga-test` was shared by every run on the
  dev Temporal server. Leftover workflows (and their queued workflow/activity tasks, also of terminated workflows)
  from earlier or crashed runs are served to the next worker, and a dead worker's poller can satisfy the
  "poller seen" readiness check (`lastAccessTime` has one-second resolution). With a queue that no earlier run touched,
  none of this can happen. This is a deduction, not an observation of the failing run.
- Found on the way: the non-debug test container (`var/cache/test`, used by `bin/temporal-worker.php` and by
  integration tests with `debug=false`) is not rebuilt when config changes, and `cache:clear --env=test` leaves it in
  place. A stale one served the old queue name to the worker. Anyone changing config must `rm -rf var/cache/test`.
  This is a possible, unverified trigger of the original failure only if config changed between runs.

### Change

- `config/packages/temporal.yaml` (`when@test`): `temporal.task_queue: '%env(TEMPORAL_TASK_QUEUE)%'`, default
  `order-saga-test`.
- `TemporalTestQueue::isolate()` gives each PHPUnit process a unique queue `order-saga-test-<hex>`
  (env var; the RoadRunner worker and consumers inherit it); `TemporalTestQueue::name()` is used by the readiness check
  and the cleanup. Called from `IntegrationTestCase::setUp` and `RefundEndToEndTest::setUp`.
- Not applied to the `workflow` suite: the replay fixture `history-happy.json` records the queue name in the activity
  commands (a unique name gave `NonDeterministicWorkflowException` in `testHappyPathHistoryStillReplays`; I noticed
  this on the first try and moved the isolation out of `tests/bootstrap.php`).
- No timeouts or assertions changed.

### Results

- `make ci`: 4 green before the change, 1 + 4 green after (5 of 5), 0 failures.
- `make test-int` x5: 5 of 5 green. `make test-wf` x3: 3 of 3. `--group concurrency` x10: 10 of 10.
- Intermediate failures while implementing: two `make ci` runs failed (stale non-debug cache serving the old queue; then
  the replay fixture above). Both fixed as described.
- All compose services `running`.

### Still unclear

- The original `history_length=2` failure was not reproduced, so the fix is not proven to be its cure. The per-run
  queue removes the shared state that could cause it; if it comes back, capture the worker output with
  `logs.level=info` and `DescribeTaskQueue` pollers at the time of failure.
- Workflows left Running on old per-run queues (`order-saga-test-*`) are not polled by anybody; only the ones the
  tests terminate are cleaned, so leaks stay on the server until their execution timeout.
