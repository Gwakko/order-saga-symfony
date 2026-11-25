# 00. Обзор: order-saga-symfony

Статус: M0 (спецификация). Код не написан. Дата: 2026-11-24.
Проект учебный: «пет-проект, проверен локально и в CI, не production». Нагрузочных цифр и эксплуатации нет и не заявляется.

## 1. Цель

Показать на работающем коде темы: Symfony, CQRS, saga, Temporal, GraphQL, circuit breaking, правила PHPStan, Go-воркер.
Стек: PHP 8.4+ / Symfony, CQRS, saga-оркестрация через Temporal, circuit breaking, GraphQL, собственные правила PHPStan, Postgres, Redis, затем (M9) Go-воркер для одной активности.

Критерий полезности: по каждому решению из этой спецификации можно открыть тест, который его проверяет (файл `04_edge_cases.md`).

## 2. Границы

### Входит
- Заказ одного цифрового ключа одним покупателем (quantity всегда 1).
- Сага: создать заказ → зарезервировать ключ → оплатить → списать резерв (ключ продан) → выдать ключ; компенсации: вернуть оплату, освободить ключ.
- Отмена заказа покупателем до выдачи ключа; возврат после выдачи (отдельный workflow).
- Идемпотентность HTTP-команд (`Idempotency-Key`), идемпотентность активностей, outbox + inbox.
- Эмулятор платёжного шлюза с управляемыми сбоями (таймаут, 5xx, обрезанный ответ, успех после таймаута, отказ).
- Circuit breaker на вызовы шлюза, состояние в Redis.
- Read model (CQRS read side) и GraphQL поверх запросов.
- Правила PHPStan: границы слоёв и модулей, детерминизм workflow-кода, запрет float для денег, запрет атрибутов фреймворка в domain/application.

### НЕ входит (и не заявляется)
- Аутентификация: покупатель передаётся заголовком `X-Customer-Id` (UUID). Это заглушка, в README так и написано.
- Реальный платёжный провайдер, PCI DSS, 3-D Secure, частичный возврат, авторизация/capture в два шага.
- Курсы валют и конвертация (FX). Цена товара задана в одной валюте; несовпадение валют = ошибка.
- Шифрование кодов ключей at rest (упомянуть как «чего нет» в M10), реальный поставщик ключей.
- Kubernetes/Helm, метрики Prometheus, трассировка. Только Docker Compose и CI.
- Корзина, несколько позиций, скидки, налоги.

## 3. Домен (кратко, подробно в `01_domain.md`)

Покупатель видит товар с ценой и отправляет заказ, указывая ожидаемую цену (`expectedPrice`). Сервер сверяет её с каталогом, создаёт `Order` в статусе `Pending` и через outbox запускает сагу `OrderFulfillmentWorkflow`.

Сага:
1. `ReserveKey`: берёт свободный `ProductKey`, создаёт `KeyReservation` с TTL.
2. `ChargePayment`: списывает деньги через шлюз (идемпотентный ключ = `PaymentId`).
3. `ConsumeReservation`: ключ переходит в `Sold`.
4. `CompleteOrder`: ключ прикрепляется к заказу, статус `Completed`, событие `OrderCompleted`.

При сбое на шаге N выполняются зарегистрированные компенсации шагов < N в обратном порядке (`RefundPayment`, `ReleaseReservation`), заказ уходит в `Failed` или `Cancelled`.

## 4. Глоссарий

