<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 * @property int $role_id
 */
class MeetingUserRole extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%meeting_user_roles}}';
    }

    public function rules(): array
    {
        return [
            [['meeting_id', 'user_id', 'role_id'], 'required'],
            [['meeting_id', 'user_id', 'role_id'], 'integer'],
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

    public function getRole(): ActiveQuery
    {
        return $this->hasOne(Role::class, ['id' => 'role_id']);
    }
}
