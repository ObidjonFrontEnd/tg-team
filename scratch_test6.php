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
use app\models\Group;
use app\models\GroupMemberRole;
use app\models\Role;
use app\services\BotHandler;

$group = Group::findOne(1);

$meeting = new Meeting([
    'group_id' => $group->id,
    'topic' => 'Auto-start test',
    'meeting_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
    'format' => Meeting::FORMAT_OFFLINE,
    'status' => Meeting::STATUS_ANNOUNCED,
    'created_by' => $group->moderator_user_id,
]);
$meeting->save(false);
echo "Created meeting id={$meeting->id}, group={$group->id}, moderator={$group->moderator_user_id}, meeting_at={$meeting->meeting_at}\n";

$kotibRoleId = Role::find()->where(['code' => Role::CODE_KOTIB])->select('id')->scalar();
echo "Kotib role id={$kotibRoleId}, kotib user ids in group1: " . implode(',', GroupMemberRole::userIdsWithRole($group->id, (int) $kotibRoleId)) . "\n";

$bot = new BotHandler(Yii::$app->telegram);
$started = $bot->autoStartDueMeetings();
echo "autoStartDueMeetings() started count={$started}\n";

$meeting->refresh();
echo "Meeting after: status={$meeting->status}, started_at={$meeting->started_at}\n";

// Ikkinchi marta chaqirsak, endi due yo'q (allaqachon boshlangan) - qayta boshlanmasligi kerak
$started2 = $bot->autoStartDueMeetings();
echo "Second call started count={$started2} (should be 0)\n";

// Tozalash
$meeting->delete();
echo "Test meeting deleted.\n";
