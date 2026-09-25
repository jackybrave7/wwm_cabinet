<?php
declare(strict_types=1);

/**
 * Webhook Tilda (Stripe) → оплаченный счёт в АвтоВебОфис.
 *
 * Настройка в Tilda:
 *   Site Settings → Forms → Webhook
 *   URL: https://bl-school.com/api/tilda-avo-webhook.php?token=YOUR_SECRET
 *   ☑ Передавать данные товаров массивами
 *   ☑ Передавать externalid
 *   ☑ Отправлять после оплаты (бесплатные заказы с купоном 100%
 *     Tilda обычно помечает как оплаченные сама — webhook тоже должен уйти)
 *
 * Ответ для Tilda: тело "ok" (иначе будут ретраи).
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

$configPath = __DIR__ . '/tilda-avo.config.php';
if (!is_readable($configPath)) {
    http_response_code(503);
    error_log('tilda-avo-webhook: missing tilda-avo.config.php');
    echo 'not configured';
    exit;
}

/** @var array<string, mixed> $config */
$config = require $configPath;

require_once __DIR__ . '/lib/AwoApi.php';
require_once __DIR__ . '/lib/CbrRates.php';
require_once __DIR__ . '/lib/TildaPayload.php';
require_once __DIR__ . '/lib/TildaUtm.php';
require_once __DIR__ . '/lib/ProcessedOrders.php';

