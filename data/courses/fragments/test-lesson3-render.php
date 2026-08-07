<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$course = json_decode(file_get_contents(dirname(__DIR__) . '/elke-en.json'), true);
foreach ($course['lessons'] as $lesson) {
    if ((int)($lesson['num'] ?? 0) !== 3) {
        continue;
    }
    $html = wwm_lesson_body_html($lesson);
    echo substr($html, 0, 500), PHP_EOL;
    echo 'materials-sheet: ', str_contains($html, 'class="materials-sheet"') ? 'YES' : 'NO', PHP_EOL;
    echo 'materials-items: ', str_contains($html, 'class="materials-items"') ? 'YES' : 'NO', PHP_EOL;
    echo 'reference-panel: ', str_contains($html, 'class="reference-panel"') ? 'YES' : 'NO', PHP_EOL;
    break;
}
