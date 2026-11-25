# 05. Тестирование, правила PHPStan, критерии приёмки

## 1. Пирамида

| Уровень | Каталог | Инструмент | Внешние зависимости | Ориентир по количеству | Когда в CI |
|---|---|---|---|---|---|
| A: архитектура | `phpstan/` | PHPStan + phpat + свои правила | нет | ~10 правил | каждый push |
| U: unit domain | `tests/Unit/*/Domain` | PHPUnit 13 | нет, без моков | основная масса | каждый push |
| U: unit application | `tests/Unit/*/Application` | PHPUnit + in-memory фейки портов | нет | | каждый push |
| I: integration | `tests/Integration` | PHPUnit + реальные Postgres, Redis, эмулятор шлюза | Docker Compose services в CI | | каждый push |
| W: workflow | `tests/Workflow` | Temporal test server из PHP SDK (`Temporal\Testing`, проверить в M6.1) + фейковые активности | test server | | каждый push |
| F: functional | `tests/Functional` | Symfony `WebTestCase`/`KernelBrowser`, реальная БД | Postgres, Redis | | каждый push |
| E2E smoke | `tests/E2E` (скрипт) | `docker compose up` + HTTP-сценарий happy path и один отказ | весь стек | 2 сценария | на `main` |

PHPUnit выбран вместо Pest: атрибуты `#[DataProvider]`, `#[Group]`, меньше магии; Pest не даёт здесь выигрыша.

## 2. Что мокать и что нет

| Зависимость | В unit | В integration/functional | Почему |
|---|---|---|---|
| Доменные объекты, VO | никогда не мокать | — | чистые функции |
| Порты репозиториев | in-memory фейк (`InMemoryOrderRepository`), не mock | реальная DBAL-реализация | фейк проверяет поведение, mock проверяет вызовы |
| `PaymentGateway` | `FakePaymentGateway` со сценариями (`succeed`, `decline`, `timeout`, `timeoutThenSucceeded`, `currencyMismatch`) и счётчиком вызовов | `HttpPaymentGateway` против контейнера `gateway-emulator` | эмулятор проверяет реальные таймауты и обрезанные ответы |
| HTTP-клиент | — | `MockHttpClient` Symfony только для маппинга ответов в адаптере (T-I-18 может быть на нём, если эмулятор не воспроизводит обрыв) | «не мокай то, чем не владеешь»: в остальном — реальный эмулятор |
| Clock | `MockClock` (`symfony/clock`) | `MockClock` через переопределение сервиса в `test`-окружении | детерминизм |
| `IdGenerator` | `SequenceIdGenerator` | реальный | предсказуемые id в ассертах |
| Doctrine DBAL, Postgres | не используется | реальный Postgres 16+ (версию зафиксировать в M1) | `SKIP LOCKED`, partial index, CAS не эмулируются |
| Redis | `InMemoryCircuitStateStore` | реальный Redis | атомарность `SET NX PX` |
| Temporal | в unit не используется | test server, активности — реальные классы с фейковыми портами (или `ActivityMocker`) | проверяется оркестрация, не БД |
| Logger | `Monolog\Handler\TestHandler` | `TestHandler` | EC-80 |
| Mock-объекты PHPUnit (`createMock`) | только чтобы проверить «не вызвано» (`expects(never())`) | нет | |

Изоляция БД между тестами: транзакция с откатом там, где тест однопроцессный; для конкурентных тестов (T-I-01, T-I-13, T-I-15, T-I-35) — `TRUNCATE` перед тестом, потому что нужна видимость между соединениями.

## 3. Список тестов

Формат: ID — класс::метод (или группа) — что проверяет — строки `04_edge_cases.md`.

### 3.1 Unit: Domain

