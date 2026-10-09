<?php
declare(strict_types=1);

/**
 * Local test: Tilda payment webhook + AVO dedupe (one access, one email).
 *
 *   php scripts/test-tilda-payment-webhook.php [email] [id_goods]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Models\Access;
use Wwm\Models\PaymentExternalKey;
use Wwm\Models\User;
use Wwm\Services\PaidAccess;

$email = strtolower(trim((string)($argv[1] ?? 'tilda-paid@wwm.test')));
$idGoods = (int)($argv[2] ?? 188);
$orderId = 'test-' . bin2hex(random_bytes(4));
$idempotencyKey = 'tilda:order:' . $orderId;
$paymentKey = 'tilda:payment:ch_test_' . $orderId;
$fpKey = 'tilda:fp:' . md5($email . '|' . $orderId);

$courseSlug = \Wwm\Services\DemoAccess::resolveCourseSlug(null, $idGoods);
if ($courseSlug === null || $courseSlug === '') {
    fwrite(STDERR, "Unknown id_goods={$idGoods}\n");
    exit(1);
}

$pdo = wwm_pdo();

// Clean prior test access for this email/course so the first grant is fresh.
$user = User::findByEmail($pdo, $email);
if ($user !== null) {
    Access::revoke($pdo, (int)$user['id'], $courseSlug, 'paid');
}

$payload = [
    'source' => 'tilda',
    'email' => $email,
    'name' => 'Tilda Test',
    'id_goods' => $idGoods,
    'amount_original' => 69.3,
    'currency_original' => 'USD',
    'idempotency_key' => $idempotencyKey,
    'external_keys' => [$idempotencyKey, $paymentKey, $fpKey],
    'order_id' => $orderId,
    'payment_id' => 'ch_test_' . $orderId,
    'promocode' => '',
    'is_free' => false,
    'id_account' => $idempotencyKey,
];

echo "=== 1) First Tilda grant ===\n";
$keys = PaymentExternalKey::collectFromPayload($payload);
$first = (new PaidAccess())->grant(
    $email,
    'Tilda Test',
    $courseSlug,
    'tilda',
    $idempotencyKey,
    [],
    null,
    null,
    gmdate('c'),
    gmdate('c')
);
$userId = (int)$first['user_id'];
PaymentExternalKey::storeMany($pdo, $keys, $userId, $courseSlug, 'tilda');
echo json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (empty($first['paid_granted']) || !empty($first['already_paid'])) {
    fwrite(STDERR, "Expected first grant to create paid access\n");
    exit(1);
}

echo "=== 2) Repeat Tilda keys → already_paid ===\n";
$keysExist = PaymentExternalKey::anyExist($pdo, $keys);
$second = (new PaidAccess())->grant($email, 'Tilda Test', $courseSlug, 'tilda', $idempotencyKey);
echo json_encode([
    'keys_exist' => $keysExist,
    'already_paid' => !empty($second['already_paid']),
    'email_sent' => !empty($second['email_sent']),
    'paid_granted' => !empty($second['paid_granted']),
], JSON_PRETTY_PRINT) . PHP_EOL;
if (!$keysExist || empty($second['already_paid']) || !empty($second['email_sent'])) {
    fwrite(STDERR, "Expected already_paid with no second email\n");
    exit(1);
}

echo "=== 3) AVO paid with tilda keys in comment → already_paid ===\n";
$avoPayload = [
    'email' => $email,
    'name' => 'Tilda Test',
    'id_goods' => $idGoods,
    'id_account' => '999001',
    'id_account_status' => 5,
    'account_comment' => "Ключ: {$idempotencyKey}\nКлючи: {$idempotencyKey}, {$paymentKey}",
    'source' => 'avo',
];
$avoKeys = PaymentExternalKey::collectFromPayload($avoPayload);
$avoKeysHit = PaymentExternalKey::anyExist($pdo, $avoKeys);
$third = (new PaidAccess())->grant($email, 'Tilda Test', $courseSlug, 'avo', '999001');
echo json_encode([
    'avo_keys' => $avoKeys,
    'avo_keys_hit' => $avoKeysHit,
    'already_paid' => !empty($third['already_paid']),
    'email_sent' => !empty($third['email_sent']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$avoKeysHit || empty($third['already_paid']) || !empty($third['email_sent'])) {
    fwrite(STDERR, "Expected AVO path to dedupe via tilda keys / has_paid\n");
    exit(1);
}

echo "OK: one access, no duplicate email\n";
