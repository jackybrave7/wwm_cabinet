# Tilda USD + AVO RUB → WWM Cabinet payments

After deploying cabinet (`/api/payment/pricing`), wire bl-school:

1. Copy `WwmCabinetPricing.php` → `public/api/lib/WwmCabinetPricing.php` on bl-school.com
2. In `tilda-avo.config.php` add:

```php
'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
'cabinet_payment_token' => '…same value as WWM_WEBHOOK_PAYMENT_TOKEN on cabinet…',
```

3. In `tilda-avo-webhook.php`, after marking the AVO account paid (before final `echo 'ok'`), call `wwm_notify_cabinet_payment_pricing()` — see reference patch in this repo’s `.tmp-bl-school` copy or the block in `WwmCabinetPricing.php` header.

Cabinet must have `webhooks.enabled` and `payment_token` set on production.
