# 02. Use cases, DTO, ошибки, порты

## 0. Соглашения

- Use case = `XxxCommand` или `XxxQuery` (Input DTO application-слоя) + `XxxHandler` + `XxxOutput`. Все `final readonly class`, поля только примитивы, enum или VO из `Shared\Domain`. Без атрибутов.
- Handler: один публичный метод `__invoke(XxxCommand $c): XxxOutput`. Транзакция открывается через порт `TransactionManager::transactional(callable)`.
- Регистрация в Symfony Messenger (шины `command.bus`, `query.bus`, синхронные) делается в `config/services.yaml` через `_instanceof` по интерфейсам `CommandHandler`/`QueryHandler` (тег `messenger.message_handler` с `bus`), а не атрибутом `#[AsMessageHandler]`, чтобы application не зависел от Symfony.
- Слой HTTP: `XxxRequest` (Infrastructure\Http\Request, `Assert`-атрибуты) → маппер → `XxxCommand`. Ответ: `XxxOutput` → Serializer (нормализатор в infrastructure) → JSON. Временные поля: ISO-8601 в UTC, формат `Y-m-d\TH:i:s.vP` с `+00:00`.
- Валидация в два уровня: формат на границе (Assert, 422 с перечнем полей), бизнес-правила в домене (типизированные исключения → маппинг кода ошибки в HTTP).
- Покупатель: заголовок `X-Customer-Id` (UUID), резолвится аргумент-резолвером в `CustomerId`. Нет или неверный → 401 `CUSTOMER_REQUIRED`.

## 1. Команды, доступные по HTTP

### 1.1 `PlaceOrder` — `POST /api/orders`

Заголовки: `X-Customer-Id`, `Idempotency-Key` (обязателен).

`PlaceOrderRequest` (HTTP, `#[MapRequestPayload]` с контекстом `allow_extra_attributes: false`; поведение контекста проверить в M5, тест EC-64i):

| Поле | Тип | Assert |
|---|---|---|
| `productId` | `string` | `NotBlank`, `Uuid` |
| `expectedPrice.amount` | `int` | `NotNull`, `Type('int')`, `Positive`, `LessThanOrEqual(100_000_000)` |
| `expectedPrice.currency` | `string` | `NotBlank`, `Choice(callback: [Currency::class, 'values'])` (строгий регистр) |

Вложенный объект `expectedPrice` — отдельный DTO `MoneyRequest` с `#[Assert\Valid]`.

`PlaceOrderCommand`: `orderId: string` (сгенерирован контроллером через `IdGenerator` до вызова, чтобы идемпотентный повтор возвращал тот же id из сохранённого ответа), `customerId: string`, `productId: string`, `expectedAmount: int`, `expectedCurrency: Currency`.

`PlaceOrderOutput`: `orderId: string`, `status: OrderStatus` (`Pending`), `price: MoneyOutput{amount:int, currency:string}`, `createdAt: DateTimeImmutable`.

Логика: загрузить `Product` (порт `ProductCatalog`) → `Order::place(...)` → `OrderRepository::save` (с outbox `OrderPlaced`). Сага стартует асинхронно.

Ответ: 201 + `Location: /api/orders/{id}`.

Ошибки: `ProductNotFound` (404), `ProductUnavailable` (409), `PriceChanged` (409, тело содержит актуальную цену), `CurrencyMismatch` (422), валидация (422), `IdempotencyKeyReused` (422), `IdempotentRequestInProgress` (409, `Retry-After: 1`), `IdempotencyKeyMissing`/`IdempotencyKeyInvalid` (400).

### 1.2 `CancelOrder` — `POST /api/orders/{orderId}/cancel`

`CancelOrderCommand`: `orderId`, `customerId`. `CancelOrderOutput`: `orderId`, `status`, `cancellationRequested: bool`.

Логика: загрузить заказ, проверить владельца (чужой → `OrderNotFound`), `requestCancellation($now)`, сохранить. Если статус `Pending` и сага ещё не стартовала, всё равно отправляется сигнал (порт `OrderSagaSignals::requestCancellation(OrderId)`); если workflow ещё не существует, сигнал не нужен: workflow при старте читает флаг (см. `03_saga.md` §5). Ошибка отправки сигнала не откатывает флаг: workflow проверяет флаг активностью `IsCancellationRequested` перед каждым шагом.

Ответ: 202. Ошибки: `OrderNotFound` (404), `OrderNotCancellable` (409), `ConcurrencyConflict` после 3 повторов (409).

### 1.3 `RequestRefund` — `POST /api/orders/{orderId}/refund`

`RequestRefundCommand`: `orderId`, `customerId`. `RequestRefundOutput`: `orderId`, `status` (`Refunding`).

