<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000001_create_users_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%users}}', [
            'id' => $this->primaryKey(),
            'telegram_id' => $this->bigInteger()->notNull(),
            'telegram_username' => $this->string(255)->null(),
            'full_name' => $this->string(255)->notNull(),
            'position' => $this->string(255)->null(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->createIndex('idx-users-telegram_id', '{{%users}}', 'telegram_id', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%users}}');
    }
}
