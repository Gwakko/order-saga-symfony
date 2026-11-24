# 04. Крайние случаи и отказы (главный файл)

Каждая строка = минимум один автоматический тест. Колонка «Тест» содержит ID из `05_testing.md` и предлагаемый класс. Инварианты INV-xx определены в `01_domain.md` §7. Уровни тестов: U = unit (domain/application, без фреймворка), I = integration (реальные Postgres/Redis/эмулятор), W = workflow (Temporal test server), F = functional (HTTP/GraphQL через Symfony `KernelBrowser`), A = architecture (PHPStan).

Тест, который нельзя написать, означает, что строку надо переформулировать, а не оставить без теста.

## A. Идемпотентность HTTP и двойная отправка

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-01 | Двойная отправка одного запроса | `POST /api/orders` дважды подряд, тот же `Idempotency-Key`, то же тело | 2-й ответ: тот же статус 201 и то же тело (тот же `orderId`), заголовок `Idempotent-Replayed: true`; в БД 1 заказ, 1 `OrderPlaced` в outbox | INV-09, INV-16 | T-F-01 `PlaceOrderIdempotencyTest::testSameKeySameBodyReplaysResponse` |
| EC-02 | Одновременная двойная отправка | два параллельных запроса с одним ключом (второй приходит, пока первый `in_progress`) | один 201, другой 409 `IDEMPOTENT_REQUEST_IN_PROGRESS` + `Retry-After`; 1 заказ | INV-09 | T-I-01 `IdempotencyStoreConcurrencyTest::testOnlyOneInsertWins` (две DBAL-сессии, барьер) |
| EC-03 | Тот же ключ, другое тело | ключ использован для `productId=A`, повтор с `productId=B` | 422 `IDEMPOTENCY_KEY_REUSED`; второго заказа нет | INV-09 | T-F-02 `...::testSameKeyDifferentBodyRejected` |
| EC-04 | Нет или неверный ключ | заголовок отсутствует; длина 0 или >255; символ `/` | 400 `IDEMPOTENCY_KEY_MISSING` / `_INVALID`; handler не вызывается | INV-09 | T-F-03 (data provider) |
| EC-05 | Один ключ у разных покупателей | покупатели C1 и C2 шлют одинаковый ключ | два независимых заказа | INV-09 | T-F-04 |
| EC-06 | Ключ истёк | повтор через >24 ч (`MockClock` сдвинут) | обрабатывается как новый запрос, новый заказ | INV-09 (в пределах TTL) | T-I-02 `IdempotencyStoreTest::testExpiredKeyIsReplaced` |
| EC-07 | Сбой при обработке | handler бросил непредвиденное исключение → 500 | строка ключа удалена; повтор с тем же ключом выполняется заново | INV-09 | T-F-05 |
| EC-07b | Процесс упал, ключ завис в `in_progress` | строка `in_progress` старше 60 с | считается истёкшей, повтор выполняется | INV-09 | T-I-03 |
| EC-08 | Бизнес-ошибка сохраняется | первый запрос получил 409 `PRICE_CHANGED` | повтор с тем же ключом и телом возвращает тот же 409 (не пересчитывает) | INV-09 | T-F-06 |

## B. Повторная оплата

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-09 | Повтор активности после успеха у шлюза | `ChargePayment`: шлюз списал, воркер упал до сохранения `Succeeded` | повтор: `Pending` → вызов `charge` с тем же `Idempotency-Key` → эмулятор возвращает тот же `chargeId`, деньги списаны 1 раз | INV-06 | T-I-10 `ChargePaymentIdempotencyTest::testRetryAfterCrashDoesNotDoubleCharge` (эмулятор считает списания) |
| EC-10 | Повтор активности после сохранённого успеха | `Payment.status = Succeeded` | шлюз не вызывается (счётчик вызовов `FakePaymentGateway` = 0) | INV-06 | T-U-30 `ChargePaymentHandlerTest::testSucceededPaymentShortCircuits` |
| EC-11 | Дубль `OrderPlaced` → второй старт саги | потребитель получил событие дважды | второй `start` ловит «уже запущен», считает успехом; одна история workflow, одно списание | INV-16 | T-W-01, T-I-20 |
| EC-12 | Две вставки `Payment` одновременно | два повтора `ChargePayment` параллельно | unique `order_id`: одна вставка, второй перечитывает; один `paymentId` | INV-06 | T-I-11 |
| EC-13 | `markPaid` другим платежом | `Order` в `Paid` с `paymentId=P1`, приходит `markPaid(P2)` | `IllegalOrderTransition` | INV-06, INV-03 | T-U-05 `OrderTest::testMarkPaidWithOtherPaymentRejected` |

