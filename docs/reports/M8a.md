# M8a. Накопленные дефекты и хвосты M3-M7

Итог: пункты 1-6 сделаны, раздел «Добавлено в M8a» (EC-86...EC-92) записан в конец `docs/spec/04_edge_cases.md`. Идентификаторы новых тестов `T-M8A-NN` (группа PHPUnit). Префикс `T-F-31`/`T-F-32` занят (GraphQL `keyCode`, N+1), поэтому новые номера в отдельном пространстве.

Наборы после M8a (`make ci` зелёный): unit 941 (было 916, +25), integration 239 (было 221, +18), workflow 28 (1 skipped: запись фикстуры), functional 160 (было 156, +4).

## 1. Лимит глубины и introspection

Сделано. `QueryDepthExceptIntrospection` (наследник `QueryDepth`) не считает поддеревья `__schema` и `__type`, когда `graphql.introspection_enabled` истинно; `GraphQlValidationRules` подставляет его вместо `QueryDepth`. Остальные поля по-прежнему лимит 8, сложность 200 не менялась.

Фактическое поведение, проверено реальным ядром `Kernel('prod', false)` (`GraphQlIntrospectionProdTest`): `__schema`/`__type` дают HTTP 400 с ошибкой «Introspection is not allowed», без `data`; полный introspection-запрос GraphiQL в `prod` даёт 400 с двумя ошибками (introspection запрещён и «Max query depth should be 8 but got 11»), то есть исключение из лимита выключено вместе с introspection. Обычный `{ __typename }` в `prod` проходит (200). В dev/test полный запрос GraphiQL (глубина 11) проходит (200).

Тесты: T-M8A-01 (unit: полный introspection проходит при включённом, обычные поля на границе 8 проходит, 9 отклоняется, глубокий обычный запрос рядом с introspection отклоняется, при выключенном introspection исключения нет; functional: полный запрос GraphiQL и `ofType` x7 в тесте-окружении, полный запрос в `prod`).

Отклонения от существующих тестов (требование противоречило им):
- `GraphQlLimitsTest`: схема не рекурсивна, глубину раньше строили через introspection (`ofType`), а теперь introspection исключён из лимита. Тест «ровно на лимите проходит» удалён из functional (границу 8/9 проверяет unit на рекурсивной схеме, T-M8A-01); тест «глубже лимита отклоняется до резолверов» (T-F-30) теперь строит цепочку несуществующих полей глубины 9 и проверяет, что среди ошибок есть «Max query depth should be 8 but got 9», 400, ни одного SQL; тест «полный introspection превышает лимит» заменён на «полный introspection проходит».

## 2. Идемпотентность и 4xx

Сделано. `ExceptionToProblemListener` помечает запрос атрибутом `_input_rejected`, когда ошибка есть отказ или разбор входа: `ValidationFailedException`, `ExtraAttributesException`, `HttpExceptionInterface` без доменной ошибки (битый JSON, 400/415 и т. п.), а также доменные ошибки формата значений (`VALIDATION_FAILED`, `INVALID_IDENTIFIER`, `NEGATIVE_MONEY`, `NON_POSITIVE_MONEY`, `MONEY_OUT_OF_RANGE`, `MONEY_OVERFLOW`, `UNKNOWN_CURRENCY`). `IdempotencyListener::onResponse` для такого ответа удаляет строку `in_progress` (`release`) вместо `complete`. Остальные 4xx доменные ошибки (404 `PRODUCT_NOT_FOUND`/`ORDER_NOT_FOUND`, 409 `PRICE_CHANGED` и т. п.) сохраняются и воспроизводятся.

Тесты: T-M8A-02 (422 валидации: строки нет, повтор считается заново без `Idempotent-Replayed`, исправленное тело с тем же ключом даёт 201), T-M8A-03 (битый JSON), T-M8A-04 (неизвестное поле), T-M8A-05 (404 `PRODUCT_NOT_FOUND` сохраняется и воспроизводится). T-F-01...06 не менялись (T-F-06 про 409 `PRICE_CHANGED` проходит как был).

Отклонение: `testValidationErrorIsStoredToo` (без идентификатора T-F-..., проверял сохранение 422 с воспроизведением) противоречил `02_use_cases.md` §5 и заменён тестом T-M8A-02. Идемпотентным остаётся только `POST /api/orders`, поэтому «404» из задачи проверен на `PRODUCT_NOT_FOUND`, а не `OrderNotFound` (у отмены и возврата `#[Idempotent]` нет).

## 3. Шаблон повторной записи в outbox

Дефект подтверждён для `DbalReservationRepository::save`: после сохранения агрегат продолжают (`create` -> `save` -> `consume`/`release`/`expire` -> `save`), а `pendingEvents` хранят события прежних сохранений; повторная запись давала `duplicate key outbox_messages_pkey` (воспроизведено до правки: 2 из 3 reservation-тестов падали с `UniqueConstraintViolationException`, после правки зелёные). Исправление как в Payment: в outbox идут только события с `aggregateVersion() >= reservation->version`.

