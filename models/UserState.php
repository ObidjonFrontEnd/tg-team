<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $telegram_id
 * @property string $state
 * @property string|null $context
 * @property string $updated_at
 */
class UserState extends ActiveRecord
{
    public const NONE = '';

    public static function tableName(): string
    {
        return '{{%user_states}}';
    }

    public function rules(): array
    {
        return [
            [['telegram_id', 'state'], 'required'],
            [['telegram_id'], 'integer'],
            [['state'], 'string', 'max' => 64],
            [['context'], 'string'],
        ];
    }

    /** @return array<string,mixed> */
    public function getContextData(): array
    {
        if (!$this->context) {
            return [];
        }
        $decoded = json_decode($this->context, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function get(int $telegramId): ?self
    {
        return self::find()->where(['telegram_id' => $telegramId])->one();
    }

    /** @param array<string,mixed> $context */
    public static function set(int $telegramId, string $state, array $context = []): self
    {
        $model = self::get($telegramId) ?? new self(['telegram_id' => $telegramId]);
        $model->state = $state;
        $model->context = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
        $model->updated_at = date('Y-m-d H:i:s');
        $model->save(false);

        return $model;
    }

    public static function clear(int $telegramId): void
    {
        self::deleteAll(['telegram_id' => $telegramId]);
    }
}
