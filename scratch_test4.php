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

use app\models\Attendance;
use app\models\Meeting;
use app\services\BotHandler;
use app\services\Texts;

$meeting = Meeting::find()->where(['status' => Meeting::STATUS_FINISHED])->orderBy(['id' => SORT_DESC])->one();
echo "Using meeting id={$meeting->id}\n";

$bot = new BotHandler(Yii::$app->telegram);
$method = new ReflectionMethod($bot, 'cycleAttendance');
$method->setAccessible(true);

$marker = \app\models\User::findOne($meeting->group->moderator_user_id);
$participant = array_key_first($meeting->getParticipantsWithRoles());

echo "Cycling attendance for user_id={$participant}:\n";
for ($i = 0; $i < 5; $i++) {
    $method->invoke($bot, 0, 0, $marker, $meeting->id, $participant);
    $att = Attendance::find()->where(['meeting_id' => $meeting->id, 'user_id' => $participant])->one();
    echo "  status={$att->status}\n";
}

// Onlayn holatiga qo'yamiz va meetingResults() ni tekshiramiz
Attendance::mark($meeting->id, $participant, Attendance::STATUS_ONLINE, $marker->id);
echo "\nmeetingResults() with online status:\n";
echo Texts::meetingResults($meeting) . "\n";

echo "\nattendanceRowStatus for online (uz/uz_cyrl/ru):\n";
echo Texts::attendanceRowStatus('uz', 'online') . "\n";
echo Texts::attendanceRowStatus('uz_cyrl', 'online') . "\n";
echo Texts::attendanceRowStatus('ru', 'online') . "\n";
