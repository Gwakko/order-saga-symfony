# order-saga-symfony

Пример: что показывает. Сага на Temporal, outbox/inbox, CQRS, GraphQL, собственные правила PHPStan.

Пет-проект, проверен локально (`make ci` в контейнерах), не production. Прогона в GitHub Actions не было. Учебная реализация заказа цифрового ключа: сага на Temporal, компенсации, идемпотентность, outbox/inbox, circuit breaker вокруг платёжного шлюза, CQRS с read model и GraphQL, собственные правила PHPStan. Работает на Docker Compose; нагрузочных цифр и опыта эксплуатации нет (см. раздел «Чего нет и что не проверено»).

Спецификация и отчёты по этапам на русском, имена в коде на английском.

## Домен и сценарий заказа

Покупатель выбирает товар с ценой и отправляет заказ (одна позиция, `quantity = 1`), указывая `expectedPrice`. Сервер сверяет цену с каталогом, создаёт `Order` в статусе `Pending` и через outbox запускает `OrderFulfillmentWorkflow` (`WorkflowId = order-{orderId}`). Покупатель передаётся заголовком `X-Customer-Id`, HTTP-команды требуют `Idempotency-Key`.

Шаги саги:

1. `ReserveKey`: берётся свободный `ProductKey`, создаётся `KeyReservation` с TTL (900 с).
2. `ChargePayment`: списание через шлюз, идемпотентный ключ шлюза = `PaymentId`.
3. `ConsumeReservation`: ключ становится `Sold`.
4. `CompleteOrder`: ключ прикрепляется к заказу, статус `Completed`.

Код ключа покидает систему только в ответе `GET /api/orders/{id}` и в GraphQL `order` у заказа в статусе `Completed`. В ответе на создание заказа, в списках, логах и событиях его нет (`INV-17`, тест `T-I-42`).

## Слои и модули

```
 HTTP / GraphQL                         Temporal (RoadRunner worker)
      |                                        ^
      v                                        | start / signals
+---------------------------- Infrastructure ----------------------------+
|  Http, GraphQL, Persistence (DBAL), PaymentGateway + CircuitBreaker,    |
|  Temporal workflow/activities, Messenger buses, outbox relay            |
|     +------------------------ Application ------------------------+     |
|     |  Commands / Queries, handlers, Output DTO, ports            |     |
|     |     +------------------- Domain -------------------+       |     |
|     |     |  aggregates, value objects, domain events    |       |     |
|     |     +----------------------------------------------+       |     |
|     +-------------------------------------------------------------+     |
+-------------------------------------------------------------------------+
Зависимости только внутрь: domain <- application <- infrastructure.

Модули (bounded contexts), связь только через сагу и события:
  Ordering  (Order, order_view)          Inventory (Product, ProductKey, KeyReservation)
  Payment   (Payment, шлюз, breaker)     Saga      (workflows, activities)
  Shared    (Money, Currency, outbox, inbox, idempotency)

CQRS:   write: command -> handler -> aggregate -> DBAL repo + outbox (одна транзакция)
        read:  outbox -> relay -> Redis Streams (Messenger) -> projection -> order_view -> query / GraphQL
Сага:   OrderPlaced (outbox) -> StartOrderSagaOnOrderPlaced -> Temporal -> activities -> gateway-emulator
```

Правила слоёв и модулей проверяет phpat, детерминизм workflow и остальные правила: собственные правила PHPStan в `phpstan/Rules/`.

## Стек

Версии взяты из `composer.json`, `composer.lock` (`composer show --direct` в контейнере) и `compose.yaml`.

| Компонент | Версия |
|---|---|
| PHP | 8.5.11 в контейнере (`php:8.5-fpm`), `composer.json`: `>=8.4` |
| Symfony | 8.1.x (framework-bundle, messenger 8.1.11) |
| Doctrine DBAL / migrations | 4.6.0 / 3.9.9 (ORM не используется) |
| Temporal PHP SDK | `temporal/sdk` 2.20 (RoadRunner 3.0.1, `ext-grpc`, `ext-protobuf`) |
| Temporal server | `temporalio/temporal:1.10.1` (CLI-образ, `server start-dev`) |
| GraphQL | `webonyx/graphql-php` 15.39.0 |
| PostgreSQL / Redis / nginx | 17-alpine / 7-alpine / 1.27-alpine |
| Эмулятор шлюза | PHP 8.5 CLI, отдельный сервис `gateway-emulator`, порт 8081 |
| PHPUnit | 13.4.8 |
| PHPStan | 2.3.7, `level: max`, без baseline и `ignoreErrors`; strict-rules, phpunit, symfony |
| phpat / PHP-CS-Fixer | 0.12.5 / 3.95.32 |

