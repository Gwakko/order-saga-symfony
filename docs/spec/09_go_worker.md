# 09. Go-воркер для одной активности саги (M9)

Статус: спецификация M9.0. Кода нет. Исполнители M9.1…M9.5 читают этот файл целиком, затем свой раздел §11.

Источники, по которым проверены факты этого файла (2026-11-25):
- код: `src/Saga/Infrastructure/Temporal/**`, `src/Payment/**`, `config/packages/temporal.yaml`, `compose.yaml`, `.rr.yaml`, `Makefile`, `bin/temporal-worker.php`, `bin/check-edge-case-coverage`, `tests/Workflow/**`, `gateway-emulator/README.md`;
- отчёты `docs/reports/M6.1.md` … `M6.4.md`, `M8a-flaky.md`;
- PHP SDK `vendor/temporal/sdk/src` (v2.20): `Workflow::getVersion(string, int, int)`, `Workflow::sideEffect`, `Workflow::newUntypedActivityStub`, `ActivityStubInterface::execute(string $name, array $args, Type|string|... $returnType)`, `ActivityOptions::withScheduleToStartTimeout`, `withHeartbeatTimeout`, `DataConverter\JsonConverter` (`JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION`), `ApplicationFailure::getType()/isNonRetryable()/getDetails()`, `TimeoutFailure::getTimeoutType()`;
- Go SDK `go.temporal.io/sdk@v1.52.0` (скачан `go get`, исходники в module cache): `converter.MetadataEncodingJSON = "json/plain"`, `converter.GetDefaultDataConverter()`, `temporal.NewApplicationErrorWithOptions(msg, errType, ApplicationErrorOptions{NonRetryable, Cause, Details, NextRetryDelay, Category})`, `temporal.NewNonRetryableApplicationError`, `(*ApplicationError).Type()/NonRetryable()`, `log.NewStructuredLogger(*slog.Logger)`, `worker.Options.WorkerStopTimeout`, `worker.InterruptCh()`, `activity.GetInfo/GetLogger/GetWorkerStopChannel`, `testsuite.WorkflowTestSuite.NewTestActivityEnvironment()`, `TestActivityEnvironment.RegisterActivityWithOptions/ExecuteActivity/SetTestTimeout/SetWorkerStopChannel`, `client.Dial`, `Client.CheckHealth`, `worker.NewWorkflowReplayer`; комментарий SDK: «ScheduleToStartTimeout is always non-retryable»;
- история `tests/Workflow/Fixtures/history-happy.json`: 59 событий, payload `metadata.encoding = base64("json/plain")`, `data = base64(JSON)`, в `ACTIVITY_TASK_SCHEDULED` записаны `activityType.name` и `taskQueue.name = "order-saga-test"`.

## 1. Цель и границы

Цель: один Go-процесс (`worker-go`) исполняет одну активность саги на своей task queue; PHP workflow вызывает её по имени. Показать кросс-языковую работу через Temporal, ничего не сломав.

Обязательные свойства результата (каждое проверяется тестом из §9):
1. `make ci` зелёный, `make smoke` без изменений поведения.
2. `tests/Workflow/Fixtures/history-happy.json` не меняется ни на байт (`git diff --exit-code`), T-W-20 зелёный без правки теста.
3. При выключенном флаге (значение по умолчанию) исход любого сценария саги (статус заказа, платежа, резерва, число списаний и возвратов у шлюза) тот же, что в M8.
4. Go не читает и не пишет БД PHP, не публикует события, не дублирует агрегаты и инварианты домена.

Не входит: перенос существующих активностей в Go, общая с PHP схема БД, Go workflow в продакшен-коде, gRPC/protobuf data converter, метрики Prometheus, нагрузочные цифры.

## 2. Какая активность уходит в Go

### 2.1 Существующие активности: все требуют БД PHP

| Активность | Реализация | Что нужно Go для переноса |
|---|---|---|
| `ReserveKey`, `ConsumeReservation`, `ReleaseReservation`, `RevokeSoldKey` | `InventoryActivitiesImpl` → `CommandBus` | агрегаты `KeyReservation`/`ProductKey`, CAS, partial unique index, outbox |
| `MarkOrder*`, `CompleteOrder`, `FailOrder`, `*Compensation`, `MarkOrderManualReview`, `IsCancellationRequested`, `CancelOrderBeforeStart`, `FinishOrderRefund` | `OrderActivitiesImpl` → `CommandBus`/`QueryBus` | агрегат `Order`, таблица переходов, версия CAS, outbox |
| `ChargePayment` | `PaymentActivitiesImpl` → `ChargePaymentHandler` | `Payment` (8 статусов, версия), `payments(order_id)` unique, `ChargeRecorder` (mismatch, события), проверка резерва (`ReservationStatusReader`, charge guard), цена заказа (`OrderPriceReader`), Redis-breaker |
| `RefundPayment` | `PaymentActivitiesImpl` → `RefundPaymentHandler` | всё из строки выше плюс `PaymentRefunder` |

Вывод: каждая существующая активность это тонкая обёртка над use case с БД (`M6.2.md`: «Реализации только вызывают `CommandBus`»). Перенос любой из них в Go требует дублировать домен или давать Go доступ к таблицам PHP. Это противоречит свойству 4 из §1.

### 2.2 Варианты

**A. `ChargePayment` в Go целиком** (так было в `03_saga.md` §8 и `00_overview.md`). Go пишет в `payments` или в свой сервис.
- Минусы: дублирование `Payment`, `ChargeRecorder`, outbox-событий `PaymentSucceeded`/`PaymentCapturedWithMismatch`, charge guard по TTL резерва; два breaker с разным состоянием на один шлюз; меняется task queue в happy path, значит фикстура T-W-20 и версия workflow (§3.1).
- Плюс: показывает «настоящий» перенос.
- Отклонено.

**B. Новая активность в happy path** (`NotifyKeyDelivered` из `06_milestones.md` M9.0): после `CompleteOrder` Go уведомляет внешний сервис.
- Минусы: нужен новый эмулятор получателя; меняется happy path и фикстура T-W-20; соблазн передать код ключа (INV-17) в payload; новый эффект без бизнес-нужды в домене.
- Отклонено.

**C. Новая активность `InspectGatewayCharge` в ветке неизвестного исхода оплаты (рекомендуется).**
- Что делает: `GET /charges/{idempotencyKey}` у шлюза (тот же эмулятор), с таймаутом и circuit breaker, без записи куда-либо; возвращает факт: списание есть и успешно / отклонено / не найдено, и совпадает ли сумма с ожидаемой.
- Где вызывается: только когда `ChargePayment` исчерпал попытки и `FailureClassifier` вернул `PaymentUnavailable` (EC-34, неизвестный исход). Сегодня в этой точке сага компенсирует (`RefundPayment` переводит платёж в `Abandoned` или возвращает найденное списание), а поздний успех потом ловит `ReconcilePayments` (EC-28) и возвращает деньги покупателю.
- Что меняется при включённом флаге: если шлюз уже видит успешное списание на ожидаемую сумму, сага не возвращает деньги, а подтверждает оплату повторным `ChargePayment` (PHP, тот же ключ идемпотентности, шлюз не списывает второй раз) и продолжает обычный хвост (`MarkOrderPaid` → guard → `ConsumeReservation` → `CompleteOrder`). Если шлюз говорит «отклонено», причина провала становится `PaymentDeclined` вместо `PaymentUnavailable`. Во всех остальных случаях, включая любую ошибку Go, поведение M8.
- Почему подходит Go: stateless HTTP-вызов, таймауты, breaker, строгий разбор ответа; домен не нужен, ответ сравнивается только с ожидаемыми `amount`/`currency`, которые передаёт PHP.
- Плюсы: happy path не трогается (свойство 2 по построению); флаг по умолчанию выключен (свойство 3); полезный эффект: заказ, оплаченный «вслепую», завершается выдачей ключа, а не возвратом.
- Минусы: одна дополнительная PHP-активность `PrepareChargeInspection` (Go должен получить ключ идемпотентности = `paymentId`, а он есть только в БД PHP); ветка редкая, поэтому сквозной тест нужен отдельный (§9.4).

