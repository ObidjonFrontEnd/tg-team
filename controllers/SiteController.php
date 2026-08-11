<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\LoginForm;
use Yii;
use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\Response;

class SiteController extends Controller
{
    public $enableCsrfValidation = false;

    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
            ],
        ];
    }

    /**
     * Bosh sahifa: login qilinmagan bo'lsa — kirish sahifasiga, aks holda boshqaruv paneliga.
     */
    public function actionIndex(): Response
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['site/login']);
        }

        return $this->redirect(['admin/index']);
    }

    public function actionLogin(): Response
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['admin/index']);
        }

        $model = new LoginForm();
        $error = '';

        if (Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post(), '');
            if ($model->login()) {
                return $this->redirect(['admin/index']);
            }
            $error = implode(' ', $model->getFirstErrors());
        }

        return $this->htmlResponse($this->loginPageHtml($error));
    }

    public function actionLogout(): Response
    {
        Yii::$app->user->logout();

        return $this->redirect(['site/login']);
    }

    /**
     * Plesk scheduler har 5 daqiqada shu URL'ni chaqiradi:
     *   https://yourdomain.com/site/ping
     * Bu PHP-FPM worker'larni "issiq" ushlab turadi: opcache sovimaydi,
     * schema FileCache'da yangilanib turadi, DB ulanish tez bo'ladi.
     */
    public function actionPing(): Response
    {
        // DB connection'ni ochadi va schema cache'ni refresh qiladi (agar muddati o'tgan bo'lsa)
        Yii::$app->db->open();

        $resp = Yii::$app->response;
        $resp->format = Response::FORMAT_RAW;
        $resp->content = 'ok';

        return $resp;
    }

    private function loginPageHtml(string $error): string
    {
        $errorHtml = $error ? '<p style="color:#c0392b;margin:0 0 15px;">' . htmlspecialchars($error) . '</p>' : '';

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Kirish</title></head>
<body style="font-family: sans-serif; background:#f4f4f4; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;">
<form method="post" style="background:#fff; padding:30px 40px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.1); min-width:280px;">
<h2 style="margin-top:0;">Boshqaruv paneli</h2>
{$errorHtml}
<div style="margin-bottom:12px;">
<label>Login<br><input type="text" name="username" style="width:100%;padding:8px;box-sizing:border-box;" autofocus></label>
</div>
<div style="margin-bottom:20px;">
<label>Parol<br><input type="password" name="password" style="width:100%;padding:8px;box-sizing:border-box;"></label>
</div>
<button type="submit" style="width:100%;padding:10px;font-size:15px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Kirish</button>
</form>
</body></html>
HTML;
    }

    private function htmlResponse(string $html): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->data = $html;
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
