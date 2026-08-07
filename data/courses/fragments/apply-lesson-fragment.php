<?php
declare(strict_types=1);

/**
 * Apply lesson HTML fragments to data/courses/elke-en.json
 * Usage: php data/courses/fragments/apply-lesson-fragment.php 3
 *        php data/courses/fragments/apply-lesson-fragment.php 24
 */

$lessonNum = isset($argv[1]) ? (int)$argv[1] : 0;
if ($lessonNum <= 0) {
    fwrite(STDERR, "Usage: php apply-lesson-fragment.php <lesson-num>\n");
    exit(1);
}

$coursePath = dirname(__DIR__) . '/elke-en.json';
$fragmentPath = __DIR__ . '/elke-en-lesson-' . $lessonNum . '.html';

if (!is_file($fragmentPath)) {
    fwrite(STDERR, "Fragment not found: {$fragmentPath}\n");
    exit(1);
}

$course = json_decode((string)file_get_contents($coursePath), true);
if (!is_array($course)) {
    throw new RuntimeException('Invalid course JSON');
}

$html = trim((string)file_get_contents($fragmentPath));
$updated = false;
foreach ($course['lessons'] as &$lesson) {
    if ((int)($lesson['num'] ?? 0) === $lessonNum) {
        $lesson['html_body'] = $html;
        $updated = true;
        break;
    }
}
unset($lesson);

if (!$updated) {
    fwrite(STDERR, "Lesson {$lessonNum} not found\n");
    exit(1);
}

file_put_contents(
    $coursePath,
    json_encode($course, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Updated lesson {$lessonNum} html_body\n";
