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

    // Webhooks (фаза 2) — пока выключены
    'webhooks' => [
        'payment_token' => '',
        'demo_token' => '',
        'enabled' => false,
    ],

    // Письма (фаза 2). Пока сброс пароля логируется в data/mail.log
    'mail' => [
        'enabled' => false,
        'from_email' => 'courses@bl-school.com',
        'from_name' => 'World Watercolor Masters',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
    ],

    'demo_hours' => 48,

    // Admin access: is_admin flag in DB and/or email allowlist
    'admin_emails' => ['demo@wwm.test'],

    'log_enabled' => true,
    'log_file' => dirname(__DIR__) . '/data/cabinet.log',
];