**Решение: C.** `03_saga.md` §8 переписывается в M9.5 со ссылкой на этот файл.

### 2.3 Как C встраивается в сагу

Изменение только в `OrderFulfillmentWorkflowImpl`, шаг `ChargePayment`. Псевдокод (имена PHP SDK проверены, синтаксис по смыслу):

```
try:
  paymentId = yield payment.chargePayment(orderId)            // как сейчас
catch ActivityFailure f:
  if classifier.reasonFor(f, ChargePayment) != PaymentUnavailable: throw f   // как сейчас
  v = yield Workflow::getVersion('gateway-inspection', DEFAULT_VERSION, 1)
  if v == DEFAULT_VERSION: throw f                            // старые истории: ветки нет
  paymentId = yield from inspectAndRecover(orderId, f)        // бросает f, InspectionDeclined или новую ActivityFailure
// дальше без изменений: MarkOrderPaid, guard, ConsumeReservation, CompleteOrder

inspectAndRecover(orderId, f):
  progress = 'inspecting payment'
  try: prep = PrepareChargeInspection(orderId)                // PHP, профиль order_step
  catch ActivityFailure: throw f                              // нет данных: поведение M8
  if !prep.inspect: throw f                                   // флаг выключен / платежа нет / статус не Pending|Unknown
  try: r = InspectionResult::fromArray(yield inspector.inspectGatewayCharge(InspectionRequest::from(prep, orderId)->toArray()))
  catch ActivityFailure | InspectionContractViolation: throw f
  match:
    r.outcome == succeeded && r.matchesExpected:
      progress = 'recovering payment'
      return text(yield recovery.chargePayment(orderId))      // профиль charge_recovery; его ActivityFailure классифицируется как обычно
    r.outcome == declined: throw InspectionDeclined           // внешний catch: reason = PaymentDeclined → compensate
    default: throw f                                          // not_found, mismatch: поведение M8
```

Внешний `catch` получает новую ветку `catch (InspectionDeclined)` → `compensate(..., FailureReason::PaymentDeclined, ChargePayment)`. `FailureClassifier` не меняется.

Почему флаг читается PHP-активностью `PrepareChargeInspection`, а не из `SagaConfiguration` в коде workflow:

| | Флаг в активности (рекомендуется) | Флаг в конфиге workflow через `Workflow::sideEffect` |
|---|---|---|
| Детерминизм | ответ записан в истории | записан маркером `sideEffect` |
| Переключение | без рестарта RR-воркера (параметр контейнера читает handler) | меняет `SagaConfigurationHolder`, нужен рестарт воркера |
| Тесты workflow | мок активности на тест: `inspect=true/false` в одном прогоне | один процесс тестового воркера = одно значение флага на весь набор |
| Цена | +1 активность в ветке отказа при выключенном флаге (исход тот же) | +1 маркер |

`PrepareChargeInspection` возвращает `inspect=false`, если флаг выключен, платежа нет или статус не `Pending`/`Unknown`; иначе `paymentId`, `amount`, `currency` из записи `payments`.

Отмена: дополнительный guard не нужен. После восстановления хвост идёт через существующий guard перед `ConsumeReservation`; если отмена пришла, компенсация `RefundPayment` вернёт подтверждённое списание (EC-110).

## 3. Совместимость с историей и replay-тестом

### 3.1 Перенос существующей активности против новой активности

| | Перенос (`ChargePayment` на `payment-go`) | Новая активность в ветке отказа (выбрано) |
|---|---|---|
| Команды happy path | меняется `taskQueue.name` в `ScheduleActivityTask` (M8a: уникальное имя очереди уже давало `NonDeterministicWorkflowException` в T-W-20) | не меняются |
| `getVersion` | обязателен вокруг выбора опций стаба; маркер попадает в happy path | обязателен в ветке; в happy path маркера нет (ветка не достигается) |
| Фикстура `history-happy.json` | старая должна остаться (проверяет старую ветку), новая пишется вторым файлом `history-happy-v2.json` | без изменений |
| Риск ложной зелени | высокий: перезапись фикстуры без версии делает T-W-20 бесполезным (`M6.4.md`) | T-W-20 не видит новую ветку вообще, поэтому нужны свои фикстуры (§3.2) |

### 3.2 Фикстуры и тесты replay

| Файл | Когда пишется | Сценарий | Тест |
|---|---|---|---|
| `history-happy.json` | не трогается | happy path | T-W-20 (без изменений) |
| `history-charge-exhausted-v0.json` | M9.1, **до** изменения workflow, текущим кодом | EC-34: `ChargePayment` исчерпан (`TimeoutFailure` по ScheduleToClose, моки как в T-W-07) → компенсации → `Failed` | T-W-21: replay текущим кодом проходит |
| `history-charge-inspected-v1.json` | M9.4, после изменения | флаг включён: исчерпание → `PrepareChargeInspection` → `InspectGatewayCharge` (`succeeded`, совпадает) → `ChargePayment` (recovery) → … → `Completed` | T-W-22: replay проходит |

Запись: по образцу `testRecordHappyPathHistory` (`RECORD_HISTORY=1`), отдельные методы `testRecordChargeExhaustedHistory` (M9.1) и `testRecordChargeInspectedHistory` (M9.4), цель Makefile `wf-record-history` расширяется именем фильтра. Workflow ID фикстур фиксированный (как `0190a5c4-…-000000000020`), очередь Go в наборе `workflow` фиксированная `payment-go-test` (уникальное имя ломает replay, см. M8a).

### 3.3 Мутационная проверка (обязательна в отчёте M9.4)

Каждая мутация вносится временно (`// TEMPORARY`), прогоняется `make test-wf`, откатывается; в отчёт пишется фактическое сообщение об ошибке.

| # | Мутация | Ожидание |
|---|---|---|
| M1 | убрать `getVersion`, ветка выполняется всегда | T-W-21 красный: лишняя команда `ScheduleActivityTask PrepareChargeInspection` против `StartOrderCompensation` в v0 |
| M2 | переименовать `changeId` `'gateway-inspection'` | T-W-22 красный (маркер версии не совпал) |
| M3 | переименовать activity type `InspectGatewayCharge` | T-W-22 красный |
| M4 | поменять местами `PrepareChargeInspection` и `InspectGatewayCharge` | T-W-22 красный |
| M5 | очередь Go = `order-saga-test` вместо `payment-go-test` | T-W-22 красный (подтверждает, что очередь входит в сравнение; если зелёный, записать в отчёт и убрать M5 из списка) |
| M6 | любая из M1…M4 | T-W-20 остаётся зелёным (доказывает, что happy path не задет и что T-W-21/22 необходимы) |

Плюс в M9.4 и M9.5: `git diff --exit-code tests/Workflow/Fixtures/history-happy.json`.

## 4. Контракт PHP ↔ Go

### 4.1 Идентификаторы

| Что | Значение |
|---|---|
| Activity type | `InspectGatewayCharge` |
| Task queue | `payment-go` (dev, compose); `payment-go-test` (набор `workflow`, фиксированная); `payment-go-test-<hex>` (integration и e2e, уникальная на прогон, как `TemporalTestQueue` в M8a) |
| Namespace | `default` |
| Версия контракта | `contractVersion: 1` во входе и выходе |
| PHP-сторона | `#[ActivityInterface] GatewayInspectionActivities` с `#[ActivityMethod(name: 'InspectGatewayCharge')] inspectGatewayCharge(array $input): array`; реализации в PHP нет, в `bin/temporal-worker.php` не регистрируется |
| Go-сторона | `RegisterActivityWithOptions(fn, activity.RegisterOptions{Name: "InspectGatewayCharge"})` |
| Golden-файлы | `contracts/inspect-gateway-charge/v1/` в корне репозитория |

### 4.2 Data converter

