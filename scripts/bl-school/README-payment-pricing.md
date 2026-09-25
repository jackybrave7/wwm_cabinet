# Tilda USD + AVO RUB → WWM Cabinet payments

Cabinet prod must have `webhooks.enabled` and `payment_token` (deploy `48cb733` or newer).

## bl-school: upload two files (full replacements)

| From (wwm-cabinet repo) | To (bl-school.com) |
|-------------------------|---------------------|
| `scripts/bl-school/WwmCabinetPricing.php` | `public/api/lib/WwmCabinetPricing.php` |
| `scripts/bl-school/tilda-avo-webhook.php` | `public/api/tilda-avo-webhook.php` |

Do **not** paste fragments — replace the whole `tilda-avo-webhook.php` on the server with the file from this repo (already includes the cabinet pricing notify).

## `tilda-avo.config.php` (you edit only config)

```php
'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
'cabinet_payment_token' => '…same as cabinet webhooks.payment_token…',
```

Keep your existing `webhook_token`, `product_map`, AVO keys, etc. — only add these two keys.
