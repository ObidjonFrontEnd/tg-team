<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Базовая роль «Ishtirokchi» (участник) — назначается по умолчанию всем,
 * чтобы у участника без специальной роли (Kotib, Taqdimotchi и т.д.) список ролей не был пустым.
 */
class m260807_000010_add_ishtirokchi_role extends Migration
{
    public function safeUp(): void
    {
        $this->insert('{{%roles}}', ['code' => 'ishtirokchi', 'name_uz' => 'Ishtirokchi', 'emoji' => '👤']);
    }

    public function safeDown(): void
    {
        $this->delete('{{%roles}}', ['code' => 'ishtirokchi']);
    }
}
