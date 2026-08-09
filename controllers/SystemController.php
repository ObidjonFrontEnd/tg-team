<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\MigrationRunner;
use Yii;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Веб-страница для ручного применения миграций без доступа к консоли сервера.
 * /system/migrate?token=...
 */
class SystemController extends Controller
{
    public $enableCsrfValidation = false;

    private function checkToken(): void
    {
        $expected = Yii::$app->params['system.migrateToken'] ?? '';
        $given = Yii::$app->request->get('token', '');

        if ($expected === '' || !hash_equals((string) $expected, (string) $given)) {
            throw new ForbiddenHttpException('Noto\'g\'ri yoki yo\'q token.');
        }
    }

    public function actionMigrate(): Response
    {
        $this->checkToken();

        $output = '';
        if (Yii::$app->request->isPost) {
            $output = MigrationRunner::up();
        }

        Yii::$app->response->format = Response::FORMAT_HTML;
        $token = htmlspecialchars(Yii::$app->request->get('token', ''));
        $outputHtml = htmlspecialchars($output);

        return $this->asRaw(<<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Migratsiyalar</title></head>
<body style="font-family: monospace; max-width: 800px; margin: 40px auto;">
<h2>Ma'lumotlar bazasi migratsiyalari</h2>
<form method="post" action="/system/migrate?token={$token}">
<button type="submit" style="padding:10px 20px;font-size:16px;">Migratsiyalarni bajarish (migrate/up)</button>
</form>
<pre style="background:#f4f4f4;padding:15px;white-space:pre-wrap;">{$outputHtml}</pre>
</body></html>
HTML);
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
