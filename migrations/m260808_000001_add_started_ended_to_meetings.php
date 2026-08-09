<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Uchrashuvning REJALASHTIRILGAN vaqti (meeting_at) bilan bir qatorda,
 * FAKTIK boshlanish/tugash vaqtini ham yozib boramiz:
 *  - started_at: Moderator/Kotib «✅ Davomat» orqali davomat ekranini birinchi ochgan payt
 *    (uchrashuv haqiqatda boshlangani haqidagi signal).
 *  - ended_at: «🏁 Yakunlash va e'lon qilish» tugmasi bosilgan payt (uchrashuv tugashi).
 */
class m260808_000001_add_started_ended_to_meetings extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%meetings}}', 'started_at', $this->timestamp()->null());
        $this->addColumn('{{%meetings}}', 'ended_at', $this->timestamp()->null());
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%meetings}}', 'started_at');
        $this->dropColumn('{{%meetings}}', 'ended_at');
    }
}
