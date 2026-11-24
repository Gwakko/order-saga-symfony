# 01. Домен

Все классы ниже: `declare(strict_types=1)`, `final readonly class`, без атрибутов фреймворков. Конструкторы VO проверяют инварианты и бросают типизированные доменные исключения (наследники `Shared\Domain\DomainError`, у каждого `code(): DomainErrorCode`).

## 1. Принцип моделирования агрегатов

Агрегаты неизменяемы: метод перехода возвращает **новый** экземпляр с увеличенной `version` и добавленным событием в `pendingEvents`.

```php
$paid = $order->markPaid($paymentId, $now);   // $order не изменился
$repository->save($paid);                     // UPDATE ... WHERE id = ? AND version = $paid->version - 1
                                              // + INSERT INTO outbox_messages (pendingEvents) в той же транзакции
```

- Плюсы: совместимо с правилом readonly-классов; переходы — чистые функции, тестируются без моков; оптимистическая блокировка естественна.
- Минусы: больше аллокаций, нет ORM. Альтернатива (мутабельный агрегат с `private(set)` из PHP 8.4) допустима, если в M2 неизменяемость окажется громоздкой; решение фиксируется в `docs/reports/M2.md`.
- `pendingEvents` не сохраняется в состоянии; репозиторий после `save` их не возвращает (новый экземпляр из `find` имеет пустой список).

## 2. Shared kernel

### 2.1 `Currency` (enum, backed string)

```
enum Currency: string { case EUR = 'EUR'; case USD = 'USD'; case PLN = 'PLN'; case UAH = 'UAH'; }
minorUnits(): int  → match: все четыре = 2
```
- Неизвестный код: `Currency::tryFrom()` вернул `null` → `UnknownCurrency`. Регистр строгий (`eur` неизвестна).
- Добавление валюты с 0 знаков (JPY) вне границ; тест не нужен.

### 2.2 `Money`

| Поле | Тип |
|---|---|
| `amount` | `int` (minor units) |
| `currency` | `Currency` |

Инварианты:
- INV-01: `0 <= amount <= Money::MAX_AMOUNT` (`MAX_AMOUNT = 100_000_000` = 1 000 000.00 при 2 знаках). Отрицательное → `NegativeMoney`, больше максимума → `MoneyOutOfRange`.
- Конструкторы: `Money::of(int $amount, Currency $c)`; `Money::positive(...)` дополнительно требует `amount > 0` (`NonPositiveMoney`), используется для цен и списаний.
- INV-02: `add`, `subtract`, `equals`, `greaterThan` требуют одинаковой валюты, иначе `CurrencyMismatch`.
- `add`: проверка переполнения до сложения (`$a > PHP_INT_MAX - $b` → `MoneyOverflow`), затем проверка `MAX_AMOUNT`. Результат никогда не `float`.
- `subtract`: результат < 0 → `NegativeMoney`.
- Нет `float`-методов, нет `multiply(float)`.

### 2.3 Идентификаторы

`OrderId`, `CustomerId`, `ProductId`, `ProductKeyId`, `ReservationId`, `PaymentId`, `EventId`: VO над строкой UUID (валидация формата RFC 4122/9562, нижний регистр). Генерация UUID v7 в инфраструктуре через порт `IdGenerator` (Application), чтобы домен был детерминирован в тестах.

### 2.4 Прочие VO

- `KeyCode`: строка 5..64 символа `[A-Z0-9-]`; `__toString` не определён; `masked(): string` → `XXXX-...-AB12` (последние 4). INV-17: в событиях и логах только `masked()`.
- `FailureReason` (enum): `OutOfStock`, `PaymentDeclined`, `PaymentUnavailable`, `ReservationExpired`, `KeyRevoked`, `CurrencyMismatch`, `CancelledByCustomer`, `CompensationFailed`.
- `RefundWindow`: длительность в секундах (по умолчанию 14×86400), проверка `isOpen(DateTimeImmutable $completedAt, DateTimeImmutable $now): bool` в UTC.

## 3. Ordering: агрегат `Order`