| Термин | Значение |
|---|---|
| Order | Агрегат заказа (модуль Ordering). |
| ProductKey | Один цифровой ключ товара в инвентаре (модуль Inventory). |
| KeyReservation | Временное удержание конкретного ключа за заказом, имеет `expiresAt`. |
| Payment | Агрегат оплаты заказа (модуль Payment). Один на заказ. |
| Saga / workflow | `OrderFulfillmentWorkflow` в Temporal: оркестратор шагов и компенсаций. |
| Activity | Шаг саги, выполняется воркером; в проекте это тонкий адаптер над application-командой. |
| Compensation | Действие, отменяющее эффект успешного шага (refund, release). |
| Outbox | Таблица `outbox_messages`: события пишутся в той же транзакции, что и состояние агрегата. |
| Relay | Процесс, который читает outbox и публикует сообщения в транспорт Messenger. |
| Inbox | Таблица `inbox_messages`: id обработанных сообщений у потребителя (дедупликация). |
| Idempotency-Key | Заголовок HTTP-команды; повтор с тем же ключом возвращает сохранённый ответ. |
| Circuit breaker | Предохранитель на вызов шлюза: `Closed` → `Open` → `HalfOpen`. |
| Minor units | Целое число минимальных единиц валюты (центы). Деньги только так. |
| Clock | Порт текущего времени (`Psr\Clock\ClockInterface`), в тестах `MockClock`. |
| Read model | Денормализованная таблица `order_view` для запросов и GraphQL. |

## 5. Архитектура

### 5.1 Слои

```
          ┌───────────────────────── Infrastructure ─────────────────────────┐
          │  Http (controllers, Request DTO, MapRequestPayload, Serializer)  │
          │  GraphQL (webonyx schema, resolvers → QueryBus)                  │
          │  Temporal (workflow, activities, worker bootstrap, client)       │
          │  Persistence (DBAL repositories, migrations, outbox, inbox)      │
          │  PaymentGateway (HTTP adapter, CircuitBreaker, Redis store)      │
          │          ┌──────────────── Application ────────────────┐         │
          │          │ Commands/Queries, Handlers, Output DTO,     │         │
          │          │ Ports (interfaces), application errors      │         │
          │          │        ┌────────── Domain ──────────┐       │         │
          │          │        │ Aggregates, VO, enums,     │       │         │
          │          │        │ domain events, invariants  │       │         │
          │          │        └────────────────────────────┘       │         │
          │          └─────────────────────────────────────────────┘         │
          └──────────────────────────────────────────────────────────────────┘
```

Правило зависимостей: только внутрь. Domain зависит только от PHP (и `Shared\Domain`). Application зависит от Domain и PSR-интерфейсов (`psr/clock`, `psr/log`). Infrastructure зависит от всего. Проверяется phpat (см. `05_testing.md`, раздел 4).

Workflow-код Temporal (`Infrastructure\Temporal\Workflow`) — отдельный «подслой» с правилом детерминизма: не обращается к БД, системному времени, случайным числам; только к интерфейсам активностей и API `Temporal\Workflow`.

### 5.2 Модули (bounded contexts)

| Модуль | Агрегаты | Владеет таблицами |
|---|---|---|
| `Ordering` | `Order` | `orders`, `order_view` |
| `Inventory` | `Product`, `ProductKey`, `KeyReservation` | `products`, `product_keys`, `key_reservations` |
| `Payment` | `Payment` | `payments` |
| `Shared` | `Money`, `Currency`, базовые классы событий/ошибок | `outbox_messages`, `inbox_messages`, `idempotency_keys` |

Модуль не импортирует Domain/Application другого модуля (кроме `Shared`). Связь между модулями только через сагу (активности) и события. Проверяется phpat.

### 5.3 Структура каталогов

