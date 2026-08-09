<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property string $name
 * @property string $channel_id
 * @property int|null $moderator_user_id
 * @property string $created_at
 */
class Group extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%groups}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'channel_id'], 'required'],
            [['moderator_user_id'], 'integer'],
            [['name'], 'string', 'max' => 255],
            [['channel_id'], 'string', 'max' => 64],
        ];
    }

    public function getModerator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'moderator_user_id']);
    }

    public function getGroupMembers(): ActiveQuery
    {
        return $this->hasMany(GroupMember::class, ['group_id' => 'id']);
    }

    public function getMembers(): ActiveQuery
    {
        return $this->hasMany(User::class, ['id' => 'user_id'])->via('groupMembers');
    }

    public function getMeetings(): ActiveQuery
    {
        return $this->hasMany(Meeting::class, ['group_id' => 'id']);
    }

    public function hasMember(int $userId): bool
    {
        return $this->getGroupMembers()->andWhere(['user_id' => $userId])->exists();
    }
}
