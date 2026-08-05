<?php
declare(strict_types=1);

/**
 * Builds data/courses/elke-en.json from AVO training #146 lesson tree.
 * Run: php scripts/build-elke-course.php
 */

$section1 = [
    'Part 1. Analysis of the work. Composition, color, technique',
    'Part 2. Review of materials',
    'List of materials and photo reference',
    'Part 3. Getting started. Drawing',
    'Part 4. Getting started in color',
    'Part 5. Painting the main flower (king)',
    'Part 6. The use of soap in painting',
    'Part 7. The use of napkins',
    'Part 8. Getting started on the second flower',
    'Part 9. Create leaves',
    'Part 10. Working with the background',
    'Part 11. Painting smaller element',
    'Part 12. Painting the vase',
    'Part 13. Bud',
    'Part 14. Painting dried areas',
    'Part 15. Go back to the vase. Periodically working with the background. White ink',
    'Part 16. We paint the background',
    'Part 17. Adjust the flower and leaves.',
    'Part 18. Layer of small leaves',
    'Part 19. Vase. Small parts',
    'Part 20. Final work',
];

$section1Ids = [1442, 1443, 1444, 1445, 1446, 1447, 1448, 1449, 1450, 1451, 1452, 1453, 1454, 1455, 1456, 1457, 1458, 1459, 1460, 1461, 1462];

$section2 = [
    'Part 1. Talking about the picture. Composition, mood, color',
    'Part 2. Review of materials',
    'List of materials and photo reference',
    'Part 3. Pencil drawing',
    'Part 4. Painting in color. Building background, windows, roof',
    'Part 5. We continue to work in color. Drawing the rest of the building',
    'Part 6. Painting the background',
    'Part 7. We are planning a house in the foreground and a well',
    'Part 8. Fill the right side of the picture',
    'Part 9. Outline the back of the alley',
    'Part 10. The road of old stones',
    'Part 11. Painting the street on the left',
    'Part 12. Shape spherical trees in the center',
    'Part 13. Painting the church',
    'Part 14. Painting the trees',
    'Part 15. Create a veil and complete the picture',
];

$section2Ids = [1463, 1464, 1465, 1466, 1467, 1468, 1469, 1470, 1471, 1472, 1473, 1474, 1475, 1476, 1477, 1478];

$extra = [
    ['title' => 'Feedback', 'avo_lesson_id' => 1440, 'kind' => 'feedback'],
    ['title' => 'Bonus', 'avo_lesson_id' => 1441, 'kind' => 'bonus', 'draft' => true],
];

$lessons = [];
$num = 1;

$addLessons = static function (array $titles, array $ids, bool $demoFirst) use (&$lessons, &$num): void {
    foreach ($titles as $i => $title) {
        $lessons[] = [
            'num' => $num,
            'title' => $title,
            'demo' => $demoFirst && $i === 0 && $num === 1,
            'avo_lesson_id' => $ids[$i],
            'video' => [
                'provider' => 'kinescope',
                'embed_url' => 'https://kinescope.io/embed/REPLACE_WITH_REAL_ID',
            ],
            'materials' => [],
        ];
        $num++;
    }
};

$addLessons($section1, $section1Ids, true);
$section1Nums = range(1, count($section1));

$addLessons($section2, $section2Ids, false);
$section2Nums = range(count($section1) + 1, count($section1) + count($section2));

$draftLessons = [];
$feedbackNum = null;

foreach ($extra as $item) {
    if (!empty($item['draft'])) {
        $draftLessons[] = [
            'title' => $item['title'],
            'avo_lesson_id' => $item['avo_lesson_id'],
            'kind' => $item['kind'],
            'request_report' => true,
        ];
        continue;
    }
    $lessons[] = [
        'num' => $num,
        'title' => $item['title'],
        'demo' => false,
        'avo_lesson_id' => $item['avo_lesson_id'],
        'kind' => $item['kind'],
        'video' => [
            'provider' => 'kinescope',
            'embed_url' => 'https://kinescope.io/embed/REPLACE_WITH_REAL_ID',
        ],
        'materials' => [],
    ];
    $feedbackNum = $num;
    $num++;
}

$sections = [
    [
        'title' => 'Lesson 1. Flower in a vase',
        'lessons' => $section1Nums,
    ],
    [
        'title' => 'Lesson 2. Cityscape',
        'lessons' => $section2Nums,
    ],
];

if ($feedbackNum !== null) {
    $sections[] = ['lessons' => [$feedbackNum]];
}

$course = [
    'slug' => 'elke-en',
    'title' => "Elke Memmler's video course 'Watercolor Expressionism'",
    'subtitle' => 'Watercolor expressionism — English dubbing',
    'buy_url' => 'https://worldwatercolormasters.art/elke-memmler',
    'avo_goods_id' => 188,
    'avo_training_id' => 146,
    'cover_image' => 'https://f1.autoweboffice.com/userdata/bl-school/training/launchpro_g1668170383.jpg',
    'demo_hours' => 48,
    'demo_lessons' => 1,
    'sections' => $sections,
    'lessons' => $lessons,
    'draft_lessons' => $draftLessons,
];

$out = dirname(__DIR__) . '/data/courses/elke-en.json';
$json = json_encode($course, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}
file_put_contents($out, $json . "\n");
echo "Wrote {$out} — " . count($lessons) . " lessons\n";