```
order-saga-symfony/
├── bin/console, bin/temporal-worker.php
├── config/                         # Symfony config, services.yaml, packages/*
├── docker/                         # Dockerfile(s), nginx, .rr.yaml
├── gateway-emulator/               # отдельный маленький HTTP-сервис (PHP, без Symfony)
├── migrations/
├── phpstan/                        # собственные правила PHPStan + phpat-тесты архитектуры
│   ├── Rules/
│   └── Architecture/
├── src/
│   ├── Shared/
│   │   ├── Domain/                 # Money, Currency, DomainEvent, DomainError, AggregateVersion
│   │   ├── Application/            # CommandBus/QueryBus порты, TransactionManager порт
│   │   └── Infrastructure/         # Messenger buses, outbox relay, inbox, idempotency, clock wiring
│   ├── Ordering/
│   │   ├── Domain/                 # Order, OrderId, OrderStatus, OrderEvent\*
│   │   ├── Application/
│   │   │   ├── Command/PlaceOrder/ # PlaceOrderCommand, PlaceOrderHandler, PlaceOrderOutput
│   │   │   ├── Query/GetOrder/     # GetOrderQuery, GetOrderHandler, OrderDetailsOutput
│   │   │   └── Port/               # OrderRepository, OrderReadModel, OrderSagaGateway ...
│   │   └── Infrastructure/
│   │       ├── Http/               # OrderController, Request\PlaceOrderRequest (Assert)
│   │       ├── GraphQL/
│   │       └── Persistence/
│   ├── Inventory/ (Domain, Application, Infrastructure)
│   ├── Payment/   (Domain, Application, Infrastructure/Gateway, CircuitBreaker)
│   ├── Saga/
│   │   └── Infrastructure/Temporal/ # OrderFulfillmentWorkflow, RefundWorkflow, Activity\*
│   └── Kernel.php
├── tests/
│   ├── Unit/{Ordering,Inventory,Payment,Shared}/Domain|Application
│   ├── Integration/                # реальные Postgres/Redis/эмулятор шлюза
│   ├── Workflow/                   # Temporal test server
│   ├── Functional/                 # HTTP/GraphQL через KernelBrowser
│   └── Architecture/ → phpstan/Architecture (phpat)
├── docs/spec/, docs/reports/
├── compose.yaml, phpstan.neon, phpunit.dist.xml, Makefile
```

## 6. Решение: границы сервисов

**Вариант A. Модульный монолит + отдельные процессы (рекомендуется).** Один репозиторий и один Symfony-кернел, модули с жёсткими границами (phpat), одна БД Postgres с таблицами по модулям (без межмодульных FK). Процессы: `http` (nginx + php-fpm), `temporal-worker` (RoadRunner), `outbox-relay` (console), `messenger-consumer` (console), `gateway-emulator`, `temporal`, `postgres`, `redis`.

**Вариант B. Три сервиса (Ordering, Inventory, Payment) с отдельными БД.** Ближе к реальной микросервисной архитектуре, но втрое больше каркаса (три composer-проекта, три CI-джоба, межсервисный транспорт, контрактные тесты), а в учебном объёме это инфраструктура вместо предметной логики.

**Почему A:**
- Сага через Temporal уже делает модули независимыми на уровне вызовов: каждая активность обращается только к одному модулю, сага не делает межмодульных транзакций. Вынос модуля в сервис = перенос его активностей на отдельную task queue и отдельный воркер, workflow не меняется. Это и есть аргумент в пользу такой структуры: «границы проведены так, что разрезать можно, вот правило phpat, которое это гарантирует».
- M9 (Go-воркер) показывает именно этот разрез на новой активности: `InspectGatewayCharge` (только чтение, запрос к шлюзу по идемпотентному ключу после исчерпанного `ChargePayment`) обслуживается Go-процессом на task queue `payment-go`. `ChargePayment` остаётся в PHP: он пишет в БД PHP (`docs/spec/09_go_worker.md` §2).
- Нет межмодульных JOIN и FK: проверяется тестом схемы (`05_testing.md`, T-ARCH-04).

## 7. Технологические решения

Версии ниже взяты с Packagist (`https://repo.packagist.org/p2/<vendor>/<package>.json`) 2026-11-24. Перед установкой в M1 перепроверить командой `composer show -a <package>` и зафиксировать в `composer.lock`.

