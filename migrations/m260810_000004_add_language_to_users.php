<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Botning shaxsiy interfeysi (kanal postlari emas — ular umumiy auditoriya uchun,
 * shu bois har doim o'zbek tilida qoladi) endi 3 tilda: 'uz' (lotin, standart),
 * 'uz_cyrl' (kirill), 'ru' (ruscha). Mavjud foydalanuvchilar uchun standart 'uz' —
 * ular uchun hech narsa o'zgarmaydi.
 */
class m260810_000004_add_language_to_users extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%users}}', 'language', $this->string(8)->notNull()->defaultValue('uz'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%users}}', 'language');
    }
}
