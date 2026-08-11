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

    // Webhooks: AVO autofunnel → GET/POST /api/demo, /api/payment, /api/mail, GET /api/engagement (same demo_token)
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

    // Paid webhook: send access email from robot@ for these slugs (international WWM).
    'paid_email_slugs' => ['elke-en', 'elke-de', 'alvaro'],

    // AVO API: assign contact tags on login / demo lesson open (for autofunnel conditions)
    'avo' => [
        'enabled' => false,
        'shop_id' => 'bl-school',
        'api_key_get' => '',
        'api_key_set' => '',
        'tags' => [
            'logged_in' => 0,     // id_contact_tag for wwm_logged_in
            'demo_opened' => 0,   // id_contact_tag for wwm_demo_opened
        ],
    ],

    'demo_hours' => 48,
    'magic_link_hours' => 72,

    // Bump on deploy when static assets change (cache bust for cabinet.css).
    'asset_version' => '1',

    // Shared password for new demo users (Account → change after login). Set via WWM_DEMO_DEFAULT_PASSWORD secret on prod.
    'demo_default_password' => 'CHANGE_ME_DEMO_PASSWORD',

    // Show “Email me a sign-in link” on /login (requires working SMTP).
    'magic_link_login' => false,

    // Admin access: is_admin flag in DB and/or email allowlist
    'admin_emails' => ['demo@wwm.test'],

    // Transactional email (password reset, demo access, magic links).
    // Without mail.enabled=true letters are written to data/mail.log only.
    'mail' => [
        'enabled' => false,
        'from_email' => 'robot@worldwatercolormasters.art',
        'from_name' => 'World Watercolor Masters',
        'smtp_host' => 'smtp.spaceweb.ru',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl', // ssl (465) or tls (587)
        'smtp_verify_ssl' => false,
        'smtp_user' => 'robot@worldwatercolormasters.art',
        'smtp_pass' => '',
    ],

    'log_enabled' => true,
    'log_file' => dirname(__DIR__) . '/data/cabinet.log',
];
