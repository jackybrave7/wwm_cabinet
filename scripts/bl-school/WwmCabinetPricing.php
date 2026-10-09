<?php
declare(strict_types=1);

/**
 * Notify WWM Cabinet after Tilda → AVO payment (deploy to bl-school public/api/lib/).
 *
 * tilda-avo.config.php:
 *   // Prefer /api/payment/webhook for Tilda-first grant (access + email before AVO invoice).
 *   'cabinet_payment_url' => 'https://my.worldwatercolormasters.art/api/payment/webhook',
 *   'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
 *   'cabinet_payment_token' => '…same as WWM_WEBHOOK_PAYMENT_TOKEN…',
 *   // bl-school GitHub Secret: WWM_CABINET_PAYMENT_TOKEN = same value
 *   'cabinet_notify_grant' => true,  // paid access + email (default true when token set)
 *   'cabinet_notify_pricing' => true, // Tilda USD line in cabinet (default true when pricing URL set)
 */

/**
 * @param array<string, mixed> $config
 */
function wwm_cabinet_webhook_token(array $config): string
{
    return trim((string)($config['cabinet_payment_token'] ?? $config['cabinet_webhook_token'] ?? ''));
}

/**
 * @param array<string, mixed> $config
 */
function wwm_cabinet_payment_grant_url(array $config): string
{
    $url = trim((string)($config['cabinet_payment_url'] ?? ''));
    if ($url !== '') {
        return $url;
    }
    $pricing = trim((string)($config['cabinet_pricing_url'] ?? ''));
    if ($pricing !== '' && str_ends_with($pricing, '/pricing')) {
        return substr($pricing, 0, -strlen('/pricing'));
    }

    return '';
}

/**
 * @param array<string, mixed> $payload
 */
function wwm_cabinet_http_post_json(string $url, string $token, array $payload, int $timeoutSec = 15): int
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return 0;
    }

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
            CURLOPT_TIMEOUT => max(5, $timeoutSec),
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-WWM-Payment-Token: ' . $token,
            ]),
            'content' => $json,
            'timeout' => max(5, $timeoutSec),
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $context);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        return (int)$m[1];
    }

    return 0;
}

/**
 * @param array<string, mixed> $config
 * @param array{
 *   email: string,
 *   name?: string,
 *   id_account: int|string,
 *   id_goods: int,
 *   id_contact?: int
 * } $grant
 * @param callable(string): void|null $logger
 */
function wwm_notify_cabinet_payment_grant(array $config, array $grant, ?callable $logger = null): void
{
    $notifyGrant = array_key_exists('cabinet_notify_grant', $config)
        ? (bool)$config['cabinet_notify_grant']
        : true;
    if (!$notifyGrant) {
        return;
    }

    $url = wwm_cabinet_payment_grant_url($config);
    $token = wwm_cabinet_webhook_token($config);
    if ($url === '' || $token === '') {
        return;
    }

    $email = strtolower(trim((string)($grant['email'] ?? '')));
    $idAccount = trim((string)($grant['id_account'] ?? ''));
    $idGoods = (int)($grant['id_goods'] ?? 0);
    if ($email === '' || $idAccount === '' || $idGoods <= 0) {
        return;
    }

    $payload = [
        'email' => $email,
        'name' => trim((string)($grant['name'] ?? '')),
        'id_goods' => $idGoods,
        'id_account' => $idAccount,
        'id_account_status' => 5,
        'source' => 'tilda',
        'source_ref' => $idAccount,
        'token' => $token,
    ];
    if (str_starts_with($idAccount, 'tilda:')) {
        $payload['idempotency_key'] = $idAccount;
        $payload['external_keys'] = [$idAccount];
    }
    $idContact = (int)($grant['id_contact'] ?? 0);
    if ($idContact > 0) {
        $payload['id_contact'] = $idContact;
    }

    $status = wwm_cabinet_http_post_json($url, $token, $payload);
    if ($logger !== null) {
        $logger(sprintf(
            'cabinet grant notify http=%d account=%s id_goods=%d email=%s',
            $status,
            $idAccount,
            $idGoods,
            $email
        ));
    }
}

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
    $notifyPricing = array_key_exists('cabinet_notify_pricing', $config)
        ? (bool)$config['cabinet_notify_pricing']
        : true;
    if (!$notifyPricing) {
        return;
    }

    $url = trim((string)($config['cabinet_pricing_url'] ?? ''));
    $token = wwm_cabinet_webhook_token($config);
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

    $status = wwm_cabinet_http_post_json($url, $token, $payload, 12);
    if ($logger !== null) {
        $logger(sprintf(
            'cabinet pricing notify http=%d account=%s email=%s',
            $status,
            $idAccount,
            $email
        ));
    }
}

/**
 * Grant paid access + optional pricing line (idempotent on cabinet side).
 *
 * @param array<string, mixed> $config
 * @param array{
 *   email: string,
 *   name?: string,
 *   id_account: int|string,
 *   id_goods: int,
 *   id_contact?: int,
 *   amount_original?: float,
 *   currency_original?: string,
 *   amount_rub?: float,
 *   fx_rate?: float
 * } $order
 * @param callable(string): void|null $logger
 */
function wwm_notify_cabinet_after_avo_order(array $config, array $order, ?callable $logger = null): void
{
    wwm_notify_cabinet_payment_grant($config, $order, $logger);
    wwm_notify_cabinet_payment_pricing($config, [
        'email' => (string)($order['email'] ?? ''),
        'id_account' => $order['id_account'] ?? '',
        'id_goods' => (int)($order['id_goods'] ?? 0),
        'amount_original' => (float)($order['amount_original'] ?? 0),
        'currency_original' => (string)($order['currency_original'] ?? ''),
        'amount_rub' => (float)($order['amount_rub'] ?? 0),
        'fx_rate' => (float)($order['fx_rate'] ?? 0),
    ], $logger);
}
