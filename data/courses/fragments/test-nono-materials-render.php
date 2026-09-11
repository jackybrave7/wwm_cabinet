<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$course = json_decode((string)file_get_contents(dirname(__DIR__) . '/nono.json'), true);
foreach ([2, 8, 12] as $want) {
    foreach ($course['lessons'] as $lesson) {
        if ((int)($lesson['num'] ?? 0) !== $want) {
            continue;
        }
        $html = wwm_lesson_body_html($lesson);
        $ok = str_contains($html, 'class="materials-sheet"')
            && str_contains($html, '/assets/courses/nono/')
            && !str_contains($html, 'thinkific')
            && !str_contains($html, 'autoweboffice');
        echo "lesson {$want}: " . ($ok ? 'OK' : 'FAIL') . ' ' . strlen($html) . "\n";
        preg_match_all('/src="([^"]+)"/', $html, $m);
        foreach ($m[1] as $src) {
            echo "  img {$src}\n";
        }
        break;
    }
}