`DbalKeyInventory::save`: тот же шаблон в коде, но у `ProductKey` единственное событие `KeyRevoked` (терминальный переход), поэтому дублю неоткуда взяться; тесты-регрессии добавлены, фильтр по версии выровнен для единообразия. Дефект не проявляется.

`DbalOrderRepository::save`: CAS `stored == version - count(pendingEvents)`, продолженный после `save` агрегат отклоняется `ConcurrencyConflict` и ничего не пишет (безопасный отказ, не дубли). Оставлено как есть (обработчики перечитывают заказ через `OrderMutator`); поведение зафиксировано тестом T-M8A-12. `DbalPaymentRepository` исправлен ещё в M4.6.

Тесты: T-M8A-06 (reservation: продолжение после save), T-M8A-07 (после загрузки), T-M8A-08 (повторное сохранение того же состояния: конфликт, в outbox без дублей), T-M8A-09 (key: повторное сохранение revoke), T-M8A-10 (key: продолжение после save), T-M8A-11 (key: после загрузки), T-M8A-12 (order: продолжение = конфликт без дублей), T-M8A-13 (order: после загрузки).

## 4. Нестабильный `RefundRetryTest`

Причину найти не удалось, поэтому тест укреплён и снабжён диагностикой.

Что наблюдалось: один таймаут в `make ci` (`TimeoutException: Unable to wait for workflow completion`, 60 с) и ещё один раз в полном прогоне `--testsuite integration` (1:29 вместо 0:45); оба раза в составе полного набора. Одиночный запуск теста: 20 из 20 проходов (до правок), каждый 19 с (5 + 10 с бэкоффа и запуск воркера); запуск после `RefundEndToEndTest` и после `rm -rf var/cache/test`: проходит; ещё 5 полных прогонов набора integration без сбоев. Второй сбой совпал по времени с тем, что я в этот момент добавлял файлы в `src/` и `config/` (ядро воркера `APP_DEBUG=0` и ядро тестов делят `var/cache/test`).

Проверенные и отвергнутые гипотезы: накопление запущенных workflow на очереди `order-saga-test` (на очереди 0 запущенных), прогрев кэша контейнера во время работы воркера (`touch config/services.yaml` + `cache:warmup` посреди теста: проходит), порог circuit breaker (в тесте 2 ошибки при пороге 5 подряд, ключ `cb:payment-gateway:streak` после прогона отсутствует), общий RPC-порт 6002 (воркер упал бы сразу с «exited early»).

Что сделано:
- `TemporalTestWorker::start` вместо фиксированной паузы 1,5 с ждёт, пока именно этот воркер (poller с `lastAccessTime` после старта процесса, не «мёртвый» от прежнего теста) начнёт опрашивать и workflow-, и activity-очередь, до 30 с; при неудаче ошибка содержит вывод воркера. Прогон теста сократился с 19,0 до 18,0 с.
- При таймауте тест бросает исключение с диагностикой: статус и история workflow (типы событий) и вывод воркера (`TemporalTestWorker::output()`), так что следующий сбой объяснит себя сам.
- Прогон 20 раз подряд после правки: см. ниже «Итоговые прогоны».

Что проверить вручную: если сбой повторится в полном прогоне, смотреть блок диагностики в тексте ошибки (статус workflow, история, вывод воркера); подозреваю гонку за `var/cache/test` между ядром воркера (`APP_DEBUG=0`, не проверяет свежесть) и ядром тестов при изменении файлов во время прогона.

Итоговые прогоны: `RefundRetryTest::testRefundExecutedOnceAcrossRetries` 20 раз подряд после правки: 20 из 20 OK (около 18 с каждый); `make test-wf` три раза подряд: 28 тестов, 1 skipped, зелёные; `phpunit --group concurrency` 10 раз подряд: 14 тестов OK каждый раз; сервисы compose: gateway-emulator, nginx, php, postgres, redis, temporal, temporal-worker в состоянии `running` (`outbox-relay`, `messenger-consumer`, `scheduler` в профиле `workers` и не входят в обычный `up`).

## 5. Sweeper зависших заказов

