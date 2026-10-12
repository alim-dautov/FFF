<?php
declare(strict_types=1);

return [
    'driver' => 'sqlite',
    'sqlite_path' => '',
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'foodforfit',
        'user' => '',
        'password' => '',
    ],
    'grok_api_key' => '',
    'grok_model' => 'grok-4',
    'whatsapp_token' => '',
    'whatsapp_phone_id' => '',
    'kaspi_api_key' => '',
    'kaspi_webhook_secret' => '',
    'vapid_public' => '',
    'vapid_private' => '',
    'vapid_subject' => 'mailto:admin@foodforfit.kz',
    'app_url' => 'http://localhost:5173',
    'debug' => true,
    'payment_provider' => 'manual',
];