## C. Склад: окончание ключей, конкуренция, TTL

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-14 | Нет ключей при резерве | у товара 0 `Available` | `OutOfStock` (non-retryable) → `FailOrder(OutOfStock)`; платёж не создаётся | INV-07 | T-W-02, T-I-12 |
| EC-15 | Последний ключ, два покупателя одновременно | 1 ключ, два `ReserveKey` для разных заказов параллельно | ровно одна резервация; второй `OutOfStock` → заказ `Failed` | INV-04 | T-I-13 `DbalKeyInventoryConcurrencyTest::testLastKeyReservedOnce` (2 процесса через `pcntl_fork` или 2 соединения + барьер, 50 повторов) |
| EC-16 | Резерв истёк между резервом и списанием, ключ ушёл другому | `MockClock` +901 с после резерва, sweeper истёк резерв, другой заказ зарезервировал этот ключ; затем `ConsumeReservation` | `ReservationExpired` → компенсация `RefundPayment` → `Failed(ReservationExpired)`; второй покупатель получает ключ | INV-14, INV-04, INV-08 | T-W-03, T-I-14 |
| EC-17 | Резерв истёк до оплаты (воркер стоял) | резерв создан, воркер недоступен 20 мин, sweeper истёк резерв | предпроверка в `ChargePayment` → `ReservationExpired` без вызова шлюза → `Failed`; списаний 0 | INV-14, INV-07 | T-U-31 `ChargePaymentHandlerTest::testExpiredReservationSkipsGateway` |
| EC-18 | Ключ отозван поставщиком во время резерва | `RevokeKey` на `Reserved` ключ, затем `ConsumeReservation` | `KeyRevoked` → refund + release (no-op для отозванного) → `Failed(KeyRevoked)` | INV-07, INV-08 | T-W-04 |
| EC-19a | Гонка sweeper и `ConsumeReservation` | оба меняют одну резервацию одновременно, `now` около `expiresAt` | CAS `WHERE status='active'`: выигрывает ровно один; второй получает 0 обновлённых строк → `ReservationExpired` либо no-op | INV-14 | T-I-15 |
| EC-20 | Граница TTL | `now == expiresAt` | резерв истёк (`consume` → `ReservationExpired`, `expire` разрешён) | INV-14 | T-U-12 `KeyReservationTest::testExpiryBoundaryIsInclusive` |
| EC-21 | Конфигурация TTL меньше суммы таймаутов | `reservation_ttl = 300` | контейнер не собирается: `InvalidSagaConfiguration` | INV-14 | T-CFG-01 |
| EC-22 | `release` для `Consumed` резерва в компенсации | компенсация после `ConsumeReservation` (не должно случаться, но защищаемся) | `released=false`, warning в логе, ключ остаётся `Sold` | INV-04 | T-U-32 |
| EC-23 | Повтор `ReserveKey` | активность выполнилась, ответ потерян, повтор | возвращается та же резервация, второй ключ не берётся | INV-05 | T-I-16 |

