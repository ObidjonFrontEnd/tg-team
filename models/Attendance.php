<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 * @property string $status
 * @property int|null $marked_by
 * @property string|null $marked_at
 */
class Attendance extends ActiveRecord
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED = 'excused';

    public static function tableName(): string
    {
        return '{{%attendance}}';
    }

    public function rules(): array
    {
        return [
            [['meeting_id', 'user_id', 'status'], 'required'],
            [['meeting_id', 'user_id', 'marked_by'], 'integer'],
            [['marked_at'], 'safe'],
            [['status'], 'in', 'range' => [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_EXCUSED]],
        ];
    }

    public function getMeeting(): ActiveQuery
    {
        return $this->hasOne(Meeting::class, ['id' => 'meeting_id']);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getMarkedByUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'marked_by']);
    }

    public static function mark(int $meetingId, int $userId, string $status, int $markedBy): self
    {
        $attendance = self::find()
            ->where(['meeting_id' => $meetingId, 'user_id' => $userId])
            ->one() ?? new self(['meeting_id' => $meetingId, 'user_id' => $userId]);

        $attendance->status = $status;
        $attendance->marked_by = $markedBy;
        $attendance->marked_at = date('Y-m-d H:i:s');
        $attendance->save(false);

        return $attendance;
    }
}