Логика: `startRefund($now, RefundWindow)` → сохранить → outbox `OrderRefundStarted` → потребитель стартует `RefundWorkflow` (`WorkflowId = refund-{orderId}`).

Ошибки: `OrderNotFound`, `OrderNotRefundable` (409, статус не `Completed`), `RefundWindowClosed` (409). Повтор на `Refunding`/`Refunded` → 202 с текущим статусом (идемпотентно).

## 2. Команды саги (вызываются только активностями Temporal)

Каждая идемпотентна по `orderId`. Ошибки разделены на **non-retryable** (бизнес-исход, сага компенсирует) и **retryable** (инфраструктура, Temporal повторяет).

| Команда | Input | Output | Non-retryable ошибки | Retryable |
|---|---|---|---|---|
| `ReserveKey` | `orderId, productId` | `reservationId, keyId, expiresAt` | `OutOfStock` | БД недоступна, `ConcurrencyConflict` |
| `MarkOrderKeyReserved` | `orderId, reservationId` | `version` | `IllegalOrderTransition` | БД |
| `IsCancellationRequested` (query) | `orderId` | `bool` | — | БД |
| `ChargePayment` | `orderId` | `paymentId, status: PaymentStatus` | `PaymentDeclined`, `CurrencyMismatch`, `ReservationExpired` (предпроверка) | `PaymentGatewayUnavailable`, `CircuitOpen`, `PaymentOutcomeUnknown` |
| `MarkOrderPaid` | `orderId, paymentId` | `version` | `IllegalOrderTransition` | БД |
| `ConsumeReservation` | `orderId` | `keyId` | `ReservationExpired`, `KeyRevoked` | БД |
| `CompleteOrder` | `orderId, keyId` | `version` | `IllegalOrderTransition` | БД |
| `FailOrder` | `orderId, reason` (только из `Pending`, напр. `OutOfStock`) | — | — | БД |
| `CancelOrderBeforeStart` | `orderId` (из `Pending` при флаге отмены) | — | — | БД |
| `StartOrderCompensation` | `orderId, reason: FailureReason` | — | — | БД |
| `RefundPayment` | `orderId` | `status` (`Refunded` или `NothingToRefund`) | — | шлюз, breaker |
| `ReleaseReservation` | `orderId` | `released: bool` | — (`Consumed` → вернуть `false` и записать предупреждение; ключ не возвращаем в продажу при компенсации) | БД |
| `FinishOrderCompensation` | `orderId` | `status` | — | БД |
| `MarkOrderManualReview` | `orderId, reason, step` | — | — | БД |
| `RevokeSoldKey` (возврат) | `orderId` | `keyId` | `KeyAlreadyRevoked` → считать успехом | БД |
| `FinishOrderRefund` | `orderId` | — | — | БД |

Детали `ChargePayment`:
1. Загрузить заказ и цену (INV-18). Загрузить/создать `Payment` (unique по `order_id`; при гонке вставки перечитать).
2. Если `Succeeded` → вернуть сразу, шлюз не вызывать (EC-10). Если `Declined` → бросить `PaymentDeclined`. Если `Abandoned` → бросить `PaymentDeclined` с причиной `abandoned` (не списывать после компенсации).
3. Предпроверка резервации через порт `ReservationStatusReader` (read-only, Inventory): если не `Active` или истекает раньше чем через `charge_guard_seconds` (60) → `ReservationExpired`, без вызова шлюза (EC-17).
4. Если `Unknown` → сначала `PaymentGateway::findCharge(idempotencyKey)`: найдено успешное → `markSucceeded`; найдено отклонённое → `markDeclined`; не найдено → шаг 5.
5. `PaymentGateway::charge(idempotencyKey = paymentId, amount)`. Таймаут/обрыв/невалидный ответ → `markUnknown`, сохранить, бросить `PaymentOutcomeUnknown` (retryable).

## 3. Команды обслуживания (console, Symfony Scheduler)

| Команда | Input | Output | Назначение |
|---|---|---|---|
| `ExpireReservations` | `limit: int (1..1000)` | `expiredCount: int` | `Active` и `expiresAt <= now` → `Expired`, ключ `Available`; CAS `WHERE status='active'` |
| `ReconcilePayments` | `olderThanSeconds: int (≥60)`, `limit` | `checked, succeededLate, refunded` | платежи `Unknown`/`Abandoned` старше порога: спросить шлюз; поздний успех у заказа в `Failed`/`Cancelled` → refund (EC-28) |
| `AddProductKeys` | `productId, codes: list<string>` | `added, duplicates` | наполнение склада (fixtures) |
| `RevokeKey` | `keyId` | — | имитация отзыва ключа поставщиком (EC-18) |

## 4. Запросы (read side)