Обе стороны используют конвертер по умолчанию, payload `json/plain`:
- PHP `JsonConverter` кодирует массив аргумента как JSON-объект (одна активность = один аргумент = один payload); результат с `returnType` `array` декодируется в ассоциативный массив.
- Go `converter.GetDefaultDataConverter()` декодирует `json/plain` в структуру через `encoding/json` (неизвестные поля игнорируются) и кодирует результат так же.
- Подтверждение по истории: все payload в `history-happy.json` имеют `encoding = json/plain`.

Проверка без сервера (T-M9-03, T-GO-12): PHP кодирует `InspectionRequest` через `DataConverter::createDefault()->toPayloads(...)` (точное имя метода проверить при M9.1 в `vendor/temporal/sdk/src/DataConverter/DataConverter.php`) и сравнивает декодированный JSON с `input.v1.json`; Go строит `commonpb.Payload{Metadata: {"encoding": "json/plain"}, Data: <golden>}` и декодирует `GetDefaultDataConverter().FromPayload` в DTO, затем кодирует свой выход и сравнивает со `output.*.json` как структуры (не побайтно: PHP экранирует `/` как `\/`). Проверка на сервере: сквозной T-M9-10.

### 4.3 Вход

`contracts/inspect-gateway-charge/v1/input.schema.json` (JSON Schema 2020-12):

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["contractVersion", "orderId", "idempotencyKey", "expected"],
  "properties": {
    "contractVersion": {"const": 1},
    "orderId": {"type": "string", "format": "uuid"},
    "idempotencyKey": {"type": "string", "minLength": 1, "maxLength": 64, "pattern": "^[A-Za-z0-9-]+$"},
    "expected": {
      "type": "object",
      "required": ["amount", "currency"],
      "properties": {
        "amount": {"type": "integer", "minimum": 1, "maximum": 100000000},
        "currency": {"enum": ["EUR", "USD", "PLN", "UAH"]}
      },
      "additionalProperties": false
    }
  },
  "additionalProperties": true
}
```

Пример `input.v1.json`:

```json
{"contractVersion": 1, "orderId": "0190a5c4-0000-7000-8000-000000000020", "idempotencyKey": "0190a5c4-0000-7000-8000-0000000000a1", "expected": {"amount": 1999, "currency": "EUR"}}
```

`idempotencyKey` = `paymentId` (ключ, с которым PHP делал `POST /charges`). `orderId` только для логов и корреляции. Верхняя граница `amount` = `Money::MAX_AMOUNT` (100 000 000), валюты = `Currency` enum. `additionalProperties: true` на верхнем уровне: в пределах версии разрешены только добавляемые необязательные поля.

### 4.4 Выход

`output.schema.json`:

```json
{
  "type": "object",
  "required": ["contractVersion", "outcome", "charge", "matchesExpected", "checkedAt"],
  "properties": {
    "contractVersion": {"const": 1},
    "outcome": {"enum": ["succeeded", "declined", "not_found"]},
    "charge": {
      "oneOf": [
        {"type": "null"},
        {
          "type": "object",
          "required": ["chargeId", "amount", "currency", "declineReason"],
          "properties": {
            "chargeId": {"type": "string", "minLength": 1, "maxLength": 128},
            "amount": {"type": "integer", "minimum": 1},
            "currency": {"type": "string", "pattern": "^[A-Z]{3}$"},
            "declineReason": {"type": ["string", "null"], "maxLength": 256}
          }
        }
      ]
    },
    "matchesExpected": {"type": "boolean"},
    "checkedAt": {"type": "string", "pattern": "^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}\\+00:00$"}
  }
}
```

Golden-примеры:
- `output.succeeded.json`: `{"contractVersion":1,"outcome":"succeeded","charge":{"chargeId":"ch_01","amount":1999,"currency":"EUR","declineReason":null},"matchesExpected":true,"checkedAt":"2026-11-25T10:15:30.123+00:00"}`
- `output.mismatch.json`: то же, `"amount":2000`, `"matchesExpected":false`
- `output.declined.json`: `"outcome":"declined"`, `"declineReason":"insufficient_funds"`, `"matchesExpected":false`
- `output.not_found.json`: `"outcome":"not_found"`, `"charge":null`, `"matchesExpected":false`

Правила:
- `charge = null` тогда и только тогда, когда `outcome = not_found`.
- `matchesExpected = true` только при `outcome = succeeded` и равенстве `amount` и `currency` ожидаемым.
- `currency` в выходе не ограничена enum: шлюз может ответить чужой валютой (режим эмулятора `wrong-currency`), это `matchesExpected = false`, а не ошибка.

### 4.5 Деньги и время

- Деньги: целые minor units, JSON integer. Go: `int64` в DTO и домене, ни одного `float64` в пакетах `domain` и `usecase` (проверка: T-GO-15, `go vet` + тест-сканер исходников на `float`). Ответ шлюза с дробным числом, строкой, отрицательным, нулём или числом вне `int64` = нечитаемый ответ (§4.6). Go декодирует тело шлюза через `json.Decoder.UseNumber()` и `json.Number.Int64()`, проверяя отсутствие `.`/`e`.
- PHP: `InspectionResult::fromArray` принимает только `int` (`is_int`); float (так PHP декодирует число > `PHP_INT_MAX`) = `InspectionContractViolation` → поведение M8.
- Время: `checkedAt` в UTC, формат RFC 3339 с миллисекундами и смещением `+00:00` (как EC-76). Go: `t.UTC().Format("2006-01-02T15:04:05.000+00:00")` (литерал смещения, а не `Z07:00`, который даёт `Z`). PHP разбирает `DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', ...)`. Workflow не использует `checkedAt` в решениях, только для логов и `progress`.

### 4.6 Ошибки и их сопоставление

Бизнес-исходы (`succeeded`, `declined`, `not_found`) возвращаются результатом, не ошибкой. Ошибка = «ответа нет». Go возвращает `temporal.NewApplicationErrorWithOptions(msg, type, opts)`; PHP видит `ActivityFailure` с `getPrevious()` = `ApplicationFailure`, `getType()` = строка типа, `isNonRetryable()`.

| Тип (`errType`) | Когда (Go) | NonRetryable | PHP workflow |
|---|---|---|---|
| `InvalidInspectionInput` | `Input.Validate()` не прошла | да | поведение M8 + `Workflow::getLogger()->error` |
| `UnsupportedContractVersion` | `contractVersion` не в списке поддерживаемых; `Details`: `{"requested":2,"supported":[1]}` | да | поведение M8 + error-лог |
| `GatewayRequestRejected` | шлюз ответил 4xx, кроме `404 charge_not_found` | да | поведение M8 |
| `GatewayUnavailable` | таймаут, обрыв, 5xx, `404` без `charge_not_found`, не JSON, тело > лимита, нет `chargeId`, неизвестный статус (`weird`), неверная сумма/валюта, `idempotencyKey` в ответе не совпал | нет | повтор Temporal; исчерпание → поведение M8 |
| `ChargePending` | статус `pending` (эмулятор `late-success`) | нет | повтор; исчерпание → поведение M8 |
| `CircuitOpen` | breaker открыт, вызов не делался; `NextRetryDelay` = остаток open-интервала, если библиотека его даёт (проверить при M9.2), иначе не задаётся | нет | повтор; исчерпание → поведение M8 |
| `TimeoutFailure` (не Go) | `SCHEDULE_TO_START` (нет поллеров), `START_TO_CLOSE`, `SCHEDULE_TO_CLOSE` | ScheduleToStart всегда non-retryable (SDK) | поведение M8 + warning-лог с типом таймаута |

Как workflow отличает «отказ банка» от «неизвестного исхода»: отказ это успешный результат `outcome = declined` (шлюз доказал отказ); неизвестный исход это `not_found`, `succeeded` с `matchesExpected = false`, любая ошибка или таймаут. Неизвестный исход всегда ведёт в поведение M8, где `RefundPayment` и `ReconcilePayments` уже разрешают неопределённость (EC-26…EC-28).

