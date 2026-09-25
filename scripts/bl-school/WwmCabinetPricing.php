<?php
declare(strict_types=1);

/**
 * Notify WWM Cabinet of Tilda original amount + AVO RUB total (deploy to bl-school public/api/lib/).
 *
 * tilda-avo.config.php:
 *   'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
 *   'cabinet_payment_token' => '…same as WWM_WEBHOOK_PAYMENT_TOKEN…',
 */

/**
 * @param array<string, mixed> $config
 * @param array{
 *   email: string,
 *   id_account: int|string,
 *   id_goods: int,
 *   amount_original: float,
 *   currency_original: string,
 *   amount_rub: float,
 *   fx_rate: float
 * } $orderPricing
 * @param callable(string): void|null $logger
 */
function wwm_notify_cabinet_payment_pricing(array $config, array $orderPricing, ?callable $logger = null): void
{
    $url = trim((string)($config['cabinet_pricing_url'] ?? ''));
    $token = trim((string)($config['cabinet_payment_token'] ?? $config['cabinet_webhook_token'] ?? ''));
    if ($url === '' || $token === '') {
        return;
    }

    $email = strtolower(trim((string)($orderPricing['email'] ?? '')));
    $idAccount = trim((string)($orderPricing['id_account'] ?? ''));
    $idGoods = (int)($orderPricing['id_goods'] ?? 0);
    if ($email === '' || $idAccount === '' || $idGoods <= 0) {
        return;
    }

    $payload = [
        'email' => $email,
        'id_account' => $idAccount,
        'id_goods' => $idGoods,
        'amount_original' => (float)($orderPricing['amount_original'] ?? 0),
        'currency_original' => (string)($orderPricing['currency_original'] ?? ''),
        'amount_rub' => (float)($orderPricing['amount_rub'] ?? 0),
        'fx_rate' => (float)($orderPricing['fx_rate'] ?? 0),
        'token' => $token,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-WWM-Payment-Token: ' . $token,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($logger !== null) {
        $logger(sprintf(
            'cabinet pricing notify http=%d account=%s email=%s',
            $status,
            $idAccount,
            $email
        ));
    }
}
