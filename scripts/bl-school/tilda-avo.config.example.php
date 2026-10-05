<?php
/**
 * Copy to bl-school: public/api/tilda-avo.config.php (not in git with real secrets).
 */
return [
    'webhook_token' => 'CHANGE_ME',
    'avo_api_key_read' => 'CHANGE_ME',
    'avo_api_key_write' => 'CHANGE_ME',
    'avo_subdomain' => 'bl-school',
    'avo_payment_system_id' => 59,
    'product_map' => [
        'Video course The Soul of Place: Interiors and Exteriors in Watercolor by Alvaro Castagnet' => 193,
        'Video course Watercolor textures by Angus McEwan' => 201,
        'Video course \'Watercolor roses\' by La Fe' => 199,
        'Video course \'Watercolor worlds\' by Alexander Votsmush' => 321,
        'Video course \'Glowing watercolors\' by Nono Garcia' => 329,
        'Video course \'Watercolor expressionism\' by Elke Memmler' => 188,
        'Videokurs Aquarell-Expressionismus von Elke Memmler' => 191,
        'Alvaro Castagnet' => 193,
        'Angus McEwan' => 201,
        'La Fe' => 199,
        'Alexander Votsmush' => 321,
        'Nono Garcia' => 329,
        'Watercolor expressionism' => 188,
        'Aquarell-Expressionismus' => 191,
    ],
    'cabinet_pricing_url' => 'https://my.worldwatercolormasters.art/api/payment/pricing',
    'cabinet_payment_token' => 'CHANGE_ME_SAME_AS_WWM_WEBHOOK_PAYMENT_TOKEN',
    'default_goods_id' => null,
    'log_enabled' => true,
    'log_file' => __DIR__ . '/data/tilda-avo.log',
    'cbr_cache_file' => __DIR__ . '/data/cbr-rates.json',
    'processed_orders_file' => __DIR__ . '/data/processed-orders.json',
    'allow_dry_run' => true,
    'accept_free_orders' => true,
    'force_single_product' => true,
    'utm_channel_map' => [],
];
