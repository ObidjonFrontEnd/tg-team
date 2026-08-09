<?php

$host = env('DB_HOST', 'db');
$port = env('DB_PORT', '5432');
$name = env('DB_NAME', 'tgteam');

return [
    'class' => \yii\db\Connection::class,
    'dsn' => "pgsql:host=$host;port=$port;dbname=$name",
    'username' => env('DB_USER', 'tgteam'),
    'password' => env('DB_PASSWORD', 'tgteam'),
    'charset' => 'utf8',

    // CURRENT_TIMESTAMP (created_at va h.k.) server vaqtida emas, Toshkent vaqtida hisoblansin —
    // konteyner OS darajasida vaqt mintaqasini sozlashga bog'liq bo'lmasin uchun har bir sessiyada aniq o'rnatiladi.
    'on afterOpen' => function ($event) {
        $event->sender->createCommand("SET TIME ZONE 'Asia/Tashkent'")->execute();
    },

    // Schema cache options (for production environment)
    //'enableSchemaCache' => true,
    //'schemaCacheDuration' => 60,
    //'schemaCache' => 'cache',
];
