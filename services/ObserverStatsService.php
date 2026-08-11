<?php

declare(strict_types=1);

namespace app\services;

use app\models\Attendance;
use app\models\Group;
use app\models\Meeting;

/**
 * Kuzatuvchi uchun davomat statistikasini hisoblaydi — WebAppController orqali Telegram
 * Web App'ga (haqiqiy HTML jadval) uzatiladi. Avval bu hisob-kitob BotHandler ichida bot
 * xabari matnini qurish uchun ishlatilgan edi, lekin Telegram xabarida ✅/❌ ustunlarini
 * piksel aniqligida tekislab bo'lmasligi sababli (rangli emoji o'zgaruvchan kenglikda
 * chiziladi) statistika endi web-app'da haqiqiy <table> sifatida ko'rsatiladi.
 */
class ObserverStatsService
{
    public const PERIOD_ALL = 'a';
    public const PERIOD_WEEK = 'w';
    public const PERIOD_MONTH = 'm';

    /** @return string[] */
    public static function periods(): array
    {
        return [self::PERIOD_ALL, self::PERIOD_WEEK, self::PERIOD_MONTH];
    }

    public static function normalizePeriod(string $period): string
    {
        return in_array($period, self::periods(), true) ? $period : self::PERIOD_ALL;
    }

    private static function since(string $period): ?string
    {
        $today = new \DateTime('today');

        return match ($period) {
            self::PERIOD_WEEK => (clone $today)->modify('-' . ((int) $today->format('N') - 1) . ' days')->format('Y-m-d 00:00:00'),
            self::PERIOD_MONTH => $today->format('Y-m-01 00:00:00'),
            default => null,
        };
    }

    /**
     * Guruhning berilgan sanadan (yoki umuman, $since === null bo'lsa) buyongi davomat
     * sonlari, har bir a'zo bo'yicha.
     *
     * @return array<int, array{present:int, absent:int, excused:int}>
     */
    private static function attendanceCounts(int $groupId, ?string $since): array
    {
        $query = Attendance::find()
            ->alias('a')
            ->select(['a.user_id', 'a.status', 'cnt' => 'COUNT(*)'])
            ->innerJoin(['m' => Meeting::tableName()], 'm.id = a.meeting_id')
            ->where(['m.group_id' => $groupId]);
        if ($since !== null) {
            $query->andWhere(['>=', 'm.meeting_at', $since]);
        }

        $stats = [];
        foreach ($query->groupBy(['a.user_id', 'a.status'])->asArray()->all() as $row) {
            $uid = (int) $row['user_id'];
            $stats[$uid] ??= ['present' => 0, 'absent' => 0, 'excused' => 0];
            $stats[$uid][$row['status']] = (int) $row['cnt'];
        }

        return $stats;
    }

    /**
     * @return array{
     *     period: string,
     *     groupId: int,
     *     groups: array<int, array{
     *         id:int, name:string, meetings:int,
     *         members: array<int, array{name:string, present:int, absent:int}>
     *     }>
     * }
     */
    public static function compute(int $groupId, string $period): array
    {
        $period = self::normalizePeriod($period);
        $groups = $groupId > 0
            ? array_filter([Group::findOne($groupId)])
            : Group::find()->where(['<>', 'type', Group::TYPE_UMUMIY])->orderBy(['name' => SORT_ASC])->all();

        $since = self::since($period);

        $result = [];
        foreach ($groups as $group) {
            $members = $group->getMembers()->orderBy(['full_name' => SORT_ASC])->all();
            $stats = self::attendanceCounts($group->id, $since);

            $meetingsQuery = $group->getMeetings()->andWhere(['status' => Meeting::STATUS_FINISHED]);
            if ($since !== null) {
                $meetingsQuery->andWhere(['>=', 'meeting_at', $since]);
            }

            $memberRows = [];
            foreach ($members as $member) {
                $s = $stats[$member->id] ?? ['present' => 0, 'absent' => 0, 'excused' => 0];
                $memberRows[] = [
                    'name' => $member->full_name,
                    'present' => $s['present'],
                    'absent' => $s['absent'] + $s['excused'],
                ];
            }

            $result[] = [
                'id' => $group->id,
                'name' => $group->name,
                'meetings' => $meetingsQuery->count(),
                'members' => $memberRows,
            ];
        }

        return ['period' => $period, 'groupId' => $groupId, 'groups' => $result];
    }

    /** @return array<int, array{id:int, name:string}> Filtr uchun barcha guruhlar («Umumiy»dan tashqari). */
    public static function allGroups(): array
    {
        return array_map(
            static fn (Group $g): array => ['id' => $g->id, 'name' => $g->name],
            Group::find()->where(['<>', 'type', Group::TYPE_UMUMIY])->orderBy(['name' => SORT_ASC])->all()
        );
    }
}