- T-U-01 `Shared\MoneyTest` — диапазон, отрицательные, ноль для `positive`, переполнение, разные валюты, `subtract` в минус — EC-64a/b/g, EC-65, EC-68.
- T-U-02 `Ordering\OrderPlaceTest::testCurrencyMismatch` — EC-66.
- T-U-03 `Ordering\OrderPlaceTest::testPriceChanged` — EC-67.
- T-U-04 `Ordering\OrderTransitionTest` — полная таблица 01_domain §3.2: для каждой пары (from, to) разрешено/запрещено (data provider генерирует все 10×10 пар) — INV-03.
- T-U-05 `OrderTest::testMarkPaidWithOtherPaymentRejected` — EC-13.
- T-U-06 `OrderCancellationTest::testCompletedNotCancellable` — EC-42.
- T-U-07 `OrderCancellationTest::testRepeatedRequestIsIdempotent` — EC-43.
- T-U-08 `OrderRefundTest::testStartRefundInsideWindow` — EC-59.
- T-U-09 `RefundWindowTest` — граница 14×86400 с — EC-60.
- T-U-10 `OrderRefundTest::testNotRefundableStatuses` — EC-62.
- T-U-11 `RefundWindowTest::testDstIndependent` — EC-75.
- T-U-12 `Inventory\KeyReservationTest` — переходы, граница `now == expiresAt`, идемпотентный `release`/`consume` — EC-20, EC-22.
- T-U-13 `Inventory\ProductKeyTest` — таблица переходов `KeyStatus`.
- T-U-14 `Payment\PaymentTest` — таблица переходов `PaymentStatus`, `Abandoned → Succeeded`, повтор `markSucceeded` — INV-06.
- T-U-15 `Shared\KeyCodeTest::testMaskedNeverExposesFullCode` — INV-17.
- T-U-16 `Ordering\OrderEventsTest` — каждый переход добавляет ровно одно событие нужного типа, идемпотентный повтор — ноль событий, версия растёт на 1.

### 3.2 Unit: Application (фейки портов)

- T-U-30 `ChargePaymentHandlerTest::testSucceededPaymentShortCircuits` — EC-10.
- T-U-31 `...::testExpiredReservationSkipsGateway` — EC-17.
- T-U-32 `ReleaseReservationHandlerTest::testConsumedReturnsFalseAndWarns` — EC-22.
- T-U-33 `ChargePaymentHandlerTest::testTimeoutMarksUnknownThenChecksStatus` — EC-24, EC-27.
- T-U-34 `...::testCircuitOpenKeepsPending` — EC-32.
- T-U-35 `...::testCurrencyMismatchInGatewayResponse` — EC-36.
- T-U-36 `...::testAmountMismatchInGatewayResponse` — EC-37.
- T-U-37 `RefundPaymentHandlerTest` — по статусам (`Succeeded`, `RefundPending`, `Unknown`+найден, `Unknown`+не найден, `Declined`, нет платежа) — EC-45, EC-49.
- T-U-38 `RevokeSoldKeyHandlerTest::testAlreadyRevokedIsSuccess` — EC-63.
- T-U-39 `ChargePaymentHandlerTest::testAmountComesFromOrder` — EC-81.
- T-U-40 `CircuitBreakerTest::testDeclineIsNotFailure` — EC-29.
- T-U-41 `CircuitBreakerTest::testOpensAfterThreshold` — EC-31.
- T-U-42 `CircuitBreakerTest::testHalfOpenSingleProbe` (успех → Closed, неудача → Open) — EC-33.
- T-U-43 `CircuitBreakerTest::testStoreFailureDegradesToClosed` — EC-35.
- T-U-44 `PlaceOrderHandlerTest` — товар не найден/неактивен, событие `OrderPlaced` в репозитории — EC-69.
- T-U-45 `CancelOrderHandlerTest` — чужой заказ = `OrderNotFound`, `ConcurrencyConflict` → 3 повтора — EC-70, EC-79.

### 3.3 Integration (реальная инфраструктура)

