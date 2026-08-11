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

    /**
     * Bitta so'rov (bitta webhook apdeyti) ichida ko'pincha bir nechta Telegram metodi ketma-ket
     * chaqiriladi (masalan answerCallbackQuery + editMessageText, yoki bir nechta odamga xabar
     * yuborish). cURL handle'ni qayta ishlatish TCP+TLS handshake'ni har safar qaytadan
     * qilmaslikka imkon beradi (keep-alive) — shuning uchun uni yopmasdan saqlab qo'yamiz.
     */
    private $handle = null;

    /**
     * answerCallbackQuery kabi «javobini kutmasak ham bo'ladigan» chaqiruvlar uchun curl_multi handle.
     * callAsync() orqali qo'shilgan so'rovlar bu yerda navbatda turadi; flushAsync() ularni kutadi.
     */
    private $multiHandle = null;
    /** @var array<int,resource|\CurlHandle> */
    private array $asyncHandles = [];

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

        if ($this->handle === null) {
            $this->handle = curl_init();
        }
        $ch = $this->handle;
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint($method),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);

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
     * So'rovni yuboradi, lekin javobni kutmaydi (fire-and-forget).
     * Asosan answerCallbackQuery uchun ishlatiladi: u DB ishi bilan parallel ketadi,
     * shunda foydalanuvchi tugmadagi «yuklanmoqda» animatsiyasini tezroq ko'rmaydi.
     * Natijasi kerak bo'lmagan hollarda flushAsync() chaqirmasa ham bo'ladi.
     *
     * @param array<string,mixed> $params
     */
    public function callAsync(string $method, array $params = []): void
    {
        if (!$this->token) {
            return;
        }

        if ($this->multiHandle === null) {
            $this->multiHandle = curl_multi_init();
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint($method),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_multi_add_handle($this->multiHandle, $ch);
        $this->asyncHandles[] = $ch;

        // Transfer'ni ishga tushiramiz (bloklanmaydi)
        $running = null;
        curl_multi_exec($this->multiHandle, $running);
    }

    /**
     * Barcha callAsync() bilan yuborilgan so'rovlar tugaguncha kutadi.
     * Asosiy javob jo'natishdan avval chaqirish shart emas — Telegram'ga
     * answerCallbackQuery yetib borgani muhim, bizning kutishimiz emas.
     */
    public function flushAsync(): void
    {
        if ($this->multiHandle === null || !$this->asyncHandles) {
            return;
        }

        $running = null;
        do {
            $status = curl_multi_exec($this->multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($this->multiHandle, 0.5);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($this->asyncHandles as $ch) {
            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }
        $this->asyncHandles = [];
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
        $this->flushAsync();
        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
        }
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

    /** answerCallbackQuery'ni bloklanmasdan yuboradi — DB ishi bilan parallel ishlaydi. */
    public function answerCallbackQueryAsync(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $this->callAsync('answerCallbackQuery', [
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