## D. Платёжный шлюз: таймауты, частичные ответы, circuit breaker

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-24 | Таймаут шлюза | эмулятор в режиме `delay=12s` (> HTTP timeout 10 с) | `Payment → Unknown`, исключение `PaymentOutcomeUnknown` (retryable); следующая попытка сначала `findCharge` | INV-06 | T-I-17 `HttpPaymentGatewayTest::testTimeoutRaisesOutcomeUnknown`, T-U-33 |
| EC-25 | Частичный ответ | эмулятор: 200 с обрезанным JSON / 200 без `chargeId` / `Content-Type` не JSON / обрыв соединения после заголовков | всё трактуется как `PaymentOutcomeUnknown`, **не** как отказ; `Payment → Unknown` | INV-06 | T-I-18 (data provider по режимам эмулятора) |
| EC-26 | 502/503 после того, как шлюз принял списание | эмулятор режим `accept-then-502` | `Unknown`; повтор → `findCharge` находит успех → `Succeeded`, повторного списания нет | INV-06 | T-I-19 |
| EC-27 | Успех после таймаута в пределах шага | 1-я попытка таймаут, эмулятор завершил списание позже; 2-я попытка через 2 с | `findCharge` → `Succeeded`; сага продолжается; одно списание | INV-06 | T-W-05 |
| EC-28 | Успех после таймаута, сага уже компенсировала | `ChargePayment` исчерпал ScheduleToClose (все попытки таймаут), `RefundPayment` перевёл платёж в `Abandoned`, заказ `Failed(PaymentUnavailable)`; эмулятор позже показывает списание | `ReconcilePayments`: `findCharge` → успех → `markSucceeded` → `requestRefund` → refund → `Refunded`; событие `OrphanChargeDetected` | INV-08, INV-06 | T-I-21 `ReconcilePaymentsTest::testLateSuccessIsRefunded` |
| EC-29 | Отказ банка | эмулятор `decline` | `PaymentDeclined` non-retryable → `ReleaseReservation` → `Failed(PaymentDeclined)`; breaker не считает это сбоем | INV-07 | T-W-06, T-U-40 |
| EC-30 | Неизвестный статус в ответе | эмулятор `status: "weird"` | `PaymentOutcomeUnknown` (не угадываем) | INV-06 | T-I-18 |
| EC-31 | Circuit breaker открывается | 5 подряд таймаутов/5xx | `Open`: следующие вызовы не идут в сеть (счётчик эмулятора не растёт), `CircuitOpen` (retryable) | — | T-U-41 `CircuitBreakerTest::testOpensAfterThreshold` (`MockClock`, in-memory store) |
| EC-32 | Breaker открыт: платёж не отправлен | `CircuitOpen` при статусе `Pending` | `Payment` остаётся `Pending` (не `Unknown`: вызова не было) | INV-06 | T-U-34 |
| EC-33 | Half-open и восстановление | прошло 30 с `Open`; эмулятор снова здоров | ровно 1 пробный вызов (параллельные получают `CircuitOpen`); успех → `Closed`; неудача → снова `Open` на 30 с | — | T-U-42, T-I-22 `RedisCircuitStateStoreTest::testSingleProbeAcrossProcesses` |
| EC-34 | Breaker открыт дольше шага | `Open` всё время ScheduleToClose `ChargePayment` (5 мин, время сдвигается тест-сервером) | исчерпание → компенсации → `Failed(PaymentUnavailable)`; платёж `Abandoned`; списаний 0 | INV-08 | T-W-07 |
| EC-35 | Redis недоступен для breaker | `RedisCircuitStateStore` бросает исключение соединения | breaker деградирует в `Closed` (вызов пропускается в шлюз), лог `warning`; HTTP-таймаут остаётся защитой | — | T-U-43 (store-заглушка, бросающая исключение) |
| EC-36 | Валюта в ответе шлюза другая | эмулятор возвращает `currency: USD` для EUR-платежа | `CurrencyMismatch` → платёж `RefundPending` → сага компенсирует (refund) → `Failed(CurrencyMismatch)` | INV-02 | T-U-35, T-W-08 |
| EC-37 | Сумма в ответе шлюза другая | эмулятор возвращает другую `amount` | как EC-36 (`AmountMismatch` трактуется аналогично, refund) | INV-18 | T-U-36 |

