<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Component;

/**
 * Лёгкий клиент Telegram Bot API поверх cURL — без стороннего SDK,
 * чтобы не тащить лишние зависимости ради нескольких методов (sendMessage, answerCallbackQuery, editMessageText).
 */
class TelegramApi extends Component
{
    public string $token = '';

    private function endpoint(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $method, array $params = []): array
    {
        if (!$this->token) {
            \Yii::warning('TelegramApi: BOT_TOKEN is not configured, skipping call to ' . $method);

            return ['ok' => false, 'description' => 'no token configured'];
        }

        $ch = curl_init($this->endpoint($method));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            \Yii::error("TelegramApi error calling {$method}: {$error}");

            return ['ok' => false, 'description' => $error];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || empty($decoded['ok'])) {
            \Yii::warning("TelegramApi {$method} responded with error: {$response}");
        }

        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'invalid json'];
    }

    /**
     * @param array<int, array<int, array<string,mixed>>>|null $inlineKeyboard
     */
    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null, ?array $replyKeyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        } elseif ($replyKeyboard !== null) {
            $params['reply_markup'] = json_encode(
                ['keyboard' => $replyKeyboard, 'resize_keyboard' => true],
                JSON_UNESCAPED_UNICODE
            );
        }

        return $this->call('sendMessage', $params);
    }

    /** @param array<int, array<int, array<string,mixed>>> $inlineKeyboard */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $inlineKeyboard): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        return $this->call('editMessageText', $params);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', ['url' => $url]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /** @param array<int, array{command:string, description:string}> $commands */
    public function setMyCommands(array $commands): array
    {
        return $this->call('setMyCommands', ['commands' => $commands]);
    }

    public function getMyCommands(): array
    {
        return $this->call('getMyCommands');
    }

    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }
}