Источник правды о повторяемости: Go (флаг на ошибке). Дополнительно PHP-профиль перечисляет те же три non-retryable типа в `RetryOptions` (страховка, если Go ошибся). Списки сверяются тестом по `contracts/inspect-gateway-charge/v1/errors.json`:

```json
{"errors": [
  {"type": "InvalidInspectionInput", "nonRetryable": true},
  {"type": "UnsupportedContractVersion", "nonRetryable": true},
  {"type": "GatewayRequestRejected", "nonRetryable": true},
  {"type": "GatewayUnavailable", "nonRetryable": false},
  {"type": "ChargePending", "nonRetryable": false},
  {"type": "CircuitOpen", "nonRetryable": false}
]}
```

T-GO-13: каждая ошибка use case маппится в тип и флаг из файла, и нет типов вне файла. T-M9-04: `non_retryable_types` профиля `inspect_gateway_charge` равен множеству `nonRetryable: true` из файла.

### 4.7 Таймауты, повторы, идемпотентность

Новые профили в `config/packages/temporal.yaml`:

| Профиль | Активность | task_queue | ScheduleToStart | StartToClose | ScheduleToClose | Retry | Non-retryable |
|---|---|---|---|---|---|---|---|
| `inspect_gateway_charge` | `InspectGatewayCharge` (Go) | `%temporal.go_task_queue%` | 20 с | 15 с | 60 с | 2 с / 2 / 10 с / без лимита | `non_retryable_types`: 3 типа из §4.6 |
| `charge_recovery` | `ChargePayment` (PHP, повтор) | очередь саги | — | 15 с | 60 с | 2 с / 2 / 10 с / без лимита | как `charge_payment` |
| `order_step` (существующий) | `PrepareChargeInspection` (PHP) | очередь саги | — | 5 с | 60 с | как есть | как есть |

Обоснование:
- HTTP-таймаут Go 5 с < StartToClose 15 с: адаптер сдаётся раньше Temporal, ошибка типизирована. Go-конфиг отклоняет `GATEWAY_TIMEOUT > 10s` (T-GO-05).
- Heartbeat не используется: одна HTTP-попытка ≤ 5 с; heartbeat ускорил бы только обнаружение мёртвого воркера (вместо 15 с StartToClose), что для редкой ветки не нужно. Решение пересматривается, если появится цикл или долгий вызов.
- ScheduleToStart 20 с: если Go-воркера нет, сага не ждёт все 60 с; таймаут non-retryable, ветка уходит в поведение M8 (EC-93).
- Инвариант TTL (`SagaConfigurationPass`, T-CFG-01) расширяется: `reservation_ttl > ReserveKey + ChargePayment + order_step(Prepare) + inspect_gateway_charge + charge_recovery + ConsumeReservation (все ScheduleToClose) + charge_guard` = 60 + 300 + 60 + 60 + 60 + 60 + 60 = 660 < 900. Слагаемые учитываются всегда, независимо от флага.

Изменения `SagaConfiguration` (M9.4): необязательные поля профиля `task_queue` (по умолчанию очередь саги), `schedule_to_start` (секунды), `non_retryable_types` (строки `^[A-Z][A-Za-z0-9]*$`, не классы; текущая проверка `is_a(..., Throwable)` для `non_retryable` остаётся). Если `RetryOptions::withNonRetryableExceptions` с не-class-string не проходит PHPStan max, записать в отчёт выбранный способ (например, `@param list<string>` во внутреннем методе-обёртке), без `ignoreErrors`.

Идемпотентность:
- `InspectGatewayCharge` только читает (`GET`), повтор и параллельные вызовы безопасны; Go не делает `POST /charges` и `POST /refunds` (T-GO-08 проверяет методы fake-шлюза).
- Восстановление через `ChargePayment` идёт с тем же `Idempotency-Key = paymentId`: платёж `Unknown` → `findCharge` → запись успеха; если `findCharge` упал, `charge()` с тем же ключом, эмулятор возвращает ту же запись без повторного списания. Итог: `executed = 1` в `/__admin/stats` (T-M9-11).

## 5. Структура Go-проекта

Каталог `worker-go/`, модуль `go.mod` в нём. Путь модуля `order-saga-symfony/worker-go` (без домена, публикация как библиотеки не планируется).

```
worker-go/
  go.mod, go.sum
  Dockerfile, .dockerignore
  cmd/worker/main.go            тонкий: signal.NotifyContext, config.Load, app.BuildWorker, Run, код выхода
  cmd/healthcheck/main.go       тонкий: GET http://127.0.0.1:$HEALTH_PORT/readyz, exit 0/1 (distroless без shell)
  internal/config/              Load(lookup func(string) (string, bool)) (Config, error); Validate(); все env здесь
  internal/domain/charge/       Money{Amount int64; Currency string}, Status, Observation, (Observation).Matches(Money) bool; без тегов
  internal/usecase/inspectcharge/
    usecase.go                  InspectChargeInput, InspectChargeOutput, Validate(), UseCase.Execute(ctx, in) (out, error)
    ports.go                    ChargeLookup, Clock
    errors.go                   ErrInvalidInput, ErrGatewayUnavailable, ErrChargePending, ErrCircuitOpen, ErrRequestRejected
  internal/adapter/gateway/httpgateway/   Config; New(cfg, Deps) (*Client, func() error, error); DTO с json-тегами + маппинг
  internal/adapter/gateway/breaker/       Config; New(cfg, Deps{Next ChargeLookup}) (...); декоратор ChargeLookup
  internal/adapter/clock/                 System (UTC)
  internal/adapter/temporal/activity/     InspectGatewayChargeRequest/Response (json-теги), маппинг DTO ↔ Input/Output, ошибки → ApplicationError
  internal/adapter/temporal/workerhost/   Config; New(cfg, Deps{Logger, Activity}) (*Host, func() error, error): client.Dial + worker.New
  internal/adapter/health/                Config; New(cfg, Deps{Probe}) (...): /livez, /readyz
  internal/app/                           BuildWorker(ctx, config.Config, *slog.Logger) (*WorkerApp, func(), error); BuildHealthcheck(config.Health)
```

Правила (обязательны, проверяются ревью и тестами):
- Use case: `InspectChargeInput{ContractVersion int; OrderID string; IdempotencyKey string; Expected charge.Money}` и `InspectChargeOutput{Outcome charge.Outcome; Charge *charge.Observation; MatchesExpected bool; CheckedAt time.Time}` без тегов; `Input.Validate()` проверяет правила без состояния (версия ∈ поддерживаемых, UUID, ключ по регулярке, сумма 1…100 000 000, валюта ∈ {EUR, USD, PLN, UAH}); `Execute(ctx, in) (InspectChargeOutput, error)`.
- Логика `Execute`: `Validate` → `lookup.FindCharge(ctx, key)` → не найдено: `not_found`; `pending`: `ErrChargePending`; `succeeded`: `MatchesExpected = obs.Matches(in.Expected)`; `declined`: `declined`. `CheckedAt = clock.Now().UTC()`.
- Поддержка версий: список `SupportedContractVersions = []int{1}` в пакете use case; ответ всегда в версии запроса.
- Доменные типы без `json`/`db` тегов; теги только в `httpgateway` DTO (ответ шлюза) и `activity` DTO (граница Temporal). Маппинг явный на каждой границе. T-GO-14 проверяет через `reflect` (или `go/ast`), что в `internal/domain` и `internal/usecase` нет struct-тегов.
- Адаптеры владеют своим `Config`, получают его аргументом, env не читают (T-GO-14 ищет `os.Getenv`/`os.LookupEnv` вне `internal/config` и `cmd`).
- `internal/app` единственное место, где собираются зависимости; use case получает только порты, не контейнер и не `*app.WorkerApp`.
- HTTP-адаптер: `http.Client{Timeout: cfg.Timeout}`, заголовки `Idempotency-Key`, `Accept: application/json`; тело читается через `io.LimitReader(cfg.MaxBodyBytes+1)` (64 KiB), превышение = `ErrGatewayUnavailable`; `declineReason` обрезается до 256 байт по границе руны; без повторов внутри адаптера (повторяет Temporal), как `HttpPaymentGateway`.
- Breaker: `github.com/sony/gobreaker/v2` (последняя v2.4.0 по Go proxy 2026-11-25; перепроверить при M9.2). Считает отказом только `ErrGatewayUnavailable`; `not_found` и `ErrRequestRejected` успех для breaker. Стартовые значения: открыть после 5 подряд отказов, open 30 с, half-open 1 запрос. Состояние в памяти процесса, не общее с Redis-breaker PHP: разные операции (`GET` против `POST`), эмулятор не применяет режимы сбоев к `GET`. Альтернатива: свой breaker на 80 строк по образцу `CircuitBreaker.php`; выбор библиотеки снижает объём кода, который надо тестировать.

