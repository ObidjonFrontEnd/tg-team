<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Uchrashuvni bekor qilishda sabab (izoh) majburiy — kim, qachon va nima uchun bekor qilgani saqlanadi.
 */
class m260807_000011_add_cancel_fields_to_meetings extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%meetings}}', 'cancel_reason', $this->text()->null());
        $this->addColumn('{{%meetings}}', 'cancelled_by', $this->integer()->null());
        $this->addColumn('{{%meetings}}', 'cancelled_at', $this->timestamp()->null());

        $this->addForeignKey(
            'fk-meetings-cancelled_by',
            '{{%meetings}}',
            'cancelled_by',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk-meetings-cancelled_by', '{{%meetings}}');
        $this->dropColumn('{{%meetings}}', 'cancel_reason');
        $this->dropColumn('{{%meetings}}', 'cancelled_by');
        $this->dropColumn('{{%meetings}}', 'cancelled_at');
    }
}
