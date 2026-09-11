<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$course = json_decode((string)file_get_contents(dirname(__DIR__) . '/alvaro.json'), true);
foreach ([3, 15, 26] as $want) {
    foreach ($course['lessons'] as $lesson) {
        if ((int)($lesson['num'] ?? 0) !== $want) {
            continue;
        }
        $html = wwm_lesson_body_html($lesson);
        $ok = str_contains($html, 'class="materials-sheet"')
            && str_contains($html, '/assets/courses/alvaro/')
            && !str_contains($html, 'thinkific')
            && !str_contains($html, 'autoweboffice');
        echo "lesson {$want}: " . ($ok ? 'OK' : 'FAIL') . ' ' . strlen($html) . "\n";
        if (preg_match('/src="([^"]+)"/', $html, $m)) {
            echo "  img {$m[1]}\n";
        }
        break;
    }
}