- T-I-01 `IdempotencyStoreConcurrencyTest::testOnlyOneInsertWins` — EC-02.
- T-I-02 `IdempotencyStoreTest::testExpiredKeyIsReplaced` — EC-06.
- T-I-03 `IdempotencyStoreTest::testStaleInProgressIsReplaced` — EC-07b.
- T-I-10 `ChargePaymentIdempotencyTest::testRetryAfterCrashDoesNotDoubleCharge` — EC-09.
- T-I-11 `DbalPaymentRepositoryTest::testConcurrentInsertOrGet` — EC-12.
- T-I-12 `DbalKeyInventoryTest::testOutOfStock` — EC-14.
- T-I-13 `DbalKeyInventoryConcurrencyTest::testLastKeyReservedOnce` (50 итераций) — EC-15.
- T-I-14 `ReservationExpiryFlowTest` — EC-16.
- T-I-15 `ReservationCasTest::testSweeperVsConsume` — EC-19a.
- T-I-16 `ReserveKeyIdempotencyTest` — EC-23.
- T-I-17 `HttpPaymentGatewayTest::testTimeoutRaisesOutcomeUnknown` — EC-24.
- T-I-18 `HttpPaymentGatewayTest::testMalformedResponses` (data provider: обрезанный JSON, нет `chargeId`, не JSON, обрыв, неизвестный статус) — EC-25, EC-30.
- T-I-19 `HttpPaymentGatewayTest::testAcceptThen502ThenFindCharge` — EC-26.
- T-I-20 `StartOrderSagaConsumerTest` — дубль старта = успех; Temporal недоступен → исключение для повтора Messenger — EC-11, EC-58.
- T-I-21 `ReconcilePaymentsTest::testLateSuccessIsRefunded` — EC-28.
- T-I-22 `RedisCircuitStateStoreTest::testSingleProbeAcrossProcesses` — EC-33.
- T-I-23 `CancelOrderTemporalDownTest` — EC-44.
- T-I-24 `RefundRetryTest::testRefundExecutedOnceAcrossRetries` — EC-45.
- T-I-25 `RefundRetryTest::testCrashAfterRefundBeforeSave` — EC-46.
- T-I-30 `OutboxRelayTest::testUnpublishedMessageIsSentAfterRestart` — EC-50.
- T-I-31 `TransactionalOutboxTest::testRollbackLeavesNoMessage` — EC-51.
- T-I-32 `OutboxRelayTest::testCrashBeforeMarkPublishedCausesDuplicate` — EC-52.
- T-I-33 `InboxTest::testDuplicateMessageIgnored` — EC-53.
- T-I-34 `OrderProjectionTest::testStaleVersionIgnored` — EC-55.
- T-I-35 `OutboxRelayConcurrencyTest` — EC-56.
- T-I-36 `MessengerFailureTransportTest` — EC-57.
- T-I-40 `TimezoneIndependenceTest` (отдельный процесс PHPUnit с `TZ=Europe/Kyiv`) — EC-74.
- T-I-41 `OrderConcurrencyTest` — EC-79.
- T-I-42 `KeyCodeLeakTest` — EC-80.
- T-I-43 `WorkerDbReconnectTest` (`@group slow`) — EC-85.
- T-CFG-01 `SagaConfigurationTest::testTtlMustExceedStepDeadlines` — EC-21.

### 3.4 Workflow (Temporal test server)

- T-W-01 дубль старта — EC-11.
- T-W-02 `OutOfStock` на первом шаге, компенсаций нет — EC-14, EC-48.
- T-W-03 `ReservationExpired` на consume → refund → `Failed` — EC-16.
- T-W-04 `KeyRevoked` — EC-18.
- T-W-05 таймаут, затем успех при повторе — EC-27.
- T-W-06 decline — EC-29.
- T-W-07 breaker открыт весь шаг (пропуск времени) — EC-34.
- T-W-08 currency mismatch — EC-36.
- T-W-09 отмена до старта саги — EC-38.
- T-W-10 отмена после резерва — EC-39.
- T-W-11 отмена во время списания — EC-40.
- T-W-12 отмена после `ConsumeReservation` игнорируется — EC-41.
- T-W-13 двойная отмена — EC-43.
- T-W-14 повтор компенсации — EC-45.
- T-W-15 компенсация не удаётся → `ManualReview` — EC-47.
- T-W-16 `RefundWorkflow` happy path — EC-59.
- T-W-17 двойной `RefundWorkflow` — EC-61.
- T-W-18 happy path: порядок активностей `Reserve → MarkReserved → Charge → MarkPaid → Consume → Complete`.
- T-W-20 replay-тест по сохранённой JSON-истории (детерминизм, `getVersion`) — EC-78. Если в PHP SDK нет `WorkflowReplayer`, заменить на запуск воркера против истории; проверить в M6.1.

