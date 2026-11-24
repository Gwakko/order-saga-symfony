# 07. Открытые вопросы

Разделено на «проверить фактом» (решает подагент на указанном этапе, результат в отчёте) и «решение пользователя».

## Проверить фактом

| ID | Вопрос | Почему не проверено в M0 | Как проверить | Этап |
|---|---|---|---|---|
| Q1 | Собирается ли `ext-grpc` (PECL `grpc`) под PHP 8.5 в Docker и сколько это занимает | локально расширения нет, сборку не запускали | `pecl install grpc` в Dockerfile на `php:8.5-cli`; при неудаче 8.4 или готовый образ/бинарь; записать время сборки | M6.1 |
| Q2 | Точные имена API PHP SDK v2.20: `Workflow\Saga`, `ActivityOptions`, `RetryOptions::withNonRetryableExceptions`, `ApplicationFailure`, политика reuse WorkflowId, исключение «уже запущен», `Temporal\Testing` (time-skipping, `ActivityMocker`), replayer | читали Packagist и поисковую выдачу, исходники не открывали | `grep -rn` по `vendor/temporal/sdk/src` и `vendor/temporal/sdk/testing`; `https://github.com/temporalio/samples-php` (пример Saga), `https://docs.temporal.io/develop/php` | M6.1 |
| Q3 | Каким образом поднимать Temporal dev-сервер в Compose (`temporal server start-dev` из образа CLI или `temporalio/auto-setup` + Postgres) | образы не проверяли | `https://docs.temporal.io/self-hosted-guide`, Docker Hub `temporalio/*`, `docker compose up` | M1.1 |
| Q4 | Сроки поддержки Symfony 7.4 и 8.1 | не открывали `symfony.com/releases` | страница релизов Symfony; влияет только на выбор §7.1 | M1.1 |
| Q5 | Совместимость `temporal/sdk` 2.20 с выбранной версией Symfony и PHP 8.5 | `require` пакета проверен (`symfony/* ^6.4 \|\| ^7 \|\| ^8`, `php >=8.2`), установка не запускалась | `composer require temporal/sdk --dry-run` | M1.1 |
| Q6 | Поведение `#[MapRequestPayload]` при лишних полях (`allow_extra_attributes: false`), JSON-числе > `PHP_INT_MAX`, битом JSON, строке вместо int | зависит от версии Serializer | функциональные тесты T-F-20 фиксируют фактическое поведение; при расхождении с EC-64 — кастомный денормализатор | M5.1 |
| Q7 | Правила `QueryDepth`/`QueryComplexity` и DataLoader-паттерн в webonyx 15.39 | документацию не открывали | `vendor/webonyx/graphql-php/src/Validator/Rules`, `https://webonyx.github.io/graphql-php/` | M7.1 |
| Q8 | Синтаксис phpat 0.12 (селекторы, `shouldNotDependOn`, поддержка атрибутов-селекторов) | README не открывали | `https://github.com/carlosas/phpat`; при нехватке — deptrac | M1.3 |
| Q9 | Механизм переподключения DBAL 4 в долгоживущем процессе | не проверено | документация DBAL 4, `symfony/doctrine-bridge` middleware; тест T-I-43 | M6.4 |
| Q10 | Совместимость `phpstan/phpstan-symfony`, `-phpunit`, `-strict-rules` с PHPStan 2.3 | не проверено | `composer require --dev ... --dry-run` | M1.2 |

## Решение пользователя

| ID | Вопрос | Варианты | Рекомендация |
|---|---|---|---|
| U1 | Версия Symfony | 7.4 LTS / 8.1 | 8.1, если M1.1 ставит всё без конфликтов (вакансия называет 6/7/8, существующий `symfony-notification-api` на 8.0) |
| U2 | Мутации в GraphQL | только REST для команд / дублировать `placeOrder` в GraphQL | только REST: идемпотентность и Problem Details в одном месте; на интервью объяснить как осознанное разделение CQRS |
| U3 | Read model синхронно или асинхронно | асинхронная проекция через outbox (eventual, EC-54) / обновление в той же транзакции | асинхронно: это то, о чём спрашивают в CQRS; read-your-write закрыт снимком в ответе POST |
| U4 | Circuit breaker | свой / `ackintosh/ganesha` | свой, Ganesha упомянуть в README |
| U5 | Агрегаты | неизменяемые `final readonly` + DBAL / мутабельные с `private(set)` | неизменяемые; если в M2 окажется громоздко, переключиться и записать |
| U6 | Транспорт Messenger | Redis Streams / Doctrine transport (Postgres) | Redis: у Redis второе осмысленное применение, а дубли доставки покрыты inbox |
| U7 | Go-воркер M9 | делать / пропустить | решить после M8 по времени до интервью; Go в вакансии в стеке, но не в требованиях |
| U8 | Пересечение с `symfony-notification-api` и `nestjs-payments-api` | ссылаться из README / переиспользовать код | только ссылаться; содержимое сверить в M8.2 (ROADMAP требует не делать дублей) |
| U9 | Срок: всё до интервью или минимальный срез | полный M1–M8 / срез M1–M6 без GraphQL | если интервью раньше, чем готов M8, приоритет: M2, M3, M6 (сага и крайние случаи — главный предмет разговора) |

## Источники, использованные в M0

- Packagist metadata API, 2026-11-24: `https://repo.packagist.org/p2/{temporal/sdk, symfony/framework-bundle, webonyx/graphql-php, api-platform/core, overblog/graphql-bundle, phpat/phpat, deptrac/deptrac, phpstan/phpstan, spiral/roadrunner, ackintosh/ganesha, phpunit/phpunit, doctrine/orm, doctrine/doctrine-bundle, symfony/clock, symfony/uid, vanta/temporal-bundle, highcore/temporal-bundle}.json`
- Temporal PHP: `https://docs.temporal.io/develop/php/set-up-your-local-php`, `https://github.com/temporalio/samples-php`, `https://docs.temporal.io/php/booking-saga-tutorial` (только поисковая выдача, страницы целиком не прочитаны — отсюда Q2)
- Локальное окружение: `php -v` (8.5.4), `php -m` (нет grpc, protobuf, redis)

## Решения пользователя (2026-11-24)

- U1: Symfony **8.1** (если M1.1 ставит всё без конфликтов, иначе записать причину и откат на 7.4 LTS).
- U2: **без мутаций** в GraphQL, команды только через REST.
- U7: Go-воркер (M9) **делаем**.
- Остальные (U3–U6, U8, U9) приняты по рекомендации.
