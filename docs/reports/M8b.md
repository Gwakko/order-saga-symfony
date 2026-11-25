# M8.1. Покрытие таблицы крайних случаев (часть б этапа M8)

## Итог

- В `04_edge_cases.md` 103 строки EC (EC-01 … EC-92 с суффиксами a/b/k, включая раздел K из M8а).
- До работы без найденного теста были 3 EC: EC-09 (T-I-10), EC-74 (T-I-40), EC-80 (T-I-42). Остальные 100 уже имели тесты.
- Теперь `bin/check-edge-case-coverage`: 103 из 103 найдены, код возврата 0. Скрипт подключён целью `make check-coverage` и входит в `make ci` (после `cs`, перед `phpstan`).
- `make ci` в контейнере зелёный три раза подряд: 941 unit (2185 assert), 242 integration (2590), 28 workflow (1 skipped: запись фикстуры), 160 functional. Было 239 integration, добавлено 3. `RefundRetryTest` в этих прогонах не падал (нестабильность из `M8a-flaky.md` не проявилась). `make test-slow` (T-I-43): зелёный, 13 с. Все сервисы compose `running`.
- Код `src/` не менялся (`git diff -- src migrations config phpstan` пуст).

## Скрипт `bin/check-edge-case-coverage`

PHP, без зависимостей, `strict_types`. Разбирает строки `| EC-… |`, берёт последнюю колонку, вытаскивает `T-…-…` (диапазоны вида `T-M8A-06 … T-M8A-13` раскрываются), сравнивает с `#[Group('T-…')]` в `tests/` и `phpstan/`. Если у EC не найден хотя бы один указанный идентификатор или идентификаторов нет вовсе, код возврата 1. Строки с «ручной»/«ревью» в колонке тестов помечены `ручной` (EC-81, EC-85), `@group slow` в файле теста даёт пометку `slow` (EC-85), оба списка печатаются в итоге явно. Флаг `--markdown` генерирует таблицу для `docs/spec/08_coverage.md`. Скрипт проверяет наличие идентификатора, а не качество (см. ниже). Ограничение: все подслучаи EC-64a..k привязаны к одному T-F-20; построчное соответствие проверено вручную (data provider `invalidBodies` содержит ключи EC-64a … EC-64k).

## Написано (3 теста, набор integration)

| Тест | Файл | Что проверяет |
|---|---|---|
| T-I-10 (EC-09) | `tests/Integration/Payment/ChargePaymentIdempotencyTest.php` (+ `Support/CrashOnSucceededPaymentRepository`) | Реальные Postgres и эмулятор шлюза. Первый прогон `ChargePaymentHandler` падает в момент сохранения `Succeeded` (после списания у шлюза): в БД `Pending` без `gateway_charge_id`, у шлюза 1 списание. Повтор: тот же `paymentId`, `Succeeded`, у шлюза суммарно 1 списание (`requests=2, executed=1` по ключу), одна строка `payments`, один `PaymentSucceeded` в outbox |
| T-I-40 (EC-74) | `tests/Integration/Persistence/TimezoneIndependenceTest.php` | Один сценарий (сохранение заказа, чтение, outbox, `ApiDateTime`, часы контейнера) под UTC и под Europe/Kyiv (`date_default_timezone_set`, `TZ`, `SET TIME ZONE` в сессии Postgres, перезагрузка ядра); снимки `to_char(... AT TIME ZONE 'UTC')`, форматов из репозитория, API и часов идентичны и равны ожидаемым литералам; `ClockInterface` отдаёт UTC. Мгновение выбрано перед переходом на летнее время 2026 |
| T-I-42 (EC-80) | `tests/Integration/Persistence/KeyCodeLeakTest.php` | Весь happy path на реальных обработчиках (place, reserve, mark reserved, charge через эмулятор, pay, consume, complete) плюс защитный `ReleaseReservation` после consume (EC-22, пишет warning). Проверяет, что путь дошёл до выдачи, что запись лога `Warning` существует (проверка не пустая), что полный код отсутствует во всех записях Monolog `TestHandler` (сообщение и контекст) и во всех `outbox_messages.payload`, а маскированный `XXXX-...-NNNN` в payload есть |

