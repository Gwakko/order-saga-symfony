# 03. Сага в Temporal

API PHP SDK в этом файле описано по смыслу. Имена классов и методов (`Workflow\Saga`, `ActivityOptions`, `RetryOptions`, `WorkflowIdReusePolicy`, `Workflow::await`, `Workflow::getVersion`, `Temporal\Testing`) **проверить в M6.1** по `vendor/temporal/sdk/src` (v2.20) и `https://github.com/temporalio/samples-php`. Если имя отличается, поправить спецификацию в отчёте `docs/reports/M6.1.md`, смысл не менять.

## 1. Workflow

| | `OrderFulfillmentWorkflow` | `RefundWorkflow` |
|---|---|---|
| WorkflowId | `order-{orderId}` | `refund-{orderId}` |
| Task queue | `order-saga` | `order-saga` |
| Вход | `orderId: string` (только id; остальное активности читают из БД, INV-18) | `orderId: string` |
| Запуск | потребитель события `OrderPlaced` | потребитель `OrderRefundStarted` |
| Reuse policy | reject duplicate | reject duplicate |
| Сигналы | `requestCancellation()` | — |
| Query | `progress(): string` (текущий шаг, для отладки) | — |
| Execution timeout | 24 ч (страховка) | 7 сут |

Код workflow детерминирован: без БД, HTTP, логгера с побочными эффектами (только `Workflow::getLogger` при наличии в SDK; проверить), без системного времени и случайных чисел. Правило PHPStan `WorkflowDeterminismRule` (05_testing §4.3).

## 2. Шаги `OrderFulfillmentWorkflow`

Псевдокод (не PHP-синтаксис SDK):

```
saga = new Saga(parallelCompensation: false)
cancelRequested = false                       // ставится сигналом
try:
  guardCancellation()                         // activity IsCancellationRequested || cancelRequested
  r = activity ReserveKey(orderId)            // OutOfStock → fail(OutOfStock), компенсаций нет
  saga.addCompensation(ReleaseReservation(orderId))
  activity MarkOrderKeyReserved(orderId, r.reservationId)

  guardCancellation()
  saga.addCompensation(RefundPayment(orderId))     // ДО вызова: исход списания может быть неизвестен
  p = activity ChargePayment(orderId)
  activity MarkOrderPaid(orderId, p.paymentId)

  guardCancellation()
  k = activity ConsumeReservation(orderId)
  // точка невозврата: дальше отмена не принимается
  activity CompleteOrder(orderId, k.keyId)
catch NonRetryable(reason) or CancellationRequested:
  if order still Pending and nothing done: activity FailOrder / CancelBeforeStart; return
  activity StartOrderCompensation(orderId, reason)
  try: saga.compensate()                      // RefundPayment, затем ReleaseReservation
  catch: activity MarkOrderManualReview(orderId, CompensationFailed, step); return
  activity FinishOrderCompensation(orderId)   // → Cancelled или Failed
```

`guardCancellation()` бросает внутреннее `CancellationRequested`, если пришёл сигнал или флаг в БД (`IsCancellationRequested`). Флаг в БД нужен, потому что `CancelOrder` мог записать флаг, а сигнал не дошёл (Temporal недоступен) или workflow ещё не стартовал.

Регистрация `RefundPayment` до вызова `ChargePayment`: если `ChargePayment` упал с неизвестным исходом и время шага исчерпано, компенсация `RefundPayment` переведёт платёж `Pending/Unknown → Abandoned` (без вызова refund), а `Succeeded → RefundPending → Refunded`. Поздний успех после `Abandoned` ловит `ReconcilePayments` (EC-28).

## 3. Активности: таймауты и повторы

Значения — стартовые настройки учебного проекта, не результат измерений. Все в `config/packages/temporal.yaml`, читаются при сборке workflow-опций.

