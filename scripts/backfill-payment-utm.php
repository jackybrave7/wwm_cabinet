<?php
declare(strict_types=1);

/**
 * Fill payments.utm_* when all five columns are empty.
 * Order: AvoUtmResolver (contact / email / numeric account), then users.utm_* from the demo form.
 * Existing UTM is left as-is.
 *
 *   php scripts/backfill-payment-utm.php --dry-run
 *   php scripts/backfill-payment-utm.php --apply
 *   php scripts/backfill-payment-utm.php --apply --limit=20
 *
 * Dry-run is the default.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Models\Payment;
use Wwm\Services\AvoUtmResolver;
use Wwm\Services\PaymentUtmExtractor;

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = in_array('--dry-run', $argv ?? [], true) || !$apply;
$limit = 0;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    }
}

$pdo = wwm_pdo();
$sql = "SELECT p.id, p.user_id, p.avo_account_id, p.avo_contact_id, p.course_slug,
               u.email,
               u.utm_source AS user_utm_source,
               u.utm_medium AS user_utm_medium,
               u.utm_campaign AS user_utm_campaign,
               u.utm_term AS user_utm_term,
               u.utm_content AS user_utm_content
        FROM payments p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE COALESCE(p.utm_source, '') = ''
          AND COALESCE(p.utm_medium, '') = ''
          AND COALESCE(p.utm_campaign, '') = ''
          AND COALESCE(p.utm_term, '') = ''
          AND COALESCE(p.utm_content, '') = ''
        ORDER BY p.id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$resolver = new AvoUtmResolver();
$stats = [
    'payments_without_utm' => count($rows),
    'would_fill' => 0,
    'from_avo' => 0,
    'from_first_touch' => 0,
    'still_empty' => 0,
    'written' => 0,
    'errors' => 0,
];

foreach ($rows as $row) {
    if ($limit > 0 && $stats['would_fill'] >= $limit) {
        break;
    }
    $id = (int)$row['id'];
    $email = strtolower(trim((string)($row['email'] ?? '')));
    $accountId = trim((string)($row['avo_account_id'] ?? ''));
    $contactId = (int)($row['avo_contact_id'] ?? 0);

    $payload = [];
    if ($email !== '') {
        $payload['email'] = $email;
    }
    if ($contactId > 0) {
        $payload['id_contact'] = $contactId;
    }
    if ($accountId !== '' && ctype_digit($accountId)) {
        $payload['id_account'] = (int)$accountId;
    }

    $utm = [];
    $origin = 'empty';
    if ($payload !== []) {
        try {
            $utm = cleanUtm($resolver->resolve($payload));
            if ($utm !== []) {
                $origin = 'avo';
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "payment {$id} avo resolve failed: {$e->getMessage()}\n");
        }
    }
    if ($utm === []) {
        $utm = cleanUtm([
            'utm_source' => $row['user_utm_source'] ?? '',
            'utm_medium' => $row['user_utm_medium'] ?? '',
            'utm_campaign' => $row['user_utm_campaign'] ?? '',
            'utm_term' => $row['user_utm_term'] ?? '',
            'utm_content' => $row['user_utm_content'] ?? '',
        ]);
        if ($utm !== []) {
            $origin = 'first_touch';
        }
    }

    if ($utm === []) {
        $stats['still_empty']++;
        continue;
    }

    $stats['would_fill']++;
    $stats[$origin === 'avo' ? 'from_avo' : 'from_first_touch']++;
    echo sprintf(
        "%s payment=%d course=%s origin=%s src=%s\n",
        $dryRun ? 'dry-run' : 'apply',
        $id,
        (string)$row['course_slug'],
        $origin,
        $utm['utm_source'] ?? ''
    );

    if ($dryRun) {
        continue;
    }

    if (Payment::fillEmptyUtm($pdo, $id, $utm, $origin)) {
        $stats['written']++;
        wwm_log(sprintf(
            'payment utm origin=%s src=%s user_id=%d account=%s',
            $origin,
            $utm['utm_source'] ?? '',
            (int)$row['user_id'],
            $accountId
        ));
    }
}

echo $dryRun ? "Dry run (no changes). Use --apply to write.\n" : "Applied.\n";
echo 'would_fill: ' . $stats['would_fill'] . PHP_EOL;
foreach ($stats as $key => $value) {
    if ($key === 'would_fill') {
        continue;
    }
    echo $key . ': ' . $value . PHP_EOL;
}

/**
 * @param array<string, mixed> $utm
 * @return array<string, string>
 */
function cleanUtm(array $utm): array
{
    $out = [];
    foreach (PaymentUtmExtractor::KEYS as $key) {
        $value = trim((string)($utm[$key] ?? ''));
        if ($value !== '') {
            $out[$key] = mb_substr($value, 0, 255);
        }
    }

    return $out;
}