| Поле | Тип | Примечание |
|---|---|---|
| `id` | `OrderId` | |
| `customerId` | `CustomerId` | |
| `productId` | `ProductId` | |
| `price` | `Money` | цена каталога на момент создания (INV-13) |
| `status` | `OrderStatus` | |
| `reservationId` | `?ReservationId` | заполняется при `KeyReserved` |
| `paymentId` | `?PaymentId` | заполняется при `Paid` |
| `deliveredKeyId` | `?ProductKeyId` | заполняется при `Completed` |
| `failureReason` | `?FailureReason` | только в `Failed`, `Cancelled`, `ManualReview` |
| `cancellationRequested` | `bool` | флаг запроса отмены |
| `createdAt`, `updatedAt`, `completedAt` | `DateTimeImmutable` (UTC), `?DateTimeImmutable` | |
| `version` | `int` | ≥ 1, растёт на 1 при каждом переходе |

### 3.1 `OrderStatus` (enum)

`Pending`, `KeyReserved`, `Paid`, `Completed`, `Compensating`, `Cancelled`, `Failed`, `Refunding`, `Refunded`, `ManualReview`.

Методы enum (через `match`): `isTerminal(): bool` (`Cancelled`, `Failed`, `Refunded`), `canTransitionTo(self $to): bool`, `isCancellable(): bool` (`Pending`, `KeyReserved`, `Paid`).

### 3.2 Таблица переходов

Строка — из какого статуса, столбец — в какой. `+` разрешено (метод агрегата), пусто = запрещено → `IllegalOrderTransition(from, to)`.

| from \ to | KeyReserved | Paid | Completed | Compensating | Cancelled | Failed | Refunding | Refunded | ManualReview |
|---|---|---|---|---|---|---|---|---|---|
| Pending | `+ markKeyReserved` | | | | `+ cancelBeforeStart` | `+ fail` (OutOfStock) | | | |
| KeyReserved | | `+ markPaid` | | `+ startCompensation` | | | | | |
| Paid | | | `+ complete` | `+ startCompensation` | | | | | |
| Completed | | | | | | | `+ startRefund` | | |
| Compensating | | | | | `+ finishCompensation` (если `cancellationRequested`) | `+ finishCompensation` (иначе) | | | `+ requireManualReview` |
| Refunding | | | | | | | | `+ finishRefund` | `+ requireManualReview` |
| Cancelled, Failed, Refunded | | | | | | | | | |
| ManualReview | | | | | `+ resolveManually` | `+ resolveManually` | | `+ resolveManually` | |

Идемпотентные повторы (не ошибка, возвращают тот же экземпляр без события и без роста версии):
- `markKeyReserved(sameReservationId)` в `KeyReserved`; `markPaid(samePaymentId)` в `Paid`; `complete(sameKeyId)` в `Completed`; `startCompensation` в `Compensating`; `finishCompensation` в целевом терминальном статусе.
- Тот же метод с **другим** id (например, `markPaid(otherPaymentId)` в `Paid`) → `IllegalOrderTransition` (защита от двух оплат, INV-06).

`requestCancellation($now)`:
- `Pending`, `KeyReserved`, `Paid` → `cancellationRequested = true`, событие `OrderCancellationRequested`. Повтор: идемпотентно.
- `Compensating`, `Cancelled` → идемпотентно, без события.
- `Completed`, `Refunding`, `Refunded`, `Failed`, `ManualReview` → `OrderNotCancellable(status)`.

### 3.3 Инварианты Order

- INV-03: статус меняется только по таблице 3.2.
- INV-07/INV-08: `Completed` ⇒ `reservationId`, `paymentId`, `deliveredKeyId` не null. `complete()` без `paymentId` невозможен по таблице (только из `Paid`).
- `failureReason !== null` ⇔ статус ∈ {`Cancelled`, `Failed`, `ManualReview`}; для `Cancelled` всегда `CancelledByCustomer`.
- `startRefund($now, RefundWindow)` только если `window.isOpen(completedAt, now)`, иначе `RefundWindowClosed`.
- INV-15: `version` строго растёт; сохранение со старой версией → `ConcurrencyConflict` (инфраструктура).
- INV-13: `Order::place(...)` принимает `catalogPrice` и `expectedPrice`; `expectedPrice.currency !== catalogPrice.currency` → `CurrencyMismatch`; суммы разные → `PriceChanged(current)`.

## 4. Inventory

### 4.1 `Product`
`id: ProductId`, `name: string (1..200)`, `price: Money` (positive), `active: bool`. Неактивный → `ProductUnavailable` при заказе.

### 4.2 `ProductKey`
`id`, `productId`, `code: KeyCode`, `status: KeyStatus`, `version`.

