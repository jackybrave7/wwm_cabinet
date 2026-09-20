<?php
declare(strict_types=1);

/**
 * Inserts or updates the post-purchase cross-sell automation from JSON.
 * Run: php scripts/build-post-purchase-cross-sell-automation.php && php scripts/seed-post-purchase-cross-sell-automation.php
 */

require __DIR__ . '/../app/bootstrap.php';

use Wwm\Models\EmailAutomation;

$slug = 'post-purchase-cross-sell';
$path = WWM_ROOT . '/data/automations/post-purchase-cross-sell.v1.json';
if (!is_readable($path)) {
    fwrite(STDERR, "Missing {$path} — run build-post-purchase-cross-sell-automation.php first.\n");
    exit(1);
}

$definition = file_get_contents($path);
if ($definition === false || trim($definition) === '') {
    fwrite(STDERR, "Empty definition file.\n");
    exit(1);
}

$pdo = wwm_pdo();
$stmt = $pdo->prepare('SELECT id FROM email_automations WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$existingId = $stmt->fetchColumn();

$now = gmdate('c');
$title = 'Post-purchase cross-sell (Elke → La Fe → Alvaro → Angus)';
$description = '30 days after any paid course: sequential 50% offers (WWM5, 48h) with 5h reminder; skips courses already purchased. Off by default — enable in admin.';

if ($existingId) {
    $pdo->prepare(
        'UPDATE email_automations SET title = ?, description = ?, course_slug = ?, entry_mode = ?, definition_json = ?, updated_at = ? WHERE id = ?'
    )->execute([
        $title,
        $description,
        '',
        EmailAutomation::ENTRY_PAYMENT_ANY,
        $definition,
        $now,
        (int)$existingId,
    ]);
    echo "Updated automation id=" . (int)$existingId . " slug={$slug}\n";
    exit(0);
}

EmailAutomation::create($pdo, [
    'slug' => $slug,
    'title' => $title,
    'description' => $description,
    'course_slug' => '',
    'entry_mode' => EmailAutomation::ENTRY_PAYMENT_ANY,
    'is_active' => false,
    'definition_json' => $definition,
]);

echo "Created automation slug={$slug} (inactive). Enable in /admin/automations.\n";
