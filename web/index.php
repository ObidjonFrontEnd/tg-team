<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../helpers/env.php';

// Plesk'da (SSH/konsol yo'q) getenv() faqat haqiqiy OS muhit o'zgaruvchilarini o'qiydi —
// .env fayli git orqali deploy qilinmaydi (gitignore'da), shuning uchun uni qo'lda serverga
// yuklab, shu yerda yuklaymiz. Docker'da esa docker-compose allaqachon haqiqiy env o'rnatadi,
// safeLoad() ularni bosib o'tmaydi (mavjud qiymatlarni saqlab qoladi).
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

defined('YII_DEBUG') or define('YII_DEBUG', ((string) env('YII_DEBUG', 'false')) === 'true');
defined('YII_ENV') or define('YII_ENV', (string) env('YII_ENV', 'prod'));

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
