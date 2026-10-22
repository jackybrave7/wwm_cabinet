<?php
declare(strict_types=1);

/**
 * HTML click tracking must rewrite entity-encoded hrefs without eating prefixes.
 *
 *   php scripts/test-email-click-rewrite.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Services\EmailTracker;

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

$logo = 'https://worldwatercolormasters.art';
$button = 'https://worldwatercolormasters.art/elke-memmler?promo=SPECWWM4&utm_source=email&utm_medium=x';
$html = '<a href="' . $logo . '">logo</a>'
    . '<a href="https://worldwatercolormasters.art/elke-memmler?promo=SPECWWM4&amp;utm_source=email&amp;utm_medium=x">Get Full Access Now &amp; Save 40%</a>';
$text = $logo . "\n" . $button . "\n";
$links = [
    ['url' => $logo, 'label' => 'logo'],
    ['url' => $logo, 'label' => 'logo-again'],
    ['url' => $button, 'label' => 'sale'],
];

$mail = wwm_config()['mail'] ?? [];
if (!empty($mail['enabled'])) {
    global $config;
    $config['mail']['enabled'] = false;
}

$tracker = EmailTracker::compose(null, 'click-rewrite@example.invalid', 'test_click_rewrite', 'Click rewrite');
$sent = $tracker->deliver($text, $html, $links);
ok($sent, 'deliver logged without SMTP');

$outHtml = (string)$tracker->lastHtmlBody();
$outText = $tracker->lastTextBody();

preg_match_all('#\bhref\s*=\s*("|\')([^"\']*)\1#i', $outHtml, $hrefs);
$values = $hrefs[2] ?? [];
ok(count($values) === 2, 'two hrefs');

$base = rtrim(wwm_base_url(), '/');
$hrefPattern = '#^' . preg_quote($base, '#') . '/t/c/[a-f0-9]{32}$#';
$tokens = [];
foreach ($values as $i => $href) {
    ok((bool)preg_match($hrefPattern, $href), 'href ' . $i . ' is a bare tracked url');
    ok(!str_contains($href, 'elke-memmler') && !str_contains($href, 'promo='), 'href ' . $i . ' has no original tail');
    if (preg_match('#/t/c/([a-f0-9]{32})$#', $href, $m)) {
        $tokens[] = $m[1];
    }
}

ok(count($tokens) === 2 && $tokens[0] !== $tokens[1], 'logo and button use different tokens');

$logoTarget = $tokens !== [] ? EmailTracker::clickTarget($tokens[0]) : null;
$buttonTarget = count($tokens) > 1 ? EmailTracker::clickTarget($tokens[1]) : null;
ok($logoTarget === $logo, 'logo clickTarget is the site root');
ok($buttonTarget === $button, 'button clickTarget keeps &');

ok(substr_count($outText, '/t/c/') === 2, 'text has two tracked urls');
ok(!str_contains($outText, 'elke-memmler?promo'), 'text no longer has the raw button url');
ok(!str_contains($outText, $logo . '/'), 'text did not prefix-replace the logo url');
foreach ($tokens as $token) {
    ok(str_contains($outText, '/t/c/' . $token), 'text contains token ' . $token);
}

$broken = '/t/c/' . $tokens[1] . '/elke-memmler';
ok(preg_match('#^/t/c/([a-f0-9]{32})(?:/.*)?$#', $broken, $route) === 1 && ($route[1] ?? '') === $tokens[1], 'old broken path still yields the token');

$pdo = wwm_pdo();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM email_links WHERE message_id = ?');
$stmt->execute([$tracker->lastMessageId()]);
ok((int)$stmt->fetchColumn() === 2, 'one token per unique url');

echo str_repeat('=', 40) . PHP_EOL;
if ($failed > 0) {
    echo "FAILED {$failed}\n";
    exit(1);
}
echo "OK\n";