## E. Отмена и компенсации

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-38 | Отмена до старта саги | `CancelOrder` пока заказ `Pending`, workflow не запущен | workflow при старте видит флаг → `Cancelled`, резерва и платежа нет | INV-08 | T-W-09 |
| EC-39 | Отмена после резерва | сигнал после `MarkOrderKeyReserved` | `ReleaseReservation` → `Cancelled`; ключ снова `Available` | INV-08, INV-04 | T-W-10 |
| EC-40 | Отмена во время списания | сигнал пока `ChargePayment` выполняется (эмулятор `delay=3s`) | списание завершается; затем refund + release → `Cancelled`; итог по деньгам 0 | INV-08 | T-W-11 |
| EC-41 | Отмена после `ConsumeReservation` | сигнал приходит после точки невозврата | заказ `Completed`, ключ выдан, `cancellationRequested=true`; ни refund, ни release | INV-07 | T-W-12 |
| EC-42 | Отмена выполненного заказа | `CancelOrder` на `Completed` | 409 `ORDER_NOT_CANCELLABLE` | INV-03 | T-U-06, T-F-10 |
| EC-43 | Двойная отмена | два `CancelOrder` подряд (разные `Idempotency-Key`) | оба 202; одно событие `OrderCancellationRequested`; один сигнал обрабатывается, второй игнорируется | INV-03 | T-U-07, T-W-13 |
| EC-44 | Отмена при недоступном Temporal | сигнал бросает исключение | флаг в БД сохранён, ответ 202; следующий guard в workflow отменяет | INV-08 | T-I-23 |
| EC-45 | Повтор компенсации (транзиентный сбой) | `RefundPayment`: шлюз 503 два раза, потом успех | 3 попытки, refund у эмулятора ровно 1 (по `refund-{paymentId}`) | INV-06 | T-W-14, T-I-24 |
| EC-46 | Повтор компенсации после падения воркера | refund выполнен у шлюза, воркер упал до `markRefunded` | повтор → эмулятор возвращает тот же `refundId` → `Refunded`; деньги возвращены 1 раз | INV-06 | T-I-25 |
| EC-47 | Компенсация не удаётся никогда | `RefundPayment` все попытки 503 до ScheduleToClose | `ManualReview`, событие `OrderNeedsManualReview`, лог `critical`; workflow завершён без исключения | INV-08 | T-W-15 |
| EC-48 | Компенсация шага, который не был выполнен | отказ на `ReserveKey` | компенсаций нет (не зарегистрированы); `RefundPayment` не вызывается | — | T-W-02 |
| EC-49 | `RefundPayment` при неизвестном исходе | платёж `Unknown`, `findCharge` → не найден | `Abandoned`, refund не вызывается | INV-06 | T-U-37 |

## F. Outbox, inbox, проекции, доставка событий

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-50 | Падение между записью в БД и отправкой события | `save(order)` закоммичен, relay не запущен / упал | событие лежит в `outbox_messages` (`published_at IS NULL`); после запуска relay публикуется | INV-10 | T-I-30 `OutboxRelayTest::testUnpublishedMessageIsSentAfterRestart` |
| EC-51 | Откат транзакции | исключение после `save` внутри `transactional` | ни строки заказа, ни outbox-сообщения | INV-10 | T-I-31 |
| EC-52 | Падение после публикации, до `published_at` | relay отправил в транспорт и упал до UPDATE | повторная публикация (дубль); потребитель отбрасывает по inbox | INV-11 | T-I-32 |
| EC-53 | Дубль события у потребителя | одно сообщение (`message_id`) доставлено дважды | обработано 1 раз: проекция и старт саги не повторяются; вторая доставка ack без эффекта | INV-11 | T-I-33 `InboxTest::testDuplicateMessageIgnored` |
| EC-54 | Read-your-write | `GET /api/orders/{id}` сразу после 201, проекция ещё не обновлена | 404 допустим (документировано); POST-ответ содержит снимок заказа; клиент повторяет | — (eventual consistency) | T-F-11 (проверяет, что 201 содержит полный снимок) |
| EC-55 | События не по порядку в проекции | `OrderPaid (v3)` пришло раньше `OrderKeyReserved (v2)` | проекция применяет только `aggregate_version > stored_version`; v2 после v3 игнорируется; итоговый статус по v3 | INV-15 | T-I-34 |
| EC-56 | Два экземпляра relay | два процесса relay одновременно | `FOR UPDATE SKIP LOCKED`: каждое сообщение отправлено одним процессом в штатном режиме | INV-10 | T-I-35 |
| EC-57 | Ядовитое сообщение | потребитель всегда падает на сообщении | 5 повторов Messenger → failure transport; остальные сообщения обрабатываются | — | T-I-36 |
| EC-58 | Temporal недоступен при старте саги | `WorkflowClient` бросает исключение | сообщение повторяется Messenger; заказ `Pending`; после восстановления workflow стартует один раз | INV-16 | T-I-20 |