### 5.1 Логирование: `log/slog`

| | `log/slog` (выбрано) | `zap` |
|---|---|---|
| Адаптер к Temporal | в SDK: `go.temporal.io/sdk/log.NewStructuredLogger(*slog.Logger)` (проверено в v1.52.0, `log/slog.go`) | свой адаптер к `log.Logger`; модуль `go.temporal.io/sdk/contrib/zap` в Go proxy не найден (2026-11-25) |
| Зависимости | стандартная библиотека | +1 модуль |
| Производительность | достаточно для одной редкой активности | выше, здесь не нужна |

JSON-хендлер в compose (`LOG_FORMAT=json`), текстовый локально. Поля: `order_id`, `idempotency_key`, `attempt` (`activity.GetInfo(ctx).Attempt`), `workflow_id`, `outcome`, `error_type`. Сумма и валюта логируются, данных карты нет по построению.

### 5.2 Версии (проверено `go list -m -versions` 2026-11-25; перепроверить при M9.1)

| Что | Значение |
|---|---|
| Go на машине | 1.27.3 (`go version`) |
| `go.mod` | `go 1.27.0` |
| `go.temporal.io/sdk` | последняя v1.52.0 (опубликована 2026-11-24, требует `go 1.26.0`); предыдущая v1.51.0 (2026-11-10). M9.1 фиксирует последнюю версию на дату работ командой `go list -m -json go.temporal.io/sdk@latest` и проверяет, что API из шапки этого файла на месте |
| `github.com/sony/gobreaker/v2` | v2.4.0 |
| Тестовые | стандартный `testing`; `github.com/santhosh-tekuri/jsonschema/v6` v6.0.3 только в тестах для проверки golden по схемам (альтернатива: без схемы, только golden; решение M9.1) |
| Docker | `golang:1.27.3-alpine3.24` (сборка), `golang:1.27.3-bookworm` (тесты, нужен gcc для `-race`), `gcr.io/distroless/static-debian12:nonroot` (рантайм); теги проверены в Docker Hub / gcr 2026-11-25, digest зафиксировать при M9.3 |

### 5.3 Конфигурация (env, читает только `internal/config`)

| Переменная | По умолчанию | Валидация |
|---|---|---|
| `TEMPORAL_ADDRESS` | `temporal:7233` | `host:port` |
| `TEMPORAL_NAMESPACE` | `default` | непустая |
| `GO_WORKER_TASK_QUEUE` | — | обязательна, `^[a-z0-9-]{1,100}$` |
| `GATEWAY_BASE_URL` | — | обязательна, `http`/`https` URL |
| `GATEWAY_TIMEOUT` | `5s` | `time.ParseDuration`, 100 мс … 10 с |
| `GATEWAY_MAX_BODY_BYTES` | `65536` | 1 KiB … 1 MiB |
| `BREAKER_FAILURES` / `BREAKER_OPEN_FOR` | `5` / `30s` | > 0 |
| `WORKER_MAX_CONCURRENT_ACTIVITIES` | `8` | 1 … 256 |
| `WORKER_STOP_TIMEOUT` | `10s` | ≥ `GATEWAY_TIMEOUT`, ≤ 60 с |
| `HEALTH_ADDR` | `:8090` | `host:port` |
| `LOG_LEVEL` / `LOG_FORMAT` | `info` / `json` | из списка |

`Load` собирает все ошибки сразу (`errors.Join`); `main` при ошибке пишет их в stderr и выходит с кодом 2 до подключения к Temporal (EC-106).

## 6. Docker и Compose

`worker-go/Dockerfile` (multi-stage):
1. `golang:1.27.3-alpine3.24 AS build`: `go mod download` отдельным слоем (кэш), `CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/worker ./cmd/worker` и `/out/healthcheck`.
2. `gcr.io/distroless/static-debian12:nonroot`: копирует два бинаря, `USER nonroot:nonroot`, `ENTRYPOINT ["/worker"]`, `HEALTHCHECK` не в образе (задаётся в compose).

`compose.yaml`:

```yaml
  worker-go:
    build: { context: ./worker-go }
    profiles: ["go"]
    environment:
      TEMPORAL_ADDRESS: temporal:7233
      GO_WORKER_TASK_QUEUE: ${GO_WORKER_TASK_QUEUE:-payment-go}
      GATEWAY_BASE_URL: http://gateway-emulator:8081
    depends_on:
      temporal: { condition: service_started }
      gateway-emulator: { condition: service_started }
    healthcheck: { test: ["CMD", "/healthcheck"], interval: 10s, timeout: 3s, retries: 3, start_period: 15s }
    stop_grace_period: 20s
    restart: unless-stopped
    read_only: true
    cap_drop: ["ALL"]
    security_opt: ["no-new-privileges:true"]

  go-toolchain:
    image: golang:1.27.3-bookworm
    profiles: ["tools"]
    working_dir: /src/worker-go
    volumes: [".:/src", "go_mod_cache:/go/pkg/mod", "go_build_cache:/root/.cache/go-build"]
    environment: { TEMPORAL_ADDRESS: temporal:7233 }
```

плюс `volumes: go_mod_cache, go_build_cache`.

- Профиль `go`, а не `workers`: `make smoke` не собирает Go-образ и не меняет поведение; при выключенном флаге запущенный Go-воркер простаивает.
- `/readyz` = воркер запущен (`worker.Start` без ошибки) и последний `Client.CheckHealth` (раз в 10 с) успешен; `/livez` = процесс отвечает.
- PHP: `temporal.go_task_queue: '%env(default:go_task_queue_default:TEMPORAL_GO_TASK_QUEUE)%'` (точный синтаксис env-процессора проверить при M9.4), dev `payment-go`; `when@test` по умолчанию `payment-go-test`; флаг `temporal.go_inspection.enabled: '%env(bool:TEMPORAL_GO_INSPECTION_ENABLED)%'`, по умолчанию `false`.
- Изоляция очередей (M8a): integration и e2e задают `TEMPORAL_GO_TASK_QUEUE=payment-go-test-<hex>` (тот же суффикс, что у `TemporalTestQueue::isolate()`), Go-воркер e2e запускается с тем же значением `GO_WORKER_TASK_QUEUE`; набор `workflow` использует фиксированную `payment-go-test` из-за фикстур (§3.2). Оставшиеся на dev-сервере задачи Go-очередей прошлых прогонов не достаются текущему.

## 7. Makefile и CI

```
GO_RUN ?= docker compose run --rm -T go-toolchain
go-test:      $(GO_RUN) sh -c 'test -z "$$(gofmt -l .)" && go vet ./... && go test -race -count=1 ./...'
go-test-int:  $(GO_RUN) go test -race -count=1 -tags=integration ./...      # нужен сервис temporal
test-go-e2e:  tests/E2E/go-e2e.sh                                             # не в ci, как smoke
ci:           ... test-unit, go-test, test-int, go-test-int, test-wf, test-func
```