### 3.5 Functional (HTTP, GraphQL)

- T-F-01 повтор с тем же ключом и телом — EC-01.
- T-F-02 тот же ключ, другое тело — EC-03.
- T-F-03 нет/неверный ключ (data provider) — EC-04.
- T-F-04 один ключ у разных покупателей — EC-05.
- T-F-05 500 удаляет ключ, повтор выполняется — EC-07.
- T-F-06 сохранённая бизнес-ошибка воспроизводится — EC-08.
- T-F-10 отмена выполненного — EC-42.
- T-F-11 POST-ответ содержит снимок — EC-54.
- T-F-12 двойной возврат — EC-61.
- T-F-20 некорректный ввод (data provider a–k) — EC-64a…k.
- T-F-21 расхождение валют — EC-66.
- T-F-22 цена изменилась — EC-67.
- T-F-23 неизвестный/неактивный товар — EC-69.
- T-F-24 чужой заказ (REST и GraphQL) — EC-70.
- T-F-25 нет покупателя — EC-71.
- T-F-26 пагинация — EC-72, EC-73.
- T-F-27 формат времени — EC-76.
- T-F-30 GraphQL лимиты — EC-82.
- T-F-31 GraphQL `keyCode` — EC-83.
- T-F-32 GraphQL N+1 — EC-84.

Проверка покрытия: скрипт `bin/check-edge-case-coverage` (M8) парсит `04_edge_cases.md`, собирает все `T-*` и ищет их в `tests/` (атрибут `#[Group('T-U-01')]` на тесте или docblock). Каждый EC без найденного теста → ненулевой код выхода.

## 4. Правила PHPStan

### 4.1 Инструмент для границ слоёв: сравнение

| | phpat 0.12.5 | deptrac 4.7.2 | Собственное правило PHPStan |
|---|---|---|---|
| Запуск | расширение PHPStan, ошибки в том же отчёте | отдельный бинарь и отчёт | PHPStan |
| Описание правил | PHP-класс с методами-правилами | YAML: слои по селекторам + ruleset | PHP-класс `Rule<Node>` |
| Модули × слои (3×3 + Shared) | селекторы по namespace, комбинируются | хорошо, есть граф | писать самим |
| Выразительность (например «workflow не вызывает `time()`») | только зависимости классов | только зависимости | любая |
| Стоимость | низкая | низкая, но второй инструмент в CI | средняя |

**Решение:** phpat для зависимостей между слоями и модулями (одна команда `vendor/bin/phpstan analyse`, всё в PHPStan max) + **собственные правила PHPStan** для того, что зависимостями не выражается. Это заодно закрывает потребность в собственных правилах PHPStan. Deptrac — альтернатива, если phpat не поддержит нужный селектор; синтаксис API phpat (`PHPat::rule()`, `Selector::inNamespace`, `shouldNotDependOn`) проверить в M1 по README `carlosas/phpat`.

### 4.2 Правила зависимостей (phpat)

| ID | Правило |
|---|---|
| T-ARCH-01 | `*\Domain` не зависит от `*\Application`, `*\Infrastructure`, `Symfony\*`, `Doctrine\*`, `Temporal\*`, `Psr\*`. Разрешено: PHP core, `App\Shared\Domain` |
| T-ARCH-02 | `Ordering\{Domain,Application}` не зависит от `Inventory\*` и `Payment\*`; аналогично для каждой пары модулей. Межмодульное — только в `Infrastructure` и `Saga` |
| T-ARCH-02b | `*\Application` не зависит от `*\Infrastructure`, `Symfony\*`, `Doctrine\*`, `Temporal\*`. Разрешено: Domain, `Psr\Clock`, `Psr\Log` |
| T-ARCH-04 | Миграции: ни одного `FOREIGN KEY` между таблицами разных модулей (тест парсит SQL миграций по карте «таблица → модуль» из 00_overview §5.2) |