Отклонение от `05_testing.md`: там T-I-40 описан как «отдельный процесс PHPUnit с TZ=Europe/Kyiv». Реализовано в одном процессе, потому что все три настройки времени (PHP default timezone, `TZ`, сессия Postgres) меняются на лету, а ядро перезагружается; результат тот же (часы контейнера создаются заново под нужной зоной). В `08_coverage.md` это отмечено, ожидаемое поведение EC не менялось.

Также усилены два существующих теста по итогам мутационной проверки (см. ниже): в T-I-25 (`RefundRetryTest::testCrashAfterRefundBeforeSave`) добавлена проверка суммарного числа возвратов у шлюза независимо от ключа.

## Выборочная проверка «тест что-то проверяет» (вручную открыты и разобраны)

Группы: деньги/платежи, идемпотентность, конкуренция, сага/отмена, безопасность, GraphQL, outbox/inbox.

| EC | Тест | Вывод |
|---|---|---|
| EC-09 | T-I-10 (новый) | Реальные БД и эмулятор, падение моделируется декоратором репозитория; считает списания у шлюза |
| EC-10 | T-U-30 | Счётчик вызовов шлюза = 0; красный в мутации |
| EC-12 | T-I-11 | 20 раундов, 3 форк-процесса, один `paymentId`, одна строка |
| EC-15 | T-I-13 | 50 раундов, форк, барьер; результат строго `[out_of_stock, reserved]`, проверены статусы в таблицах |
| EC-19a | T-I-15 | Форк sweeper против consume, ровно один победитель, плюс детерминированный тест устаревшей записи (`ConcurrencyConflict`) |
| EC-24..26, 30 | T-I-17/18/19 | Реальный эмулятор во всех режимах, время таймаута измеряется (9.5..11.5 с) |
| EC-36 | T-U-35 | Проверяет статус `RefundPending`, событие, сохранение запрошенной суммы, затем сам refund по charge id |
| EC-03 | T-F-02 | 422 + код + нет `Idempotent-Replayed` + в БД 1 заказ |
| EC-53 | T-I-33 | Счётчик применений = 1, одна строка inbox |
| EC-64a..k | T-F-20 | Таблица `invalidBodies` с ключами по подслучаям, проверяются статус, `propertyPath`, код |
| EC-70 | T-F-24 | REST и GraphQL: чужой и несуществующий неразличимы, `cancellation_requested` не изменилось |
| EC-79 | T-I-41 | Детерминированная гонка (`RaceOnceOrderRepository`) в обе стороны и 30 форк-раундов; итог `Paid` + флаг |
| EC-40/41/47 | T-W-11/12/15 | Реальный test server с замоканными активностями; проверяется точная последовательность активностей и результат. Замечание: флаг `cancellationRequested=true` в EC-41 в workflow-тесте не виден (это база), он покрыт T-U-07/T-I-23 |
| EC-45/46 | T-I-24/25 | Реальный воркер/Temporal и эмулятор, число попыток из истории, `executed=1` |
| EC-50..52 | T-I-30/31/32 | См. мутации |
| EC-82/84 | T-F-30/32 | Лимиты по тексту ошибки GraphQL, резолверы не вызываются (пустой список SQL); N+1 считается по SQL-запросам через рекордер |
| EC-80 | T-I-42 (новый) | См. выше |

Поиск формальных заглушек по всем `tests/`: `assertTrue(true)` нет, `markTestIncomplete` нет, один `markTestSkipped` (запись фикстуры, T-W-20, штатно). Вызовы `addToAssertionCount` стоят только внутри `try`/`catch` после `self::fail(...)` (ожидаемое исключение), в `SagaConfigurationTest` и `OrderFulfillmentReplayTest` (`replayFromJSON` бросает при несовместимости): допустимо.

## Мутационная проверка вручную (5 критичных EC)

Каждая поломка внесена правкой, затем откатана обратной правкой; `git diff -- src` после каждой пуст (без `git stash`/`checkout`).

