<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Роли участника «по умолчанию» в группе (не привязаны к конкретной встрече).
 * Лидер группы назначает их через бота («Guruh a'zolari»); при создании новой встречи
 * они подставляются как предзаполненный выбор в мастере назначения ролей (можно изменить под встречу).
 */
class m260807_000009_create_group_member_roles_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%group_member_roles}}', [
            'id' => $this->primaryKey(),
            'group_id' => $this->integer()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'role_id' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-group_member_roles-group_id',
            '{{%group_member_roles}}',
            'group_id',
            '{{%groups}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-group_member_roles-user_id',
            '{{%group_member_roles}}',
            'user_id',
            '{{%users}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-group_member_roles-role_id',
            '{{%group_member_roles}}',
            'role_id',
            '{{%roles}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->createIndex(
            'idx-group_member_roles-unique',
            '{{%group_member_roles}}',
            ['group_id', 'user_id', 'role_id'],
            true
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%group_member_roles}}');
    }
}
