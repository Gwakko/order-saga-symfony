# gateway-emulator

Эмулятор платёжного шлюза. Порт 8081. Деньги: целое число в минимальных единицах (`amount`), валюта `currency` (ISO-4217, заглавные). Тела JSON.

## Платёжный API

### POST /charges
Заголовок `Idempotency-Key` обязателен (иначе 400). Тело: `{"amount": 1999, "currency": "EUR"}`.
Ответ 200: `{"chargeId","idempotencyKey","status","amount","currency"}`, `status` из `succeeded | pending | weird`.
Отказ банка: 402 со `status: "declined"`. Невалидное тело (в т. ч. float в `amount`): 422.
Повтор с тем же ключом возвращает тот же результат и не списывает повторно; тот же ключ с другим телом: 409.

### GET /charges/{idempotencyKey}
200 с тем же объектом, что и при списании; 404 `charge_not_found`. На режимы сбоев не реагирует (кроме отложенного успеха: `pending` превращается в `succeeded`).

### POST /refunds
Заголовок `Idempotency-Key` обязателен. Тело: `{"chargeId","amount","currency"}`.
Ответ 200: `{"refundId","chargeId","idempotencyKey","status":"refunded","amount","currency"}`.
Повтор с тем же ключом возвращает тот же `refundId`, возврат выполняется один раз. 404: неизвестный `chargeId`; 422: списание не `succeeded` или сумма больше списанной; 409: тот же ключ с другим телом.

## Админ API

### POST /__admin/mode
Тело: `{"mode": "<режим>"}`. Действует на все последующие `POST /charges` и `POST /refunds`. Неизвестный режим: 422.

| Режим | Поведение |
|---|---|
| `ok` | успех (по умолчанию) |
| `decline` | списание отклонено, 402 `declined` (на возвраты не влияет) |
| `delay:<ms>` | операция выполняется, ответ приходит через `<ms>` |
| `http500` | 500, ничего не выполняется |
| `http503:<n>` | первые `<n>` запросов получают 503 без выполнения, затем режим возвращается в `ok` |
| `accept-then-502` | операция выполняется, ответ 502 |
| `truncated-json` | операция выполняется, 200 с обрезанным JSON |
| `no-charge-id` | операция выполняется, 200 без `chargeId` (для возврата без `refundId`) |
| `wrong-currency` | списание фиксируется в другой валюте (`USD`, для USD-платежа `EUR`) |
| `wrong-amount` | списание фиксируется на сумму `amount + 1` |
| `drop-connection` | операция выполняется, заголовки и начало тела отправлены, `Content-Length` больше фактического тела, соединение закрывается |
| `unknown-status` | `status: "weird"` |
| `late-success:<ms>` | `POST /charges` отвечает `status: "pending"`, `GET` покажет `succeeded` через `<ms>` |

Режимы ответа (`delay`, `accept-then-502`, `truncated-json`, `no-charge-id`, `drop-connection`) действуют и на повторы существующего ключа. Режимы данных (`decline`, `wrong-*`, `unknown-status`, `late-success`) действуют только при создании записи; `unknown-status` влияет и на возвраты.

### GET /__admin/stats
```json
{"mode":"ok","charges":{"<key>":{"requests":2,"executed":1}},"refunds":{"<key>":{"requests":1,"executed":1}},"totals":{"charges":1,"refunds":1}}
```
`requests` число запросов с ключом, `executed` сколько раз реально выполнено (0 для `decline`, иначе 1).

### POST /__admin/reset
Очищает все записи и возвращает режим `ok`. Вне основной спеки, нужен тестам.

### GET /health
`{"status":"ok"}`.
