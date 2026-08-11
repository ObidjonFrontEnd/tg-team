<?php

declare(strict_types=1);

namespace app\controllers;

use app\services\BotHandler;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class BotController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * Единственная точка входа для апдейтов Telegram (вебхук).
     * Настраивается один раз: https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://.../bot/webhook
     */
    public function actionWebhook(): void
    {
        $raw = Yii::$app->request->getRawBody();
        $update = json_decode($raw, true);

        // 200 OK ni darhol Telegramga yuboramiz — shunda Telegram 5s timeout'da retry
        // qilmaydi, hatto bizning handler sekin ishlasa ham. fastcgi_finish_request() TCP
        // ulanishini yopadi, lekin PHP jarayoni ishlashda davom etadi.
        $resp = Yii::$app->response;
        $resp->format = Response::FORMAT_RAW;
        $resp->headers->set('Content-Type', 'application/json');
        $resp->content = '{"ok":true}';
        $resp->send();
        // Yii2 output buffer'ini ham tozalaymiz, keyin ulanishni yopamiz
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if (!is_array($update)) {
            return;
        }

        try {
            (new BotHandler(Yii::$app->telegram))->handleUpdate($update);
        } catch (\Throwable $e) {
            Yii::error('Webhook handling failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}
