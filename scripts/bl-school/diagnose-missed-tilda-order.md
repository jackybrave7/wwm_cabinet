# Пропущенный заказ Tilda (настройки формы уже OK)

Письмо `noreply@tilda.ws` ≠ webhook. Ищем след на **bl-school**.

## 1. Лог webhook

В `tilda-avo.config.php`: `log_file`, `log_enabled => true`.

На сервере (SSH / файловый менеджер):

```bash
grep -E '1665044292|frankieleon|ch_3UL2mV' /path/to/tilda-avo.log
```

| Результат | Значение |
|-----------|----------|
| Пусто около времени оплаты | POST **не пришёл** на bl-school (сеть, DNS, другой URL в проекте, Тильда не вызвала) |
| `forbidden` | token в URL |
| `unmapped product` | SKU / product_map |
| `avo invoice error` / `502` | AVO API |
| `busy` | параллельные ретраи; смотрите строки до/после |
| `duplicate skipped` + `id_account=` пусто | битая идемпотентность (фикс в `ProcessedOrders.php`) |
| `ok account=NNNN` | счёт создан — ищите в AVO по email и № |

## 2. Файл идемпотентности

Путь: `processed_orders_file` в config (часто `data/tilda_processed_orders.json`).

Поиск: email, `1665044292`, `ch_3UL2mV`, `tilda:tran:`.

Запись `status: done` **без** `id_account` → Тильда могла получить `ok`, счёта нет. Обновите `ProcessedOrders.php`, удалите битую запись по ключу, переотправьте webhook.

## 3. PHP error log

Хостинг bl-school: `error_log` за время оплаты — fatal/timeout (AVO > 5–30 с).

## 4. Тест без AVO (dry run)

С сервера или ПК (подставьте token и поля как в реальном POST):

```bash
curl -sS -X POST "https://bl-school.com/api/tilda-avo-webhook.php?token=SECRET&dry_run=1" \
  -d "Email=frankieleon1954@gmail.com" \
  -d "Name=Frankie Leon" \
  -d "payment[amount]=69.3" \
  -d "payment[currency]=USD" \
  -d "payment[orderid]=1665044292" \
  -d "payment[sys]=Stripe" \
  -d "payment[tranid]=ch_3UL2mVJJ5HWMfeeI1dfKmUik"
```

Ответ JSON с `payload.lines` и `id_goods` — маппинг OK. Без `dry_run` создаст счёт (не дублируйте, если счёт уже есть).

## 5. Кабинет вручную

```bash
php scripts/regrant-cabinet-payment.php EMAIL 188 "Name"
```