| Пакет | Последняя стабильная на 2026-11-24 | Требование PHP | Примечание |
|---|---|---|---|
| `symfony/framework-bundle` | 8.1.11; ветка 7.4 = 7.4.23 | 8.x: `>=8.4.1` | см. 7.1 |
| `temporal/sdk` | v2.20 (2026-10-23) | `>=8.2` | зависит от `roadrunner/roadrunner ^3`, `grpc/grpc`, Symfony-компонентов `^6.4 \|\| ^7 \|\| ^8` |
| `spiral/roadrunner` (бинарь) | v2025.1.15 | — | бинарь ставится через `vendor/bin/rr get-binary` |
| `webonyx/graphql-php` | v15.39.0 | `^7.4 \|\| ^8` | см. 7.2 |
| `api-platform/core` | v5.0.9 | `>=8.2` | GraphQL через webonyx (suggest) |
| `overblog/graphql-bundle` | v1.10.0 | `>=8.1` | поверх webonyx `^15.24` |
| `doctrine/dbal`, `doctrine/migrations` | проверить при M1 | — | `doctrine/orm` 3.8.0 не используется (см. 7.4) |
| `phpstan/phpstan` | 2.3.7 | — | |
| `phpat/phpat` | 0.12.5 | `^8.1` | |
| `deptrac/deptrac` | 4.7.2 | `^8.2` | альтернатива phpat |
| `phpunit/phpunit` | 13.4.8 | `>=8.4.1` | |
| `ackintosh/ganesha` | 4.0.0 (2025-02-22) | `^8.1` | альтернатива своему breaker |
| `symfony/clock`, `symfony/uid` | 8.1.9 | — | `MockClock`, UUID v7 |

Локально: PHP 8.5.4 CLI, расширений `grpc`, `protobuf`, `redis` нет (проверено `php -m`). В Docker-образе ставятся отдельно.

### 7.1 Версия Symfony и PHP

- Вариант A: **Symfony 7.4 LTS** (поддержка дольше, все нужные бандлы её поддерживают).
- Вариант B: **Symfony 8.1** (свежая версия).
- **Рекомендация: 8.1**, если в M1 `composer require` всех зависимостей из таблицы проходит без конфликтов; иначе 7.4. Обоснование: Temporal SDK, overblog, API Platform уже объявляют `^8.0` для Symfony-компонентов; проект долго жить не обязан. Сроки поддержки веток проверить на `https://symfony.com/releases` при M1 (здесь не утверждаются).
- PHP: `composer.json` → `"php": ">=8.4"`; Docker-образ PHP 8.5 (вакансия называет 8.5+); CI-матрица 8.4 и 8.5. Риск: сборка `ext-grpc` под 8.5 (`07_open_questions.md`, Q1).

### 7.2 GraphQL-библиотека

| Критерий | webonyx/graphql-php напрямую | API Platform 5 | overblog/graphql-bundle |
|---|---|---|---|
| Совместимость с чистой архитектурой | схема и резолверы в infrastructure, резолвер = вызов QueryBus | ресурсы строятся вокруг классов-ресурсов, тянет к экспонированию сущностей и к собственному state provider; можно, но много «против течения» | конфиг типов YAML/атрибуты, кодогенерация |
| Объём магии | минимальный, всё явно | высокий | средний |
| Что покажет | понимание GraphQL (схема, резолверы, DataLoader, лимиты сложности) | знание API Platform | знание бандла |
| Только read side | легко | REST+GraphQL разом, лишнее | легко |

**Решение: `webonyx/graphql-php`**, схема в SDL-файле (`schema.graphql`) + `BuildSchema` и резолверы-классы, один контроллер `POST /graphql`. Лимиты `QueryDepth` и `QueryComplexity` из `GraphQL\Validator\Rules` (наличие правил проверить в документации webonyx при M7). GraphQL только для запросов; мутации идут через REST, чтобы идемпотентность была в одном месте. Альтернатива (мутация `placeOrder`) вынесена в `07_open_questions.md`, U2.

### 7.3 Temporal из PHP

