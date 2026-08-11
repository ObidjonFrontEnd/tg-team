<?php

declare(strict_types=1);

use yii\db\Migration;

/** «Mehmon» roli emojisini 🎫 (chipta/karta) dan 👤 (odam) ga almashtiradi — foydalanuvchi so'rovi bo'yicha. */
class m260811_000001_fix_mehmon_emoji extends Migration
{
    public function safeUp(): void
    {
        $this->update('{{%roles}}', ['emoji' => '👤'], ['code' => 'mehmon']);
    }

    public function safeDown(): void
    {
        $this->update('{{%roles}}', ['emoji' => '🎫'], ['code' => 'mehmon']);
    }
}