## Как запустить

Всё выполняется через контейнер `php` (Makefile: `PHP ?= docker compose exec -T php`). Хостовый `make ci PHP=` рассчитан на GitHub Actions.

```bash
make up                          # docker compose up -d: php, nginx (8080), postgres, redis, temporal (7233, UI 8233), gateway-emulator, temporal-worker
make install                     # composer install в контейнере
make wf-install                  # один раз: скачать temporal-test-server (около 83 MB) для набора workflow
make ci                          # composer validate, cs, check-coverage, phpstan, unit, go-test, integration, go-test-int, workflow, functional
make smoke                       # E2E-проверка поверх compose (tests/E2E/smoke.sh), в make ci не входит
make go-test                     # Go-воркер: gofmt, go vet, тесты с -race в контейнере go-toolchain (входит в make ci)
make go-test-int                 # Go-воркер против compose-сервера Temporal (входит в make ci)
make test-go-e2e                 # PHP-сага против настоящего Go-воркера (tests/E2E/go-e2e.sh), около 2,5 минут, в make ci не входит
make test-wf                     # только workflow-тесты (Temporal test server)
make test-slow                   # медленные интеграционные (T-I-43: обрыв сессий БД под работающей сагой, около 15 с)
bin/check-edge-case-coverage     # у каждого EC из docs/spec/04_edge_cases.md есть существующий тест
```

Фоновые процессы (профиль `workers`: `outbox-relay`, `messenger-consumer`, `scheduler`) обычным `make up` не стартуют: интеграционные тесты делят БД и Redis Streams с dev-стеком, работающий relay публиковал бы строки, которые тесты ждут неопубликованными. Запуск: `docker compose --profile workers up -d`.

`tests/E2E/smoke.sh` на хосте не использует `curl`: HTTP идёт через `docker compose exec php php`. Скрипт поднимает стек и профиль `workers`, применяет миграции, создаёт товары и ключи SQL-вставками (команды наполнения каталога в проекте нет) и проходит четыре сценария: заказ до `Completed` (ключ в ответе на создание, в списке и в логах не появляется, повтор с тем же `Idempotency-Key` возвращает `Idempotent-Replayed: true`), отказ шлюза (`decline`, заказ `Failed`, ключ снова `available`), возврат (`Refunded`), отмена во время списания (`Cancelled`, ключ свободен). Воркеры, которые скрипт запустил сам, он останавливает в конце (чтобы не мешать интеграционным тестам); `KEEP_WORKERS=1` оставляет их работать; `temporal-worker` не в профиле и остаётся. Код возврата 1 при любом сбое. Тестовые данные остаются в dev-БД.

REST: `POST /api/orders`, `GET /api/orders/{id}`, `GET /api/orders`, `POST /api/orders/{id}/cancel`, `POST /api/orders/{id}/refund`. GraphQL: `POST /graphql`.

## Как устроена сага

