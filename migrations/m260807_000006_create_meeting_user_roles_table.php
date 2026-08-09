<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000006_create_meeting_user_roles_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%meeting_user_roles}}', [
            'id' => $this->primaryKey(),
            'meeting_id' => $this->integer()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'role_id' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-meeting_user_roles-meeting_id',
            '{{%meeting_user_roles}}',
            'meeting_id',
            '{{%meetings}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-meeting_user_roles-user_id',
            '{{%meeting_user_roles}}',
            'user_id',
            '{{%users}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-meeting_user_roles-role_id',
            '{{%meeting_user_roles}}',
            'role_id',
            '{{%roles}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex(
            'idx-meeting_user_roles-unique',
            '{{%meeting_user_roles}}',
            ['meeting_id', 'user_id', 'role_id'],
            true
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%meeting_user_roles}}');
    }
}