$logger = static function (string $message) use ($config): void {
    if (empty($config['log_enabled'])) {
        return;
    }
    $line = '[' . date('c') . '] ' . $message;
    error_log('tilda-avo: ' . $message);
    $file = (string)($config['log_file'] ?? '');
    if ($file !== '') {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
};

$tokenExpected = (string)($config['webhook_token'] ?? '');
$tokenGot = (string)($_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if ($tokenExpected === '' || !hash_equals($tokenExpected, $tokenGot)) {
    http_response_code(403);
    $logger('forbidden: bad token');
    echo 'forbidden';
    exit;
}

// Tilda при сохранении Webhook шлёт тестовый POST без полей формы.
// Нужен ответ "ok", иначе в интерфейсе: [400] email required.
if (isTildaWebhookPing($_POST)) {
    $logger('ping ok (tilda connection test)');
    echo 'ok';
    exit;
}

$dryRun = !empty($config['allow_dry_run']) && isset($_GET['dry_run']);

try {
    $order = TildaPayload::parse($_POST);
} catch (Throwable $e) {
    http_response_code(400);
    $logger('parse error: ' . $e->getMessage());
    echo 'bad payload';
    exit;
}

if ($order['email'] === '') {
    // Повторная страховка: пустой/тестовый запрос без email
    if (isTildaWebhookPing($_POST) || ($order['products'] === [] && $order['amount'] <= 0)) {
        $logger('ping ok (no email, empty order)');
        echo 'ok';
        exit;
    }
    http_response_code(400);
    $logger('missing email; keys=' . implode(',', array_keys($_POST)));
    echo 'email required';
    exit;
}

$acceptFreeOrders = array_key_exists('accept_free_orders', $config)
    ? (bool)$config['accept_free_orders']
    : true;

$isFree = !empty($order['is_free']) || $order['amount'] <= 0.0;

if ($isFree && !$acceptFreeOrders) {
    http_response_code(422);
    $logger('free order rejected (accept_free_orders=false) email=' . $order['email']);
    echo 'free orders disabled';
    exit;
}

if ($order['products'] === [] && $order['amount'] <= 0) {
    http_response_code(400);
    $logger('empty order for ' . $order['email']);
    echo 'empty order';
    exit;
}

if ($isFree && $order['products'] === []) {
    http_response_code(422);
    $logger('free order without products email=' . $order['email']);
    echo 'free order needs products';
    exit;
}

$idempotencyKeys = buildTildaIdempotencyKeys($order);
$idempotencyKey = $idempotencyKeys[0] ?? (
    'tilda:' . md5($order['email'] . '|' . $order['amount'] . '|' . $order['currency'])
);

$processed = new ProcessedOrders((string)$config['processed_orders_file']);
$existingProcessed = $processed->findCompleted($idempotencyKeys);
if ($existingProcessed !== null) {
    $logger(sprintf(
        'duplicate skipped (done): keys=%s id_account=%s',
        implode(',', $idempotencyKeys),
        (string)($existingProcessed['id_account'] ?? '')
    ));
    echo 'ok';
    exit;
}

try {
    $cbr = new CbrRates((string)$config['cbr_cache_file']);
    $fx = $cbr->convertToRub($order['amount'], $order['currency']);
} catch (Throwable $e) {
    http_response_code(502);
    $logger('fx error: ' . $e->getMessage());
    echo 'fx error';
    exit;
}

$productMap = is_array($config['product_map'] ?? null) ? $config['product_map'] : [];
$defaultGoodsId = $config['default_goods_id'] ?? null;

// WWM: на сайте всегда один курс за заказ. Если Tilda прислала несколько
// позиций (общая корзина между страницами) — оставляем одну.
$forceSingleProduct = array_key_exists('force_single_product', $config)
    ? (bool)$config['force_single_product']
    : true;
if ($forceSingleProduct && count($order['products']) > 1) {
    $beforeNames = array_map(
        static fn(array $p): string => (string)$p['name'],
        $order['products']
    );
    $order['products'] = selectSingleCourseProduct(
        $order['products'],
        (float)$order['amount'],
        $isFree,
        $logger
    );
    $keptName = (string)($order['products'][0]['name'] ?? '');
    $order['comment_parts'][] = 'Оставлен 1 курс из корзины Tilda: ' . $keptName
        . ' (было: ' . implode(' + ', $beforeNames) . ')';
}

$lines = [];
$linesSum = 0.0;

if ($order['products'] !== []) {
    $productTotalOrig = 0.0;
    foreach ($order['products'] as $p) {
        $productTotalOrig += $p['price'] * $p['quantity'];
    }

    // Платный заказ: распределяем рублёвую сумму пропорционально.
    // Бесплатный (купон 100%): sum_price=0, price_full — курс×цена позиции.
    $scale = (!$isFree && $productTotalOrig > 0)
        ? ($fx['amount_rub'] / $productTotalOrig)
        : 0.0;

    foreach ($order['products'] as $index => $p) {
        $goodsId = resolveGoodsId($p, $productMap, $defaultGoodsId);
        if ($goodsId === null) {
            http_response_code(422);
            $logger('unmapped product: ' . json_encode($p, JSON_UNESCAPED_UNICODE));
            echo 'unmapped product';
            exit;
        }

        try {
            $fullFx = $cbr->convertToRub($p['price'], $order['currency']);
            $unitFull = round($fullFx['amount_rub'], 2);
        } catch (Throwable $e) {
            $unitFull = round($p['price'] * (float)$fx['rate'], 2);
        }

        if ($isFree) {
            $unitPrice = 0.0;
            $lineSum = 0.0;
        } else {
            $lineSum = round($p['price'] * $p['quantity'] * $scale, 2);
            if ($index === count($order['products']) - 1) {
                $lineSum = round($fx['amount_rub'] - $linesSum, 2);
            }
            $unitPrice = $p['quantity'] > 0 ? round($lineSum / $p['quantity'], 2) : $lineSum;
        }

        $linesSum = round($linesSum + $lineSum, 2);

        $lines[] = [
            'goods' => $p['name'] !== '' ? $p['name'] : ('Товар #' . $goodsId),
            'id_goods' => $goodsId,
            'price_full' => $isFree ? $unitFull : $unitPrice,
            'price' => $unitPrice,
            'quantity' => $p['quantity'],
            'sum_price' => $lineSum,
        ];
    }

    $beforeDedupe = count($lines);
    $lines = dedupeExclusiveCourseLines($lines, $logger);
    if (count($lines) !== $beforeDedupe) {
        // После удаления дубля EN/GE перераскидываем сумму оплаты на оставшиеся строки
        $linesSum = 0.0;
        if ($isFree || $lines === []) {
            foreach ($lines as &$line) {
                $line['price'] = 0.0;
                $line['sum_price'] = 0.0;
            }
            unset($line);
        } else {
            $target = round((float)$fx['amount_rub'], 2);
            $n = count($lines);
            $assigned = 0.0;
            foreach ($lines as $i => &$line) {
                if ($i === $n - 1) {
                    $lineSum = round($target - $assigned, 2);
                } else {
                    $lineSum = round($target / $n, 2);
                    $assigned = round($assigned + $lineSum, 2);
                }
                $qty = max(1.0, (float)$line['quantity']);
                $line['sum_price'] = $lineSum;
                $line['price'] = round($lineSum / $qty, 2);
                $linesSum = round($linesSum + $lineSum, 2);
            }
            unset($line);
        }
    }
} else {
    $goodsId = is_numeric($defaultGoodsId) ? (int)$defaultGoodsId : null;
    if ($goodsId === null) {
        http_response_code(422);
        $logger('no products and no default_goods_id');
        echo 'no products';
        exit;
    }
    $lines[] = [
        'goods' => 'Оплата Tilda / Stripe',
        'id_goods' => $goodsId,
        'price_full' => $fx['amount_rub'],
        'price' => $fx['amount_rub'],
        'quantity' => 1,
        'sum_price' => $fx['amount_rub'],
    ];
    $linesSum = $fx['amount_rub'];
}

// страховка от рассинхрона копеек (для бесплатных — account_sum = 0)
$accountSum = $isFree ? 0.0 : round($fx['amount_rub'], 2);
if (!$isFree && abs($linesSum - $accountSum) >= 0.01 && $lines !== []) {
    $diff = round($accountSum - $linesSum, 2);
    $last = count($lines) - 1;
    $lines[$last]['sum_price'] = round($lines[$last]['sum_price'] + $diff, 2);
    if ((float)$lines[$last]['quantity'] > 0) {
        $lines[$last]['price'] = round($lines[$last]['sum_price'] / (float)$lines[$last]['quantity'], 2);
        $lines[$last]['price_full'] = $lines[$last]['price'];
    }
}

[$firstName, $lastName] = splitName($order['name']);

$utm = is_array($order['utm'] ?? null) ? $order['utm'] : TildaUtm::parse($_POST);
$channelMap = is_array($config['utm_channel_map'] ?? null) ? $config['utm_channel_map'] : [];
$adFields = TildaUtm::toAwoAdvertisingFields($utm, $channelMap);

$comment = buildComment($order, $fx, $idempotencyKey, $isFree);
if (count($idempotencyKeys) > 1) {
    $comment .= "\nКлючи: " . implode(', ', $idempotencyKeys);
}

$payloadPreview = [
    'email' => $order['email'],
    'name' => $order['name'],
    'is_free' => $isFree,
    'promocode' => $order['promocode'] ?? '',
    'amount_orig' => $order['amount'] . ' ' . $order['currency'],
    'amount_rub' => $accountSum,
    'rate' => $fx['rate'],
    'rate_date' => $fx['rate_date'],
    'lines' => $lines,
    'utm' => [
        'utm_source' => $utm['utm_source'] ?? '',
        'utm_medium' => $utm['utm_medium'] ?? '',
        'utm_campaign' => $utm['utm_campaign'] ?? '',
        'utm_content' => $utm['utm_content'] ?? '',
        'utm_term' => $utm['utm_term'] ?? '',
        'present' => !empty($utm['present']),
    ],
    'advertising' => $adFields,
    'comment' => $comment,
];

if ($dryRun) {
    $logger('dry_run: ' . json_encode($payloadPreview, JSON_UNESCAPED_UNICODE));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'dry_run' => true, 'payload' => $payloadPreview], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$api = new AwoApi([
    'apiKeyRead' => (string)$config['avo_api_key_read'],
    'apiKeyWrite' => (string)$config['avo_api_key_write'],
    'subdomain' => (string)$config['avo_subdomain'],
]);

// Ранний claim ДО create в АВО: параллельный/ретрай webhook не создаст второй счёт.
$claim = $processed->claim($idempotencyKeys, [
    'email' => $order['email'],
    'amount_rub' => $accountSum,
    'currency' => $order['currency'],
    'amount_orig' => $order['amount'],
    'is_free' => $isFree,
]);

if ($claim['status'] === 'exists') {
    $logger(sprintf(
        'duplicate skipped (claim exists): key=%s id_account=%s',
        (string)$claim['key'],
        (string)(($claim['record']['id_account'] ?? ''))
    ));
    echo 'ok';
    exit;
}

if ($claim['status'] === 'busy') {
    // Другой воркер уже создаёт счёт — ждём завершения и отвечаем ok без create.
    $waited = waitForProcessedCompletion($processed, $idempotencyKeys, 25);
    if ($waited !== null) {
        $logger(sprintf(
            'duplicate skipped (busy→done): id_account=%s',
            (string)($waited['id_account'] ?? '')
        ));
        echo 'ok';
        exit;
    }
    // Первый воркер ещё не закончил — пусть Tilda повторит (не ok),
    // иначе при таймауте первого мы сами создадим дубль.
    http_response_code(503);
    $logger('busy: peer still processing keys=' . implode(',', $idempotencyKeys));
    echo 'busy';
    exit;
}

$claimedKeys = $idempotencyKeys;

try {
    // Страховка: уже есть свежий счёт в АВО (email + сумма + окно) — переиспользуем.
    $reuseWindowSec = (int)($config['avo_reuse_window_seconds'] ?? 300);
    $reusedAccountId = findRecentAvoAccount(
        $api,
        $order['email'],
        $accountSum,
        $idempotencyKeys,
        $reuseWindowSec,
        $logger
    );

    if ($reusedAccountId > 0) {
        $paymentSystemId = (int)($config['avo_payment_system_id'] ?? 59);
        ensureAvoAccountPaid(
            $api,
            $reusedAccountId,
            $paymentSystemId,
            $comment,
            $adFields,
            $logger
        );
        $processed->complete($claimedKeys, [
            'id_account' => $reusedAccountId,
            'email' => $order['email'],
            'amount_rub' => $accountSum,
            'currency' => $order['currency'],
            'amount_orig' => $order['amount'],
            'is_free' => $isFree,
            'promocode' => $order['promocode'] ?? '',
            'reused' => true,
        ]);
        $logger(sprintf(
            'ok reused account=%d email=%s %s %s → %s RUB%s',
            $reusedAccountId,
            $order['email'],
            $order['amount'],
            $order['currency'],
            $accountSum,
            $isFree ? ' [FREE]' : ''
        ));
        echo 'ok';
        exit;
    }

    $contactPayload = array_merge([
        'email' => $order['email'],
        'name' => $firstName,
        'last_name' => $lastName,
        'phone_number' => $order['phone'],
    ], $adFields);

    $contactRaw = $api->contact()->create([$contactPayload]);
    $contact = unwrapEntity($contactRaw, 'contact');
    if ($contact === null) {
        $processed->release($claimedKeys);
        http_response_code(502);
        $logger('contact create failed: ' . summarizeApiError($contactRaw, $api));
        echo 'avo contact error';
        exit;
    }

    $idContact = (int)$contact->id_contact;
    $paymentSystemId = (int)($config['avo_payment_system_id'] ?? 59);

    $invoicePayload = array_merge([
        'id_contact' => $idContact,
        'email' => $order['email'],
        'name' => $firstName,
        'last_name' => $lastName,
        'phone_number' => $order['phone'],
        'account_sum' => $accountSum,
        'account_comment' => $comment,
        'id_partner' => (int)($contact->id_partner ?? 0),
        'id_payment_system' => $paymentSystemId,
    ], $adFields);

    $invoiceRaw = $api->invoice()->create([$invoicePayload]);
    $invoice = unwrapEntity($invoiceRaw, 'invoice');
    if ($invoice === null || empty($invoice->id_account)) {
        $processed->release($claimedKeys);
        http_response_code(502);
        $logger('invoice create failed: ' . summarizeApiError($invoiceRaw, $api));
        echo 'avo invoice error';
        exit;
    }

    $idAccount = (int)$invoice->id_account;

    $linePayload = [];
    foreach ($lines as $line) {
        $linePayload[] = array_merge($line, ['id_account' => $idAccount]);
    }
    $linesRaw = $api->invoiceLine()->create($linePayload);
    if (isAwoError($linesRaw)) {
        $processed->release($claimedKeys);
        http_response_code(502);
        $logger('invoice lines failed for account ' . $idAccount . ': ' . summarizeApiError($linesRaw, $api));
        echo 'avo lines error';
        exit;
    }

    $paidRaw = $api->invoice()->update($idAccount, array_merge([
        'id_account_status' => 5,
        'id_payment_system' => $paymentSystemId,
        'date_of_payment' => date('Y-m-d H:i:s'),
        'account_comment' => $comment,
    ], $adFields));
    if (isAwoError($paidRaw)) {
        $processed->release($claimedKeys);
        http_response_code(502);
        $logger('mark paid failed for account ' . $idAccount . ': ' . summarizeApiError($paidRaw, $api));
        echo 'avo pay error';
        exit;
    }

    $processed->complete($claimedKeys, [
        'id_account' => $idAccount,
        'email' => $order['email'],
        'amount_rub' => $accountSum,
        'currency' => $order['currency'],
        'amount_orig' => $order['amount'],
        'is_free' => $isFree,
        'promocode' => $order['promocode'] ?? '',
    ]);

    if (is_readable(__DIR__ . '/lib/WwmCabinetPricing.php')) {
        require_once __DIR__ . '/lib/WwmCabinetPricing.php';
        $primaryGoodsId = (int)($lines[0]['id_goods'] ?? 0);
        if (function_exists('wwm_notify_cabinet_payment_pricing') && $primaryGoodsId > 0) {
            wwm_notify_cabinet_payment_pricing($config, [
                'email' => $order['email'],
                'id_account' => $idAccount,
                'id_goods' => $primaryGoodsId,
                'amount_original' => (float)$order['amount'],
                'currency_original' => (string)$order['currency'],
                'amount_rub' => (float)$accountSum,
                'fx_rate' => (float)$fx['rate'],
            ], $logger);
        }
    }

    $logger(sprintf(
        'ok account=%d email=%s %s %s → %s RUB (rate %s on %s)%s%s',
        $idAccount,
        $order['email'],
        $order['amount'],
        $order['currency'],
        $accountSum,
        $fx['rate'],
        $fx['rate_date'],
        $isFree ? ' [FREE]' : '',
        !empty($utm['present'])
            ? (' utm=' . ($utm['utm_source'] ?? '') . '/' . ($utm['utm_medium'] ?? '') . '/' . ($utm['utm_campaign'] ?? ''))
            : ''
    ));

    echo 'ok';
    exit;
} catch (Throwable $e) {
    $processed->release($claimedKeys);
    http_response_code(502);
    $logger('exception after claim: ' . $e->getMessage());
    echo 'avo exception';
    exit;
}

/**
 * Тестовый запрос Tilda при подключении Webhook (без полей заказа).
 *
 * @param array<string, mixed> $post
 */
function isTildaWebhookPing(array $post): bool
{
    if ($post === []) {
        return true;
    }

    // Иногда Tilda шлёт служебные ключи без Email/payment
    $meaningful = 0;
    foreach ($post as $key => $value) {
        $k = strtolower((string)$key);
        if (in_array($k, ['email', 'name', 'phone', 'payment', 'paymentsystem', 'tranid', 'formid'], true)) {
            if (is_array($value) || trim((string)$value) !== '') {
                $meaningful++;
            }
        }
        if ($k === 'products' && (is_array($value) ? $value !== [] : trim((string)$value) !== '')) {
            $meaningful++;
        }
    }

    return $meaningful === 0;
}

/**
 * Несколько ключей на один платёж Tilda (orderid / paymentid / tranid + fingerprint).
 * Любой повтор с тем же id попадает в уже обработанную запись.
 *
 * @param array{
 *   order_id: string,
 *   payment_id?: string,
 *   tranid: string,
 *   email: string,
 *   amount: float,
 *   currency: string,
 *   products: list<array{name?: string, externalid?: string}>
 * } $order
 * @return list<string>
 */
function buildTildaIdempotencyKeys(array $order): array
{
    $keys = [];
    $add = static function (string $prefix, string $value) use (&$keys): void {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $key = $prefix . $value;
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    };

    $add('tilda:order:', (string)($order['order_id'] ?? ''));
    $add('tilda:payment:', (string)($order['payment_id'] ?? ''));
    $add('tilda:tran:', (string)($order['tranid'] ?? ''));

    $productBits = [];
    foreach ($order['products'] as $p) {
        $bit = trim((string)($p['externalid'] ?? ''));
        if ($bit === '') {
            $bit = trim((string)($p['name'] ?? ''));
        }
        if ($bit !== '') {
            $productBits[] = $bit;
        }
    }
    sort($productBits);
    $fingerprint = md5(strtolower(trim((string)$order['email'])) . '|'
        . (string)$order['amount'] . '|'
        . strtoupper((string)$order['currency']) . '|'
        . implode('+', $productBits));
    $add('tilda:fp:', $fingerprint);

    if ($keys === []) {
        $keys[] = 'tilda:fp:' . md5((string)microtime(true));
    }

    return $keys;
}

/**
 * @param list<string> $keys
 * @return array<string, mixed>|null
 */
function waitForProcessedCompletion(ProcessedOrders $processed, array $keys, int $timeoutSec): ?array
{
    $deadline = microtime(true) + max(1, $timeoutSec);
    while (microtime(true) < $deadline) {
        usleep(250000);
        $done = $processed->findCompleted($keys);
        if ($done !== null) {
            return $done;
        }
    }
    return null;
}

/**
 * Ищем недавний счёт того же платежа в АВО (email + сумма + ключ в комментарии / окно времени).
 *
 * @param list<string> $idempotencyKeys
 * @param callable(string): void $logger
 */
function findRecentAvoAccount(
    AwoApi $api,
    string $email,
    float $amountRub,
    array $idempotencyKeys,
    int $windowSec,
    callable $logger
): int {
    $email = strtolower(trim($email));
    if ($email === '') {
        return 0;
    }

    try {
        $raw = $api->invoice()->pageSize(50)->orderBy(['id_account' => 'DESC'])->getAll([
            'email' => $email,
        ]);
    } catch (Throwable $e) {
        $logger('avo search failed: ' . $e->getMessage());
        return 0;
    }

    $list = [];
    if ($raw instanceof stdClass && !isset($raw->error)) {
        $list[] = $raw;
    } elseif (is_array($raw)) {
        foreach ($raw as $item) {
            if ($item instanceof stdClass && !isset($item->error)) {
                $list[] = $item;
            }
        }
    }

    if ($list === []) {
        return 0;
    }

    $now = time();
    $bestId = 0;
    $bestScore = -1;

    foreach ($list as $item) {
        $itemEmail = strtolower(trim((string)($item->email ?? '')));
        if ($itemEmail !== '' && $itemEmail !== $email) {
            continue;
        }

        $sum = (float)($item->account_sum ?? 0);
        if ($amountRub > 0 && abs($sum - $amountRub) > 1.0) {
            continue;
        }

        $comment = (string)($item->account_comment ?? '');
        $keyHit = false;
        foreach ($idempotencyKeys as $key) {
            if ($key !== '' && str_contains($comment, $key)) {
                $keyHit = true;
                break;
            }
        }

        $createdTs = 0;
        foreach (['date_of_payment', 'creationDate', 'creation_date', 'date_created', 'created_at'] as $field) {
            $rawDate = trim((string)($item->{$field} ?? ''));
            if ($rawDate === '') {
                continue;
            }
            $ts = strtotime($rawDate);
            if ($ts !== false) {
                $createdTs = $ts;
                break;
            }
        }

        $inWindow = $createdTs > 0 && ($now - $createdTs) <= $windowSec && ($now - $createdTs) >= -60;
        $status = (int)($item->id_account_status ?? 0);
        // 5 = оплачен; 1/2 часто «новый/в обработке» — тоже кандидат на reuse
        $statusOk = in_array($status, [1, 2, 3, 5, 0], true);

        if (!$keyHit && !$inWindow) {
            continue;
        }
        if (!$statusOk) {
            continue;
        }

        $score = 0;
        if ($keyHit) {
            $score += 100;
        }
        if ($status === 5) {
            $score += 20;
        }
        if ($inWindow) {
            $score += 10;
        }
        $id = (int)($item->id_account ?? 0);
        if ($id > 0 && $score > $bestScore) {
            $bestScore = $score;
            $bestId = $id;
        }
    }

    if ($bestId > 0) {
        $logger('avo reuse candidate account=' . $bestId . ' score=' . $bestScore);
    }

    return $bestId;
}

/**
 * Доводим существующий счёт до «Оплачен», не создавая новый.
 *
 * @param array<string, mixed> $adFields
 * @param callable(string): void $logger
 */
function ensureAvoAccountPaid(
    AwoApi $api,
    int $idAccount,
    int $paymentSystemId,
    string $comment,
    array $adFields,
    callable $logger
): void {
    $existing = $api->invoice()->get($idAccount);
    if (isAwoError($existing)) {
        $logger('avo get for reuse failed account=' . $idAccount . ': ' . summarizeApiError($existing, $api));
        return;
    }

    $status = is_object($existing) ? (int)($existing->id_account_status ?? 0) : 0;
    if ($status === 5) {
        $logger('avo reuse already paid account=' . $idAccount);
        return;
    }

    $oldComment = is_object($existing) ? trim((string)($existing->account_comment ?? '')) : '';
    $mergedComment = $oldComment;
    if ($comment !== '' && !str_contains($oldComment, $comment)) {
        $mergedComment = trim($oldComment . ($oldComment !== '' ? "\n" : '') . $comment);
    }

    $paidRaw = $api->invoice()->update($idAccount, array_merge([
        'id_account_status' => 5,
        'id_payment_system' => $paymentSystemId,
        'date_of_payment' => date('Y-m-d H:i:s'),
        'account_comment' => $mergedComment !== '' ? $mergedComment : $comment,
    ], $adFields));

    if (isAwoError($paidRaw)) {
        $logger('avo reuse mark paid failed account=' . $idAccount . ': ' . summarizeApiError($paidRaw, $api));
        return;
    }

    $logger('avo reuse marked paid account=' . $idAccount);
}

/**
 * Оставить один курс из списка товаров Tilda.
 *
 * Правила:
 * 1) Если сумма оплаты совпадает с ровно одной позицией — берём её (остальные «прилипли» из корзины).
 * 2) Иначе берём последнюю позицию (обычно последний добавленный / целевой курс).
 * Сумма оплаты Stripe при этом не меняется — целиком уходит на выбранный курс.
 *
 * @param list<array{name: string, price: float, quantity: float, externalid: string, sku: string}> $products
 * @param callable(string):void $logger
 * @return list<array{name: string, price: float, quantity: float, externalid: string, sku: string}>
 */
function selectSingleCourseProduct(array $products, float $amount, bool $isFree, callable $logger): array
{
    if (count($products) <= 1) {
        return $products;
    }

    $keepIndex = count($products) - 1;
    $reason = 'last_item';

    if (!$isFree && $amount > 0) {
        $matches = [];
        foreach ($products as $i => $p) {
            $lineTotal = round((float)$p['price'] * (float)$p['quantity'], 2);
            if (abs($lineTotal - $amount) < 0.02) {
                $matches[] = $i;
            }
        }
        if (count($matches) === 1) {
            $keepIndex = $matches[0];
            $reason = 'amount_match';
        }
    }

    $kept = $products[$keepIndex];
    $dropped = [];
    foreach ($products as $i => $p) {
        if ($i === $keepIndex) {
            continue;
        }
        $dropped[] = trim((string)$p['name']) !== ''
            ? (string)$p['name']
            : ('#' . ($i + 1));
    }

    $logger(sprintf(
        'force_single_product(%s): keep="%s" drop=[%s] amount=%s',
        $reason,
        (string)$kept['name'],
        implode(' | ', $dropped),
        $isFree ? '0(free)' : (string)$amount
    ));

    return [$kept];
}

/**
 * EN/GE версии одного курса не должны попадать в один счёт.
 * Если Tilda прислала обе (корзина с двух страниц) — оставляем лучшее совпадение по языку названия.
 *
 * @param list<array{goods: string, id_goods: int|string, price_full?: float, price: float, quantity: float, sum_price: float}> $lines
 * @param callable(string):void $logger
 * @return list<array{goods: string, id_goods: int|string, price_full?: float, price: float, quantity: float, sum_price: float}>
 */
function dedupeExclusiveCourseLines(array $lines, callable $logger): array
{
    $groups = [
        // Elke Memmler: EN=188, GE=191
        [188, 191],
    ];

    foreach ($groups as $ids) {
        $indexes = [];
        foreach ($lines as $i => $line) {
            if (in_array((int)$line['id_goods'], $ids, true)) {
                $indexes[] = $i;
            }
        }
        if (count($indexes) <= 1) {
            continue;
        }

        $bestIdx = $indexes[0];
        $bestScore = scoreExclusiveCourseLine($lines[$bestIdx]);
        foreach ($indexes as $i) {
            $score = scoreExclusiveCourseLine($lines[$i]);
            // при равном score берём более поздний товар в корзине (обычно последний добавленный)
            if ($score >= $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        foreach ($indexes as $i) {
            if ($i === $bestIdx) {
                continue;
            }
            $logger(sprintf(
                'dedupe exclusive courses: keep id=%s, drop id=%s (%s)',
                $lines[$bestIdx]['id_goods'],
                $lines[$i]['id_goods'],
                $lines[$i]['goods']
            ));
            unset($lines[$i]);
        }
        $lines = array_values($lines);
    }

    return $lines;
}

/** @param array{goods?: string, id_goods?: int|string} $line */
function scoreExclusiveCourseLine(array $line): int
{
    $name = normalizeMapKey((string)($line['goods'] ?? ''));
    $id = (int)($line['id_goods'] ?? 0);
    $score = 0;

    if ($id === 188) {
        if (str_contains($name, 'watercolor expressionism')) {
            $score += 10;
        }
        if (str_contains($name, 'by elke')) {
            $score += 5;
        }
        if (str_contains($name, 'aquarell') || str_contains($name, 'videokurs')) {
            $score -= 20;
        }
    }
    if ($id === 191) {
        if (str_contains($name, 'aquarell-expressionismus')) {
            $score += 10;
        }
        if (str_contains($name, 'von elke')) {
            $score += 5;
        }
        if (str_contains($name, 'watercolor expressionism') || str_contains($name, 'video course')) {
            $score -= 20;
        }
    }

    return $score;
}

/**
 * Сопоставление товара Tilda с id_goods АВО.
 * Порядок: точное имя → подстрока (длинный ключ побеждает) → numeric externalid → default.
 *
 * Для WWM без каталога в webhook приходит только name из #order:Name=Price,
 * поэтому в product_map достаточно коротких ключей вроде "Alvaro Castagnet".
 *
 * @param array{name: string, externalid: string, sku: string} $product
 * @param array<string|int, mixed> $map
 * @param mixed $defaultGoodsId
 */
function resolveGoodsId(array $product, array $map, $defaultGoodsId): ?int
{
    $name = trim((string)$product['name']);
    $externalid = trim((string)$product['externalid']);
    $sku = trim((string)$product['sku']);

    $exactCandidates = [];
    foreach ([$externalid, $sku, $name] as $key) {
        if ($key === '') {
            continue;
        }
        $exactCandidates[] = $key;
        $exactCandidates[] = normalizeMapKey($key);
    }

    $mapNorm = [];
    foreach ($map as $mapKey => $mapVal) {
        if (!is_numeric($mapVal) || (int)$mapVal <= 0) {
            continue;
        }
        $mapNorm[normalizeMapKey((string)$mapKey)] = (int)$mapVal;
        $mapNorm[(string)$mapKey] = (int)$mapVal;
    }

    foreach ($exactCandidates as $key) {
        if (isset($mapNorm[$key])) {
            return $mapNorm[$key];
        }
    }

    $haystack = normalizeMapKey($name);

    // Жёсткие правила для EN/GE Elke — не путать языковые версии
    if (str_contains($haystack, 'aquarell-expressionismus')
        || (str_contains($haystack, 'videokurs') && str_contains($haystack, 'elke'))) {
        return 191;
    }
    if (str_contains($haystack, 'watercolor expressionism')
        && str_contains($haystack, 'elke')) {
        return 188;
    }

    // Подстрока: ключ «Alvaro Castagnet» матчит полное Tilda-название курса
    if ($haystack !== '') {
        $bestKey = '';
        $bestId = null;
        foreach ($map as $mapKey => $mapVal) {
            if (!is_numeric($mapVal) || (int)$mapVal <= 0) {
                continue;
            }
            $needle = normalizeMapKey((string)$mapKey);
            if ($needle === '' || strlen($needle) < 3) {
                continue;
            }
            // не матчить короткий «Elke Memmler» на обе версии — только через правила выше
            if ($needle === 'elke memmler') {
                continue;
            }
            if (str_contains($haystack, $needle) && strlen($needle) > strlen($bestKey)) {
                $bestKey = $needle;
                $bestId = (int)$mapVal;
            }
        }
        if ($bestId !== null) {
            return $bestId;
        }
    }

    if ($externalid !== '' && ctype_digit($externalid)) {
        return (int)$externalid;
    }

    if (is_numeric($defaultGoodsId)) {
        return (int)$defaultGoodsId;
    }

    return null;
}

function normalizeMapKey(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\u{00A0}", '’', '‘', '`'], [' ', "'", "'", "'"], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

/** @return array{0: string, 1: string} */
function splitName(string $full): array
{
    $full = trim(preg_replace('/\s+/u', ' ', $full) ?? $full);
    if ($full === '') {
        return ['', ''];
    }
    $parts = explode(' ', $full, 2);
    if (count($parts) === 1) {
        return [$parts[0], ''];
    }
    // Tilda часто шлёт "Имя Фамилия"
    return [$parts[0], $parts[1]];
}

/**
 * @param array{
 *   order_id: string,
 *   payment_id?: string,
 *   tranid: string,
 *   payment_system: string,
 *   amount: float,
 *   currency: string,
 *   promocode?: string,
 *   comment_parts: list<string>
 * } $order
 * @param array{amount_rub: float, rate: float, currency: string, rate_date: string} $fx
 */
function buildComment(array $order, array $fx, string $idempotencyKey, bool $isFree = false): string
{
    $parts = [
        'Источник: Tilda / Stripe (worldwatercolormasters.art)',
        'Ключ: ' . $idempotencyKey,
    ];
    if ($isFree) {
        $parts[] = 'Бесплатный заказ (сумма 0 — купон/скидка 100%)';
        if (!empty($order['promocode'])) {
            $parts[] = 'Промокод: ' . $order['promocode'];
        }
    }
    if ($order['order_id'] !== '') {
        $parts[] = 'Tilda order: ' . $order['order_id'];
    }
    if (!empty($order['payment_id'])) {
        $parts[] = 'Tilda paymentid: ' . $order['payment_id'];
    }
    if ($order['tranid'] !== '') {
        $parts[] = 'Tilda tranid: ' . $order['tranid'];
    }
    $parts[] = sprintf(
        'Оплата: %s %s → %s RUB (ЦБ %s на %s)',
        $order['amount'],
        $order['currency'],
        $isFree ? 0 : $fx['amount_rub'],
        $fx['rate'],
        $fx['rate_date']
    );
    if ($order['payment_system'] !== '') {
        $parts[] = 'ПС Tilda: ' . $order['payment_system'];
    }
    foreach ($order['comment_parts'] as $extra) {
        $parts[] = $extra;
    }
    return implode("\n", $parts);
}

/** @param mixed $raw */
function unwrapEntity($raw, string $label): ?stdClass
{
    if ($raw instanceof stdClass) {
        if (isset($raw->error)) {
            return null;
        }
        return $raw;
    }
    if (is_array($raw)) {
        if ($raw === []) {
            return null;
        }
        // список созданных сущностей
        $first = reset($raw);
        if ($first instanceof stdClass && !isset($first->error)) {
            return $first;
        }
        return null;
    }
    return null;
}

/** @param mixed $raw */
function isAwoError($raw): bool
{
    if (is_string($raw)) {
        return true;
    }
    if ($raw instanceof stdClass && isset($raw->error)) {
        return true;
    }
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if ($item instanceof stdClass && isset($item->error)) {
                return true;
            }
            if (is_string($item)) {
                return true;
            }
        }
    }
    return false;
}

/** @param mixed $raw */
function summarizeApiError($raw, AwoApi $api): string
{
    $bits = [];
    if (is_string($raw)) {
        $bits[] = $raw;
    } elseif ($raw instanceof stdClass) {
        $bits[] = json_encode($raw, JSON_UNESCAPED_UNICODE) ?: 'object';
    } elseif (is_array($raw)) {
        $bits[] = json_encode($raw, JSON_UNESCAPED_UNICODE) ?: 'array';
    }
    $resp = $api->getResponse();
    if (is_string($resp) && $resp !== '') {
        $bits[] = 'raw=' . (function_exists('mb_substr') ? mb_substr($resp, 0, 500) : substr($resp, 0, 500));
    }
    $bits[] = 'http=' . $api->getResponseCode();
    return implode(' | ', $bits);
}
