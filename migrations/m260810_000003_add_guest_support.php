<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Mehmon (guest) — ro'yxatdan o'tmagan, botdan foydalanmaydigan, lekin uchrashuv/treningga
 * kelgan odam. U ham `users` jadvalida saqlanadi (is_guest=true, telegram_id=NULL), shunda
 * mavjud Attendance/MeetingUserRole/Texts::* kodining barchasi o'zgarishsiz ishlayveradi.
 * PostgreSQL'da UNIQUE indeks bir nechta NULL qiymatga ruxsat beradi, shuning uchun
 * telegram_id'ni NULL qilish mavjud "idx-users-telegram_id" indeksini buzmaydi.
 */
class m260810_000003_add_guest_support extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%users}}', 'telegram_id', $this->bigInteger()->null());
        $this->addColumn('{{%users}}', 'is_guest', $this->boolean()->notNull()->defaultValue(false));

        $this->insert('{{%roles}}', ['code' => 'mehmon', 'name_uz' => 'Mehmon', 'emoji' => '🎫']);
    }

    public function safeDown(): void
    {
        $this->delete('{{%roles}}', ['code' => 'mehmon']);
        $this->dropColumn('{{%users}}', 'is_guest');
        $this->alterColumn('{{%users}}', 'telegram_id', $this->bigInteger()->notNull());
    }
}
