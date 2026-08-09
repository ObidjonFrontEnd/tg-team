<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property int $group_id
 * @property string $topic
 * @property string $meeting_at
 * @property string $format
 * @property string $status
 * @property int $created_by
 * @property string|null $announced_at
 * @property string|null $results_published_at
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by
 * @property string|null $cancelled_at
 * @property string|null $started_at
 * @property string|null $ended_at
 * @property string $created_at
 */
class Meeting extends ActiveRecord
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ANNOUNCED = 'announced';
    public const STATUS_ATTENDANCE_MARKING = 'attendance_marking';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_OFFLINE = 'offline';
    public const FORMAT_ONLINE = 'online';

    public static function tableName(): string
    {
        return '{{%meetings}}';
    }

    public function rules(): array
    {
        return [
            [['group_id', 'topic', 'meeting_at', 'format', 'created_by'], 'required'],
            [['group_id', 'created_by', 'cancelled_by'], 'integer'],
            [['meeting_at', 'announced_at', 'results_published_at', 'cancelled_at', 'started_at', 'ended_at', 'created_at', 'cancel_reason'], 'safe'],
            [['topic'], 'string', 'max' => 500],
            [['format'], 'in', 'range' => [self::FORMAT_OFFLINE, self::FORMAT_ONLINE]],
            [['status'], 'in', 'range' => [
                self::STATUS_SCHEDULED,
                self::STATUS_ANNOUNCED,
                self::STATUS_ATTENDANCE_MARKING,
                self::STATUS_FINISHED,
                self::STATUS_CANCELLED,
            ]],
        ];
    }

    public function getGroup(): ActiveQuery
    {
        return $this->hasOne(Group::class, ['id' => 'group_id']);
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getMeetingUserRoles(): ActiveQuery
    {
        return $this->hasMany(MeetingUserRole::class, ['meeting_id' => 'id']);
    }

    public function getAttendances(): ActiveQuery
    {
        return $this->hasMany(Attendance::class, ['meeting_id' => 'id']);
    }

    public function formatLabel(): string
    {
        return $this->format === self::FORMAT_ONLINE ? 'Onlayn' : 'Oflayn';
    }

    /**
     * Список участников встречи со всеми их ролями.
     * @return array<int, array{user: User, roles: Role[]}>
     */
    public function getParticipantsWithRoles(): array
    {
        $rows = $this->getMeetingUserRoles()->with(['user', 'role'])->all();

        $result = [];
        foreach ($rows as $row) {
            $uid = $row->user_id;
            if (!isset($result[$uid])) {
                $result[$uid] = ['user' => $row->user, 'roles' => []];
            }
            $result[$uid]['roles'][] = $row->role;
        }

        return $result;
    }

    /** @return Role[] */
    public function getRolesOfUser(int $userId): array
    {
        return array_map(
            fn (MeetingUserRole $r) => $r->role,
            $this->getMeetingUserRoles()->with('role')->andWhere(['user_id' => $userId])->all()
        );
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }
}
