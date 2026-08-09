<?php

return [
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',

    'bot.token' => getenv('BOT_TOKEN') ?: '',
    'bot.username' => getenv('BOT_USERNAME') ?: '',
    'bot.channelId' => getenv('CHANNEL_ID') ?: '',

    'system.migrateToken' => getenv('SYSTEM_MIGRATE_TOKEN') ?: '',

    'admin.login' => getenv('ADMIN_LOGIN') ?: 'admin',
    'admin.password' => getenv('ADMIN_PASSWORD') ?: 'admin123',

    // Vaqt cheklovlarisiz (12 soatlik qoidalarsiz) sinov qilishi mumkin bo'lgan telegram_id'lar, vergul bilan.
    'test.bypassTelegramIds' => getenv('TEST_BYPASS_TELEGRAM_IDS') ?: '',
];
