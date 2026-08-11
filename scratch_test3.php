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
use app\services\BotHandler;

$user = User::findOne(3); // Yalgashov Obidjon, moderator of group 1
$group = Group::findOne($user->active_group_id ?? 1);

$bot = new BotHandler(Yii::$app->telegram);
$method = new ReflectionMethod($bot, 'mainMenuKeyboard');
$method->setAccessible(true);
$rows = $method->invoke($bot, $user, $group);
echo "mainMenuKeyboard for user3/group{$group->id}:\n";
print_r($rows);

$timeMethod = new ReflectionMethod($bot, 'timePickerKeyboard');
$timeMethod->setAccessible(true);
$timeRows = $timeMethod->invoke($bot, 'uz');
echo "timePickerKeyboard:\n";
foreach ($timeRows as $row) {
    foreach ($row as $btn) {
        echo (is_array($btn) ? ($btn['text'] ?? '') : $btn) . ' | ';
    }
    echo "\n";
}

// meetingResults() bilan started_at/ended_at ko'rinishini tekshirish
use app\models\Meeting;
use app\services\Texts;

$meeting = Meeting::find()->where(['status' => Meeting::STATUS_FINISHED])->orderBy(['id' => SORT_DESC])->one();
if ($meeting) {
    echo "\nmeetingResults() for meeting id={$meeting->id} (started_at={$meeting->started_at}, ended_at={$meeting->ended_at}):\n";
    echo Texts::meetingResults($meeting) . "\n";
} else {
    echo "\nNo finished meeting found to test meetingResults() with real started/ended.\n";
}
