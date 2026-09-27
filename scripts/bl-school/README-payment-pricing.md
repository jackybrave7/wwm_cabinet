# Tilda → AVO → WWM Cabinet (paid access + payments)

Cabinet prod must have `webhooks.enabled` and `payment_token` (deploy `48cb733` or newer).

## bl-school: upload two files (full replacements)

| From (wwm-cabinet repo) | To (bl-school.com) |
|-------------------------|---------------------|
| `scripts/bl-school/WwmCabinetPricing.php` | `public/api/lib/WwmCabinetPricing.php` |
| `scripts/bl-school/lib/TildaOrderEnrich.php` | `public/api/lib/TildaOrderEnrich.php` |
| `scripts/bl-school/tilda-avo-webhook.php` | `public/api/tilda-avo-webhook.php` |

After a successful AVO invoice the webhook calls **both**:

- `POST /api/payment` — paid access + robot@ email (no separate AVO product URL required)
- `POST /api/payment/pricing` — Tilda USD + RUB on the payment row

On Tilda **retries** (duplicate order keys) the cabinet is notified again (idempotent).

## `tilda-avo.config.php`

```php
'cabinet_payment_token' => '…same as cabinet webhooks.payment_token…',
'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
// optional; derived from pricing URL if omitted:
// 'cabinet_payment_url' => 'https://my.worldwatercolormasters.art/api/payment',
```

Keep `log_enabled` + `log_file` on — when a payment is missing, open the log for `unmapped product`, `forbidden`, `avo invoice error`, or no line at all (Tilda never called bl-school).

## Zero-total orders (gift / 100% promo)

The webhook treats **paid total = 0** as a normal paid flow (`accept_free_orders`, default **true**): AVO invoice with **sum 0**, line **price_full** from catalog price.

**Tilda (required):**

- Webhook on **all** checkout forms, **send after payment**
- ☑ Product data **as arrays** + **externalid** (SKU = AVO `id_goods` per course in catalog)
- For 100% coupons Tilda must still **fire the webhook** on “Оплачен”

**bl-school `tilda-avo.config.php` (fallback if Tilda omits product rows on $0):**

```php
'accept_free_orders' => true,
'default_goods_id' => 188, // last resort only — prefer SKU/externalid on product
// optional per landing:
// 'form_goods_map' => ['form12345' => 188],
// 'page_goods_map' => ['1234567' => 188],
// 'course_slug_goods_map' => ['elke-en' => 188],
```

Upload **`lib/TildaOrderEnrich.php`** together with `tilda-avo-webhook.php`.

## If a buyer already paid

1. Tilda → **Orders** → open the order → check webhook response (must be `ok`).
2. bl-school log / `processed_orders` for that email.
3. Cabinet-only recovery (SSH or local with prod DB):  
   `php scripts/regrant-cabinet-payment.php email@example.com 188 ID_ACCOUNT "Name"`
4. If AVO also empty — fix bl-school/Tilda first, then replay webhook from Tilda or create the AVO invoice manually.
