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
    public function actionWebhook(): Response
    {
        $raw = Yii::$app->request->getRawBody();
        $update = json_decode($raw, true);

        Yii::$app->response->format = Response::FORMAT_JSON;

        if (!is_array($update)) {
            return $this->asJson(['ok' => false]);
        }

        try {
            (new BotHandler(Yii::$app->telegram))->handleUpdate($update);
        } catch (\Throwable $e) {
            Yii::error('Webhook handling failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        return $this->asJson(['ok' => true]);
    }
}
