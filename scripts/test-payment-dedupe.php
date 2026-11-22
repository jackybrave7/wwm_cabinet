<?php
declare(strict_types=1);

/**
 * One Tilda checkout must be one payment, with the dollar amount and the ruble equivalent.
 *
 *   php scripts/test-payment-dedupe.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Models\Payment;
use Wwm\Services\PaymentRecorder;

$failed = 0;

function ok(bool $cond, string $label): void
{
    global $failed;
    if ($cond) {
        echo "[ok] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
}

function memoryPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  email TEXT NOT NULL
);
CREATE TABLE payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  avo_account_id TEXT NOT NULL,
  course_slug TEXT NOT NULL,
  id_goods INTEGER,
  amount REAL,
  currency TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'avo',
  ordered_at TEXT,
  paid_at TEXT,
  utm_source TEXT,
  utm_medium TEXT,
  utm_campaign TEXT,
  utm_term TEXT,
  utm_content TEXT,
  ad_snapshot TEXT,
  avo_contact_id INTEGER,
  amount_original REAL,
  currency_original TEXT NOT NULL DEFAULT '',
  fx_rate REAL,
  refunded_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE(avo_account_id, course_slug)
);
SQL);
    $pdo->exec("INSERT INTO users (id, email) VALUES (1039, 'buyer@example.com')");

    return $pdo;
}

/**
 * @param array<string, mixed> $extra
 */
function paymentRow(PDO $pdo, array $extra): void
{
    $base = [
        'user_id' => 1039,
        'avo_account_id' => '10518',
        'course_slug' => 'elke-en',
        'id_goods' => 188,
        'amount' => 5695.84,
        'currency' => 'RUB',
        'source' => 'tilda',
        'ordered_at' => '2026-11-22T17:22:00+00:00',
        'paid_at' => '2026-11-22T17:22:00+00:00',
        'utm_source' => 'ig_fb',
        'utm_medium' => 'lead',
        'utm_campaign' => 'elke_lead',
        'utm_term' => null,
        'utm_content' => null,
        'ad_snapshot' => null,
        'avo_contact_id' => null,
        'amount_original' => 69.3,
        'currency_original' => 'USD',
        'fx_rate' => 82.191,
    ];
    Payment::upsert($pdo, array_merge($base, $extra));
}

$pdo = memoryPdo();
paymentRow($pdo, []);
paymentRow($pdo, [
    'avo_account_id' => 'tilda:order:1385245952',
    'amount' => null,
    'currency' => '',
    'fx_rate' => null,
]);
$removed = Payment::collapseAliasDuplicates($pdo);
$rows = Payment::forUser($pdo, 1039);
ok($removed === 1, 'existing alias pair collapses to one row');
ok(count($rows) === 1, 'student keeps a single payment');
ok((string)($rows[0]['avo_account_id'] ?? '') === '10518', 'AVO invoice id stays');
ok(abs((float)$rows[0]['amount_original'] - 69.3) < 0.001, 'dollar amount stays');
ok(abs((float)$rows[0]['amount'] - 5695.84) < 0.001, 'ruble equivalent stays');
$lines = Payment::amountDisplayLines($rows[0]);
ok($lines['primary'] === '$69.30', 'primary line is the charge currency');
ok($lines['secondary'] === '5 695.84 ₽', 'secondary line is the ruble equivalent');
ok($lines['estimated'] === false, 'ruble line is the stored equivalent');
$agg = Payment::aggregateRevenue($rows);
ok((int)$agg['payment_count'] === 1, 'dashboard counts one purchase');
ok(abs((float)$agg['tilda_total'] - 69.3) < 0.001, 'dashboard dollars are not doubled');
ok(abs((float)$agg['rub_total'] - 5695.84) < 0.001, 'dashboard rubles are not doubled');

$live = memoryPdo();
$paidAt = '2026-11-22T17:22:00+00:00';
$tilda = [
    'source' => 'tilda',
    'email' => 'buyer@example.com',
    'id_account' => 'tilda:order:1385245952',
    'id_goods' => 188,
    'amount_original' => 69.3,
    'currency_original' => 'USD',
    'utm_source' => 'ig_fb',
    'utm_medium' => 'lead',
    'utm_campaign' => 'elke_lead',
];
$avo = [
    'source' => 'tilda',
    'email' => 'buyer@example.com',
    'id_account' => '10518',
    'id_goods' => 188,
    'amount_original' => 69.3,
    'currency_original' => 'USD',
    'amount_rub' => 5695.84,
    'fx_rate' => 82.191,
    'utm_source' => 'ig_fb',
    'utm_medium' => 'lead',
    'utm_campaign' => 'elke_lead',
];
PaymentRecorder::recordFromWebhook($live, 1039, 'elke-en', 'tilda', $tilda, $paidAt, $paidAt, null);
PaymentRecorder::recordFromWebhook($live, 1039, 'elke-en', 'tilda', $avo, $paidAt, $paidAt, null);
$after = Payment::forUser($live, 1039);
ok(count($after) === 1, 'second notify updates the same payment');
ok((string)$after[0]['avo_account_id'] === '10518', 'later AVO id becomes the order id');
ok(abs((float)$after[0]['amount_original'] - 69.3) < 0.001, 'dollars survive the second notify');
ok(abs((float)$after[0]['amount'] - 5695.84) < 0.001, 'rubles are filled in by the second notify');

$reverse = memoryPdo();
PaymentRecorder::recordFromWebhook($reverse, 1039, 'elke-en', 'tilda', $avo, $paidAt, $paidAt, null);
PaymentRecorder::recordFromWebhook($reverse, 1039, 'elke-en', 'tilda', $tilda, $paidAt, $paidAt, null);
$rev = Payment::forUser($reverse, 1039);
ok(count($rev) === 1, 'Tilda notify after AVO does not add a row');
ok(abs((float)$rev[0]['amount'] - 5695.84) < 0.001, 'rubles stay when the Tilda key arrives second');
ok(abs((float)$rev[0]['amount_original'] - 69.3) < 0.001, 'dollars stay when the Tilda key arrives second');

$other = memoryPdo();
paymentRow($other, []);
paymentRow($other, [
    'avo_account_id' => '10519',
    'course_slug' => 'nono',
    'amount' => 4000,
    'amount_original' => 49,
]);
ok(Payment::collapseAliasDuplicates($other) === 0, 'a second course is a separate payment');
ok(count(Payment::forUser($other, 1039)) === 2, 'different courses both remain');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}

echo "OK\n";
