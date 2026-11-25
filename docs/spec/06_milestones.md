# 06. Этапы и задачи для исполнителей этапов

## Общие правила для каждой задачи (вставлять в промпт исполнителя этапа)

- Прочитать только перечисленные входные файлы спецификации и указанные исходники; не перечитывать весь репозиторий.
- Соблюдать `00_overview.md` §8 (правила кода). PHPStan max без `ignoreErrors`.
- В конце: запустить команду проверки, записать отчёт `docs/reports/<ID>.md` (сделано / не сделано / отклонения от спеки с причиной / что проверить вручную). Не коммитить (коммитит основная сессия). Без AI-атрибуции.
- Если спецификация противоречит факту (API библиотеки другое), не выдумывать: сделать ближайшее по смыслу, описать в отчёте раздел «Отклонения».
- Не трогать файлы вне списка «На выходе», кроме `composer.json`/`composer.lock`/`config/services.yaml`, когда это необходимо (указать в отчёте).

Проверочные команды предполагают `Makefile` из M1.1: `make phpstan`, `make test-unit`, `make test-int`, `make test-wf`, `make test-func`, `make ci`.

---

## M1. Каркас

### M1.1 Скелет Symfony и Docker Compose
- **Цель:** пустое Symfony-приложение с контейнерами `php` (8.5, расширения `pdo_pgsql`, `redis`, `intl`, `opcache`), `nginx`, `postgres`, `redis`, `temporal` (dev-сервер), `gateway-emulator` (заглушка `/health`).
- **Вход:** `00_overview.md` (§5.3, §7, §7.1, §7.3), `07_open_questions.md` Q1–Q3.
- **На выходе:** `composer.json` (`php >=8.4`, версия Symfony по §7.1 после `composer require` без конфликтов), `compose.yaml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, `Makefile`, `.env`, `src/Kernel.php`, каталоги из §5.3 с `.gitkeep`, `docs/reports/M1.1.md` с фактическими версиями (`composer show --direct`) и выбранным образом Temporal.
- **Проверка:** `docker compose up -d && docker compose exec php bin/console about && curl -fsS localhost:8080/health` (через ctx-инструменты), `docker compose exec php php -m | grep -E "pdo_pgsql|redis"`.
- **Не делать:** ext-grpc и RoadRunner (это M6.1), бизнес-код, ORM.

### M1.2 Качество: PHPStan max, CS, PHPUnit
- **Цель:** настроенные инструменты, пустые наборы тестов проходят.
- **Вход:** `05_testing.md` §1, §4.3 (только конфиг), §5.
- **На выходе:** `phpstan.neon`, `.php-cs-fixer.dist.php` (`@PER-CS`, `declare_strict_types`, `strict_param`), `phpunit.dist.xml` с наборами `unit`, `integration`, `workflow`, `functional`, группа `concurrency`; цели `Makefile`.
- **Проверка:** `make phpstan && make test-unit`.
- **Не делать:** собственные правила PHPStan (M1.3), baseline.

### M1.3 Правила слоёв (phpat) и каркас собственных правил
- **Цель:** T-ARCH-01, -02, -02b через phpat; правила T-ARCH-06, -07, -08 с `RuleTestCase`.
- **Вход:** `05_testing.md` §4; `00_overview.md` §5.
- **На выходе:** `phpstan/Architecture/LayerRulesTest.php` (phpat), `phpstan/Rules/{StrictTypesDeclaredRule,NoSwitchRule,NoSystemClockRule}.php`, тесты в `tests/Unit/PhpStan/`, регистрация в `phpstan.neon`; временные фикстуры-нарушители в `tests/Fixtures/PhpStan/` (исключены из основного анализа).
- **Проверка:** `make phpstan && vendor/bin/phpunit tests/Unit/PhpStan`. Ручная: положить `use Doctrine\DBAL\Connection;` в класс `src/Ordering/Domain/` → `make phpstan` падает; удалить.
- **Не делать:** правила T-ARCH-03/05/09/10 (M2.5, M6.2).

### M1.4 CI
- **Цель:** GitHub Actions: матрица PHP 8.4/8.5, сервисы Postgres и Redis, `make ci`.
- **Вход:** `05_testing.md` §5.
- **На выходе:** `.github/workflows/ci.yml`.
- **Проверка:** `act` недоступен → проверка YAML `docker run --rm -v $PWD:/repo rhysd/actionlint` (или прогон после push основной сессией).
- **Не делать:** деплой, публикацию образов.

## M2. Domain

### M2.1 Shared kernel
- **Цель:** `Currency`, `Money`, идентификаторы, `KeyCode`, `DomainError`, `DomainErrorCode`, `DomainEvent`, `FailureReason`, `RefundWindow`.
- **Вход:** `01_domain.md` §2, §6 (интерфейс события), `02_use_cases.md` §6 (коды).
- **На выходе:** `src/Shared/Domain/**`, `tests/Unit/Shared/Domain/**` (T-U-01, T-U-09, T-U-11, T-U-15).
- **Проверка:** `make phpstan && vendor/bin/phpunit --testsuite unit --filter 'Shared'`.
- **Не делать:** Symfony/Doctrine-зависимости, генерацию UUID внутри домена.

### M2.2 Ordering: `Order`
- **Цель:** агрегат, `OrderStatus`, таблица переходов, события.
- **Вход:** `01_domain.md` §1, §3, §6, §7; `04_edge_cases.md` строки EC-13, EC-42, EC-43, EC-59…EC-62, EC-66, EC-67.
- **На выходе:** `src/Ordering/Domain/**`, тесты T-U-02…T-U-08, T-U-10, T-U-16.
- **Проверка:** `make phpstan && vendor/bin/phpunit --filter 'Ordering\\\\.*Domain'`.
- **Не делать:** репозитории, сохранение, Clock (время приходит аргументом).

### M2.3 Inventory: `Product`, `ProductKey`, `KeyReservation`
- **Вход:** `01_domain.md` §4; EC-20, EC-22.
- **На выходе:** `src/Inventory/Domain/**`, T-U-12, T-U-13.
- **Проверка:** `make phpstan && vendor/bin/phpunit --filter Inventory`.
- **Не делать:** SQL, sweeper.

### M2.4 Payment: `Payment`
- **Вход:** `01_domain.md` §5; EC-36, EC-37, EC-49.
- **На выходе:** `src/Payment/Domain/**`, T-U-14.
- **Проверка:** `make phpstan && vendor/bin/phpunit --filter 'Payment\\\\.*Domain'`.
- **Не делать:** шлюз, breaker.

### M2.5 Правила PHPStan для домена
- **Цель:** T-ARCH-03, T-ARCH-05, T-ARCH-10 с `RuleTestCase`.
- **Вход:** `05_testing.md` §4.3.
- **На выходе:** `phpstan/Rules/{DomainNoFrameworkAttributesRule,NoFloatInDomainRule,ReadonlyValueObjectRule}.php` + тесты.
- **Проверка:** `make phpstan && vendor/bin/phpunit tests/Unit/PhpStan`.
- **Не делать:** править домен ради обхода правила без записи в отчёт.

## M3. Application

### M3.1 Порты, ошибки, фейки
- **Вход:** `02_use_cases.md` §6, §7.
- **На выходе:** `src/*/Application/Port/*.php`, application-исключения, `tests/Support/Fake/*` (`InMemory*Repository`, `FakePaymentGateway` со сценариями, `SequenceIdGenerator`, `NoopTransactionManager`).
- **Проверка:** `make phpstan`.
- **Не делать:** реализации на DBAL/HTTP.

### M3.2 HTTP-команды: `PlaceOrder`, `CancelOrder`, `RequestRefund`
- **Вход:** `02_use_cases.md` §0, §1; EC-66, EC-67, EC-69, EC-70, EC-79.
- **На выходе:** `src/Ordering/Application/Command/{PlaceOrder,CancelOrder,RequestRefund}/*`, T-U-44, T-U-45.
- **Проверка:** `make phpstan && vendor/bin/phpunit --filter 'PlaceOrderHandler|CancelOrderHandler|RequestRefund'`.
- **Не делать:** контроллеры, Messenger-конфиг.

### M3.3 Команды саги
- **Предварительно (решение автора 2026-11-24, см. `01_domain.md` §5 последний пункт):** в `Payment` добавить переход `markCapturedWithMismatch(chargeId, MismatchReason)` из `Pending`/`Unknown`/`Abandoned` в `RefundPending`, событие `PaymentCapturedWithMismatch`, обновить матрицу переходов в тестах M2.4 (новые допустимые пары, остальные остаются недопустимыми), добавить тесты T-U-35, T-U-36 на уровне домена и на уровне handler. Затем handlers команд.
- **Вход:** `02_use_cases.md` §2; `03_saga.md` §4, §6; EC-10, EC-17, EC-22…EC-24, EC-27, EC-32, EC-36, EC-37, EC-45, EC-49, EC-63, EC-81.
- **На выходе:** handlers всех команд из §2 в своих модулях; T-U-30…T-U-39.
- **Проверка:** `make phpstan && make test-unit`.
- **Не делать:** Temporal-код; межмодульные импорты (только через порты).

### M3.4 Обслуживание и запросы
- **Вход:** `02_use_cases.md` §3, §4.
- **На выходе:** `ExpireReservations`, `ReconcilePayments`, `AddProductKeys`, `RevokeKey`, `GetOrder`, `ListCustomerOrders`, `GetProductAvailability` (handlers + Output + порты read model); unit-тесты на фейках.
- **Проверка:** `make phpstan && make test-unit`.
- **Не делать:** SQL read model (M4.6).

## M4. Infrastructure

### M4.1 Схема БД и миграции
- **Вход:** `00_overview.md` §5.2, §7.4, §7.5; `01_domain.md` (поля); `02_use_cases.md` §5 (idempotency).
- **На выходе:** `migrations/Version*.php`: все таблицы, partial unique indexes (INV-04, INV-05), unique `payments(order_id)`, `outbox_messages`, `inbox_messages`, `idempotency_keys`, `order_view`; T-ARCH-04.
- **Проверка:** `docker compose exec php bin/console doctrine:migrations:migrate -n && ... migrate first -n && ... migrate -n` (вверх-вниз-вверх) + `vendor/bin/phpunit --filter MigrationForeignKeyTest`.
- **Не делать:** межмодульные FK, ORM-маппинг.

### M4.2 DBAL-репозитории и транзакции
- **Вход:** `01_domain.md` §1; `02_use_cases.md` §7; EC-12, EC-14, EC-15, EC-19a, EC-23, EC-79.
- **На выходе:** `Dbal*Repository`, `DbalKeyInventory` (`FOR UPDATE SKIP LOCKED`), `DbalTransactionManager`, CAS-обновления; T-I-11…T-I-16, T-I-41.
- **Проверка:** `make test-int` и `for i in $(seq 20); do vendor/bin/phpunit --group concurrency || exit 1; done`.
- **Не делать:** outbox (M4.3).

### M4.3 Outbox, relay, inbox, Messenger
- **Вход:** `00_overview.md` §7.5; EC-50…EC-53, EC-56, EC-57.
- **На выходе:** запись событий в `save`, нормализаторы событий, `OutboxRelayCommand`, Messenger-транспорт Redis, `InboxMiddleware`/декоратор потребителя, конфиг повторов и failure transport; T-I-30…T-I-33, T-I-35, T-I-36.
- **Проверка:** `make test-int`.
- **Не делать:** старт Temporal из потребителя (M6.2), проекцию (M4.6).

### M4.4 Эмулятор платёжного шлюза
- **Цель:** отдельный сервис `gateway-emulator` (PHP без Symfony, `php -S` или минимальный роутер), хранение в SQLite/файле.
- **API:** `POST /charges` (`Idempotency-Key`), `GET /charges/{idempotencyKey}`, `POST /refunds` (`Idempotency-Key`), `POST /__admin/mode` (режимы: `ok`, `decline`, `delay:<ms>`, `http500`, `http503:<n раз>`, `accept-then-502`, `truncated-json`, `no-charge-id`, `wrong-currency`, `wrong-amount`, `drop-connection`, `unknown-status`, `late-success:<ms>`), `GET /__admin/stats` (число списаний/возвратов по ключу).
- **Вход:** EC-24…EC-30, EC-36, EC-37, EC-45, EC-46.
- **На выходе:** `gateway-emulator/**`, `gateway-emulator/README.md` (только API), сервис в `compose.yaml`, свои тесты эмулятора.
- **Проверка:** `docker compose up -d gateway-emulator && vendor/bin/phpunit gateway-emulator/tests`.
- **Не делать:** зависимость эмулятора от `src/`.

### M4.5 HTTP-адаптер шлюза и circuit breaker
- **Вход:** `00_overview.md` §7.7; `02_use_cases.md` §2 (ChargePayment), §7 (`PaymentGateway`); EC-24…EC-26, EC-29…EC-35.
- **На выходе:** `HttpPaymentGateway` (Symfony HttpClient, timeout 10 с), `CircuitBreaker`, `CircuitBreakingPaymentGateway`, `RedisCircuitStateStore`, `InMemoryCircuitStateStore`; T-U-40…T-U-43, T-I-17…T-I-19, T-I-22.
- **Проверка:** `make test-unit && make test-int`.
- **Не делать:** повторы внутри адаптера (повторяет Temporal).

### M4.6 Read model и сверка
- **Вход:** `02_use_cases.md` §3, §4; EC-28, EC-55.
- **На выходе:** `OrderProjection` (потребитель событий, проверка версии), `DbalOrderReadModel`, `DbalProductReadModel`, консольные команды `ExpireReservations`/`ReconcilePayments` + Symfony Scheduler; T-I-21, T-I-34.
- **Проверка:** `make test-int`.

## M5. Входящий HTTP-слой

### M5.1 Контроллеры, Request DTO, ошибки
- **Вход:** `02_use_cases.md` §0, §1, §4, §6; EC-42, EC-54, EC-64a…k, EC-66…EC-73, EC-76.
- **На выходе:** контроллеры, `*Request` с Assert, резолвер `CustomerId`, нормализаторы Output, `ExceptionToProblemListener` (`match` по коду); T-F-10, T-F-11, T-F-20…T-F-27.
- **Проверка:** `make test-func`. Записать в отчёт фактическое поведение EC-64j и EC-64i.
- **Не делать:** идемпотентность (M5.2), GraphQL.

### M5.2 Идемпотентный ключ
- **Вход:** `02_use_cases.md` §5; EC-01…EC-08.
- **На выходе:** `IdempotencyStore` (DBAL), listener/декоратор, T-F-01…T-F-06, T-I-01…T-I-03.
- **Проверка:** `make test-func && make test-int`.

## M6. Temporal (Opus уточняет спеку M6 после M6.1, если факты API расходятся)

### M6.1 Спайк: RoadRunner + PHP SDK + ext-grpc
- **Цель:** подтвердить факты из `03_saga.md` (вступление) и `00_overview.md` §7.3; hello-world workflow с одной активностью, вызывающей Symfony-сервис.
- **Вход:** `00_overview.md` §7.3, `03_saga.md` §1, §7; `07_open_questions.md` Q1, Q2, Q5.
- **На выходе:** `docker/php/Dockerfile` (ext-grpc, protobuf), `.rr.yaml`, `bin/temporal-worker.php`, сервис `temporal-worker` в Compose, `TemporalClientFactory`; `docs/reports/M6.1.md`: точные имена API (`Saga`, `ActivityOptions`, `RetryOptions`, reuse policy, `Temporal\Testing`, replayer, исключение «уже запущен»), время сборки образа, найденные расхождения.
- **Проверка:** `docker compose up -d temporal temporal-worker && docker compose exec php bin/console app:temporal:hello` возвращает результат; `make phpstan`.
- **Не делать:** бизнес-workflow. Если ext-grpc не собирается под 8.5 за разумное время — собрать на 8.4 и записать (Q1).

### M6.2 `OrderFulfillmentWorkflow` и активности
- **Вход:** `03_saga.md` §2–§4, §6; `02_use_cases.md` §2; отчёт M6.1.
- **На выходе:** интерфейсы и классы активностей (тонкие, вызывают command bus), workflow, опции из конфига, `TemporalOrderSagaStarter`, потребитель `StartOrderSagaOnOrderPlaced`, `SagaConfigurationTest` (T-CFG-01), правило `WorkflowDeterminismRule` (T-ARCH-09); T-W-01…T-W-08, T-W-18, T-I-20.
- **Проверка:** `make phpstan && make test-wf && make test-int`.
- **Не делать:** отмену и `RefundWorkflow` (M6.3).

### M6.3 Отмена, компенсации, возврат
- **Вход:** `03_saga.md` §4, §5, §7; EC-38…EC-47, EC-59, EC-61.
- **На выходе:** сигнал `requestCancellation`, guard, `TemporalOrderSagaSignals`, `RefundWorkflow`, `ManualReview`; T-W-09…T-W-17, T-I-23…T-I-25.
- **Проверка:** `make test-wf && make test-int`.

### M6.4 Устойчивость воркера
- **Вход:** `03_saga.md` §7; EC-85, EC-78.
- **На выходе:** переподключение DBAL и сброс сервисов между активностями; сохранённая история happy path в `tests/Workflow/Fixtures/history-happy.json`; T-W-20, T-I-43.
- **Проверка:** `make test-wf`; ручная: `docker compose restart postgres` во время сценария, заказ доходит до `Completed`.

## M7. GraphQL

### M7.1 Схема и резолверы
- **Вход:** `00_overview.md` §7.2; `02_use_cases.md` §4; EC-70, EC-83, EC-84.
- **На выходе:** `config/graphql/schema.graphql` (`Query { order(id: ID!): Order, myOrders(status, limit, cursor): OrderPage!, product(id: ID!): Product }`), резолверы → `query.bus`, батч-загрузка продуктов, `GraphQLController` (`POST /graphql`), маппинг ошибок в `extensions.code`; T-F-24 (GraphQL-часть), T-F-31, T-F-32.
- **Проверка:** `make test-func && make phpstan`.
- **Не делать:** мутации (см. U2).

### M7.2 Лимиты и безопасность
- **Вход:** EC-82.
- **На выходе:** глубина 8, сложность 200, отключение introspection в `prod`; T-F-30.
- **Проверка:** `make test-func`.

## M8. Крайние случаи и документация

### M8.1 Покрытие таблицы крайних случаев
- **Цель:** все EC имеют тесты; скрипт проверки.
- **Вход:** `04_edge_cases.md`, `05_testing.md` §3; список уже существующих тестов (`grep -rn "#\[Group('T-" tests`).
- **На выходе:** недостающие тесты, `bin/check-edge-case-coverage`, T-I-40, T-I-42.
- **Проверка:** `bin/check-edge-case-coverage && make ci`.
- **Не делать:** менять ожидаемое поведение в `04_edge_cases.md` без пометки в отчёте.

### M8.2 E2E smoke и README
- **Вход:** `05_testing.md` §5 п.6–7; `00_overview.md` §2, §5.
- **На выходе:** `tests/E2E/smoke.sh`, `README.md` (что это, «пет-проект, проверен локально и в CI, не production», схема слоёв, запуск, ссылки на спецификацию, раздел «чего нет»), ссылки на `symfony-notification-api`, `nestjs-payments-api` при пересечении.
- **Проверка:** `docker compose up -d && tests/E2E/smoke.sh`; `grep -iE "rps|requests per second|production-ready|в продакшене" README.md` пусто.

## M9. Go-воркер (сделан по `09_go_worker.md`, отчёты `docs/reports/M9.1.md`…`M9.5.md`)

Фактически вместо переноса `ChargePayment` на другую очередь добавлена новая активность `InspectGatewayCharge` (Go, только чтение), спецификация `docs/spec/09_go_worker.md`. Каталог проекта `worker-go/` (не `go-worker/`), спецификация не `08_go_worker.md`.

- M9.0 спецификация `09_go_worker.md` (Opus).
- M9.1 контракт `contracts/inspect-gateway-charge/v1/`, golden-файлы, PHP-маппинг, фикстура `history-charge-exhausted-v0.json` (T-M9-01…04, T-W-21).
- M9.2 Go-ядро: use case, домен, HTTP-адаптер, circuit breaker; `make go-test` (T-GO-01…08, 12…16).
- M9.3 Go-воркер, Dockerfile, профиль compose `go`, интеграционные тесты `make go-test-int` (T-GO-05, 09…11, 17, 20…26).
- M9.4 ветка в саге под `getVersion` и флагом, `PrepareChargeInspection`, фикстура `history-charge-inspected-v1.json`, мутационная таблица (T-M9-04…06, T-W-22…32).
- M9.5 сквозные тесты `make test-go-e2e` (T-M9-10…13, вне `make ci`), EC-93…EC-111 в `04_edge_cases.md`, сканер Go-тестов в `bin/check-edge-case-coverage`, документация.

Не закрыто (честно): replay не сравнивает имя очереди Go-воркера (Q-M9-2), опечатку в нём ловят T-M9-05 и сквозные тесты; ветка проверена только на dev-сервере Temporal; флаг выключен по умолчанию.

## M10. Итоговый набор материалов (основная сессия)
Схема, 5 решений с обоснованием (модульный монолит vs сервисы; неизменяемые агрегаты + DBAL; сигнал отмены vs Temporal cancel; outbox для старта саги; свой breaker + обработка неизвестного исхода оплаты), список «чего нет» (`00_overview.md` §2). Не исполнитель этапа.
