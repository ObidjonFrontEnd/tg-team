<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/helpers/env.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/config/console.php';
new yii\console\Application($config);

use app\models\Group;
use app\models\GroupMember;
use app\models\User;

// 1. Umumiy guruh yaratamiz
$umumiy = Group::findOne(['name' => 'Umumiy Test']);
if ($umumiy === null) {
    $umumiy = new Group([
        'name' => 'Umumiy Test',
        'channel_id' => '-100999',
        'type' => Group::TYPE_UMUMIY,
    ]);
    $umumiy->save(false);
    echo "Created Umumiy group id={$umumiy->id}\n";
} else {
    echo "Umumiy group exists id={$umumiy->id}\n";
}

$user4 = User::findOne(4);
$user5 = User::findOne(5);
echo "user4 active_group_id before=" . var_export($user4->active_group_id, true) . "\n";

// 2. user4 ni Umumiy'ga a'zo qilamiz (agar hali emas)
if (!$umumiy->hasMember(4)) {
    (new GroupMember(['group_id' => $umumiy->id, 'user_id' => 4]))->save(false);
    echo "Added user4 to Umumiy\n";
}
if (!$umumiy->hasMember(5)) {
    (new GroupMember(['group_id' => $umumiy->id, 'user_id' => 5]))->save(false);
    echo "Added user5 to Umumiy\n";
}

// 3. user4 ni group 1 (normal) a'zosi qilamiz (Kotib rolini keyin qo'shamiz)
$group1 = Group::findOne(1);
if (!$group1->hasMember(4)) {
    (new GroupMember(['group_id' => 1, 'user_id' => 4]))->save(false);
    echo "Added user4 to group1\n";
}

echo "Umumiy id={$umumiy->id}\n";
