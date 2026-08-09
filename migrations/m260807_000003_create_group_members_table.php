<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000003_create_group_members_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%group_members}}', [
            'id' => $this->primaryKey(),
            'group_id' => $this->integer()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->addForeignKey(
            'fk-group_members-group_id',
            '{{%group_members}}',
            'group_id',
            '{{%groups}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-group_members-user_id',
            '{{%group_members}}',
            'user_id',
            '{{%users}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex('idx-group_members-unique', '{{%group_members}}', ['group_id', 'user_id'], true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%group_members}}');
    }
}
