<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\MigrationRunner;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Веб-страница для ручного применения миграций без доступа к консоли сервера.
 * /system/migrate — без токена и логина (по прямому запросу пользователя во время аварийного деплоя).
 */
class SystemController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionMigrate(): Response
    {
        $dbOk = $this->checkDbConnection();

        $runOutput = '';
        if ($dbOk && Yii::$app->request->isPost) {
            $runOutput = MigrationRunner::up();
        }

        $pendingOutput = $dbOk ? MigrationRunner::pending() : '';

        Yii::$app->response->format = Response::FORMAT_HTML;
        $dbStatusHtml = $dbOk
            ? '<span style="color:#0a0;font-weight:bold;">true</span> — baza bilan bog\'lanish bor'
            : '<span style="color:#c00;font-weight:bold;">false</span> — bazaga ulanib bo\'lmadi (.env dagi DB_* qiymatlarini tekshiring)';
        $pendingHtml = htmlspecialchars($pendingOutput);
        $runOutputHtml = $runOutput !== '' ? '<h3>Natija:</h3><pre style="background:#f4f4f4;padding:15px;white-space:pre-wrap;">' . htmlspecialchars($runOutput) . '</pre>' : '';
        $button = $dbOk
            ? '<form method="post" action="/system/migrate"><button type="submit" style="padding:10px 20px;font-size:16px;">▶️ Migratsiyalarni boshlash</button></form>'
            : '<p style="color:#c00;">Baza ulanmagani uchun migratsiyani boshlab bo\'lmaydi.</p>';

        return $this->asRaw(<<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Migratsiyalar</title></head>
<body style="font-family: monospace; max-width: 800px; margin: 40px auto;">
<h2>Ma'lumotlar bazasi migratsiyalari</h2>
<p><b>Bazaga ulanish:</b> {$dbStatusHtml}</p>
<h3>Hali qo'llanilmagan migratsiyalar:</h3>
<pre style="background:#f4f4f4;padding:15px;white-space:pre-wrap;">{$pendingHtml}</pre>
{$button}
{$runOutputHtml}
</body></html>
HTML);
    }

    private function checkDbConnection(): bool
    {
        try {
            Yii::$app->db->open();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function asRaw(string $html): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->data = $html;
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