Факты (источники: Packagist `temporal/sdk`, `https://docs.temporal.io/develop/php/set-up-your-local-php`, `https://github.com/temporalio/samples-php`):
- PHP SDK v2.20; воркер работает внутри RoadRunner: RR держит gRPC-соединение с Temporal и общается с PHP-процессами по goridge. Без RoadRunner воркер PHP SDK не запускается.
- Клиент (`WorkflowClient`: старт workflow, сигналы) из обычного PHP-процесса ходит в Temporal по gRPC и требует `ext-grpc`. Точную формулировку требований перепроверить в README `temporalio/sdk-php` при M6.1.
- Саги в PHP SDK: в samples-php есть пример с классом `Temporal\Workflow\Saga` (`addCompensation`, `compensate`, `setParallelCompensation`). **Проверить при M6.1**, что класс есть в v2.20: `grep -r "class Saga" vendor/temporal/sdk/src`.
- Тестирование: в SDK есть пакет `Temporal\Testing` (тестовый сервер с пропуском времени, `ActivityMocker`). **Проверить при M6.1** по `vendor/temporal/sdk/testing` и документации.

Варианты:
- **A. PHP SDK + RoadRunner (рекомендуется).** Это типовой стек для PHP-воркера Temporal. Воркер = контейнер с `rr serve -c .rr.yaml`, PHP-входная точка `bin/temporal-worker.php` поднимает Symfony Kernel и берёт реализации активностей из контейнера. Без стороннего бандла (`vanta/temporal-bundle` 0.5, `highcore/temporal-bundle` 1.2.4 существуют, но молодые; ручной bootstrap на ~50 строк понятнее и проще объяснить).
- **B. Свой оркестратор саги на Postgres + Messenger** (таблица состояния саги, шаги как сообщения). Резервный вариант, если ext-grpc/RR не заведутся. Порт `OrderSagaStarter` в application позволяет заменить реализацию, не трогая домен и use cases.
- Временный сервер Temporal в Compose: образ и команду (`temporal server start-dev` из CLI или `temporalio/auto-setup`) **проверить при M1** по `https://docs.temporal.io/self-hosted-guide` и Docker Hub; не утверждается здесь.

### 7.4 Хранение

- **Postgres**: все агрегаты, outbox, inbox, idempotency, read model. Доступ через **Doctrine DBAL** и ручные SQL-репозитории; ORM не используется.
  - Почему не ORM: агрегаты — `final readonly class` с переходами, которые возвращают новый экземпляр (см. `01_domain.md`), это плохо сочетается с unit of work и identity map; нужны явные `FOR UPDATE SKIP LOCKED` и `UPDATE ... WHERE version = :expected`; домен без атрибутов маппинга.
  - Альтернатива: ORM 3 с XML-маппингом и мутабельными сущностями. Отклонено: противоречит правилу readonly и скрывает SQL, который как раз нужно показать.
  - Миграции: `doctrine/migrations`, все timestamp-колонки `timestamptz`, сессия БД в UTC.
- **Redis**: (1) состояние circuit breaker, общее для всех воркеров (счётчики сбоев, открытие, единственный probe в half-open через `SET NX PX`); (2) транспорт Symfony Messenger для событий из outbox (Redis Streams, `symfony/redis-messenger`). Идемпотентность и блокировки в Redis **не** хранятся: им нужна долговечность и транзакционность с данными, это Postgres.

### 7.5 Outbox и inbox

- Репозиторий агрегата в одной транзакции пишет состояние и `pendingEvents()` в `outbox_messages(id uuid, aggregate_type, aggregate_id, aggregate_version, event_type, payload jsonb, occurred_at, published_at null, attempts int)`.
- `outbox-relay` (console-команда, цикл): `SELECT ... WHERE published_at IS NULL ORDER BY occurred_at LIMIT 100 FOR UPDATE SKIP LOCKED` → отправка в Messenger → `published_at = now`. Гарантия: at-least-once.
- Потребители идемпотентны: `inbox_messages(consumer, message_id)` с PK; обработка и вставка в inbox в одной транзакции.
- Запуск саги тоже через outbox: событие `OrderPlaced` → потребитель `StartOrderSagaOnOrderPlaced` → `WorkflowClient::start` с `WorkflowId = "order-{orderId}"`. Так нет окна «заказ записан, а workflow не запущен».