`KeyStatus` (enum): `Available`, `Reserved`, `Sold`, `Revoked`.

| from \ to | Available | Reserved | Sold | Revoked |
|---|---|---|---|---|
| Available | | `+ reserve` | | `+ revoke` |
| Reserved | `+ release` | | `+ sell` | `+ revoke` |
| Sold | | | | `+ revoke` (возврат) |
| Revoked | | | | |

### 4.3 `KeyReservation`
`id`, `orderId`, `keyId`, `productId`, `status: ReservationStatus`, `createdAt`, `expiresAt`, `version`.

`ReservationStatus`: `Active`, `Consumed`, `Released`, `Expired`.

| from \ to | Consumed | Released | Expired |
|---|---|---|---|
| Active | `+ consume($now)` если `$now < expiresAt` | `+ release` | `+ expire($now)` если `$now >= expiresAt` |
| Consumed, Released, Expired | | | |

- `consume` при `$now >= expiresAt` → `ReservationExpired` (граница: равенство = истекла, INV-14).
- `release` в `Released`/`Expired` → идемпотентно (no-op). `release` в `Consumed` → `ReservationAlreadyConsumed` (освобождать проданный ключ нельзя; это возврат, а не компенсация).
- `consume` в `Consumed` с тем же `orderId` → идемпотентно.
- `expiresAt = createdAt + ttl`, `ttl` по умолчанию 900 с (конфиг `inventory.reservation_ttl_seconds`).

Инварианты Inventory:
- INV-04: у ключа не более одной `Active` резервации (partial unique index `key_reservations(key_id) WHERE status = 'active'`), ключ `Sold` принадлежит одному заказу.
- INV-05: у заказа не более одной резервации в статусе `Active` или `Consumed` (partial unique index по `order_id`).
- Согласованность статусов ключа и резервации обеспечивает application-handler в одной транзакции: `reserve` (ключ `Reserved` + резервация `Active`), `release`/`expire` (ключ `Available`), `consume` (ключ `Sold`).

## 5. Payment: агрегат `Payment`

`id: PaymentId`, `orderId`, `amount: Money` (positive), `status: PaymentStatus`, `gatewayChargeId: ?string`, `gatewayRefundId: ?string`, `attempts: int`, `lastError: ?string`, `createdAt`, `updatedAt`, `version`.

`PaymentStatus`: `Pending`, `Unknown`, `Succeeded`, `Declined`, `Abandoned`, `RefundPending`, `Refunded`.

| from \ to | Unknown | Succeeded | Declined | Abandoned | RefundPending | Refunded |
|---|---|---|---|---|---|---|
| Pending | `+ markUnknown` (таймаут/обрыв) | `+ markSucceeded` | `+ markDeclined` | `+ abandon` (компенсация до ответа) | `+ markCapturedWithMismatch` | |
| Unknown | (повтор = no-op) | `+ markSucceeded` (по проверке статуса) | `+ markDeclined` | `+ abandon` | `+ markCapturedWithMismatch` | |
| Succeeded | | | | | `+ requestRefund` | |
| RefundPending | | | | | | `+ markRefunded` |
| Abandoned | | `+ markSucceeded` (поздний успех, только сверка) | | | `+ markCapturedWithMismatch` | |
| Declined, Refunded | | | | | | |

- `Abandoned → Succeeded` разрешён только чтобы зафиксировать факт позднего успеха; сразу после него сверка делает `requestRefund` (EC-28).
- INV-06: один `Payment` на заказ (unique `payments(order_id)`); `markSucceeded(chargeId)` повторно с тем же `chargeId` — no-op, с другим → `PaymentAlreadySucceeded`; refund только из `Succeeded`, сумма refund = `amount` (полный возврат).
- INV-18: сумма списания берётся из `Order.price`, а не из входа workflow (EC-81).
- Валюта или сумма ответа шлюза не совпадают с `amount` (EC-36, EC-37): деньги списаны, платёж нельзя считать успешным. Решение пользователя 2026-11-24: `markSucceeded` по-прежнему бросает `CurrencyMismatch`/`AmountMismatch` и состояние не меняет; handler ловит ошибку и вызывает `markCapturedWithMismatch(chargeId, MismatchReason)`, который из `Pending`/`Unknown`/`Abandoned` переводит платёж сразу в `RefundPending`, сохраняя `gatewayChargeId` и причину в `lastError`. Возврат делается по `chargeId` целиком (шлюз возвращает фактически списанное); фактическая валюта и сумма из ответа шлюза записываются в событие `PaymentCapturedWithMismatch` для сверки, а `Payment.amount` не меняется (INV-18). Сага компенсирует и завершает заказ `Failed(CurrencyMismatch|AmountMismatch)`. Повтор с тем же `chargeId`: no-op.

