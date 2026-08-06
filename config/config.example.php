<?php
/**
 * Скопируйте в config.php и заполните значения.
 */
return [
    'app_name' => 'World Watercolor Masters',
    'base_url' => 'https://my.worldwatercolormasters.art',
    'timezone' => 'UTC',

    'db_path' => dirname(__DIR__) . '/data/wwm.sqlite',

    // Случайная строка ≥ 32 символов (сессии, токены сброса пароля)
    'app_secret' => 'CHANGE_ME_LONG_RANDOM_SECRET',

    // Webhooks: AVO autofunnel → GET/POST /api/demo (token in URL or X-WWM-Demo-Token header)
    'webhooks' => [
        'payment_token' => '',
        'demo_token' => '',
        'enabled' => false,
    ],

    // AVO id_goods → course slug (for /api/demo id_goods param)
    'avo_goods_to_course' => [
        188 => 'elke-en',
        191 => 'elke-de',
        193 => 'alvaro',
    ],

    'demo_hours' => 48,

    // Shared password for new demo users (Account → change after login). Empty = random + reset email.
    'demo_default_password' => 'Gh45tyhf',

    // Admin access: is_admin flag in DB and/or email allowlist
    'admin_emails' => ['demo@wwm.test'],

    'log_enabled' => true,
    'log_file' => dirname(__DIR__) . '/data/cabinet.log',
];