- `go-test` без сети после первого `go mod download` (кэш в volume), без Temporal-сервера: unit, контракт, `testsuite`-тесты активности.
- `go-test-int`: Go-тесты с build tag `integration` против compose `temporal` (уникальная очередь `payment-go-gotest-<random>`, свой fake-шлюз `httptest` в процессе теста); без `TEMPORAL_ADDRESS` тест делает `t.Skip`.
- Локально без Docker: `cd worker-go && go test -race ./...`.
- Время и кэш: первая загрузка модулей и сборка с `-race` зависят от сети и машины; цифры не обещаются, M9.2/M9.3 записывают фактическое время первого и повторного прогона в отчёт.
- `test-go-e2e` (скрипт): генерирует суффикс, собирает и запускает `worker-go` с уникальной очередью (`docker compose --profile go run -d`), ждёт healthy, запускает `$(PHP) env TEMPORAL_GO_TASK_QUEUE=... TEMPORAL_GO_INSPECTION_ENABLED=1 vendor/bin/phpunit --group go-e2e`, останавливает только то, что запустил. Группа `go-e2e` исключается из `test-int` (`--exclude-group slow --exclude-group go-e2e`: PHPUnit 13 не принимает список через запятую, см. `docs/reports/M9.5.md`). Причина вне `ci`: нужна сборка образа и управление контейнером с хоста, прецедент `smoke`.
- `bin/check-edge-case-coverage` (M9.5): кроме `tests`/`phpstan` сканирует `worker-go/**/*_test.go` на строки `// Group: T-GO-NN` (тот же формат ID `T-[A-Z0-9]+-…`).

## 8. Изменения PHP (M9.4)

- `Payment\Application\Query\PrepareChargeInspection\{PrepareChargeInspectionQuery, ...Handler, ...Output}`: читает `PaymentRepository::findByOrder` и флаг (параметр контейнера); `Output{inspect: bool, paymentId: ?string, amount: ?int, currency: ?string}`.
- `PaymentActivities`: `#[ActivityMethod(name: 'PrepareChargeInspection')] prepareChargeInspection(string $orderId): array`; реализация через `QueryBus`.
- `Saga\Infrastructure\Temporal\Activity\GatewayInspectionActivities` (интерфейс без реализации).
- `Saga\Infrastructure\Temporal\Contract\GatewayInspection\{InspectionRequest, InspectionResult, InspectionOutcome, InspectionContractViolation}`: чистый маппинг массив ↔ объект, `final readonly`, без часов и случайности (разрешено правилом детерминизма, проверить, что `WorkflowDeterminismRule` не срабатывает на `DateTimeImmutable::createFromFormat` вне namespace `Workflow`).
- `OrderFulfillmentWorkflowImpl`: §2.3; `Workflow\InspectionDeclined`.
- `tests/Workflow/worker.php`: второй воркер на `payment-go-test` с тестовой PHP-реализацией `InspectGatewayCharge`, которая бросает `LogicException`, если мок не задан. Работает ли `ActivityMocker` для второго воркера того же `Testing\WorkerFactory`, проверить первым делом; запасной вариант: в `when@test` для набора `workflow` очередь Go = очередь саги, фикстура v1 пишется с этим значением, M5 из §3.3 снимается.
- `config/packages/temporal.yaml`: профили §4.7, `temporal.go_task_queue`, `temporal.go_inspection.enabled`.

## 9. Тесты

ID: `T-GO-NN` (Go), `T-W-21…` (workflow PHP), `T-M9-NN` (прочие PHP). Каждый тест PHP помечен `#[Group('...')]`, Go-тест комментарием `// Group: T-GO-NN`.

### 9.1 Go unit и контракт (`make go-test`)

| ID | Что | Где |
|---|---|---|
| T-GO-01 | `Validate`: таблица валидных и невалидных входов (версия 0/2, не UUID, ключ с `/`, сумма 0, −1, 100 000 001, валюта `eur`, `XYZ`) | usecase |
| T-GO-02 | `Execute`: `succeeded` + совпадение, `succeeded` + чужая сумма, чужая валюта, `declined`, `not_found`, `pending` → `ErrChargePending`; `CheckedAt` из fake clock | usecase |
| T-GO-03 | ошибки порта проходят без изменения типа (`errors.Is`) | usecase |
| T-GO-04 | `Matches`: равенство только при равных сумме и валюте | domain |
| T-GO-05 | `config.Load`: обязательные, диапазоны, `WORKER_STOP_TIMEOUT < GATEWAY_TIMEOUT`, несколько ошибок сразу | config |
| T-GO-06 | HTTP-адаптер на `httptest.Server`: 200 каждого статуса, 404 `charge_not_found`, 404 иной, 400/409/422, 500/502/503, не JSON, обрезанный JSON, без `chargeId`, `weird`, `amount` дробное/строка/0/вне int64, тело 64 KiB + 1, таймаут (сервер спит дольше `Timeout`), обрыв соединения (`Hijack` + close), несовпадение `idempotencyKey`, заголовки запроса | httpgateway |
| T-GO-07 | breaker: 5 отказов → `ErrCircuitOpen` без запроса к серверу; half-open; `not_found`/`rejected` не открывают | breaker |
| T-GO-08 | адаптер шлёт только `GET` (fake-шлюз отвечает 405 и падает тест на любом другом методе) | httpgateway |
| T-GO-09 | `testsuite` activity env: golden `input.v1.json` → выход совпадает с `output.succeeded.json` (кроме фиксированного `checkedAt` из fake clock) | activity |
| T-GO-10 | activity env: ошибка use case → `*temporal.ApplicationError` с типом и `NonRetryable()` из `errors.json` | activity |
| T-GO-11 | activity env: `contractVersion: 2` → `UnsupportedContractVersion`, `Details` содержит `supported` | activity |
| T-GO-12 | конвертер: golden как `json/plain` payload ↔ DTO через `GetDefaultDataConverter()`; все `output.*.json` проходят схему | activity |
| T-GO-13 | полнота маппинга ошибок против `errors.json` | activity |
| T-GO-14 | архитектура: нет struct-тегов в `domain`/`usecase`; нет `os.Getenv`/`LookupEnv` вне `config`/`cmd`; `usecase` не импортирует `go.temporal.io`, `net/http`, `internal/app` | `internal/archtest` |
| T-GO-15 | нет `float32`/`float64` в `domain`/`usecase` (`go/ast`) | `internal/archtest` |
| T-GO-16 | формат `checkedAt` при `TZ=Europe/Kyiv` (`time.Local` подменён) | activity |
| T-GO-17 | остановка воркера посреди активности: `SetWorkerStopChannel`, адаптер получает отменённый `ctx` и возвращает `ErrGatewayUnavailable` | activity |

### 9.2 Go integration (`make go-test-int`, compose `temporal`)

Тестовый Go workflow (только в `_test.go`) вызывает `InspectGatewayCharge` по имени на уникальной очереди; fake-шлюз `httptest` в процессе.

| ID | Что |
|---|---|
| T-GO-20 | успешный вызов через настоящий сервер; результат совпадает с golden |
| T-GO-21 | non-retryable: `GatewayRequestRejected` → одна попытка (счётчик запросов fake-шлюза = 1) |
| T-GO-22 | retryable: первые 2 ответа 503, третий 200 → результат, 3 запроса, `Attempt = 3` |
| T-GO-23 | ответ позже StartToClose (тест задаёт 2 с, шлюз спит 4 с на первой попытке) → вторая попытка успешна, поздний результат первой отброшен |
| T-GO-24 | падение воркера: воркер A берёт задачу (шлюз блокирует первый запрос), A останавливается `Stop()`, воркер B на той же очереди завершает повтор; итог успешен, 2 `GET` с одним ключом, 0 записей |
| T-GO-25 | нет поллеров: ScheduleToStart 2 с → `TimeoutError` типа `SCHEDULE_TO_START`, попыток 0 |
| T-GO-26 | graceful shutdown: `SIGTERM` процессу `worker` (собранный бинарь, `exec.Command`) во время активности → активность завершена, код выхода 0, в пределах `WORKER_STOP_TIMEOUT` |

### 9.3 PHP (`make test-unit`, `test-wf`, `test-int`)

