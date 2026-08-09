<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $created_at
 */
class GroupMember extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%group_members}}';
    }

    public function rules(): array
    {
        return [
            [['group_id', 'user_id'], 'required'],
            [['group_id', 'user_id'], 'integer'],
        ];
    }

    public function getGroup(): ActiveQuery
    {
        return $this->hasOne(Group::class, ['id' => 'group_id']);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
