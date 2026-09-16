<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$broken = '<table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" align="center" '
    . 'style="margin:8px auto 0;border-radius:8px;background:#e63027;">'
    . '<tr><td align="center" style="border-radius:8px;background:#e63027;">'
    . '< a href="https://example.com/buy" target="_blank" '
    . 'style="display:inline-block;padding:16px 32px;font-size:17px;font-weight:700;color:#ffffff;'
    . 'text-decoration:none;font-family:Arial,Helvetica,sans-serif;">'
    . 'Get Full Access Now & Save 40%</a></td></tr></table>';

$repaired = wwm_repair_email_html($broken);
$issues = wwm_email_html_issues($repaired);

echo "Broken CTA repair test\n";
echo str_contains((string)$repaired, '< a href=') ? "[FAIL] still has spaced anchor\n" : "[ok] spaced anchor fixed\n";
echo str_contains((string)$repaired, 'class="btn"') && str_contains((string)$repaired, '<a href="https://example.com/buy"') ? "[ok] button rebuilt\n" : "[FAIL] button missing\n";
echo $issues === [] ? "[ok] no layout issues\n" : "[FAIL] " . implode(', ', $issues) . "\n";

$ctx = \Wwm\Services\EmailTemplateCatalog::sampleContext('demo');
$ctx['course_title'] = "Elke Memmler's video course 'Watercolor Expressionism'";
$demoHtml = (string)(\Wwm\Services\EmailTemplateRenderer::render('demo', $ctx)['html'] ?? '');
echo "\nDemo title with apostrophes\n";
echo str_contains($demoHtml, '< /strong>') ? "[FAIL] broken strong close visible\n" : "[ok] no broken strong close\n";
echo str_contains($demoHtml, '<span style="font-weight:700;">Elke Memmler&#039;s') ? "[ok] course title escaped in span\n" : "[FAIL] course title span missing\n";
echo str_contains($demoHtml, '<span style="color:#ffffff!important;') ? "[ok] button label span\n" : "[FAIL] button label span missing\n";