| ID | Что |
|---|---|
| T-M9-01 | `InspectionRequest::toArray` = `input.v1.json` (структурно) |
| T-M9-02 | `InspectionResult::fromArray`: каждый `output.*.json`; нарушения (`charge` при `not_found`, float, строка, лишний `outcome`, неверный `checkedAt`, `contractVersion: 2`) → `InspectionContractViolation` |
| T-M9-03 | PHP `DataConverter` кодирует запрос в `json/plain`, декодированный JSON равен golden |
| T-M9-04 | `non_retryable_types` профиля = `nonRetryable: true` из `errors.json` |
| T-M9-05 | `SagaConfiguration`: `task_queue`, `schedule_to_start`, `non_retryable_types` попадают в `ActivityOptions`; неверный тип/пустая очередь → `InvalidSagaConfiguration` |
| T-CFG-01 | (обновлённый) инвариант TTL с новыми слагаемыми: 660 < 900 проходит, TTL 660 падает |
| T-M9-06 | `PrepareChargeInspectionHandler`: флаг выкл → `inspect=false`; нет платежа; `Succeeded`/`Declined`/`Abandoned` → `false`; `Pending`/`Unknown` → данные платежа (integration на БД) |
| T-W-21 | replay `history-charge-exhausted-v0.json` |
| T-W-22 | replay `history-charge-inspected-v1.json` |
| T-W-23 | флаг выкл (мок `inspect=false`): исход и последовательность компенсаций как в T-W-07, `InspectGatewayCharge` не вызван |
| T-W-24 | `succeeded` + совпадение → `ChargePayment` (recovery) → `Completed`; `RefundPayment` не вызван |
| T-W-25 | `succeeded` + `matchesExpected=false` → поведение M8 (`Failed(PaymentUnavailable)`) |
| T-W-26 | `declined` → `Failed(PaymentDeclined)`, компенсации |
| T-W-27 | `not_found` → поведение M8 |
| T-W-28 | ошибки Go (мок `expectFailure` с `ApplicationFailure` каждого типа и `TimeoutFailure` ScheduleToStart) → поведение M8 |
| T-W-29 | нарушение контракта в ответе (мок возвращает `charge` при `not_found`) → поведение M8 |
| T-W-30 | recovery `ChargePayment` падает `ReservationNotActive` → `Failed(ReservationExpired)` с компенсациями |
| T-W-31 | отмена (сигнал) во время инспекции → после recovery guard перед `ConsumeReservation` → `RefundPayment` + `ReleaseReservation` → `Cancelled` |
| T-W-32 | `PrepareChargeInspection` падает (БД) → поведение M8 |

### 9.4 Сквозные и кросс-языковые (M9.5, `make test-go-e2e`)

| ID | Что |
|---|---|
| T-M9-10 | PHP workflow → настоящий Go-воркер → результат: эмулятор `late-success:<ms>` делает `ChargePayment` неизвестным до исчерпания (тест укорачивает профиль через env тестового окружения либо использует `accept-then-502` + флаг; конкретный способ выбрать в M9.5 и описать в отчёте), инспекция видит `succeeded`, заказ `Completed`, платёж `Succeeded` |
| T-M9-11 | тот же сценарий: `/__admin/stats` → `charges.<paymentId>.executed = 1`, `totals.refunds = 0` |
| T-M9-12 | Go-воркер не запущен: заказ завершается как в M8 (`Failed(PaymentUnavailable)`), время ветки инспекции ≤ ScheduleToStart + `PrepareChargeInspection` |
| T-M9-13 | флаг выключен при запущенном Go-воркере: в истории нет `InspectGatewayCharge` |

Golden-файлы читают и PHP (`tests/Unit/...` через путь от корня), и Go (`../../../contracts/...` от пакета, корень репозитория смонтирован в `go-toolchain` как `/src`). Изменение файла в `contracts/` без правки обеих сторон ломает T-M9-01…04 или T-GO-09…13.

## 10. Крайние случаи (EC-93…EC-111)

Строки переносятся в `04_edge_cases.md` новым разделом «L. Добавлено в M9» в M9.5, когда тесты существуют: `bin/check-edge-case-coverage` входит в `make ci` и падает на строке со ссылкой на несуществующий тест, поэтому в M9.0 `04_edge_cases.md` не меняется.

| ID | Случай | Условие | Поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-93 | Go-воркер не запущен | нет поллеров на `payment-go` | `InspectGatewayCharge` ждёт ScheduleToStart 20 с → `TimeoutFailure(SCHEDULE_TO_START)`, без повторов → поведение M8; warning в лог workflow с типом таймаута. Алерт (ручная проверка): `temporal task-queue describe --task-queue payment-go` без поллеров, healthcheck контейнера `unhealthy` | INV-08 | T-GO-25, T-W-28, T-M9-12 |
| EC-94 | PHP новее Go | PHP шлёт `contractVersion: 2`, Go знает 1 | `UnsupportedContractVersion`, non-retryable → поведение M8, error-лог | — | T-GO-11, T-W-28 |
| EC-95 | Go новее PHP | Go знает 1 и 2, PHP шлёт 1 | ответ в версии 1, golden v1 проходит | — | T-GO-09, T-M9-02 |
| EC-96 | Ответ позже StartToClose | вызов шлюза или воркер медленнее 15 с | попытка считается проваленной, повтор; поздний результат отбрасывается сервером; эффектов нет (только `GET`) | — | T-GO-23 |
| EC-97 | Дубль вызова | повтор Temporal или два экземпляра Go | только `GET`; recovery `ChargePayment` с тем же `Idempotency-Key`; у шлюза `executed = 1` | INV-06 | T-GO-08, T-GO-24, T-M9-11 |
| EC-98 | Неизвестный исход инспекции | шлюз 5xx, таймаут, обрыв, `pending`, нечитаемый ответ, breaker открыт | повтор до ScheduleToClose 60 с → поведение M8 | INV-08 | T-GO-06, T-GO-07, T-GO-22, T-W-28 |
| EC-99 | Списание есть, но не то | `succeeded`, сумма или валюта не совпали (`wrong-amount`, `wrong-currency`) | `matchesExpected=false` → поведение M8 (`RefundPayment` найдёт и вернёт, ср. EC-36/EC-37); заказ не завершается | INV-02, INV-08, INV-18 | T-GO-02, T-W-25 |
| EC-100 | Шлюз доказал отказ | `declined` | `Failed(PaymentDeclined)` с компенсациями; `Unknown` платёж закроет `ReconcilePayments` | — | T-GO-02, T-W-26 |
| EC-101 | Большой payload | тело ответа шлюза > 64 KiB; длинный `declineReason` | > лимита = `GatewayUnavailable`; `declineReason` ≤ 256 байт; вход и выход активности фиксированного размера, лимиты Temporal на payload не приближаются | — | T-GO-06, T-GO-12 |
| EC-102 | Часовой пояс и формат времени | `TZ=Europe/Kyiv` у Go-процесса | `checkedAt` в UTC с `+00:00` и мс; workflow не принимает решений по `checkedAt` | INV-12 | T-GO-16, T-M9-02 |
| EC-103 | Деньги не целые | шлюз вернул `19.99`, `"1999"`, 0, число вне int64; PHP получил float | нечитаемый ответ (`GatewayUnavailable`) в Go; `InspectionContractViolation` в PHP → поведение M8 | INV-01 | T-GO-06, T-GO-15, T-M9-02 |
| EC-104 | Перезапуск Go-воркера посреди активности | процесс убит после начала вызова | повтор после StartToClose на любом экземпляре; результат тот же | — | T-GO-24 |
| EC-105 | Graceful shutdown | `SIGTERM` (compose stop) | приём новых задач прекращён, текущая активность завершается в пределах `WORKER_STOP_TIMEOUT` (10 с < `stop_grace_period` 20 с), код 0 | — | T-GO-17, T-GO-26 |
| EC-106 | Неверная конфигурация Go | нет `GO_WORKER_TASK_QUEUE`, `GATEWAY_TIMEOUT=30s` и т. п.; Temporal недоступен при старте | список всех ошибок в stderr, код 2, к Temporal не подключается; недоступный Temporal: код 1, `restart: unless-stopped`; healthcheck `unhealthy` | — | T-GO-05 |
| EC-107 | Флаг выключен | значение по умолчанию | `PrepareChargeInspection` → `inspect=false`, Go не вызывается, исход как в M8 | — | T-W-23, T-M9-13 |
| EC-108 | Старые истории | workflow, начатые до M9, доходят до ветки при replay | `getVersion` = `DEFAULT_VERSION`, новых команд нет | — | T-W-21, T-W-20 |
| EC-109 | Неверная конфигурация PHP | пустая `go_task_queue`, `non_retryable_types` не по регулярке, TTL меньше суммы | `InvalidSagaConfiguration` при сборке контейнера | — | T-M9-05, T-CFG-01 |
| EC-110 | Отмена во время инспекции | сигнал `requestCancellation` пришёл в ветке | recovery подтверждает оплату, guard перед `ConsumeReservation` → `RefundPayment` + `ReleaseReservation` → `Cancelled` | INV-08 | T-W-31 |
| EC-111 | Резерв истёк к моменту recovery | recovery `ChargePayment` → `ReservationNotActive` | `Failed(ReservationExpired)` с компенсациями | INV-14, INV-08 | T-W-30 |

