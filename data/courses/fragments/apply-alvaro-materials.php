<?php
declare(strict_types=1);

$coursePath = dirname(__DIR__) . '/alvaro.json';
$fragmentDir = __DIR__;
$map = [
    3 => $fragmentDir . '/alvaro-lesson-3.html',
    15 => $fragmentDir . '/alvaro-lesson-15.html',
    26 => $fragmentDir . '/alvaro-lesson-26.html',
];

$course = json_decode((string)file_get_contents($coursePath), true);
if (!is_array($course)) {
    fwrite(STDERR, "Invalid alvaro.json\n");
    exit(1);
}

foreach ($course['lessons'] as &$lesson) {
    $num = (int)($lesson['num'] ?? 0);
    if (!isset($map[$num])) {
        continue;
    }
    $html = trim((string)file_get_contents($map[$num]));
    $lesson['html_body'] = $html;
    echo "lesson {$num} " . strlen($html) . " bytes\n";
}
unset($lesson);

file_put_contents(
    $coursePath,
    json_encode($course, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
echo "Wrote {$coursePath}\n";
