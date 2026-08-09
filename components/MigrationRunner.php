<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * Запускает `yii migrate/up` отдельным процессом (console\Controller пишет напрямую в STDOUT,
 * поэтому ob_start() внутри веб-запроса вывод не перехватит — используем shell_exec).
 */
class MigrationRunner
{
    public static function up(): string
    {
        return self::run('migrate/up');
    }

    /** Hali qo'llanilmagan migratsiyalar ro'yxati (bazaga hech narsa yozmaydi, faqat ko'rsatadi). */
    public static function pending(): string
    {
        return self::run('migrate/new');
    }

    private static function run(string $action): string
    {
        $yiiScript = Yii::getAlias('@app/yii');
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($yiiScript) . ' ' . $action . ' --interactive=0 2>&1';

        $output = shell_exec($cmd);

        return $output !== null ? $output : '(chiqish yo\'q, buyruqni bajarib bo\'lmadi)';
    }
}
