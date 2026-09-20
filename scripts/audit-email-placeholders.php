<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$ids = [
    'demo',
    'paid',
    'reminder_demo_no_login',
    'reminder_demo_no_lesson',
    'reminder_demo_expiring',
    'sale_demo_discount_24h',
    'sale_demo_discount_3h',
    'sale_crosssell_50_offer',
    'sale_crosssell_50_reminder',
    'magic',
    'reset',
];

$newLogo = wwm_email_logo_url();
$oldLogoNeedle = 'f1.autoweboffice.ru/bl-school/Watercolor_masters/World';

echo 'Logo URL (expected): ' . $newLogo . PHP_EOL . PHP_EOL;

foreach ($ids as $id) {
    $vars = \Wwm\Services\EmailTemplateCatalog::variables($id);
    $draft = \Wwm\Services\EmailTemplateCatalog::placeholderDraft($id);
    $ctx = \Wwm\Services\EmailTemplateCatalog::sampleContext($id);

    echo "=== {$id} ===\n";
    echo 'SUBJECT: ' . $draft['subject'] . "\n";

    if ($draft['html'] !== null) {
        $rendered = \Wwm\Services\EmailTemplateCatalog::builtInMessage($id, $ctx);
        $htmlOut = (string)($rendered['html'] ?? '');
        $logoOk = str_contains($htmlOut, $newLogo) && !str_contains($htmlOut, $oldLogoNeedle);
        echo 'LOGO: ' . ($logoOk ? 'new OK' : 'CHECK') . "\n";
        if (str_contains($draft['html'], '{{logo_url}}')) {
            echo "LOGO draft: {{logo_url}} placeholder\n";
        }
    }

    foreach ($vars as $v) {
        $inSubject = str_contains($draft['subject'], $v);
        $inText = str_contains($draft['text'], $v);
        $inHtml = $draft['html'] !== null && str_contains($draft['html'], $v);
        if (!$inSubject && !$inText && !$inHtml) {
            echo "  MISSING: {$v}\n";
        }
    }

    foreach ($ctx as $k => $val) {
        $val = trim((string)$val);
        if ($val === '' || strlen($val) < 8) {
            continue;
        }
        if (
            str_contains($draft['html'] ?? '', $val)
            || str_contains($draft['text'], $val)
            || str_contains($draft['subject'], $val)
        ) {
            echo '  LEAKED sample ' . $k . ': ' . substr($val, 0, 80) . "\n";
        }
    }

    echo "\n";
}