### 7.6 Идемпотентность (сводка; детали в `02_use_cases.md` §5 и `03_saga.md` §6)

| Уровень | Механизм |
|---|---|
| HTTP-команда | заголовок `Idempotency-Key`, таблица `idempotency_keys` (PK `customer_id, key`), хеш тела запроса, сохранённый ответ, TTL 24 ч |
| Старт саги | `WorkflowId = order-{orderId}`, политика reuse = reject duplicate; «уже запущен» = успех |
| Активности | каждая идемпотентна по `orderId` (unique-индексы + CAS-обновления) |
| Шлюз | `Idempotency-Key: {paymentId}` для charge, `refund-{paymentId}` для refund; эмулятор дедуплицирует |
| События | inbox по `message_id` |

### 7.7 Circuit breaker

- Вариант A: **собственная реализация** `CircuitBreaker` (порт состояния `CircuitStateStore`, реализации `RedisCircuitStateStore` и `InMemoryCircuitStateStore`, время через Clock). ~150 строк, полностью тестируется с `MockClock`, легко объяснима.
- Вариант B: `ackintosh/ganesha` 4.0.0 (Redis-адаптер, rate/count стратегии). Последний релиз 2025-02, это не довод против, но и прозрачности меньше.
- **Рекомендация: A**, с упоминанием Ganesha в README как production-альтернативы.
- Параметры по умолчанию (конфиг, не измерения): открыть после 5 сбоев подряд или 50% сбоев при не менее 10 вызовах за 30 с окно; `Open` 30 с; в `HalfOpen` один пробный вызов.
- Сбой для breaker'а: таймаут, сетевой отказ, 5xx. Отказ банка (`declined`) и 4xx сбоем **не** считаются.

## 8. Пользовательские правила кода (обязательные требования к реализации)

1. PHP 8.4+, `declare(strict_types=1);` в каждом файле (проверка: PHP-CS-Fixer правило `declare_strict_types` + тест T-ARCH-06).
2. Типы на всех параметрах, свойствах, возвращаемых значениях, включая примитивы; `mixed` только с комментарием-обоснованием.
3. `final readonly class` для VO, DTO, команд, запросов, событий, агрегатов.
4. `enum` вместо строковых/целых констант-статусов; `match` вместо `switch` и цепочек `if/elseif` (PHPStan-правило `NoSwitchRule`, T-ARCH-07).
5. PHPStan `level: max`, без baseline и без `ignoreErrors` (критерий приёмки).
6. Входящие HTTP-данные: Request DTO в `Infrastructure\Http\Request` с `#[MapRequestPayload]`/`#[MapQueryString]` и `Assert`-ограничениями; ответы через Symfony Serializer.
7. Domain и Application без атрибутов валидации, сериализации, ORM, Messenger (`DomainNoFrameworkAttributesRule`, T-ARCH-03).
8. Use case = `XxxCommand`/`XxxQuery` (это и есть Input DTO application-слоя) + `XxxHandler` + `XxxOutput`.
9. Деньги: `int` в минимальных единицах + `Currency` enum. `float` в типах Domain/Application запрещён (`NoFloatInDomainRule`, T-ARCH-05).
10. Время: Domain получает `DateTimeImmutable $now` аргументом; Application берёт его из `ClockInterface`; Workflow из `Workflow::now()`. `new DateTimeImmutable()` без аргумента, `time()`, `date()` запрещены вне `Infrastructure\Clock` (T-ARCH-08).
