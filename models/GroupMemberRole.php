<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * Роль участника «по умолчанию» в группе (вне контекста конкретной встречи).
 * Используется как предзаполнение при создании новой встречи.
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property int $role_id
 */
class GroupMemberRole extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%group_member_roles}}';
    }

    public function getRole(): ActiveQuery
    {
        return $this->hasOne(Role::class, ['id' => 'role_id']);
    }

    /**
     * Faqat "haqiqiy" (maxsus) rollarni qaytaradi — «Ishtirokchi» hech qachon bazada saqlanmaydi,
     * u faqat ro'yxat bo'sh bo'lganda ko'rsatiladigan hisoblanadigan (computed) fallback.
     * Shu tufayli "odam ham maxsus rolga ega, ham Ishtirokchi" degan chalkashlik bo'lmaydi.
     *
     * @return int[]
     */
    public static function roleIdsFor(int $groupId, int $userId): array
    {
        $ishtirokchiId = Role::ishtirokchi()?->id;
        $query = self::find()->where(['group_id' => $groupId, 'user_id' => $userId]);
        if ($ishtirokchiId !== null) {
            $query->andWhere(['<>', 'role_id', $ishtirokchiId]);
        }

        return $query->select('role_id')->column();
    }

    /** @return int[] Shu rolga ega barcha a'zolarning user_id'lari (masalan, guruhdagi barcha Kotiblar). */
    public static function userIdsWithRole(int $groupId, int $roleId): array
    {
        return self::find()->where(['group_id' => $groupId, 'role_id' => $roleId])->select('user_id')->column();
    }

    /**
     * roleIdsFor() ning butun guruh uchun bir so'rovda ishlaydigan varianti — a'zolar ro'yxatini
     * chiqarishda (masalan, «Guruh a'zolari») har bir a'zo uchun alohida so'rov yubormaslik uchun.
     *
     * @return array<int,int[]> user_id => [role_id, ...]
     */
    public static function mapForGroup(int $groupId): array
    {
        $ishtirokchiId = Role::ishtirokchi()?->id;
        $query = self::find()->where(['group_id' => $groupId]);
        if ($ishtirokchiId !== null) {
            $query->andWhere(['<>', 'role_id', $ishtirokchiId]);
        }

        $map = [];
        foreach ($query->select(['user_id', 'role_id'])->asArray()->all() as $row) {
            $map[(int) $row['user_id']][] = (int) $row['role_id'];
        }

        return $map;
    }

    public static function toggle(int $groupId, int $userId, int $roleId): void
    {
        $existing = self::find()->where(['group_id' => $groupId, 'user_id' => $userId, 'role_id' => $roleId])->one();
        if ($existing) {
            $existing->delete();
        } else {
            (new self(['group_id' => $groupId, 'user_id' => $userId, 'role_id' => $roleId]))->save(false);
        }
    }
}
