<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property int|null $telegram_id
 * @property string|null $telegram_username
 * @property string $full_name
 * @property string|null $position
 * @property string|null $phone
 * @property int|null $active_group_id
 * @property bool $is_observer
 * @property bool $is_guest
 * @property string $created_at
 */
class User extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%users}}';
    }

    public function rules(): array
    {
        return [
            [['telegram_id'], 'required', 'when' => fn (self $model) => !$model->is_guest],
            [['full_name'], 'required'],
            [['telegram_id', 'active_group_id'], 'integer'],
            [['telegram_username', 'full_name', 'position'], 'string', 'max' => 255],
            [['phone'], 'string', 'max' => 32],
            [['is_observer', 'is_guest'], 'boolean'],
        ];
    }

    public function getGroupMembers(): ActiveQuery
    {
        return $this->hasMany(GroupMember::class, ['user_id' => 'id']);
    }

    public function getGroups(): ActiveQuery
    {
        return $this->hasMany(Group::class, ['id' => 'group_id'])->via('groupMembers');
    }

    public function getActiveGroup(): ActiveQuery
    {
        return $this->hasOne(Group::class, ['id' => 'active_group_id']);
    }

    public static function findByTelegramId(int $telegramId): ?self
    {
        return self::find()->where(['telegram_id' => $telegramId])->one();
    }

    public static function findOrCreateByTelegramId(int $telegramId, ?string $username = null): self
    {
        $user = self::findByTelegramId($telegramId);
        if ($user === null) {
            $user = new self();
            $user->telegram_id = $telegramId;
            $user->full_name = $username ?: ('user' . $telegramId);
        }
        if ($username !== null) {
            $user->telegram_username = $username;
        }
        $user->save(false);

        return $user;
    }

    public function isRegistered(): bool
    {
        return !empty($this->position);
    }

    /**
     * Mehmon — botdan foydalanmaydigan, ro'yxatdan o'tmagan, faqat ismi bilan bitta
     * uchrashuvga «Mehmon» rolida qo'shiladigan odam (qarang: Role::CODE_MEHMON).
     */
    public static function createGuest(string $fullName): self
    {
        $guest = new self([
            'full_name' => $fullName,
            'is_guest' => true,
        ]);
        $guest->save(false);

        return $guest;
    }
}