## G. Возврат после выдачи ключа

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-59 | Возврат в окне | `Completed`, прошло < 14 сут | `Refunding` → `RefundWorkflow`: `RevokeSoldKey` (`Sold → Revoked`) → `RefundPayment` → `Refunded`; ключ больше не отдаётся в `GetOrder` | INV-06, INV-17 | T-W-16, T-U-08 |
| EC-60 | Возврат вне окна | прошло ровно 14 сут + 1 с (`MockClock`) | 409 `REFUND_WINDOW_CLOSED` | — | T-U-09 `RefundWindowTest` (граница: ровно 14×86400 с = ещё открыто, +1 с закрыто) |
| EC-61 | Двойной запрос возврата | два `RequestRefund` | второй 202 с текущим статусом; один `RefundWorkflow` (`refund-{orderId}`), refund у шлюза 1 | INV-06, INV-16 | T-F-12, T-W-17 |
| EC-62 | Возврат не выполненного заказа | `RequestRefund` на `Paid`/`Failed` | 409 `ORDER_NOT_REFUNDABLE` | INV-03 | T-U-10 |
| EC-63 | Ключ уже отозван при возврате | `RevokeKey` сделан до `RequestRefund` | `RevokeSoldKey` считает `KeyAlreadyRevoked` успехом, refund выполняется | INV-06 | T-U-38 |

## H. Входные данные, деньги, валюта

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-64a | Отрицательная сумма | `expectedPrice.amount = -100` | 422, `errors[].propertyPath = expectedPrice.amount` | INV-01 | T-F-20 (data provider a–k) |
| EC-64b | Нулевая сумма | `amount = 0` | 422 | INV-01 | T-F-20 |
| EC-64c | Неизвестная валюта | `currency = "XYZ"` | 422 | — | T-F-20 |
| EC-64d | Регистр валюты | `currency = "eur"` | 422 (строгий ISO) | — | T-F-20 |
| EC-64e | Сумма строкой | `amount = "100"` | 422 (type enforcement денормализации, без приведения) | INV-01 | T-F-20 |
| EC-64f | Сумма дробная | `amount = 10.5` | 422 | INV-01 | T-F-20 |
| EC-64g | Больше максимума | `amount = 100000001` | 422 | INV-01 | T-F-20 |
| EC-64h | Переполнение int | `amount = 99999999999999999999` (JSON-число > PHP_INT_MAX, декодируется как float) | 422, не 500, не обрезание | INV-01 | T-F-20 |
| EC-64i | Лишнее поле | `{"price": 1, ...}` | 422 (`allow_extra_attributes: false`) | — | T-F-20 |
| EC-64j | Битый JSON / не JSON | тело `{"productId":` | 400 (Symfony) или 422 — зафиксировать фактическое поведение `MapRequestPayload` в M5 и тест | — | T-F-20 |
| EC-64k | `productId` не UUID | `"abc"` | 422 | — | T-F-20 |
| EC-65 | Переполнение в домене | `Money::of(PHP_INT_MAX, EUR)->add(Money::of(1, EUR))` и `add` в пределах int, но > `MAX_AMOUNT` | `MoneyOverflow` / `MoneyOutOfRange`; никогда `float` | INV-01 | T-U-01 `MoneyTest` |
| EC-66 | Расхождение валют при заказе | каталог EUR, `expectedPrice.currency = USD` | 422 `CURRENCY_MISMATCH`, заказа нет | INV-02, INV-13 | T-U-02, T-F-21 |
| EC-67 | Цена изменилась | каталог 1999, клиент ждёт 1899 | 409 `PRICE_CHANGED` с актуальной ценой | INV-13 | T-U-03, T-F-22 |
| EC-68 | Операции с разными валютами | `EUR.add(USD)` | `CurrencyMismatch` | INV-02 | T-U-01 |
| EC-69 | Неизвестный/неактивный товар | `productId` не существует / `active=false` | 404 `PRODUCT_NOT_FOUND` / 409 `PRODUCT_UNAVAILABLE` | — | T-F-23 |
| EC-70 | Чужой заказ | C2 запрашивает или отменяет заказ C1 | 404 `ORDER_NOT_FOUND` (REST и GraphQL) | — | T-F-24 |
| EC-71 | Нет `X-Customer-Id` | заголовок отсутствует или не UUID | 401 `CUSTOMER_REQUIRED` | — | T-F-25 |
| EC-72 | Битый курсор пагинации | `cursor=%%%` | 422 `INVALID_CURSOR` | — | T-F-26 |
| EC-73 | `limit` вне диапазона | `limit=0`, `limit=101`, `limit=abc` | 422 | — | T-F-26 |

