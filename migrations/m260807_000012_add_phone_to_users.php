<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Telefon raqami — ro'yxatdan o'tishda Telegram "kontakt ulashish" tugmasi orqali olinadi,
 * ishtirokchilar bir-birini tezroq topishi uchun guruh ro'yxatida ko'rsatiladi.
 */
class m260807_000012_add_phone_to_users extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%users}}', 'phone', $this->string(32)->null());
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%users}}', 'phone');
    }
}