| Активность | StartToClose | ScheduleToClose | Retry: initial / backoff / max interval / max attempts | Non-retryable |
|---|---|---|---|---|
| `ReserveKey` | 5 с | 1 мин | 1 с / 2.0 / 10 с / без лимита в пределах ScheduleToClose | `OutOfStock` |
| `MarkOrder*`, `CompleteOrder`, `IsCancellationRequested` | 5 с | 1 мин | 1 с / 2.0 / 10 с | `IllegalOrderTransition` |
| `ChargePayment` | 15 с (HTTP timeout клиента 10 с, должен быть меньше) | 5 мин | 2 с / 2.0 / 30 с | `PaymentDeclined`, `CurrencyMismatch`, `ReservationExpired` |
| `ConsumeReservation` | 5 с | 1 мин | 1 с / 2.0 / 10 с | `ReservationExpired`, `KeyRevoked` |
| `RefundPayment` (компенсация) | 15 с | 7 сут | 5 с / 2.0 / 5 мин | нет |
| `ReleaseReservation` (компенсация) | 5 с | 1 сут | 1 с / 2.0 / 1 мин | нет |

Инвариант конфигурации (проверяется при сборке контейнера, тест T-CFG-01):
`reservation_ttl (900 с) > ReserveKey.ScheduleToClose + ChargePayment.ScheduleToClose + ConsumeReservation.ScheduleToClose + charge_guard (60 с)` = 60 + 300 + 60 + 60 = 480 с. Иначе исключение `InvalidSagaConfiguration` при старте.

Heartbeat не используется: все активности короткие.

## 4. Компенсации

| Компенсация | Что делает | Идемпотентность | Когда no-op |
|---|---|---|---|
| `RefundPayment` | по статусу платежа (`match`): `Succeeded` → `requestRefund` + `gateway.refund(idempotencyKey = "refund-{paymentId}")` → `Refunded`; `RefundPending` → повторить вызов refund; `Pending`/`Unknown` → сначала `findCharge`: успех → refund, нет → `Abandoned`; `Declined`/`Abandoned`/`Refunded`/нет платежа → ничего | ключ refund у шлюза + статус `Refunded` | нет платежа, `Declined`, `Abandoned`, `Refunded` |
| `ReleaseReservation` | `Active` → `Released`, ключ `Available` (одна транзакция, CAS) | CAS `WHERE status='active'` | `Released`, `Expired`, нет резервации; `Consumed` → `released=false` + warning |

Порядок: обратный регистрации (`RefundPayment`, затем `ReleaseReservation`), последовательно. Причина последовательности: при параллели ключ может вернуться в продажу раньше, чем выяснится, что refund невозможен; последовательность проще объяснить и протестировать.

Если компенсация не уложилась в ScheduleToClose → `MarkOrderManualReview` (статус `ManualReview`, событие `OrderNeedsManualReview`, лог `critical`). Ручное разрешение — команда `resolveManually` (вне M6, только доменный переход).

## 5. Отмена в середине саги

Выбор механизма:
- Вариант A: **сигнал `requestCancellation` + флаг в БД (рекомендуется).** Workflow проверяет флаг между шагами; активность, которая уже выполняется, доходит до конца. Исход списания всегда известен до компенсации.
- Вариант B: отмена workflow средствами Temporal (cancellation request). Прерывает ожидание активности, требует detached cancellation scope для компенсаций и heartbeat у активностей, чтобы они узнали об отмене. Сложнее, и отменённое на середине HTTP-списание даёт неизвестный исход.

Поведение по точке, в которой пришла отмена:

| Где сага | Результат |
|---|---|
| Workflow ещё не стартовал (`Pending`) | при старте первый `guardCancellation` видит флаг → `CancelBeforeStart` → `Cancelled`, резерва нет |
| Выполняется `ReserveKey` | шаг завершается; затем guard → компенсация `ReleaseReservation` → `Cancelled` |
| После резерва, до оплаты | guard → `ReleaseReservation` → `Cancelled` |
| Выполняется `ChargePayment` | шаг завершается (успех/отказ/исчерпание); затем guard → `RefundPayment` (если списано) + `ReleaseReservation` → `Cancelled` |
| После оплаты, до `ConsumeReservation` | guard → refund + release → `Cancelled` |
| После `ConsumeReservation` | отмена игнорируется (точка невозврата); заказ `Completed`, `cancellationRequested=true` остаётся как след; покупателю доступен возврат (`RequestRefund`) |
| `Completed` и позже | `CancelOrder` отклоняется доменом (`OrderNotCancellable`) |

## 6. Идемпотентность активностей

Любая активность может выполниться больше одного раза (повтор после таймаута, падение воркера после эффекта, но до ответа Temporal). Поэтому:

