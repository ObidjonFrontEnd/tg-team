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

    /** @return int[] */
    public static function roleIdsFor(int $meetingId, int $userId): array
    {
        return self::find()->where(['meeting_id' => $meetingId, 'user_id' => $userId])->select('role_id')->column();
    }

    /**
     * Bitta uchrashuvdagi rolni yoqish/o'chirish — guruh shablonidan mustaqil. Har bir uchrashuvda
     * odamlarning roli (Moderatordan tashqari) har xil bo'lishi mumkin, shuning uchun davomat
     * belgilanayotganda ("Davomat" ekranida) shu yerda to'g'irlanadi, guruh shabloni o'zgarmaydi.
     *
     * Toggle'dan keyin «Ishtirokchi» avtomatik boshqariladi: hech qanday maxsus rol qolmasa —
     * qo'shiladi (ro'yxat bo'sh bo'lib qolmasligi uchun); maxsus rol paydo bo'lsa — olib tashlanadi
     * (rol + Ishtirokchi birga bo'lmasligi uchun, xuddi guruh shablonidagidek).
     */
    public static function toggleAndNormalize(int $meetingId, int $userId, int $roleId): void
    {
        static::toggle($meetingId, $userId, $roleId);

        $ishtirokchiId = Role::ishtirokchi()?->id;
        if ($ishtirokchiId === null) {
            return;
        }

        $current = self::roleIdsFor($meetingId, $userId);
        $hasOtherRole = array_diff($current, [$ishtirokchiId]) !== [];

        if (!$current) {
            (new self(['meeting_id' => $meetingId, 'user_id' => $userId, 'role_id' => $ishtirokchiId]))->save(false);
        } elseif ($hasOtherRole && in_array($ishtirokchiId, $current, true)) {
            self::deleteAll(['meeting_id' => $meetingId, 'user_id' => $userId, 'role_id' => $ishtirokchiId]);
        }
    }

    private static function toggle(int $meetingId, int $userId, int $roleId): void
    {
        $existing = self::find()->where(['meeting_id' => $meetingId, 'user_id' => $userId, 'role_id' => $roleId])->one();
        if ($existing) {
            $existing->delete();
        } else {
            (new self(['meeting_id' => $meetingId, 'user_id' => $userId, 'role_id' => $roleId]))->save(false);
        }
    }
}