## I. Время и часовые пояса

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-74 | Системный TZ не UTC | `TZ=Europe/Kyiv` и `date.timezone=Europe/Kyiv` в тестовом окружении | хранимые и возвращаемые значения те же, что при UTC; `NativeClock` всегда возвращает UTC | INV-12 | T-I-40 `TimezoneIndependenceTest` |
| EC-75 | Переход на летнее время | окно возврата пересекает DST в Europe/Kyiv (последнее воскресенье марта) | окно считается в секундах UTC (14×86400), результат не зависит от DST | INV-12 | T-U-11 |
| EC-76 | Формат времени в API | любой ответ с датой | ISO-8601 с `+00:00` и миллисекундами | INV-12 | T-F-27 |
| EC-77 | Системное время в домене/workflow | код вызывает `new DateTimeImmutable()` или `time()` в Domain/Application/Workflow | PHPStan падает | INV-12 | T-ARCH-08 |
| EC-78 | Время в workflow | workflow вычисляет дедлайн | только `Workflow::now()`; replay даёт тот же результат | INV-12 | T-ARCH-09, T-W-20 |

## J. Конкуренция и прочее

| ID | Случай | Условие | Ожидаемое поведение | Инвариант | Тест |
|---|---|---|---|---|---|
| EC-79 | Конкурентное изменение заказа | `CancelOrder` и активность `MarkOrderPaid` пишут один `Order` одновременно | одна запись проходит, вторая `ConcurrencyConflict`; HTTP-handler повторяет до 3 раз, активность повторяет Temporal; итог согласован (`Paid` + `cancellationRequested=true`) | INV-15 | T-I-41 |
| EC-80 | Код ключа в логах | полный happy path с `TestHandler` Monolog | ни одна запись лога и ни одно outbox-сообщение не содержит полный код | INV-17 | T-I-42 |
| EC-81 | Сумма списания из workflow-входа | попытка передать сумму во вход workflow | вход workflow = только `orderId`; `ChargePayment` берёт `Order.price` | INV-18 | T-U-39, ревью сигнатуры |
| EC-82 | GraphQL: глубина/сложность | запрос глубже лимита (8) или сложнее лимита (200) | ошибка валидации GraphQL, резолверы не вызываются | — | T-F-30 |
| EC-83 | GraphQL: `keyCode` не для `Completed` | `order { keyCode }` для `Paid`/`Refunded` | `null` | INV-17 | T-F-31 |
| EC-84 | GraphQL: N+1 | `myOrders { product { name } }` на 20 заказах | один запрос к продуктам (DataLoader/батч); тест считает SQL-запросы через DBAL middleware | — | T-F-32 |
| EC-85 | Потеря соединения с БД в воркере | Postgres перезапущен между активностями | следующая активность переподключается и выполняется | — | T-I-43 (ручной/полуавтоматический, помечен `@group slow`) |