| Активность | Ключ идемпотентности | Механизм |
|---|---|---|
| `ReserveKey` | `orderId` | сначала `findReservationByOrder`: `Active`/`Consumed` → вернуть её; partial unique index на `order_id` ловит гонку двух повторов → перечитать |
| `ChargePayment` | `orderId` → `paymentId` | unique `payments(order_id)`; `Succeeded` → без вызова шлюза; шлюз дедуплицирует по `Idempotency-Key = paymentId` |
| `ConsumeReservation` | `orderId` | `Consumed` с тем же заказом → вернуть `keyId` |
| `MarkOrder*`, `CompleteOrder`, `FinishOrderCompensation` | `orderId` + целевой статус | идемпотентные повторы в агрегате (01_domain §3.2) |
| `RefundPayment` | `refund-{paymentId}` | статус `Refunded` + дедупликация у шлюза |
| `ReleaseReservation` | `orderId` | CAS, no-op в конечных статусах |

## 7. Отказы инфраструктуры

| Ситуация | Поведение |
|---|---|
| Воркер упал во время активности | Temporal переназначает по истечении StartToClose; повтор идемпотентен (§6) |
| Воркер упал между активностями | workflow восстанавливается replay'ем истории на другом/перезапущенном воркере; уже выполненные активности не повторяются |
| Повторный старт workflow (дубль `OrderPlaced`) | `WorkflowExecutionAlreadyStarted` (имя проверить) ловится в `TemporalOrderSagaStarter` и считается успехом; второго запуска нет |
| Temporal недоступен при старте | потребитель бросает исключение → Messenger повторяет с backoff (`retry_strategy`: 5 попыток, 1 с × 2), затем failure transport; заказ остаётся `Pending`, сообщение можно переиграть `messenger:failed:retry` |
| Temporal недоступен при `CancelOrder` | флаг в БД сохранён, ответ 202; сигнал логируется как неотправленный; guard прочитает флаг |
| БД недоступна в активности | retryable исключение, повтор Temporal |
| Потеря соединения с БД в долгоживущем воркере | перед каждой активностью проверка соединения и переподключение (DBAL: проверить механизм в M6.1, например middleware/`ping` эквивалент); между активностями сброс сервисов (`services_resetter`) |
| Изменение кода workflow при запущенных экземплярах | `Workflow::getVersion` на месте изменения; replay-тест по сохранённой истории (T-W-20) |

## 8. Go-воркер (M9)

Итоговое решение и все подробности: `docs/spec/09_go_worker.md`. Сначала предполагалось вынести `ChargePayment` на task queue `payment-go`; это отвергнуто: активность пишет в БД PHP (`payments`, `product_keys`), а Go-воркеру БД не отдаётся. Вместо переноса существующих активностей добавлена **новая** активность `InspectGatewayCharge`, которую обслуживает Go-воркер (очередь `payment-go`, профиль `inspect_gateway_charge`): только чтение, `GET /charges/{idempotencyKey}` у шлюза.

- Ветка включается, только когда `ChargePayment` исчерпан с неизвестным исходом (EC-34, классификатор даёт `PaymentUnavailable`), под `Workflow::getVersion('gateway-inspection')` и флагом `TEMPORAL_GO_INSPECTION_ENABLED` (по умолчанию выключен; читает PHP-активность `PrepareChargeInspection`, не код workflow).
- `succeeded` с совпавшей суммой и валютой: повторный `ChargePayment` с тем же идемпотентным ключом (профиль `charge_recovery`) и обычное продолжение саги; `declined`: `Failed(PaymentDeclined)` с компенсациями; всё остальное (ошибка Go, любой таймаут, `not_found`, несовпадение, нарушение контракта) оставляет поведение M8.
- Go-воркер не запущен: `InspectGatewayCharge` ждёт ScheduleToStart 20 с и без повторов уступает поведению M8 (EC-93, T-M9-12).
- Старые истории проигрываются без новых команд (`DEFAULT_VERSION`, EC-108, T-W-20, T-W-21); история с веткой записана как `history-charge-inspected-v1.json` (T-W-22).
- Инвариант TTL резерва с новыми слагаемыми: 60 + 300 + 60 + 60 + 60 + 60 + 60 = 660 < 900 (T-CFG-01).
- Контракт PHP и Go: `contracts/inspect-gateway-charge/v1/`, golden-файлы читают обе стороны.
