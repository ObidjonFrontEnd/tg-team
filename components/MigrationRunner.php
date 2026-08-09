<?php

declare(strict_types=1);

namespace app\components;

use Throwable;
use Yii;
use yii\db\Migration;

/**
 * Migratsiyalarni web-so'rov ichida, alohida process ochmasdan qo'llaydi.
 *
 * Ilgari shell_exec() orqali `php yii migrate/up`ni tashqi process sifatida chaqirardik,
 * lekin ko'p shared-hosting/Plesk muhitlarida xavfsizlik uchun shell_exec/exec o'chirilgan
 * bo'ladi — natijada tugma jim ishlamay qolardi. Shuning uchun migratsiya fayllarini
 * to'g'ridan-to'g'ri shu yerda (joriy PHP so'rovi ichida) qo'llaymiz.
 */
class MigrationRunner
{
    public static function up(): string
    {
        self::ensureMigrationTable();
        $applied = self::findAppliedVersions();
        $pending = self::findPendingMigrationFiles($applied);

        if (!$pending) {
            return "Yangi migratsiyalar yo'q. Hozircha qo'llanilgan: " . count($applied) . " ta.\n";
        }

        $log = [];
        foreach ($pending as $file) {
            $result = self::applyOne($file);
            $log[] = $result;
            if (str_starts_with($result, 'XATOLIK')) {
                break;
            }
        }

        return implode("\n", $log) . "\n";
    }

    /** Hali qo'llanilmagan migratsiyalar ro'yxati — bazaga hech narsa yozmaydi, faqat ko'rsatadi. */
    public static function pending(): string
    {
        self::ensureMigrationTable();
        $applied = self::findAppliedVersions();
        $pending = self::findPendingMigrationFiles($applied);

        if (!$pending) {
            return "Yangi migratsiyalar yo'q. Hozircha qo'llanilgan: " . count($applied) . " ta.\n";
        }

        $names = array_map(static fn (string $file): string => basename($file, '.php'), $pending);

        return "Hali qo'llanilmagan (" . count($names) . " ta):\n" . implode("\n", $names) . "\n";
    }

    /**
     * Oddiy `php yii migrate` konsolda birinchi ishga tushganda shu jadvalni o'zi yaratadi —
     * bu yerda buni qiladigan hech kim yo'q, shuning uchun o'zimiz takrorlaymiz.
     * Jadval allaqachon bor bo'lsa — hech narsaga ta'sir qilmaydi.
     */
    private static function ensureMigrationTable(): void
    {
        $db = Yii::$app->db;
        if ($db->schema->getTableSchema('{{%migration}}', true) !== null) {
            return;
        }

        $db->createCommand()->createTable('{{%migration}}', [
            'version' => 'varchar(180) NOT NULL PRIMARY KEY',
            'apply_time' => 'integer',
        ])->execute();
        $db->createCommand()->insert('{{%migration}}', [
            'version' => 'm000000_000000_base',
            'apply_time' => time(),
        ])->execute();
    }

    /** @return string[] */
    private static function findAppliedVersions(): array
    {
        return Yii::$app->db->createCommand('SELECT version FROM {{%migration}}')->queryColumn();
    }

    /**
     * @param string[] $applied
     * @return string[] Yangi migratsiya fayllari yo'llari, qo'llash tartibida.
     */
    private static function findPendingMigrationFiles(array $applied): array
    {
        $files = glob(Yii::getAlias('@app/migrations') . '/m*.php') ?: [];
        sort($files);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file, '.php'), $applied, true)
        ));
    }

    private static function applyOne(string $file): string
    {
        $className = basename($file, '.php');
        require_once $file;

        /** @var Migration $migration */
        $migration = new $className(['db' => Yii::$app->db]);
        $transaction = Yii::$app->db->beginTransaction();

        // yii\db\Migration progressni echo orqali chiqaradi (konsol uchun mo'ljallangan) —
        // web-so'rov ichida bu javobning bir qismini muddatidan oldin yuborib yuborardi
        // va "headers already sent" xatosiga olib kelardi. Shu sabab ob_start bilan ushlaymiz.
        ob_start();

        try {
            $result = $migration->safeUp();
            $printed = trim((string) ob_get_clean());

            if ($result === false) {
                $transaction->rollBack();

                return "XATOLIK {$className}: safeUp() false qaytardi\n{$printed}";
            }

            Yii::$app->db->createCommand()->insert('{{%migration}}', [
                'version' => $className,
                'apply_time' => time(),
            ])->execute();
            $transaction->commit();

            return "OK {$className}\n{$printed}";
        } catch (Throwable $e) {
            $printed = trim((string) ob_get_clean());
            $transaction->rollBack();

            return "XATOLIK {$className}: {$e->getMessage()}\n{$printed}";
        }
    }
}
