<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'telegram_drive',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        'username' => getenv('ADMIN_USERNAME') ?: 'admin',
        'password' => getenv('ADMIN_PASSWORD') ?: 'admin',
    ],
    'telegram' => [
        'bot_token' => getenv('TG_BOT_TOKEN') ?: '',
        'chat_id' => getenv('TG_CHAT_ID') ?: '',
    ],
    'limits' => [
        'max_upload_bytes' => 5 * 1024 * 1024,
        'public_hourly' => 30,
        'api_hourly' => 200,
    ],
];