| EC | Что ломал | Тесты | Результат |
|---|---|---|---|
| EC-09, EC-10 двойная оплата | (a) в `ChargePaymentHandler` в `gateway->charge` ключ идемпотентности `paymentId` заменён на `paymentId + uniqid()`; (b) `Succeeded => output` заменён на `=> charge` (повторное списание после сохранённого успеха) | (a) T-I-10 (новый); (b) `ChargePaymentHandlerTest` | (a) красный (изначально падал косвенно: на отсутствии статистики по ключу; тест усилен суммарным подсчётом списаний, теперь падает на «2 вместо 1»). (b) красный: `testSucceededPaymentShortCircuits`, `testRepeatAfterSuccessDoesNotChargeAgain` |
| EC-15 гонка за последний ключ | (a) убрано `FOR UPDATE SKIP LOCKED` в `DbalKeyInventory::lockAvailable`; (b) дополнительно убрана проверка версии в `UPDATE product_keys` (`version = :expected`) | T-I-13 | (a) красный (оба теста: `ConcurrencyConflict` вместо `OutOfStock`, 6 покупателей на 4 ключа получили результат не того вида); (b) красный: оба покупателя получают ключ, раунд 1 падает |
| EC-24/25/26 неизвестный исход шлюза | В `HttpPaymentGateway` таймаут транспорта преобразован в `PaymentGatewayUnavailable`, а ответ 5xx в `GatewayRequestRejected` (отказ вместо «неизвестно») | `HttpPaymentGatewayTest` | красный: 8 тестов (таймаут, drop-connection, HTTP 500/503, accept-then-502, короткий таймаут, 502, ошибка транспорта) |
| EC-45/46 повтор компенсации | В `PaymentRefunder` ключ `refund-{paymentId}` заменён на ключ с `uniqid()` (каждый повтор двигает деньги заново) | T-I-25 (`testCrashAfterRefundBeforeSave`) | красный. Первоначально падал косвенно (`1 вместо 2` по статистике ключа); добавлена проверка суммарного числа возвратов, теперь падает на «2 вместо 1». T-U-37 при этой мутации зелёный (он про `Unknown`/`Abandoned`, не про ключ) |
| EC-50/51/52 потеря события outbox | (a) `DbalOrderRepository::save` перестал писать события в outbox; (b) `OutboxRelay` при ошибке публикации проставляет `published_at` (сообщение теряется) | `OutboxRelayTest`, `TransactionalOutboxTest`, `MessengerFailureTransportTest` | (a) красный: `testUnpublishedMessageIsSentAfterRestart`, `testEveryDomainEventOfAFullFlowIsNormalised`, `testCommitKeepsStateAndMessageTogether` (T-I-31 `testRollbackLeavesNoMessage` при (a) зелёный, это ожидаемо: он проверяет отсутствие строк при откате). (b) красный: `testCrashBeforeMarkPublishedCausesDuplicate`, `testRowsBeforeAFailingOneAreMarkedAndTheFailureIsCountedAndRethrown` |

Все пять защит имеют красный тест; тестов, оставшихся зелёными при сломанной защите, нет. Два теста были красными по косвенной причине и усилены (T-I-10 и T-I-25).

## Расхождения кода и спеки

Расхождений поведения не найдено; `04_edge_cases.md` не менялся.
Наблюдения (без изменения ожидаемого поведения):

- `05_testing.md` описывает T-I-40 как отдельный процесс PHPUnit; реализация в одном процессе (см. выше). Спека-описание способа не правилось, пометка в `08_coverage.md`.
- EC-64j в спеке допускает 400 или 422; тест фиксирует фактическое 400.
- EC-41 / T-W-12: workflow-тест подтверждает `Completed` без refund/release, но не флаг `cancellationRequested` (его пишет обработчик отмены; покрыт T-U-07, T-I-23).

## Осталось ручным / вне `make ci`

- EC-85 (T-I-43, потеря соединения воркера с БД): `@group slow`, запускается `make test-slow`, в этом прогоне зелёный (13 с); `bin/worker-postgres-restart-check` как ручной сценарий остаётся. Автоматическая часть найдена скриптом.
- EC-81: часть «ревью сигнатуры входа workflow» ручная; автоматическая часть (T-U-39) найдена.
- Карта EC → тест → статус: `docs/spec/08_coverage.md` (сгенерирована скриптом).

## Изменённые и добавленные файлы

- `bin/check-edge-case-coverage`, `Makefile` (цель `check-coverage`, в `ci`), `docs/spec/08_coverage.md`
- `tests/Integration/Payment/ChargePaymentIdempotencyTest.php`, `tests/Integration/Persistence/TimezoneIndependenceTest.php`, `tests/Integration/Persistence/KeyCodeLeakTest.php`, `tests/Integration/Support/CrashOnSucceededPaymentRepository.php`
- `tests/Integration/Saga/RefundRetryTest.php` (усилен T-I-25)
