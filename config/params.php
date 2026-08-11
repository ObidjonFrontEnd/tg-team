<?php

return [
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',

    'bot.token' => env('BOT_TOKEN', ''),
    'bot.username' => env('BOT_USERNAME', ''),
    'bot.channelId' => env('CHANNEL_ID', ''),

    // Telegram Web App (Mini App) tugmalari uchun to'liq https bazaviy manzil
    // (masalan https://team.megabuild.uz yoki mahalliy ngrok domeni).
    'app.baseUrl' => env('APP_BASE_URL', ''),

    'system.migrateToken' => env('SYSTEM_MIGRATE_TOKEN', ''),

    'admin.login' => env('ADMIN_LOGIN', 'admin'),
    'admin.password' => env('ADMIN_PASSWORD', 'admin123'),

    // Vaqt cheklovlarisiz (12 soatlik qoidalarsiz) sinov qilishi mumkin bo'lgan telegram_id'lar, vergul bilan.
    'test.bypassTelegramIds' => env('TEST_BYPASS_TELEGRAM_IDS', ''),
];
