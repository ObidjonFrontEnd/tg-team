<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Bir foydalanuvchi bir nechta guruhga a'zo bo'lishi mumkin (group_members — ko'p-ko'pga).
 * Botning asosiy menyusi bir vaqtda faqat bitta guruh kontekstida ishlaydi, shuning uchun
 * foydalanuvchi qaysi guruhni "faol" tanlagani shu ustunda saqlanadi (guruhlar orasida
 * "🔀 Guruhni almashtirish" tugmasi bilan almashtiriladi).
 */
class m260808_000002_add_active_group_to_users extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%users}}', 'active_group_id', $this->integer()->null());
        $this->addForeignKey(
            'fk_users_active_group',
            '{{%users}}',
            'active_group_id',
            '{{%groups}}',
            'id',
            'SET NULL'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_users_active_group', '{{%users}}');
        $this->dropColumn('{{%users}}', 'active_group_id');
    }
}