- Шаги: `ReserveKey`, `ChargePayment`, `ConsumeReservation`, `CompleteOrder` (см. выше). После каждого шага статус заказа фиксируется активностью (`MarkOrderKeyReserved`, `MarkOrderPaid`).
- Компенсации (`Saga` из Temporal PHP SDK, выполняются последовательно): `RefundPayment` регистрируется до `ChargePayment`, затем `ReleaseReservation`. Заказ уходит в `Failed` (бизнес-причина, например нет ключей или отказ банка) или `Cancelled`.
- Отмена: `POST /cancel` ставит флаг в БД и шлёт сигнал `requestCancellation`; workflow проверяет флаг перед `ReserveKey`, `ChargePayment` и `ConsumeReservation`. После `ConsumeReservation` отмена невозможна, остаётся возврат.
- `RefundWorkflow` (`refund-{orderId}`): после выдачи ключа `RevokeSoldKey`, `RefundPayment`, `FinishOrderRefund`; запускается тем же путём через outbox.
- `ManualReview`: если компенсация не удалась или сбой активности не удалось классифицировать (эффект шага неизвестен, например `ConsumeReservation` мог пройти), workflow не гадает, а переводит заказ в `ManualReview` с событием `OrderNeedsManualReview` и записью `critical` в лог. Команды оператора для выхода из `ManualReview` нет.
- Sweeper `app:orders:sweep-stuck`: переводит в `ManualReview` (`SagaInterrupted`) заказы в `Pending`/`KeyReserved`/`Paid`/`Refunding` без живого workflow.
- Дополнительно по расписанию (Symfony Scheduler): `ExpireReservations` раз в 30 с, `ReconcilePayments` раз в 5 мин (платежи в `Unknown`/`Abandoned`: спросить шлюз, поздний успех у проваленного заказа порождает возврат).
- Платёж с неизвестным исходом (таймаут, обрезанный ответ) не считается ни успехом, ни отказом: выясняется запросом к шлюзу по идемпотентному ключу.

Подробно: `docs/spec/03_saga.md`.

## Go-воркер (M9)

Одна активность саги, `InspectGatewayCharge`, написана на Go (`worker-go/`, Temporal Go SDK) и обслуживается отдельным процессом на своей task queue (`payment-go`). Она только читает: делает `GET /charges/{idempotencyKey}` у шлюза и отвечает, есть ли списание, на какую сумму и в какой валюте. Идёт она после того, как `ChargePayment` исчерпал повторы с неизвестным исходом (таймаут, обрыв, 5xx: деньги могли списаться). Если шлюз подтверждает списание на ожидаемую сумму, сага повторяет `ChargePayment` с тем же идемпотентным ключом (шлюз второй раз не списывает), записывает успех и доводит заказ до `Completed`. Если шлюз доказывает отказ, заказ получает `Failed(PaymentDeclined)`. Любой другой исход (ошибка Go, таймаут, списания нет, чужая сумма) оставляет прежнее поведение саги. Существующие активности в Go не переносились: они пишут в БД PHP. Контракт PHP и Go: `contracts/inspect-gateway-charge/v1/` (JSON-схемы и golden-файлы, которые читают обе стороны). Спецификация: `docs/spec/09_go_worker.md`.

Как включить:

```bash
docker compose --profile go up -d --build worker-go   # профиль go: make up и make smoke Go-воркер не собирают и не запускают (make go-up / go-down)
TEMPORAL_GO_INSPECTION_ENABLED=1                        # переменная окружения PHP-воркера и приложения; по умолчанию 0, ветка выключена
make test-go-e2e                                        # поднимает Go-воркер на уникальной очереди, прогоняет группу PHPUnit go-e2e, останавливает и удаляет контейнер
```

Ветка стоит под `Workflow::getVersion('gateway-inspection')`: истории, начатые до неё, проигрываются без новых команд (replay-тесты по сохранённым историям `v0` и `v1`). Если Go-воркер не запущен при включённом флаге, активность ждёт 20 с (ScheduleToStart) и сага продолжает как до M9. Сквозные тесты `make test-go-e2e` доводят `ChargePayment` до исчерпания за 20 с вместо 300: переменная `TEMPORAL_E2E_CHARGE_SCHEDULE_TO_CLOSE` читается только конфигурацией окружения `test`, принимает лишь значение короче настроенного, поэтому инвариант TTL резерва (660 с < 900 с) остаётся верным.

## Ключевые решения

