<?php
declare(strict_types=1);

/**
 * Builds data/automations/post-purchase-cross-sell.v1.json
 * Run: php scripts/build-post-purchase-cross-sell-automation.php
 */

require __DIR__ . '/../app/bootstrap.php';

const WAIT_AFTER_PURCHASE = 2592000; // 30 days
const GAP_BETWEEN_OFFERS = 2678400; // 31 days
const WAIT_BEFORE_REMINDER = 154800; // 43h (48h promo − 5h)
const WAIT_AFTER_REMINDER = 18000; // 5h

/** @var list<array{slug: string, label: string}> */
$courses = [
    ['slug' => 'elke-en', 'label' => 'Elke'],
    ['slug' => 'la-fe', 'label' => 'La Fe'],
    ['slug' => 'alvaro', 'label' => 'Alvaro'],
    ['slug' => 'angus', 'label' => 'Angus'],
];

$nodes = [
    'start' => [
        'type' => 'trigger',
        'label' => 'Entry: after any course purchase',
    ],
    'wait_30d' => [
        'type' => 'delay',
        'seconds' => WAIT_AFTER_PURCHASE,
        'label' => 'Wait 30 days after purchase',
    ],
    'end_done' => [
        'type' => 'end',
        'label' => 'End — cross-sell sequence finished',
    ],
];

$edges = [
    ['from' => 'start', 'to' => 'wait_30d'],
];

$prev = 'wait_30d';

foreach ($courses as $i => $course) {
    $slug = $course['slug'];
    $label = $course['label'];
    $key = str_replace('-', '_', $slug);
    $cond = 'cond_' . $key;
    $offer = 'offer_' . $key;
    $wait43 = 'wait43_' . $key;
    $reminder = 'reminder_' . $key;
    $wait5 = 'wait5_' . $key;
    $gap31 = 'gap31_' . $key;
    $nextCond = $i + 1 < count($courses)
        ? 'cond_' . str_replace('-', '_', $courses[$i + 1]['slug'])
        : 'end_done';

    $nodes[$cond] = [
        'type' => 'condition',
        'condition' => 'has_paid_course',
        'course_slug' => $slug,
        'label' => $label . ' — already purchased?',
    ];
    $nodes[$offer] = [
        'type' => 'send_template',
        'template' => 'sale_crosssell_50_offer',
        'course_slug' => $slug,
        'label' => $label . ' — 50% offer (WWM5, 48h)',
    ];
    $nodes[$wait43] = [
        'type' => 'delay',
        'seconds' => WAIT_BEFORE_REMINDER,
        'label' => $label . ' — wait until 5h before promo ends',
    ];
    $nodes[$reminder] = [
        'type' => 'send_template',
        'template' => 'sale_crosssell_50_reminder',
        'course_slug' => $slug,
        'label' => $label . ' — reminder (5h left)',
    ];
    $nodes[$wait5] = [
        'type' => 'delay',
        'seconds' => WAIT_AFTER_REMINDER,
        'label' => $label . ' — promo window closed',
    ];

    if ($nextCond !== 'end_done') {
        $nodes[$gap31] = [
            'type' => 'delay',
            'seconds' => GAP_BETWEEN_OFFERS,
            'label' => 'Wait 31 days before next course offer',
        ];
    }

    $edges[] = ['from' => $prev, 'to' => $cond];
    $edges[] = ['from' => $cond, 'to' => $nextCond, 'branch' => 'yes'];
    $edges[] = ['from' => $cond, 'to' => $offer, 'branch' => 'no'];
    $edges[] = ['from' => $offer, 'to' => $wait43];
    $edges[] = ['from' => $wait43, 'to' => $reminder];
    $edges[] = ['from' => $reminder, 'to' => $wait5];
    if ($nextCond === 'end_done') {
        $edges[] = ['from' => $wait5, 'to' => 'end_done'];
        $prev = 'end_done';
    } else {
        $edges[] = ['from' => $wait5, 'to' => $gap31];
        $edges[] = ['from' => $gap31, 'to' => $nextCond];
        $prev = $gap31;
    }
}

$definition = [
    'version' => 1,
    'meta' => [
        'title' => 'Post-purchase cross-sell (50% / WWM5)',
        'description' => '30 days after any purchase, then Elke → La Fe → Alvaro → Angus every 31 days; skip owned courses; 48h promo + 5h reminder; marketing unsubscribe.',
        'entry_mode' => 'payment_any',
    ],
    'nodes' => $nodes,
    'edges' => $edges,
];

$path = WWM_ROOT . '/data/automations/post-purchase-cross-sell.v1.json';
$json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}
file_put_contents($path, $json . "\n");
echo "Wrote {$path}\n";
