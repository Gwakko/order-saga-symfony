.DEFAULT_GOAL := help
PHP ?= docker compose exec -T php

.PHONY: help up down install phpstan cs cs-fix test-unit test-int test-wf test-func ci

help:
	@grep -E "^[a-z-]+:" Makefile | cut -d: -f1

up:
	docker compose up -d

down:
	docker compose down

install:
	$(PHP) composer install --no-interaction

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
	$(PHP) vendor/bin/phpunit --testsuite integration

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