## 6. Доменные события

Все: `final readonly class`, реализуют `DomainEvent { eventId(): EventId; aggregateId(): string; aggregateVersion(): int; occurredAt(): DateTimeImmutable; }`. Payload сериализуется в outbox инфраструктурным нормализатором (не атрибутами). Коды ключей в событиях только маскированные (INV-17).

| Событие | Когда | Значимые поля | Потребители |
|---|---|---|---|
| `OrderPlaced` | `Order::place` | orderId, customerId, productId, price | старт саги, проекция |
| `OrderKeyReserved` | `markKeyReserved` | reservationId | проекция |
| `OrderPaid` | `markPaid` | paymentId | проекция |
| `OrderCompleted` | `complete` | keyId, keyMasked | проекция, уведомление (заглушка-лог) |
| `OrderCancellationRequested` | `requestCancellation` | | проекция |
| `OrderCompensationStarted` | `startCompensation` | reason | проекция |
| `OrderCancelled` | `finishCompensation`/`cancelBeforeStart` | | проекция |
| `OrderFailed` | `fail`/`finishCompensation` | reason | проекция |
| `OrderRefundStarted`, `OrderRefunded` | возврат | | проекция |
| `OrderNeedsManualReview` | `requireManualReview` | reason, step | проекция, лог уровня `critical` |
| `KeyReserved`, `KeyReleased`, `ReservationExpired`, `KeySold`, `KeyRevoked` | Inventory | keyId, orderId | проекция доступности |
| `PaymentSucceeded`, `PaymentDeclined`, `PaymentRefunded`, `OrphanChargeDetected` | Payment | paymentId, amount | лог, проекция |

## 7. Сводный список инвариантов

| ID | Инвариант | Где обеспечивается |
|---|---|---|
| INV-01 | Деньги: `int` minor units, `0..MAX_AMOUNT`, цены и списания > 0 | `Money` |
| INV-02 | Операции с деньгами только в одной валюте | `Money` |
| INV-03 | Статус заказа меняется только по таблице переходов | `Order`, `OrderStatus` |
| INV-04 | У ключа ≤ 1 активной резервации; проданный ключ у одного заказа | `KeyReservation` + partial unique index + `SKIP LOCKED` |
| INV-05 | У заказа ≤ 1 активной/списанной резервации | partial unique index, идемпотентный `ReserveKey` |
| INV-06 | Один платёж на заказ, ≤ 1 успешное списание, возврат ≤ списанного и только после успеха | `Payment`, unique index, idempotency key шлюза |
| INV-07 | Ключ выдаётся только после успешной оплаты и списания резерва | порядок шагов саги + таблица переходов |
| INV-08 | `Cancelled`/`Failed` ⇒ после компенсаций нет неотменённого списания и активной резервации | сага + сверка платежей |
| INV-09 | Один `Idempotency-Key` покупателя = один эффект; другое тело → отказ | `idempotency_keys` |
| INV-10 | Изменение агрегата и его события фиксируются атомарно | репозиторий + outbox в одной транзакции |
| INV-11 | Потребитель обрабатывает сообщение ≤ 1 раза по `message_id` | inbox |
| INV-12 | Время только UTC; домен получает `now` аргументом; workflow берёт `Workflow::now()` | правила PHPStan, Clock |
| INV-13 | Цена заказа = цена каталога; ожидаемая цена клиента должна совпасть | `Order::place` |
| INV-14 | Резервацию можно списать только `Active` и `now < expiresAt` | `KeyReservation::consume` + CAS |
| INV-15 | Версия агрегата монотонна; конкурентная запись со старой версией отклоняется | репозиторий |
| INV-16 | Для заказа ≤ 1 запуск саги | `WorkflowId = order-{id}` |
| INV-17 | Код ключа не попадает в логи и события | `KeyCode::masked`, тест логов |
| INV-18 | Сумма списания берётся из сохранённого заказа | `ChargePaymentHandler` |
