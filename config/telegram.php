<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'webhook_path' => env('TELEGRAM_WEBHOOK_PATH', 'telegram/webhook'),
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
];
