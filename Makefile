.DEFAULT_GOAL := help
PHP ?= docker compose exec -T php

.PHONY: help up down install wf-install wf-record-history phpstan cs cs-fix test-unit test-int test-slow test-wf test-func ci

help:
	@grep -E "^[a-z-]+:" Makefile | cut -d: -f1

up:
	docker compose up -d

down:
	docker compose down

install:
	$(PHP) composer install --no-interaction

# One-off, explicit: downloads the pinned temporal-test-server (about 83 MB) into var/temporal-test-server/. test-wf never downloads.
wf-install:
	$(PHP) php bin/install-temporal-test-server.php

# Re-records tests/Workflow/Fixtures/history-happy.json (T-W-20) from a real happy-path run. Only after an intentional, versioned workflow change.
wf-record-history:
	$(PHP) env RECORD_HISTORY=1 vendor/bin/phpunit tests/Workflow/OrderFulfillmentReplayTest.php --filter testRecordHappyPathHistory

phpstan:
	$(PHP) bin/console cache:warmup --env=dev -q
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix

test-unit:
	$(PHP) vendor/bin/phpunit --testsuite unit

test-int:
	$(PHP) vendor/bin/phpunit --testsuite integration --exclude-group slow

# Slow integration tests (T-I-43: kills the database sessions under a running saga, about 15 s). Not part of `make ci`.
test-slow:
	$(PHP) vendor/bin/phpunit --group slow

test-wf:
	$(PHP) vendor/bin/phpunit --testsuite workflow

test-func:
	$(PHP) vendor/bin/phpunit --testsuite functional

ci:
	$(PHP) composer validate --strict
	$(MAKE) cs
	$(MAKE) phpstan
	$(MAKE) test-unit
	$(MAKE) test-int
	$(MAKE) test-wf
	$(MAKE) test-func
