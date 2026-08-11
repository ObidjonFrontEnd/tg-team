<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Attendance;
use app\models\Group;
use app\models\Meeting;
use app\models\User;
use app\services\Texts;
use yii\console\Controller;
use Yii;

/**
 * Единственная регулярная фоновая задача MVP — еженедельный субботний отчёт по посещаемости.
 * Запускается через Plesk «Планировщик задач» (тип «Выполнить PHP-скрипт»):
 *   путь: yii
 *   аргументы: cron/weekly-report
 */
class CronController extends Controller
{
    public function actionWeeklyReport(): int
    {
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $until = date('Y-m-d H:i:s');

        foreach (Group::find()->all() as $group) {
            $this->publishWeeklyReportForGroup($group, $since, $until);
        }

        return 0;
    }

    private function publishWeeklyReportForGroup(Group $group, string $since, string $until): void
    {
        $meetings = $group->getMeetings()
            ->andWhere(['status' => Meeting::STATUS_FINISHED])
            ->andWhere(['between', 'meeting_at', $since, $until])
            ->all();

        if (!$meetings) {
            return;
        }

        $meetingIds = array_map(fn (Meeting $m) => $m->id, $meetings);

        $attendances = Attendance::find()->where(['meeting_id' => $meetingIds])->all();

        $perUser = [];
        $totalPresent = 0;
        $totalAbsent = 0;

        foreach ($attendances as $attendance) {
            $uid = $attendance->user_id;
            if (!isset($perUser[$uid])) {
                $perUser[$uid] = ['present' => 0, 'absent' => 0];
            }
            if (in_array($attendance->status, [Attendance::STATUS_PRESENT, Attendance::STATUS_ONLINE], true)) {
                $perUser[$uid]['present']++;
                $totalPresent++;
            } else {
                $perUser[$uid]['absent']++;
                $totalAbsent++;
            }
        }

        $rows = [];
        foreach ($perUser as $uid => $counts) {
            $user = User::findOne($uid);
            if ($user === null) {
                continue;
            }
            $rows[] = [
                'full_name' => $user->full_name,
                'present' => $counts['present'],
                'absent' => $counts['absent'],
            ];
        }

        $text = Texts::weeklyReport(count($meetings), $totalPresent, $totalAbsent, $rows, $group->isUmumiy());

        Yii::$app->telegram->sendMessage($group->channel_id, $text);
    }
}