### 4.3 Собственные правила PHPStan (`phpstan/Rules`, каждое с тестом на `PHPStan\Testing\RuleTestCase`)

| ID | Класс | Что запрещает | Где действует |
|---|---|---|---|
| T-ARCH-03 | `DomainNoFrameworkAttributesRule` | атрибуты из `Symfony\Component\Validator\Constraints`, `Symfony\Component\Serializer\Attribute`, `Doctrine\ORM\Mapping`, `Symfony\Component\Messenger\Attribute` | `*\Domain`, `*\Application` |
| T-ARCH-05 | `NoFloatInDomainRule` | тип `float` в параметрах, свойствах, возвращаемых значениях; литералы float в арифметике с `Money` | `*\Domain`, `*\Application` |
| T-ARCH-06 | `StrictTypesDeclaredRule` (или PHP-CS-Fixer `declare_strict_types` в CI) | файл без `declare(strict_types=1)` | весь `src/`, `phpstan/`, `tests/` |
| T-ARCH-07 | `NoSwitchRule` | `switch` | весь `src/` |
| T-ARCH-08 | `NoSystemClockRule` | `time()`, `date()`, `microtime()`, `hrtime()`, `new DateTimeImmutable()` / `new DateTime()` без аргументов или с `'now'`, `date_create()` | везде, кроме `*\Infrastructure\Clock` |
| T-ARCH-09 | `WorkflowDeterminismRule` | в классах с атрибутом `#[WorkflowInterface]` (имя проверить) и их namespace: `rand`, `random_int`, `mt_rand`, `uniqid`, `sleep`, `usleep`, `Ramsey\Uuid\Uuid::uuid*`, `Symfony\Component\Uid\*::v*`, любой `Doctrine\*`, `Symfony\Contracts\HttpClient\*`, `PDO`, `file_*`, `getenv`, статические свойства | `Saga\Infrastructure\Temporal\Workflow` |
| T-ARCH-10 | `ReadonlyValueObjectRule` | класс в `*\Domain` или DTO (`*Command`, `*Query`, `*Output`, `*Request`), не объявленный `final readonly` (исключения — список в конфиге правила) | Domain, Application, Http\Request |

Конфиг: `phpstan.neon` — `level: max`, `paths: [src, phpstan, tests]`, `checkUninitializedProperties: true`, без `baseline`, без `ignoreErrors`. Расширения: `phpstan/phpstan-symfony`, `phpstan/phpstan-phpunit`, `phpstan/phpstan-strict-rules` (наличие и совместимость с PHPStan 2.3 проверить в M1 через `composer require --dry-run`).

## 5. Критерии приёмки проекта

1. `make ci` (= `composer validate --strict`, PHP-CS-Fixer `--dry-run`, `phpstan analyse`, `phpunit` все наборы) проходит локально и в GitHub Actions на PHP 8.4 и 8.5.
2. PHPStan `level: max`, 0 ошибок, нет `baseline` и `ignoreErrors` (проверка: `grep -E "ignoreErrors|baseline" phpstan.neon` пусто).
3. Каждое правило из §4 имеет `RuleTestCase` с минимум одним нарушающим и одним корректным примером.
4. `bin/check-edge-case-coverage` — 0 непокрытых EC.
5. Конкурентные тесты (T-I-01, T-I-13, T-I-15, T-I-35) стабильны: 20 прогонов подряд без падений (`for i in $(seq 20); do vendor/bin/phpunit --group concurrency || exit 1; done`).
6. `docker compose up` + `tests/E2E/smoke.sh`: заказ проходит до `Completed`, `GET /api/orders/{id}` возвращает `keyCode`; сценарий с эмулятором в режиме `decline` заканчивается `Failed`, ключ снова `Available`.
7. README: слова «пет-проект, проверен локально и в CI, не production»; схема слоёв; раздел «чего нет». Нет нагрузочных цифр.
