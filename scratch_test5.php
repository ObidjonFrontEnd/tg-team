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

use app\models\Meeting;
use app\models\User;
use app\services\BotHandler;

$meeting = Meeting::find()->where(['status' => Meeting::STATUS_FINISHED])->orderBy(['id' => SORT_DESC])->one();
$meeting->status = Meeting::STATUS_ATTENDANCE_MARKING;
$meeting->started_at = date('Y-m-d H:i:s'); // hozirgina boshlangan, deb sozlaymiz
$meeting->ended_at = null;
$meeting->save(false);
echo "Meeting {$meeting->id} started_at just set to now: {$meeting->started_at}\n";

$bot = new BotHandler(Yii::$app->telegram);
$method = new ReflectionMethod($bot, 'finishMeeting');
$method->setAccessible(true);

$marker = User::findOne($meeting->group->moderator_user_id);
$method->invoke($bot, 0, 0, $marker, $meeting->id);

$meeting->refresh();
echo "After finishMeeting() immediately: status={$meeting->status}, ended_at=" . var_export($meeting->ended_at, true) . "\n";