| Решение | Почему |
|---|---|
| Модульный монолит, не набор сервисов | Один проект и одна БД проще проверять; границы модулей держит phpat, а не сеть (`00_overview.md` §6). |
| DBAL без ORM, неизменяемые агрегаты | `final readonly` агрегаты с переходами, возвращающими новый экземпляр, плохо сочетаются с unit of work; нужны явные `FOR UPDATE SKIP LOCKED` и `UPDATE ... WHERE version = :expected`. |
| Свой circuit breaker, состояние в Redis | Счётчики и единственный probe в half-open общие для всех воркеров; отказ банка (`declined`) и 4xx сбоем не считаются. |
| Outbox + inbox, старт саги через outbox | Нет окна «заказ записан, workflow не запущен»; гарантия at-least-once, потребители идемпотентны через `inbox_messages`. |
| Replay-тест истории workflow | История happy path записана в `tests/Workflow/Fixtures/history-happy.json`; несовместимое изменение workflow ломает тест (`T-W-20`). Перезапись: `make wf-record-history`. |
| Правила PHPStan на слои и детерминизм | Запрет системного времени, случайных чисел и обращения к БД в коде workflow, запрет `float` для денег и атрибутов фреймворка в domain, проверяются `RuleTestCase`. |
| Деньги только в minor units (`int`) | Нет ошибок округления; несовпадение валют это ошибка. |
| Идемпотентность HTTP-команд в Postgres | Ключ и ответ живут в той же транзакции, что и данные; Redis для этого не годится по долговечности. |

## Тесты

Числа из последнего прогона `make ci` в контейнере.

| Набор | Что проверяет | Тестов |
|---|---|---|
| unit | домен, application, правила PHPStan, конфигурация, контракт Go-воркера | 1009 |
| integration | реальные Postgres, Redis, эмулятор шлюза, Temporal dev-сервер; без групп `slow` и `go-e2e` | 249 |
| workflow | `OrderFulfillmentWorkflow`, `RefundWorkflow` на Temporal test server, replay | 52 (3 пропущены: методы записи фикстур) |
| functional | HTTP и GraphQL через `KernelBrowser` | 160 |
| slow | `T-I-43`, `make test-slow`, в `make ci` не входит | отдельный запуск |
| Go unit | `make go-test`: домен, use case, HTTP-адаптер, breaker, контракт, активность, конфигурация, архитектурные проверки | 66 функций `Test*` |
| Go integration | `make go-test-int`: настоящий воркер против dev-сервера Temporal | 8 (T-GO-20…26, код выхода 2 при плохой конфигурации) |
| E2E smoke | `make smoke`, в `make ci` не входит | 4 сценария |
| E2E Go-воркер | `make test-go-e2e` (группа `go-e2e`, вне `make ci`): `T-M9-10`…`T-M9-13` | 3 теста, 4 идентификатора |

Дополнительно: `bin/check-edge-case-coverage` подтверждает, что у каждого из 122 крайних случаев (EC) есть найденный в коде тест; карта: `docs/spec/08_coverage.md`. Скрипт проверяет наличие идентификатора, а не качество теста. Качество разобрано выборочно, с ручной мутационной проверкой пяти критичных EC (`docs/reports/M8b.md`).

## Чего нет и что не проверено

