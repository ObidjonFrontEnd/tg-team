<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000005_create_meetings_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%meetings}}', [
            'id' => $this->primaryKey(),
            'group_id' => $this->integer()->notNull(),
            'topic' => $this->string(500)->notNull(),
            'meeting_at' => $this->timestamp()->notNull(),
            'format' => $this->string(16)->notNull(), // offline | online
            'status' => $this->string(20)->notNull()->defaultValue('scheduled'),
            // scheduled -> announced -> attendance_marking -> finished -> cancelled
            'created_by' => $this->integer()->notNull(),
            'announced_at' => $this->timestamp()->null(),
            'results_published_at' => $this->timestamp()->null(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->addForeignKey(
            'fk-meetings-group_id',
            '{{%meetings}}',
            'group_id',
            '{{%groups}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-meetings-created_by',
            '{{%meetings}}',
            'created_by',
            '{{%users}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%meetings}}');
    }
}
