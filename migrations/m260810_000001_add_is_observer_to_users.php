<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Kuzatuvchi (observer) — hech qanday guruhga a'zo bo'lmasdan turib barcha guruhlarning
 * davomat statistikasini ko'ra oladigan foydalanuvchi. Bu guruh darajasidagi rol emas
 * (group_member_roles'ga tegishli emas), shuning uchun to'g'ridan-to'g'ri users jadvalida saqlanadi.
 */
class m260810_000001_add_is_observer_to_users extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%users}}', 'is_observer', $this->boolean()->notNull()->defaultValue(false));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%users}}', 'is_observer');
    }
}
