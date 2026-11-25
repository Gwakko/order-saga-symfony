# M8c (M8.2): E2E smoke и README

## Сделано

- `tests/E2E/smoke.sh` (bash, `set -euo pipefail`): `docker compose up -d`, перезапуск `temporal-worker` (compose-воркер держит старый код), миграции, `--profile workers up -d`, ожидание готовности (nginx `/health`, эмулятор, Temporal `cluster health`), затем четыре сценария, у каждого свой товар и ключ (SQL-вставки: команды наполнения каталога нет):
  1. happy path: `POST /api/orders` (201), `Completed`; кода ключа нет в ответе POST, в списке заказов и в логах контейнеров за время сценария; `GET` у `Completed` возвращает ключ (так в спеке); повтор с тем же `Idempotency-Key` даёт `Idempotent-Replayed: true`, тот же `orderId`, в БД один заказ;
  2. `decline` в эмуляторе: `Failed`, ключ снова `available`;
  3. возврат заказа из п.1: 202, `Refunded`;
  4. отмена во время списания (`delay:5000`): `Cancelled`, ключ `available`.
- HTTP без `curl`: `docker compose exec -T php php -r` (клиент на `file_get_contents` внутри сети compose).
- Правило воркеров: те, что скрипт запустил, останавливаются в конце (`trap EXIT`), уже работавшие не трогаются, `KEEP_WORKERS=1` оставляет; `temporal-worker` не в профиле и остаётся. Режим эмулятора сбрасывается в `ok` в `trap`.
- `Makefile`: цель `smoke` (в `ci` не входит).
- `README.md`: описание, сценарий, схема слоёв/модулей/CQRS/outbox, стек, запуск, сага, решения, таблица тестов, раздел «Чего нет и что не проверено», ссылки.

## Фактический вывод smoke (последний прогон, `make smoke`)

```
ok   stack is up
1. POST /api/orders (201); no key code in POST / list / logs; Completed; key returned by GET; replay Idempotent-Replayed=true, same orderId, 1 order
2. decline: Failed; key available
3. refund: 202; Refunded
4. cancel: 202 (status key_reserved, cancellationRequested true); Cancelled; key available
smoke: 16 passed, 0 failed
smoke: OK
workers stopped: outbox-relay messenger-consumer scheduler
```

Код возврата 0. Полный лог: `/private/tmp/claude-501/smoke-final.log`. Проверка ненулевого кода при сбое: первые два прогона (до исправлений) завершились кодом 1 (в одном случае `ABORT`, в другом 4 FAIL).

## Проверки

- `make ci` в контейнере: зелёный. unit 941 (2185 assertions), integration 242 (2590), workflow 28 (85 assertions, 1 skipped), functional 160 (1048). `bin/check-edge-case-coverage`: 103 из 103. PHPStan и cs зелёные (PHP не менялся).
- Все сервисы compose `running` (php, nginx, postgres, redis, temporal, temporal-worker, gateway-emulator); профильные воркеры остановлены smoke по правилу выше.
- `grep -iE "rps|requests per second|production-ready|в продакшене|highload|enterprise" README.md`: пусто. Слово `production` встречается только в `не production` (строка 3, формулировка из спеки).

## Не сделано

- Ссылки на `symfony-notification-api` и `nestjs-payments-api` не добавлены: README обоих открыты, пересечения нет (первый на RabbitMQ, Doctrine ORM и Messenger без саги и outbox; второй на Stripe/JWT/NestJS).
- GitHub-прогон не делался (как и в прежних этапах).

## Отклонения

- Спека 06 M8.2 и 05 §5 п.6 требуют проверять `keyCode` в `GET`; поэтому «нет полного кода ключа» в smoke проверено для ответа создания, списка и логов, а у `Completed` GET ключ наличие проверяется (так задано спекой, INV-17).
- Сценарий `decline` из 05 §5 п.6 выполнен; добавлены возврат и отмена.
- Вне списка «На выходе» изменён только `Makefile` (цель `smoke`). `src/`, `tests/` (кроме `tests/E2E/`), `compose.yaml`, CI не менялись.
- Первые прогоны smoke упали по моим ошибкам в скрипте (нижний регистр в коде ключа против `[A-Z0-9-]`, ожидание 202 вместо 201 у POST, PHP 8.5 deprecated `$http_response_header` в stdout, ранний выход при ожидании `Refunded` на статусе `completed`); исправлено, продуктовый код не затрагивался.

## Что проверить вручную

- `make smoke` на чистой машине (первый `docker compose up`, сборка образов).
- Сценарий отмены зависит от тайминга: отмена ловится во время `delay:5000` эмулятора; при очень быстрой машине гонка возможна в обе стороны (статус может быть `Cancelled` или заказ успеет дойти до другой точки отмены); во всех прогонах после исправлений скрипта (2 просмотренных) получен `Cancelled`.
- Запуск smoke одновременно с `make ci`: делят БД и Redis Streams, одновременно не запускать.

## Откуда взят каждый факт раздела «Чего нет»

| Пункт README | Источник |
|---|---|
| нет эксплуатации и нагрузочных цифр | `docs/spec/00_overview.md` §2 и шапка |
| платёжный шлюз это эмулятор; нет PCI, 3-D Secure, частичного возврата, FX | `00_overview.md` §2 «НЕ входит»; `gateway-emulator/README.md` |
| GitHub CI не запускался; PHP 8.4 не проверялся | `M1.4.md` («Не сделано», «Что проверить вручную»), `M6.2.md`, `M6.3.md`, `M6.4.md` |
| история Temporal не сохраняется | `M1.1.md` («Отклонения», без `--db-filename`) |
| `RefundRetryTest` нестабилен, причина не доказана, изоляция очереди | `M8a-flaky.md` (раздел «Correction», «What was tried», «Change»); `M8a.md`; набор workflow без изоляции: `M8a-flaky.md` |
| `ReleaseReservation` не запускается после упавшего refund | `M6.3.md` («Отклонения» п.2, T-W-15) |
| execution timeout 8 суток | `M6.3.md` («Отклонения» п.1) |
| EC-81, EC-85 частично ручные | `M8b.md` (строки про EC-85 и EC-81), `docs/spec/08_coverage.md` (пометки) |
| сбой `MarkOrderManualReview` оставляет промежуточный статус | `M6.3.md`, `M6.4.md` («Не сделано») |
| нет `resolveManually`, автоматического разрешения | `M6.3.md`, `M6.4.md` |
| аутентификация заглушка; шифрования ключей нет | `00_overview.md` §2 |
| нет команды наполнения каталога; проекция пуста до первого события | `M4.6.md` («Не сделано») и факт: `bin/console list` без такой команды; smoke делает SQL |
| Symfony 8.1 не LTS, EOL 01/2027 | `M1.1.md` («Отклонения», Q4, из `about`) |
| Go-воркер не сделан | `docs/spec/06_milestones.md` M9 (необязательно); в `src/` и корне нет `go-worker/` |
| не проверялись prod-запуск и introspection, другая ОС, Web UI Temporal | `M7.2.md`, `M1.1.md` («Что проверить вручную») |
| нет rate limiting и persisted queries | `M7.2.md` («Не сделано») |
