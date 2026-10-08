<?php
declare(strict_types=1);

/**
 * Public demo form + cabinet-form automation entry (no outbound mail).
 *
 *   php scripts/test-demo-lead.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

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

echo "Demo lead form\n";
echo str_repeat('=', 40) . PHP_EOL;

$copy = \Wwm\Services\DemoLead::copy([
    'demo_hours' => 48,
    'demo_lead' => ['title' => '48 hours of access — free.', 'button' => '<b>Send</b>'],
]);
ok($copy['title'] === '48 hours of access — free.', 'custom title');
ok($copy['button'] === 'Send', 'button strips tags');
ok(str_starts_with(\Wwm\Services\DemoLead::copy(['demo_hours' => 72])['title'], '72 '), 'default title uses hours');

ok(\Wwm\Services\DemoLead::originAllowed('https://worldwatercolormasters.art'), 'marketing origin');
ok(\Wwm\Services\DemoLead::originAllowed('https://www.worldwatercolormasters.art'), 'www origin');
ok(\Wwm\Services\DemoLead::originAllowed('http://localhost'), 'local origin');
ok(!\Wwm\Services\DemoLead::originAllowed('https://evil.example'), 'foreign origin rejected');
ok(!\Wwm\Services\DemoLead::originAllowed('http://worldwatercolormasters.art'), 'plain http marketing rejected');
ok(!\Wwm\Services\DemoLead::originAllowed(''), 'empty origin rejected');

$utm = \Wwm\Services\DemoLead::utmFromPost([
    'utm_source' => "  ig\n",
    'utm_medium' => 'cpc',
    'nope' => 'x',
]);
ok(($utm['utm_source'] ?? '') === 'ig' && ($utm['utm_medium'] ?? '') === 'cpc' && !isset($utm['nope']), 'utm sanitized');
ok(\Wwm\Services\DemoLead::thanksUrl() === 'https://worldwatercolormasters.art/thanks', 'default thanks url');
ok(\Wwm\Services\DemoLead::thanksUrl(['thanks_url' => 'https://evil.example/x']) === 'https://worldwatercolormasters.art/thanks', 'thanks url host locked');
ok(str_contains(\Wwm\Services\DemoLeadEmbed::script('elke-en', 'https://my.worldwatercolormasters.art/demo/elke-en', \Wwm\Services\DemoLead::copy([]), false, false), 'pingThanksPage'), 'embed pings thanks');

$js = \Wwm\Services\DemoLeadEmbed::script('elke-en', 'https://my.worldwatercolormasters.art/demo/elke-en', $copy, false, false);
ok(str_contains($js, 'elke-en') && !str_contains($js, '{$cfg}'), 'embed script interpolated');
ok(str_contains($js, 'href$='), 'hash link selector');
ok(str_contains($js, 'insideTildaPopup') && str_contains($js, 'forceInline'), 'inline/tilda mode in embed');
$jsInline = \Wwm\Services\DemoLeadEmbed::script('elke-en', 'https://my.worldwatercolormasters.art/demo/elke-en', $copy, false, false, true);
ok(str_contains($jsInline, '"forceInline":true'), 'force inline flag');

$mode = \Wwm\Models\EmailAutomation::normalizeEntryMode('demo_form');
ok($mode === \Wwm\Models\EmailAutomation::ENTRY_DEMO_FORM, 'demo_form entry mode');
ok(\Wwm\Models\EmailAutomation::entryModeRequiresCourseSlug($mode), 'demo_form requires a course');
ok(\Wwm\Models\EmailAutomation::normalizeEntryMode('nope') === \Wwm\Models\EmailAutomation::ENTRY_DEMO_GRANT, 'unknown mode stays demo_grant');
ok(isset(\Wwm\Models\EmailAutomation::entryModeLabels()[\Wwm\Models\EmailAutomation::ENTRY_DEMO_FORM]), 'editor label exists');

$elke = (new \Wwm\Services\CourseCatalog())->get('elke-en');
ok(is_array($elke) && ($elke['demo_lead']['button'] ?? '') === 'Send me the demo', 'elke-en demo_lead copy');

echo str_repeat('=', 40) . PHP_EOL;
if ($failed > 0) {
    echo "FAILED {$failed}\n";
    exit(1);
}
echo "OK\n";