## 11. Этапы для исполнителей

Общее для всех: прочитать этот файл целиком; `make ci` зелёный в конце; отчёт `docs/reports/M9.N.md` (сделано, проверка с фактическим выводом, отклонения, что проверить вручную); не менять существующие строки `04_edge_cases.md`; коммиты без упоминания генерации.

### M9.1 Контракт, golden-файлы, фикстура v0 (PHP + `contracts/`)
- Цель: зафиксировать контракт до кода Go и снять историю ветки EC-34 текущим кодом.
- Вход: §3.2, §4, §9.3 (T-M9-01…04, T-W-21).
- Выход: `contracts/inspect-gateway-charge/v1/{input.schema.json, output.schema.json, input.v1.json, output.succeeded.json, output.mismatch.json, output.declined.json, output.not_found.json, errors.json, README.md}`; `Saga\Infrastructure\Temporal\Contract\GatewayInspection\*` + unit-тесты T-M9-01…04 (T-M9-04 сверяет пока с константой в тесте; профиль появится в M9.4); `history-charge-exhausted-v0.json`, метод записи, T-W-21; версии из §5.2 перепроверены и записаны в отчёт.
- Проверка: `make ci`; `git diff --exit-code tests/Workflow/Fixtures/history-happy.json`; `RECORD_HISTORY=1` запись v0 дважды даёт эквивалентную историю (без `identity`, времени).
- Не делать: менять workflow, `temporal.yaml`, писать Go-код.

### M9.2 Go-ядро и unit-тесты
- Цель: use case, домен, конфиг, HTTP-адаптер, breaker; Go-тесты в `make ci`.
- Вход: §4.3…4.6, §5, §7 (`go-test`), §9.1 (T-GO-01…08, 12 (часть со схемами), 13…16).
- Выход: `worker-go/{go.mod, go.sum, internal/{config,domain,usecase,adapter/gateway,adapter/clock,archtest}}`; сервис `go-toolchain` и volumes в `compose.yaml`; цель `go-test` и её место в `ci`.
- Проверка: `make go-test`; `make ci`.
- Не делать: Temporal-зависимости в `usecase`/`domain`; `cmd/`, Dockerfile; PHP-код.

### M9.3 Go-воркер, Docker, Compose
- Цель: процесс, который регистрирует `InspectGatewayCharge` на очереди и корректно стартует и останавливается.
- Вход: §4.1, §4.6, §5 (activity, workerhost, health, app, cmd), §6, §7 (`go-test-int`), §9.1 (T-GO-09…11, 17), §9.2.
- Выход: `worker-go/{cmd/worker, cmd/healthcheck, internal/adapter/temporal, internal/adapter/health, internal/app, Dockerfile, .dockerignore}`; сервис `worker-go` (профиль `go`); цель `go-test-int` в `ci`.
- Проверка: `make go-test go-test-int`; `docker compose --profile go up -d worker-go` → `docker compose ps` показывает `healthy`; `docker compose stop worker-go` завершается с кодом 0 быстрее 20 с; `docker image inspect` → `User = nonroot`; `make ci`.
- Не делать: менять PHP; добавлять `worker-go` в профиль `workers`.

### M9.4 Интеграция в PHP-сагу, фикстура v1
- Цель: ветка §2.3 под `getVersion` и флагом; Go в тестах замокан.
- Вход: §2.3, §3, §4.7, §8, §9.3 (T-M9-05, 06, T-CFG-01, T-W-22…32).
- Выход: изменения из §8; `history-charge-inspected-v1.json` и T-W-22; мутационная таблица §3.3 с фактическими сообщениями в отчёте.
- Проверка: `make ci`; `git diff --exit-code tests/Workflow/Fixtures/history-happy.json history-charge-exhausted-v0.json`; `grep -rn TEMPORARY src tests` пусто.
- Не делать: менять порядок существующих шагов happy path; перезаписывать `history-happy.json` или v0; включать флаг по умолчанию; трогать `worker-go/`.

### M9.5 Сквозные тесты и документация
- Цель: PHP workflow ↔ настоящий Go-воркер; EC в общей таблице; документы.
- Вход: §7 (`test-go-e2e`, сканер Go-тестов), §9.4, §10.
- Выход: `tests/E2E/go-e2e.sh`, `tests/Integration/.../GoWorkerEndToEndTest` (группа `go-e2e`, T-M9-10…13); расширение `bin/check-edge-case-coverage` для `worker-go/**/*_test.go`; раздел «L. Добавлено в M9» в `04_edge_cases.md` (EC-93…EC-111 из §10 без изменений смысла); `08_coverage.md` перегенерирован; `03_saga.md` §8 заменён ссылкой на этот файл и итоговым решением; `05_testing.md` (новые ID), `06_milestones.md` (M9 по факту), `README.md` (как запустить Go-воркер, флаг, `make test-go-e2e`, строка «Go-воркер (M9) не сделан» удаляется), `00_overview.md` строка про `ChargePayment` на `payment-go` исправлена.
- Проверка: `make ci`; `make test-go-e2e` трижды подряд зелёный; `make smoke` зелёный; `bin/check-edge-case-coverage` без «НЕ НАЙДЕН».
- Не делать: менять поведение кода, кроме тестовой инфраструктуры; добавлять `test-go-e2e` в `ci` без отдельного решения.

## 12. Открытые вопросы (решить при указанном этапе)

| # | Вопрос | Как закрыть | Этап |
|---|---|---|---|
| Q-M9-1 | Обслуживает ли `ActivityMocker` второй воркер (`payment-go-test`) того же `Testing\WorkerFactory` | пробный тест; иначе запасной вариант §8 | M9.4 (первым делом) |
| Q-M9-2 | Сравнивает ли replay `taskQueue` активности (M5 в §3.3) | мутация M5 | M9.4 |
| Q-M9-3 | Как довести `ChargePayment` до исчерпания в e2e за разумное время (5 мин ScheduleToClose на dev-сервере без пропуска времени) | env-переопределение профиля `charge_payment` только для e2e-прогона, либо режим эмулятора; инвариант TTL должен остаться верным. **Решено в M9.5:** оба вместе: `TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE=20` (только `services_test.yaml`, фабрика `tests/Integration/Support/E2eSagaConfiguration`, принимает лишь значение короче настроенного) и режим эмулятора `late-success:32000`; `docs/reports/M9.5.md` | M9.5 |
| Q-M9-4 | `non_retryable_types` и тип параметра `withNonRetryableExceptions` под PHPStan max | §4.7 | M9.4 |
| Q-M9-5 | Даёт ли `gobreaker/v2` остаток open-интервала для `NextRetryDelay` | документация и код библиотеки | M9.2 |
| Q-M9-6 | Версия `go.temporal.io/sdk` на дату работ и совместимость с образом `temporalio/temporal:1.10.1` | `go list -m -json ...@latest`, T-GO-20 | M9.1 / M9.3 |
