<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Services\TildaPaymentPayload;

$post = [
    'Email' => 'student@example.com',
    'Name' => 'Ann',
    'payment' => [
        'amount' => '69.3',
        'currency' => 'USD',
        'orderid' => '1413494082',
        'products' => [[
            'name' => "Video course 'Watercolor expressionism' by Elke Memmler",
            'price' => '69.3',
            'quantity' => '1',
        ]],
    ],
    'tranid' => 'ch_3UL2mVJJ5HWMfeeI1dfKmUik',
];

$fail = 0;
$n = TildaPaymentPayload::normalize($post);
if (!TildaPaymentPayload::isNative($post)) {
    echo "FAIL not native\n";
    $fail++;
}
if ((int)$n['id_goods'] !== 188) {
    echo "FAIL id_goods={$n['id_goods']}\n";
    $fail++;
}
$keys = $n['external_keys'];
if (!in_array('tilda:order:1413494082', $keys, true) || !in_array('tilda:tran:ch_3UL2mVJJ5HWMfeeI1dfKmUik', $keys, true)) {
    echo 'FAIL keys ' . json_encode($keys) . "\n";
    $fail++;
}
$de = TildaPaymentPayload::normalize([
    'Email' => 'de@example.com',
    'payment' => [
        'amount' => '0',
        'currency' => 'EUR',
        'orderid' => '99',
        'products' => [[
            'name' => 'Videokurs Aquarell-Expressionismus von Elke Memmler',
            'price' => '0',
            'quantity' => '1',
        ]],
    ],
]);
if ((int)$de['id_goods'] !== 191 || empty($de['is_free'])) {
    echo 'FAIL de ' . json_encode($de) . "\n";
    $fail++;
}
if (!TildaPaymentPayload::isPing([])) {
    echo "FAIL ping\n";
    $fail++;
}
$ready = [
    'source' => 'tilda',
    'email' => 'a@b.c',
    'id_goods' => 188,
    'idempotency_key' => 'tilda:order:1',
];
if (TildaPaymentPayload::isNative($ready) || TildaPaymentPayload::isPing($ready)) {
    echo "FAIL ready json should pass through\n";
    $fail++;
}

echo $fail === 0 ? "OK tilda native parse\n" : "$fail FAIL\n";
exit($fail === 0 ? 0 : 1);
