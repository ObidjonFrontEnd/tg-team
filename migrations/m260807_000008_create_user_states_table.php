<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Хранит текущий шаг диалога (FSM) для многошаговых сценариев бота:
 * регистрация, создание встречи, назначение ролей, отметка посещаемости.
 */
class m260807_000008_create_user_states_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%user_states}}', [
            'id' => $this->primaryKey(),
            'telegram_id' => $this->bigInteger()->notNull(),
            'state' => $this->string(64)->notNull(),
            'context' => $this->text()->null(), // JSON с промежуточными данными сценария
            'updated_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx-user_states-telegram_id', '{{%user_states}}', 'telegram_id', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%user_states}}');
    }
}