Читают таблицу `order_view` и `product_availability_view` через порт `OrderReadModel`/`ProductReadModel` (DBAL, без доменных объектов).

### 4.1 `GetOrder` — `GET /api/orders/{orderId}`, GraphQL `order(id)`
`GetOrderQuery`: `orderId`, `customerId`. `OrderDetailsOutput`: `orderId, status, price{amount,currency}, productId, failureReason: ?string, cancellationRequested: bool, keyCode: ?string, createdAt, updatedAt, completedAt: ?`.
- `keyCode` заполняется только если статус `Completed` (берётся из `product_keys` по `deliveredKeyId`; это единственное место, где код покидает систему, INV-17). В `Refunding`/`Refunded` = null.
- Чужой или отсутствующий заказ → `OrderNotFound` (404), не 403.
- Read model eventual: сразу после `PlaceOrder` возможен 404 (EC-54).

### 4.2 `ListCustomerOrders` — `GET /api/orders`, GraphQL `myOrders`
`ListCustomerOrdersRequest` (`#[MapQueryString]`): `status: ?string` (`Choice` значений `OrderStatus`), `limit: int = 20` (`Range(1, 100)`), `cursor: ?string` (`Length(max: 200)`).
`ListCustomerOrdersQuery`: `customerId, status: ?OrderStatus, limit, cursor: ?string`. Output: `items: list<OrderSummaryOutput>, nextCursor: ?string`. Сортировка `created_at DESC, order_id DESC`, курсор = base64url(`created_at|order_id`); битый курсор → 422 `INVALID_CURSOR`.

### 4.3 `GetProductAvailability` — GraphQL `product(id)`
Output: `productId, name, price, available: int (≥0)`.

## 5. Идемпотентность HTTP (`IdempotencyMiddleware`, infrastructure)

Таблица `idempotency_keys(customer_id uuid, key varchar(255), request_hash char(64), method, path, status enum('in_progress','completed'), response_status int null, response_body jsonb null, created_at, expires_at)`, PK `(customer_id, key)`.

Алгоритм (Symfony `kernel.request`/`kernel.response` listener или декоратор контроллера, выбирается в M5):
1. Нет заголовка на `POST` → 400 `IDEMPOTENCY_KEY_MISSING`. Длина 1..255, допустимые символы `[A-Za-z0-9_-]` → иначе 400 `IDEMPOTENCY_KEY_INVALID`.
2. `request_hash = sha256(method + path + канонический JSON тела)`.
3. `INSERT ... ON CONFLICT DO NOTHING` со статусом `in_progress` и `expires_at = now + 24h`.
   - Вставилось → выполнить запрос; ответ 2xx или 4xx бизнес-ошибки → сохранить `completed` + ответ; 5xx или исключение → удалить строку (повтор разрешён).
   - Не вставилось → прочитать строку: `expires_at <= now` → удалить и повторить шаг 3; `request_hash` другой → 422 `IDEMPOTENCY_KEY_REUSED`; `in_progress` → 409 `IDEMPOTENT_REQUEST_IN_PROGRESS` + `Retry-After: 1`; `completed` → вернуть сохранённый статус и тело + заголовок `Idempotent-Replayed: true`.
4. Ключ привязан к покупателю: одинаковый ключ у разных покупателей независим.
5. Зависшие `in_progress` (процесс упал) старше 60 с считаются истёкшими (EC-07b).

## 6. Типизированные ошибки

`Shared\Domain\DomainErrorCode` (enum, backed string) и базовые классы. Маппинг в HTTP — `match` в `ExceptionToProblemListener` (RFC 9457 `application/problem+json`: `type`, `title`, `status`, `code`, `errors[]` для валидации).

