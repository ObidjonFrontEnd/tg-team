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
use app\models\User;

function dump(string $label): void {
    $u4 = User::findOne(4);
    $u5 = User::findOne(5);
    $umumiy = Group::findOne(['name' => 'Umumiy Test']);
    echo "[$label] umumiy.moderator_user_id=" . var_export($umumiy->moderator_user_id, true)
        . " user4.active_group_id=" . var_export($u4->active_group_id, true)
        . " user5.active_group_id=" . var_export($u5->active_group_id, true) . "\n";
}

// Bu — AdminController::actionGroupSetModerator'dagi (fix qilingandan keyingi) mantiqning
// aynan o'zi, faqat Telegram xabar yuborishsiz (BotHandler chaqirilmaydi) — DB holatini tekshirish uchun.
function setModerator(int $groupId, int $userId): void {
    $group = Group::findOne($groupId);
    $previousModeratorId = $group->moderator_user_id;
    $group->moderator_user_id = $userId;
    $group->save(false);

    $member = User::findOne($userId);
    if ($member !== null) {
        $member->active_group_id = $group->id;
        $member->save(false);
    }
    if ($previousModeratorId && $previousModeratorId !== $userId) {
        $previousModerator = User::findOne($previousModeratorId);
        if ($previousModerator !== null) {
            if ($group->isUmumiy() && $previousModerator->active_group_id === $group->id) {
                $previousModerator->active_group_id = null;
                $previousModerator->save(false);
            }
        }
    }
}

dump('before any assignment');

$umumiy = Group::findOne(['name' => 'Umumiy Test']);

setModerator($umumiy->id, 4);
dump('after promoting user4');

setModerator($umumiy->id, 5);
dump('after promoting user5 (demoting user4)');

setModerator($umumiy->id, 4);
dump('after re-promoting user4');
