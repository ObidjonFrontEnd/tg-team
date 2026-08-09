<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000002_create_groups_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%groups}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'channel_id' => $this->string(64)->notNull(),
            'moderator_user_id' => $this->integer()->null(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->addForeignKey(
            'fk-groups-moderator_user_id',
            '{{%groups}}',
            'moderator_user_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk-groups-moderator_user_id', '{{%groups}}');
        $this->dropTable('{{%groups}}');
    }
}
