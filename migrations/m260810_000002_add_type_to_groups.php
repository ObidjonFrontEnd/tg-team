<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Guruh turi: 'normal' — oddiy guruh, 'umumiy' — barcha guruhlardagi ishtirokchilarni
 * avtomatik jamlaydigan maxsus guruh (faqat uning Moderatoriga ko'rinadi, botdagi
 * boshqa hech kimga ko'rsatilmaydi — qarang BotHandler::userGroups()).
 */
class m260810_000002_add_type_to_groups extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%groups}}', 'type', $this->string(16)->notNull()->defaultValue('normal'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%groups}}', 'type');
    }
}