Сделано.
- Application (`Ordering`): порт `WorkflowStatusChecker::isRunning(OrderId, SagaWorkflowKind): bool` (`@throws WorkflowStatusUnavailable`), enum `SagaWorkflowKind` (`Fulfillment` = `order-{id}`, `Refund` = `refund-{id}`), порт `StuckOrderFinder` + `StuckOrder`, команда `SweepStuckOrders` (`Command`, `Handler`, `Output`). Обработчик берёт заказы в статусах `Pending`, `KeyReserved`, `Paid`, `Compensating`, `Refunding` с `updated_at` старше порога, спрашивает порт и для неживых вызывает существующий `MarkOrderManualReviewHandler` с `FailureReason::SagaInterrupted` и шагом = статус зависания. Живой workflow не трогается; неизвестный статус (Temporal недоступен) и заказ, ушедший вперёд между запросом и переходом (`DomainError`: `OrderNotFound`, `ConcurrencyConflict`, недопустимый переход), пропускаются; повторный запуск идемпотентен (заказ в `ManualReview` больше не кандидат).
- Infrastructure: `DbalStuckOrderFinder` (таблица `orders`), `TemporalWorkflowStatusChecker` (`describe` последнего запуска; реальный SDK на несуществующий id отдаёт `ServiceClientException` с gRPC NOT_FOUND, не `WorkflowNotFoundException`: это нашёл интеграционный тест, обработаны оба; прочие ошибки означают «неизвестно»), консоль `app:orders:sweep-stuck [--older-than=1800] [--limit=100]`, `SweepStuckOrdersTick` + `SweepStuckOrdersTickHandler`, запись в `Schedule` раз в 5 минут. Порог из конфига: параметр `app.stuck_orders.older_than_seconds: 1800` в `config/services.yaml`; привязка `WorkflowStatusChecker` к Temporal-реализации там же. В `config/services_test.yaml` добавлены публичные сервисы для тестов.
- Фейки: `FakeWorkflowStatusChecker`, `InMemoryStuckOrderFinder`; в `InMemoryOrderRepository` добавлен `all()`.

Тесты: unit `SweepStuckOrdersHandlerTest` (T-M8A-14 старый заказ без workflow переводится, по статусам; T-M8A-15 живой workflow не трогается; T-M8A-16 `Refunding` судится по refund-workflow; T-M8A-17 свежий заказ и граница порога не трогаются, Temporal не опрашивается; T-M8A-18 повторный запуск ничего не меняет; T-M8A-19 прочие статусы не кандидаты; T-M8A-20 Temporal недоступен: заказ остаётся, остальные обработаны; T-M8A-21 заказ ушёл вперёд; T-M8A-22 `limit` и порядок «старые первыми»; T-M8A-23 валидация команды); integration: T-M8A-28 (`TemporalWorkflowStatusChecker`: идёт, завершён/terminated, не существовал), T-M8A-29 (недоступный сервер: `WorkflowStatusUnavailable`), T-M8A-30 (`DbalStuckOrderFinder`), T-M8A-31 (консоль на Postgres и настоящем Temporal: terminated и несуществующий workflow переводятся, живой нет), T-M8A-32 (повтор и Scheduler-tick идемпотентны), T-M8A-33 (границы опций).

Отклонения: домен и `OrderMutator` не менялись; нового индекса под выборку по `(status, updated_at)` нет (выборка идёт по малой таблице заказов, при росте стоит добавить частичный индекс); `Completed` не sweeper'ится (после доставки ключа саги нет, зависнуть ему нечему).

## 6. Очистка просроченных `idempotency_keys`

Сделано. Порт `Shared\Application\Port\IdempotencyKeyCleaner::deleteExpired(now, limit)`, команда `PurgeIdempotencyKeys` (`Command`/`Handler`/`Output`), `DbalIdempotencyKeyCleaner` (один ограниченный `DELETE ... WHERE (customer_id, key) IN (SELECT ... WHERE expires_at <= :now ORDER BY expires_at LIMIT :limit)` по индексу `idempotency_keys_expiry_idx`; граница `expires_at <= now` совпадает с `DbalIdempotencyStore::begin`), консоль `app:idempotency:purge [--limit=10000]`, `PurgeIdempotencyKeysTick` + обработчик, `Schedule` раз в час.

Тесты: T-M8A-24 (просроченные завершённые и брошенные `in_progress` удаляются, живые остаются), T-M8A-25 (граница), T-M8A-26 (повтор и `limit`), T-M8A-27 (консоль и Scheduler-tick с настоящими часами, неверный `limit` -> 2), T-M8A-34 (unit: часы и `limit` передаются порту, валидация).

Отклонение: брошенные `in_progress` старше 60 с (EC-07b) считаются истёкшими логикой `begin`, но удаляются очисткой только по `expires_at` (через 24 ч), как требовалось.

## Общие изменения вне списка

- `tests/Unit/Shared/Infrastructure/ScheduleTest.php`: ожидание расписания расширено двумя задачами (тест утверждал точный список из двух; изменение вытекает из пунктов 5-6).
- `config/services.yaml` (параметр порога и привязка порта), `config/services_test.yaml` (публичные сервисы для тестов), `src/Schedule.php`. `compose.yaml` и CI не менялись.

## Что проверить вручную

- `docker compose exec php bin/console app:orders:sweep-stuck --older-than=60` на стенде с зависшим заказом (после остановки воркера и `terminate` workflow): заказ уходит в `manual_review` с причиной `SagaInterrupted`.
- `docker compose exec php bin/console app:idempotency:purge`.
- В Temporal UI: у переведённого заказа workflow закрыт, живые не тронуты.
