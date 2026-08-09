<?php

declare(strict_types=1);

use yii\db\Migration;

class m260807_000004_create_roles_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%roles}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull(),
            'name_uz' => $this->string(128)->notNull(),
            'emoji' => $this->string(8)->null(),
        ]);

        $this->createIndex('idx-roles-code', '{{%roles}}', 'code', true);

        $this->batchInsert('{{%roles}}', ['code', 'name_uz', 'emoji'], [
            ['moderator', 'Moderator', '👑'],
            ['kotib', 'Kotib', '📝'],
            ['jarayon_eksperti', 'Jarayon eksperti', '💡'],
            ['texnik_xodim', 'Texnik xodim', '🛠'],
            ['taqdimotchi', 'Taqdimotchi', '🎤'],
        ]);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%roles}}');
    }
}
