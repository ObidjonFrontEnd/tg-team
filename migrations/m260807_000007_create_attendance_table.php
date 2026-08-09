<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000007_create_attendance_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%attendance}}', [
            'id' => $this->primaryKey(),
            'meeting_id' => $this->integer()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull(), // present | absent | excused
            'marked_by' => $this->integer()->null(),
            'marked_at' => $this->timestamp()->null(),
        ]);

        $this->addForeignKey(
            'fk-attendance-meeting_id',
            '{{%attendance}}',
            'meeting_id',
            '{{%meetings}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-attendance-user_id',
            '{{%attendance}}',
            'user_id',
            '{{%users}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-attendance-marked_by',
            '{{%attendance}}',
            'marked_by',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
        $this->createIndex('idx-attendance-unique', '{{%attendance}}', ['meeting_id', 'user_id'], true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%attendance}}');
    }
}