- Нет эксплуатации и нагрузочных цифр. Проект запускался только локально; пропускная способность, поведение под нагрузкой, метрики и трассировка не измерялись и не настроены.
- Платёжный шлюз это эмулятор (`gateway-emulator`) с управляемыми сбоями. Реального провайдера, PCI DSS, 3-D Secure, частичного возврата, курсов валют нет.
- GitHub CI в этом проекте ни разу не запускался: файл `.github/workflows/ci.yml` есть, но на удалённом репозитории не проверен (матрица PHP 8.4/8.5, установка `grpc`, `rr`, test server, шаг с Temporal dev-сервером). PHP 8.4 локально не проверялся, только 8.5.
- История Temporal не сохраняется между перезапусками контейнера: dev-сервер запущен без файловой БД (контейнер не от root, том недоступен для записи).
- `RefundRetryTest` нестабильно падал в полном прогоне (таймаут ожидания workflow). Причина не доказана: сбой не воспроизвёлся, воспроизведена только искусственная перегрузка. Добавлена изоляция очереди Temporal для каждого процесса PHPUnit (`docs/reports/M8a-flaky.md`). Набор `workflow` изоляцию не использует (replay-фикстура хранит имя очереди).
- `ReleaseReservation` не запускается после упавшего `RefundPayment`: сага останавливается на первом сбое компенсации и переводит заказ в `ManualReview`, резерв освобождает TTL или `ExpireReservations`.
- Execution timeout обоих workflow равен 8 суткам (вместо 24 часов в спецификации): компенсация `RefundPayment` ограничена 7 сутками, иначе Temporal убил бы workflow раньше перехода в `ManualReview`.
- EC-81 и EC-85 частично ручные: для EC-81 ревью сигнатуры входа workflow, для EC-85 (потеря соединения воркера с БД) автоматический `T-I-43` плюс ручной `bin/worker-postgres-restart-check`.
- Если после сбоя БД не выполнится сама активность `MarkOrderManualReview` (60 с), workflow завершится ошибкой, а заказ останется в промежуточном статусе; закрывает это только sweeper, и только если его запускают.
- Автоматического разрешения `ManualReview` нет, команды оператора `resolveManually` тоже нет.
- Аутентификация покупателя заглушка: идентификатор берётся из заголовка `X-Customer-Id`, токенов и проверки владельца за пределами этого заголовка нет. Шифрования кодов ключей в БД нет.
- Нет команды или эндпоинта для наполнения каталога товарами и ключами (`smoke.sh` делает SQL-вставки); после создания товара его доступность в `order_view`-проекции появляется после первого события резервации.
- Symfony 8.1 не LTS: по данным `bin/console about` на момент M1.1 конец поддержки 01/2027.
- Go-воркер: replay-тест не сравнивает имя очереди Go-воркера (Q-M9-2: мутация, меняющая очередь активности `InspectGatewayCharge` на очередь саги, оставила replay зелёным). Опечатку в имени очереди поймают только `T-M9-05` (опции профиля) и сквозные тесты `make test-go-e2e`.
- Go-воркер: типы non-retryable ошибок Go (`InvalidInspectionInput`, `UnsupportedContractVersion`, `GatewayRequestRejected`) записываются в `RetryOptions` через `ReflectionProperty`. Это обход проверки `assert(is_a(..., Throwable))` в `RetryOptions::withNonRetryableExceptions()` Temporal PHP SDK: у этих типов нет PHP-класса. Прямая запись в свойство отклоняется PHPStan на уровне max.
- Флаг `TEMPORAL_GO_INSPECTION_ENABLED` по умолчанию выключен: без него Go-воркер простаивает, а сага работает как до M9.
- Go-ветка проверена на dev-сервере Temporal (`temporalio/temporal:1.10.1`, `server start-dev`), а не в конфигурации, близкой к боевой: без кластера, постоянного хранилища, нескольких экземпляров Go-воркера и реального шлюза. Сквозные тесты используют укороченный дедлайн `ChargePayment` (20 с вместо 300 с), только в окружении `test`.
- `make test-go-e2e` не входит в `make ci` (собирает образ и управляет контейнером с хоста); прогон зависит от времени (в сценариях ждут реальные таймауты), на нагруженной машине границы по времени могут не выдержаться.
- Не проверялись: запуск контейнера с `APP_ENV=prod` и introspection в нём вручную, свежий `docker compose up` на другой ОС (разрабатывалось на OrbStack), веб-интерфейс Temporal.
- Rate limiting и persisted queries в GraphQL не реализованы (есть лимиты глубины и сложности запроса).

## Как сделан

Спецификация и код писались поэтапно с помощью AI-ассистентов (Claude). Каждый этап автор проверял прогоном тестов. Отчёты по этапам лежат в `docs/reports/`.

## Документация

- Спецификация: `docs/spec/` ([обзор](docs/spec/00_overview.md), [домен](docs/spec/01_domain.md), [use cases](docs/spec/02_use_cases.md), [сага](docs/spec/03_saga.md), [крайние случаи](docs/spec/04_edge_cases.md), [тесты](docs/spec/05_testing.md), [этапы](docs/spec/06_milestones.md), [открытые вопросы](docs/spec/07_open_questions.md), [карта покрытия](docs/spec/08_coverage.md), [Go-воркер](docs/spec/09_go_worker.md)).
- Отчёты по этапам: `docs/reports/` (`M1.1` ... `M9.5`; про нестабильный тест: `M8a-flaky.md`).
- Эмулятор шлюза и его режимы: `gateway-emulator/README.md`. Контракт Go-воркера: `contracts/inspect-gateway-charge/v1/README.md`.

## Лицензия

MIT, см. [LICENSE](LICENSE).
