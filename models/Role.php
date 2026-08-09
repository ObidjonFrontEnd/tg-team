<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int $id
 * @property string $code
 * @property string $name_uz
 * @property string|null $emoji
 */
class Role extends ActiveRecord
{
    public const CODE_MODERATOR = 'moderator';
    public const CODE_KOTIB = 'kotib';
    public const CODE_JARAYON_EKSPERTI = 'jarayon_eksperti';
    public const CODE_TEXNIK_XODIM = 'texnik_xodim';
    public const CODE_TAQDIMOTCHI = 'taqdimotchi';
    public const CODE_ISHTIROKCHI = 'ishtirokchi';

    public static function tableName(): string
    {
        return '{{%roles}}';
    }

    public function getMeetingUserRoles(): ActiveQuery
    {
        return $this->hasMany(MeetingUserRole::class, ['role_id' => 'id']);
    }

    public function label(): string
    {
        return trim(($this->emoji ? $this->emoji . ' ' : '') . $this->name_uz);
    }

    /** Rol tanlash klaviaturasida ko'rsatiladigan rollar — moderator va ishtirokchi avtomatik beriladi, tanlashda kerak emas. @return Role[] */
    public static function assignable(): array
    {
        return self::find()
            ->where(['not in', 'code', [self::CODE_MODERATOR, self::CODE_ISHTIROKCHI]])
            ->orderBy('id')
            ->all();
    }

    public static function ishtirokchi(): ?self
    {
        return self::find()->where(['code' => self::CODE_ISHTIROKCHI])->one();
    }

    /** Muhimlik tartibi — ro'yxatlarda shu tartibda ko'rsatish uchun (Moderator birinchi, Ishtirokchi oxirida). */
    public static function priority(): array
    {
        return [
            self::CODE_MODERATOR => 1,
            self::CODE_KOTIB => 2,
            self::CODE_JARAYON_EKSPERTI => 3,
            self::CODE_TEXNIK_XODIM => 4,
            self::CODE_TAQDIMOTCHI => 5,
            self::CODE_ISHTIROKCHI => 6,
        ];
    }

    /** @param Role[] $roles */
    public static function bestPriority(array $roles): int
    {
        $priority = self::priority();
        $best = 99;
        foreach ($roles as $role) {
            $best = min($best, $priority[$role->code] ?? 99);
        }

        return $best;
    }

    /** @param Role[] $roles Eng muhim (birinchi) rolning emojisi — qatorni shu bilan boshlash uchun. */
    public static function leadEmoji(array $roles): string
    {
        if (!$roles) {
            return '';
        }
        usort($roles, fn (self $a, self $b) => (self::priority()[$a->code] ?? 99) <=> (self::priority()[$b->code] ?? 99));

        return $roles[0]->emoji ?? '';
    }

    /** @param Role[] $roles Faqat nomlar (emojisiz), muhimlik tartibida, vergul bilan. */
    public static function namesOnly(array $roles): string
    {
        usort($roles, fn (self $a, self $b) => (self::priority()[$a->code] ?? 99) <=> (self::priority()[$b->code] ?? 99));

        return implode(', ', array_map(fn (self $r) => $r->name_uz, $roles));
    }

    /** @param Role[] $roles "{emoji} {F.I.Sh.} — {rol nomlari}" — birinchi navbatda eng muhim rol ikonkasi. */
    public static function formatPerson(string $fullName, array $roles): string
    {
        $icon = self::leadEmoji($roles);

        return trim("{$icon} {$fullName}") . ' — ' . self::namesOnly($roles);
    }
}