| Код | Класс | Слой | HTTP | Retryable в саге |
|---|---|---|---|---|
| `VALIDATION_FAILED` | `ValidationFailedException` (Symfony) | HTTP | 422 | — |
| `CUSTOMER_REQUIRED` | `CustomerRequired` | HTTP | 401 | — |
| `IDEMPOTENCY_KEY_MISSING` / `_INVALID` | `IdempotencyKey*` | HTTP | 400 | — |
| `IDEMPOTENCY_KEY_REUSED` | `IdempotencyKeyReused` | HTTP | 422 | — |
| `IDEMPOTENT_REQUEST_IN_PROGRESS` | `IdempotentRequestInProgress` | HTTP | 409 | — |
| `NEGATIVE_MONEY`, `NON_POSITIVE_MONEY`, `MONEY_OUT_OF_RANGE`, `MONEY_OVERFLOW` | `Money*` | Domain | 422 | нет |
| `UNKNOWN_CURRENCY` | `UnknownCurrency` | Domain | 422 | нет |
| `CURRENCY_MISMATCH` | `CurrencyMismatch` | Domain | 422 | нет |
| `PRICE_CHANGED` | `PriceChanged` | Domain | 409 | — |
| `PRODUCT_NOT_FOUND` / `PRODUCT_UNAVAILABLE` | | Application/Domain | 404 / 409 | — |
| `ORDER_NOT_FOUND` | `OrderNotFound` | Application | 404 | нет |
| `ILLEGAL_ORDER_TRANSITION` | `IllegalOrderTransition` | Domain | 409 | нет |
| `ORDER_NOT_CANCELLABLE` / `ORDER_NOT_REFUNDABLE` / `REFUND_WINDOW_CLOSED` | | Domain | 409 | — |
| `CONCURRENCY_CONFLICT` | `ConcurrencyConflict` | Application (порт) | 409 | да |
| `OUT_OF_STOCK` | `OutOfStock` | Application | — | нет |
| `RESERVATION_EXPIRED` / `RESERVATION_ALREADY_CONSUMED` / `KEY_REVOKED` | | Domain | — | нет |
| `PAYMENT_DECLINED` | `PaymentDeclined` | Application | — | нет |
| `PAYMENT_ALREADY_SUCCEEDED` | | Domain | — | нет |
| `PAYMENT_GATEWAY_UNAVAILABLE` / `CIRCUIT_OPEN` / `PAYMENT_OUTCOME_UNKNOWN` | | Application (порт) | — | да |
| `INVALID_CURSOR` | | Application | 422 | — |

В активностях non-retryable ошибки оборачиваются в `ApplicationFailure` с `nonRetryable = true` и `type = code` (точное API конструктора проверить в M6.1); список non-retryable типов дублируется в `RetryOptions::withNonRetryableExceptions` как страховка.

## 7. Порты (интерфейсы в Application)

| Порт | Модуль | Методы (сигнатуры) | Реализации |
|---|---|---|---|
| `OrderRepository` | Ordering | `get(OrderId): Order` (бросает `OrderNotFound`), `save(Order): void` (бросает `ConcurrencyConflict`) | `DbalOrderRepository`, `InMemoryOrderRepository` |
| `OrderReadModel` | Ordering | `find(OrderId, CustomerId): ?OrderDetailsOutput`, `list(...)`: `OrderPage` | `DbalOrderReadModel` |
| `ProductCatalog` | Inventory→Ordering* | `find(ProductId): ?ProductSnapshot{id, price, active}` | DBAL, in-memory |
| `KeyInventory` | Inventory | `reserveAny(ProductId, OrderId, DateTimeImmutable now, int ttl): KeyReservation` (`OutOfStock`), `findReservationByOrder(OrderId): ?KeyReservation`, `save(...)` | `DbalKeyInventory` (`SKIP LOCKED`) |
| `ReservationStatusReader` | Inventory→Payment* | `activeUntil(OrderId): ?DateTimeImmutable` | DBAL |
| `PaymentRepository` | Payment | `findByOrder(OrderId): ?Payment`, `insertOrGet(Payment): Payment`, `save(Payment)` | DBAL, in-memory |
| `PaymentGateway` | Payment | `charge(string idempotencyKey, Money): ChargeResult`, `findCharge(string idempotencyKey): ?ChargeResult`, `refund(string idempotencyKey, string chargeId, Money): RefundResult` | `HttpPaymentGateway`, декоратор `CircuitBreakingPaymentGateway`, `FakePaymentGateway` (сценарии) |
| `OrderSagaStarter` | Ordering | `start(OrderId): void` (уже запущен = успех) | `TemporalOrderSagaStarter` |
| `OrderSagaSignals` | Ordering | `requestCancellation(OrderId): void` | `TemporalOrderSagaSignals` |
| `TransactionManager` | Shared | `transactional(callable(): T): T` | `DbalTransactionManager`, `NoopTransactionManager` |
| `IdGenerator` | Shared | `next(): string` (UUID v7) | `SymfonyUuidGenerator`, `SequenceIdGenerator` (тесты) |
| `Psr\Clock\ClockInterface` | Shared | `now()` | `NativeClock` (UTC), `MockClock` |
| `KeyDeliveryNotifier` | Ordering | `notify(OrderId, CustomerId): void` | `LogKeyDeliveryNotifier` (заглушка) |

\* Межмодульные порты-чтения объявлены в модуле-потребителе (Ordering объявляет `ProductCatalog`), реализация в infrastructure модуля-владельца. Domain/Application модулей друг друга не импортируют (T-ARCH-02).

`ChargeResult`: `chargeId: string`, `status: GatewayChargeStatus` (enum `Succeeded`, `Declined`), `amount: Money`, `declineReason: ?string`. Таймаут и обрыв — исключения `PaymentOutcomeUnknown`, не значение.
