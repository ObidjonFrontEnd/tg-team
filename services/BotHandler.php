<?php

declare(strict_types=1);

namespace app\services;

use app\components\TelegramApi;
use app\models\Attendance;
use app\models\Group;
use app\models\GroupMember;
use app\models\GroupMemberRole;
use app\models\Meeting;
use app\models\MeetingUserRole;
use app\models\Role;
use app\models\User;
use app\models\UserState;
use Yii;

/**
 * Диспетчер входящих Telegram-апдейтов и вся сценарная логика бота
 * (регистрация, группы, создание встреч, роли, посещаемость).
 *
 * Многошаговые сценарии хранятся в UserState (FSM), т.к. каждый вебхук-запрос — новый HTTP-запрос без памяти.
 */
class BotHandler
{
    /**
     * Doimiy pastki tugmalar — har bir kalit uchun 3 tilda ('_trening' qo'shimchasi bilan
     * Umumiy guruh varianti). Aniq matn emas, KALIT bilan ishlash uchun — qarang btnLabel()/isButton().
     */
    private const BUTTON_LABELS = [
        'upcoming' => [User::LANG_UZ => '📅 Uchrashuvlar', User::LANG_UZ_CYRL => '📅 Учрашувлар', User::LANG_RU => '📅 Встречи'],
        'upcoming_trening' => [User::LANG_UZ => '📅 Treninglar', User::LANG_UZ_CYRL => '📅 Тренинглар', User::LANG_RU => '📅 Тренинги'],
        'create' => [User::LANG_UZ => '➕ Yangi uchrashuv', User::LANG_UZ_CYRL => '➕ Янги учрашув', User::LANG_RU => '➕ Новая встреча'],
        'create_trening' => [User::LANG_UZ => '➕ Yangi trening', User::LANG_UZ_CYRL => '➕ Янги тренинг', User::LANG_RU => '➕ Новый тренинг'],
        'attendance' => [User::LANG_UZ => '✅ Davomat', User::LANG_UZ_CYRL => '✅ Давомат', User::LANG_RU => '✅ Посещаемость'],
        'group' => [User::LANG_UZ => '👥 Guruh', User::LANG_UZ_CYRL => '👥 Гуруҳ', User::LANG_RU => '👥 Группа'],
        'history' => [User::LANG_UZ => '🕘 Tarix', User::LANG_UZ_CYRL => '🕘 Тарих', User::LANG_RU => '🕘 История'],
        'stats' => [User::LANG_UZ => '📊 Statistika', User::LANG_UZ_CYRL => '📊 Статистика', User::LANG_RU => '📊 Статистика'],
        'lang' => [User::LANG_UZ => '🌐 Til', User::LANG_UZ_CYRL => '🌐 Тил', User::LANG_RU => '🌐 Язык'],
    ];

    private function btnLabel(string $key, string $lang, bool $isTrening = false): string
    {
        $effectiveKey = ($isTrening && isset(self::BUTTON_LABELS["{$key}_trening"])) ? "{$key}_trening" : $key;

        return self::BUTTON_LABELS[$effectiveKey][$lang] ?? self::BUTTON_LABELS[$effectiveKey][User::LANG_UZ];
    }

    /** $text berilgan tugma kalitiga (istalgan tilda, uchrashuv/trening variantidan qat'i nazar) mos kelishini tekshiradi. */
    private function isButton(string $text, string $key): bool
    {
        foreach ([User::LANG_UZ, User::LANG_UZ_CYRL, User::LANG_RU] as $lang) {
            if ($text === $this->btnLabel($key, $lang, false) || $text === $this->btnLabel($key, $lang, true)) {
                return true;
            }
        }

        return false;
    }

    /** Davomatni belgilash faqat uchrashuv boshlanganidan keyin va shuncha soat ichida ochiq. */
    private const ATTENDANCE_WINDOW_HOURS = 12;

    private const UZ_WEEKDAYS = ['Yak', 'Dush', 'Sesh', 'Chor', 'Pay', 'Jum', 'Shan'];
    private const UZ_MONTHS = ['', 'Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'Iyun', 'Iyul', 'Avgust', 'Sentabr', 'Oktabr', 'Noyabr', 'Dekabr'];

    public function __construct(private readonly TelegramApi $api)
    {
    }

    /** @param array<string,mixed> $update */
    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    // ---------------------------------------------------------------- utils

    private function resolveUser(array $from): User
    {
        return User::findOrCreateByTelegramId((int) $from['id'], $from['username'] ?? null);
    }

    /**
     * @return Group[] Foydalanuvchi a'zo bo'lgan barcha guruhlar (id bo'yicha tartiblangan).
     * «Umumiy» turidagi guruh (barcha ishtirokchilar avtomatik jamlangan) faqat uning
     * Moderatoriga ko'rinadi — a'zolikdan qat'i nazar boshqa hech kimga ko'rsatilmaydi.
     */
    private function userGroups(User $user): array
    {
        return $user->getGroups()
            ->andWhere(['or',
                ['<>', '{{%groups}}.type', Group::TYPE_UMUMIY],
                ['{{%groups}}.moderator_user_id' => $user->id],
            ])
            ->orderBy(['{{%groups}}.id' => SORT_ASC])
            ->all();
    }

    /**
     * Foydalanuvchining "faol" guruhi — bot menyusi shu kontekstda ishlaydi.
     * Bir kishi bir nechta guruhga a'zo bo'lishi mumkin, shuning uchun tanlov users.active_group_id'da
     * saqlanadi va «🔀 Guruhni almashtirish» orqali o'zgartiriladi. Agar hali tanlanmagan bo'lsa yoki
     * saqlangan guruhdan chiqarilgan bo'lsa — birinchi a'zolikka avtomatik qaytariladi.
     *
     * Kuzatuvchi uchun ishlatilmaydi — u hech qanday guruhga a'zo emas, shuning uchun har bir
     * bo'lim («Uchrashuvlar», «Guruh» va h.k.) o'zi alohida guruh tanlashni so'raydi (pastga qarang).
     */
    private function currentGroup(User $user): ?Group
    {
        if ($user->active_group_id !== null) {
            $stillMember = GroupMember::find()
                ->where(['group_id' => $user->active_group_id, 'user_id' => $user->id])
                ->exists();
            if ($stillMember) {
                return Group::findOne($user->active_group_id);
            }
        }

        $groups = $this->userGroups($user);
        if (!$groups) {
            if ($user->active_group_id !== null) {
                $user->active_group_id = null;
                $user->save(false);
            }

            return null;
        }

        // Iyerarxiya: Moderator > Kotib > Ishtirokchi.
        // Foydalanuvchi bir nechta guruhda bo'lsa, default guruh sifatida
        // Moderator guruhi tanlanadi — aks holda pastki rol (Kotib) ustun bo'lib,
        // Moderator tugmalari ko'rinmay qoladi.
        $defaultGroup = $groups[0];
        foreach ($groups as $g) {
            if ($g->moderator_user_id === $user->id) {
                $defaultGroup = $g;
                break;
            }
        }

        $user->active_group_id = $defaultGroup->id;
        $user->save(false);

        return $defaultGroup;
    }

    /**
     * Menyu foydalanuvchi roliga qarab farq qiladi:
     * oddiy a'zo — 2 tugma (Uchrashuvlar, Guruh); Kotib — 3 (+ Davomat); Moderator — 4 (+ Yangi uchrashuv, Davomat);
     * Kuzatuvchi — 4 tugma (Uchrashuvlar, Guruh, Tarix, Statistika), har biri bosilganda o'zi alohida
     * guruh so'raydi (chunki Kuzatuvchi biror guruhga a'zo emas — «faol guruh» tushunchasi unga tegishli emas).
     * Alohida «guruhni almashtirish» tugmasi yo'q — bir nechta guruhga a'zo bo'lsa, «👥 Guruh»
     * tugmasi bosilganda o'zi qaysi guruh kerakligini so'raydi (qarang: showGroupMembers).
     */
    private function mainMenuKeyboard(User $user, ?Group $group): array
    {
        $lang = $user->language;

        if ($user->is_observer) {
            return [
                [$this->btnLabel('upcoming', $lang), $this->btnLabel('group', $lang)],
                [$this->btnLabel('history', $lang), $this->btnLabel('stats', $lang)],
                [$this->btnLabel('lang', $lang)],
            ];
        }

        if ($group === null) {
            return [[$this->btnLabel('lang', $lang)]];
        }

        $isModerator = $group->moderator_user_id === $user->id;
        $isKotib = !$isModerator && $this->isKotibInGroup($group, $user);
        $isTrening = $group->isUmumiy();

        $rows = [[$this->btnLabel('upcoming', $lang, $isTrening), $this->btnLabel('group', $lang)]];

        $row2 = [];
        if ($isModerator) {
            $row2[] = $this->btnLabel('create', $lang, $isTrening);
        }
        if ($isModerator || $isKotib) {
            $row2[] = $this->btnLabel('attendance', $lang);
        }
        if ($row2) {
            $rows[] = $row2;
        }

        // Har doim ko'rinadi — foydalanuvchi tilni istalgan payt bitta tugma bilan almashtira olsin
        // (avval faqat /til buyrug'i orqali bo'lgan, lekin ko'p foydalanuvchi buyruqni bilmasdi).
        $rows[] = [$this->btnLabel('lang', $lang)];

        return $rows;
    }

    /** «👥 Guruh» bosilganda: bir nechta guruhga a'zo bo'lsa — avval qaysi guruh kerakligini so'raydi. */
    private function showGroupPicker(int $chatId, User $user): void
    {
        $groups = $this->userGroups($user);
        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [['text' => $group->name, 'callback_data' => "swg:{$group->id}"]];
        }

        $prompt = match ($user->language) {
            User::LANG_RU => "👥 Участников какой группы хотите посмотреть?",
            User::LANG_UZ_CYRL => "👥 Қайси гуруҳ аъзоларини кўрмоқчисиз?",
            default => "👥 Qaysi guruh a'zolarini ko'rmoqchisiz?",
        };
        $this->api->sendMessage($chatId, $prompt, $rows);
    }

    /** Guruh tanlangach — faol guruh sifatida saqlanadi (keyingi Uchrashuvlar/Davomat ham shu bilan ishlaydi) va a'zolari ko'rsatiladi. */
    private function switchGroup(int $chatId, User $user, int $groupId): void
    {
        $isMember = GroupMember::find()->where(['group_id' => $groupId, 'user_id' => $user->id])->exists();
        if (!$isMember) {
            return;
        }

        $user->active_group_id = $groupId;
        $user->save(false);

        $group = Group::findOne($groupId);
        $this->showGroupMembers($chatId, $user, $group);

        // Guruh almashganda pastki tugmalar (reply keyboard) yangi guruh roliga mos ravishda
        // yangilanishi kerak — aks holda eski guruhning tugmalari qolib, noto'g'ri amallar chaqiriladi.
        $switched = match ($user->language) {
            User::LANG_RU => "Активная группа: <b>{$group->name}</b>",
            User::LANG_UZ_CYRL => "Фаол гуруҳ: <b>{$group->name}</b>",
            default => "Faol guruh: <b>{$group->name}</b>",
        };
        $this->sendMainMenu($chatId, $user, $group, $switched);
    }

    private function isKotibInGroup(Group $group, User $user): bool
    {
        $kotibRoleId = Role::idByCode(Role::CODE_KOTIB);
        if (!$kotibRoleId) {
            return false;
        }

        return in_array($kotibRoleId, GroupMemberRole::roleIdsFor($group->id, $user->id), true);
    }

    /** Har qanday ko'p bosqichli jarayonni (uchrashuv yaratish/tahrirlash, mehmon qo'shish va h.k.) bekor qilish tugmasi. */
    private function cancelKeyboard(string $lang = User::LANG_UZ): array
    {
        $label = match ($lang) {
            User::LANG_RU => '❌ Отмена',
            User::LANG_UZ_CYRL => '❌ Бекор қилиш',
            default => '❌ Bekor qilish',
        };

        return [[['text' => $label, 'callback_data' => 'cancelflow']]];
    }

    /** Foydalanuvchi ozod matn o'rniga asosiy menyu tugmalaridan birini bosganini aniqlaydi. */
    private function isMenuButtonText(string $text): bool
    {
        if (str_starts_with($text, '/')) {
            return true;
        }
        foreach (array_keys(self::BUTTON_LABELS) as $key) {
            $baseKey = str_ends_with($key, '_trening') ? substr($key, 0, -8) : $key;
            if ($this->isButton($text, $baseKey)) {
                return true;
            }
        }

        return false;
    }

    private function sendMainMenu(int $chatId, User $user, ?Group $group, string $text): void
    {
        $this->api->sendMessage($chatId, $text, null, $this->mainMenuKeyboard($user, $group));
    }

    /**
     * Telefon raqami — ishtirokchilar bir-birini tezroq topishi uchun. Telegram'ning
     * "kontakt ulashish" tugmasi orqali olinadi (raqamni qo'lda yozish shart emas),
     * lekin xohlasa qo'lda ham yozishi mumkin.
     */
    private function askForPhone(int $chatId, User $user): void
    {
        UserState::set($user->telegram_id, 'reg_wait_phone');
        $shareLabel = match ($user->language) {
            User::LANG_RU => '📱 Поделиться номером',
            User::LANG_UZ_CYRL => '📱 Рақамни улашиш',
            default => '📱 Raqamni ulashish',
        };
        $this->api->sendMessage(
            $chatId,
            Texts::askPhone($user->language),
            null,
            [[['text' => $shareLabel, 'request_contact' => true]]]
        );
    }

    private function handlePhoneInput(int $chatId, User $user, string $phone): void
    {
        $phone = trim($phone);
        if ($phone === '') {
            $emptyPhone = match ($user->language) {
                User::LANG_RU => "Номер не может быть пустым. Отправьте через кнопку или введите вручную:",
                User::LANG_UZ_CYRL => "Рақам бўш бўлмаслиги керак. Тугма орқали юборинг ёки қўлда ёзинг:",
                default => "Raqam bo'sh bo'lmasligi kerak. Tugma orqali yuboring yoki qo'lda yozing:",
            };
            $this->api->sendMessage($chatId, $emptyPhone);

            return;
        }

        $user->phone = $phone;
        $user->save(false);
        UserState::clear($user->telegram_id);
        $this->sendMainMenu($chatId, $user, $this->currentGroup($user), Texts::registrationDone($user->language, $user->full_name));
    }

    // -------------------------------------------------------------- til (language)

    private const LANGUAGE_PROMPT = "🌐 Tilni tanlang / Тилни танланг / Выберите язык:";

    private function askLanguage(int $chatId, User $user): void
    {
        UserState::set($user->telegram_id, 'lang_wait_choice');
        $this->api->sendMessage($chatId, self::LANGUAGE_PROMPT, [
            [['text' => "🇺🇿 O'zbek (lotin)", 'callback_data' => 'lang:' . User::LANG_UZ]],
            [['text' => "🇺🇿 Ўзбек (кирилл)", 'callback_data' => 'lang:' . User::LANG_UZ_CYRL]],
            [['text' => "🇷🇺 Русский", 'callback_data' => 'lang:' . User::LANG_RU]],
        ]);
    }

    private function setLanguage(int $chatId, User $user, string $lang): void
    {
        if (!in_array($lang, [User::LANG_UZ, User::LANG_UZ_CYRL, User::LANG_RU], true)) {
            return;
        }

        $user->language = $lang;
        $user->save(false);

        if (!$user->isRegistered()) {
            UserState::set($user->telegram_id, 'reg_wait_name');
            $this->api->sendMessage($chatId, Texts::welcome($lang));

            return;
        }

        UserState::clear($user->telegram_id);
        $confirm = match ($lang) {
            User::LANG_RU => "✅ Язык изменён на русский.",
            User::LANG_UZ_CYRL => "✅ Тил ўзбекча (кирилл)га ўзгартирилди.",
            default => "✅ Til o'zbekchaga (lotin) o'zgartirildi.",
        };
        $this->sendMainMenu($chatId, $user, $user->is_observer ? null : $this->currentGroup($user), $confirm);
    }

    // ------------------------------------------------------------- messages

    /** @param array<string,mixed> $message */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        $user = $this->resolveUser($message['from']);
        $state = UserState::get($user->telegram_id);
        $stateName = $state?->state ?? '';

        if ($stateName === 'reg_wait_phone' && isset($message['contact']['phone_number'])) {
            $this->handlePhoneInput($chatId, $user, $message['contact']['phone_number']);

            return;
        }

        if ($text === '/til' || $text === '/language') {
            UserState::clear($user->telegram_id);
            $this->askLanguage($chatId, $user);

            return;
        }

        if ($text === '/start') {
            UserState::clear($user->telegram_id);
            if (!$user->isRegistered()) {
                $this->askLanguage($chatId, $user);
            } elseif (empty($user->phone)) {
                $this->askForPhone($chatId, $user);
            } else {
                $this->sendMainMenu($chatId, $user, $this->currentGroup($user), Texts::mainMenuHint($user->language));
            }

            return;
        }

        if (!$user->isRegistered() && !in_array($stateName, ['lang_wait_choice', 'reg_wait_name', 'reg_wait_position'], true)) {
            $this->askLanguage($chatId, $user);

            return;
        }

        /**
         * Himoya: agar foydalanuvchi hozir ozod matn kutilayotgan holatda bo'lsa (ism/lavozim/telefon,
         * uchrashuv mavzusi va h.k.), lekin aslida menyu tugmasini bossa — bu matnni ma'lumot sifatida
         * SAQLAMASLIK kerak (aks holda ismi/lavozimi/telefoni yoki uchrashuv mavzusi tugma matniga
         * aylanib qoladi). Holatni tozalab, pastdagi asosiy menyu blokiga o'tkazamiz.
         */
        $registrationStates = ['reg_wait_name', 'reg_wait_position', 'reg_wait_phone'];
        $rawTextCaptureStates = [
            ...$registrationStates,
            'meeting_wait_topic', 'meeting_wait_date', 'meeting_wait_time_custom',
            'meeting_wait_cancel_reason', 'meeting_edit_topic', 'meeting_edit_date',
            'meeting_wait_guest_name',
        ];
        if (in_array($stateName, $rawTextCaptureStates, true) && $this->isMenuButtonText($text)) {
            if (in_array($stateName, $registrationStates, true)) {
                // Ro'yxatdan o'tish hali tugallanmagan — bosilgan tugmani e'tiborsiz qoldirib,
                // xuddi shu bosqichni (holatni o'zgartirmasdan) qaytadan so'raymiz, boshidan emas.
                match ($stateName) {
                    'reg_wait_name' => $this->api->sendMessage($chatId, Texts::welcome($user->language)),
                    'reg_wait_position' => $this->api->sendMessage($chatId, Texts::askPosition($user->language)),
                    'reg_wait_phone' => $this->askForPhone($chatId, $user),
                    default => null,
                };

                return;
            }

            UserState::clear($user->telegram_id);
            $stateName = '';
        }

        switch ($stateName) {
            case 'lang_wait_choice':
                $this->askLanguage($chatId, $user);

                return;

            case 'reg_wait_name':
                $user->full_name = $text;
                $user->save(false);
                UserState::set($user->telegram_id, 'reg_wait_position');
                $this->api->sendMessage($chatId, Texts::askPosition($user->language));

                return;

            case 'reg_wait_position':
                $user->position = $text;
                $user->save(false);
                $this->askForPhone($chatId, $user);

                return;

            case 'reg_wait_phone':
                $this->handlePhoneInput($chatId, $user, $text);

                return;

            case 'meeting_wait_topic':
                $topicContext = $state->getContextData() + ['topic' => $text];
                UserState::set($user->telegram_id, 'meeting_wait_day', $topicContext);
                $isTreningTopic = Group::findOne($topicContext['group_id'] ?? null)?->isUmumiy() ?? false;
                $dayPrompt = match ($user->language) {
                    User::LANG_RU => "Выберите дату " . ($isTreningTopic ? 'тренинга' : 'встречи') . ':',
                    User::LANG_UZ_CYRL => ($isTreningTopic ? 'Тренинг' : 'Учрашув') . " санасини танланг:",
                    default => ($isTreningTopic ? 'Trening' : 'Uchrashuv') . " sanasini tanlang:",
                };
                $this->api->sendMessage($chatId, $dayPrompt, $this->dayPickerKeyboard($user->language));

                return;

            case 'meeting_wait_date':
                $this->handleMeetingDateInput($chatId, $user, $state, $text);

                return;

            case 'meeting_wait_time_custom':
                $this->handleCustomTimeInput($chatId, $user, $state, $text);

                return;

            case 'meeting_wait_cancel_reason':
                $this->handleCancelReasonInput($chatId, $user, $state, $text);

                return;

            case 'meeting_edit_topic':
                $this->handleEditTopicInput($chatId, $user, $state, $text);

                return;

            case 'meeting_edit_date':
                $this->handleEditDateInput($chatId, $user, $state, $text);

                return;

            case 'meeting_wait_guest_name':
                $this->handleGuestNameInput($chatId, $user, $state, $text);

                return;
        }

        // idle state — обрабатываем кнопки главного меню
        // Kuzatuvchi uchun «faol guruh» tushunchasi yo'q — bu qiymat oddiy a'zo/Kotib/Moderator uchun ishlatiladi.
        $group = $user->is_observer ? null : $this->currentGroup($user);

        if ($this->isButton($text, 'lang')) {
            UserState::clear($user->telegram_id);
            $this->askLanguage($chatId, $user);
        } elseif ($this->isButton($text, 'upcoming')) {
            if ($user->is_observer) {
                $this->showObserverGroupPicker($chatId, $user, 'oum', 'upcoming');
            } else {
                $this->showUpcomingMeetings($chatId, $user);
            }
        } elseif ($this->isButton($text, 'create')) {
            $this->startMeetingCreation($chatId, $user, $group);
        } elseif ($this->isButton($text, 'group')) {
            if ($user->is_observer) {
                $this->showObserverGroupPicker($chatId, $user, 'ogm', 'group');
            } elseif (count($this->userGroups($user)) > 1) {
                $this->showGroupPicker($chatId, $user);
            } else {
                $this->showGroupMembers($chatId, $user, $group);
            }
        } elseif ($this->isButton($text, 'attendance')) {
            $this->showAttendanceMeetingList($chatId, $user, $group);
        } elseif ($this->isButton($text, 'history')) {
            $this->showObserverGroupPicker($chatId, $user, 'ohm', 'history');
        } elseif ($this->isButton($text, 'stats')) {
            $this->openObserverStatsWebApp($chatId, $user);
        } else {
            $this->sendMainMenu($chatId, $user, $group, Texts::mainMenuHint($user->language));
        }
    }

    // ------------------------------------------------------------- groups

    /**
     * Guruh a'zolari — hamma ko'radi va ismga bosib kontaktini (telefon raqami bilan) ochadi,
     * shu bilan ishtirokchilar bir-birini tezroq topadi. Faqat Moderator uchun ism bosilganda
     * kontakt o'rniga rol tahrirlash ekrani ochiladi (qarang: handleMemberTap).
     */
    private function showGroupMembers(int $chatId, User $user, ?Group $group): void
    {
        if ($group === null) {
            $this->api->sendMessage($chatId, Texts::noGroup($user->language));

            return;
        }

        $members = $group->getMembers()->all();
        if (!$members) {
            $noMembers = match ($user->language) {
                User::LANG_RU => "В группе пока нет участников. Администратор должен их добавить.",
                User::LANG_UZ_CYRL => "Гуруҳда ҳали аъзолар йўқ. Администратор қўшиши керак.",
                default => "Guruhda hali a'zolar yo'q. Administrator qo'shishi kerak.",
            };
            $this->api->sendMessage($chatId, $noMembers);

            return;
        }

        $roleMap = GroupMemberRole::mapForGroup($group->id);
        usort($members, fn (User $a, User $b) => $this->memberPriority($group, $a, $roleMap) <=> $this->memberPriority($group, $b, $roleMap));

        $isModerator = $group->moderator_user_id === $user->id;
        $hint = match (true) {
            $user->language === User::LANG_RU && $isModerator => "Нажмите на имя, чтобы изменить роль:",
            $user->language === User::LANG_RU => "Нажмите на имя, чтобы увидеть контакт (номер телефона):",
            $user->language === User::LANG_UZ_CYRL && $isModerator => "Ролини ўзгартириш учун исмини босинг:",
            $user->language === User::LANG_UZ_CYRL => "Контактини (телефон рақамини) кўриш учун исмини босинг:",
            $isModerator => "Rolini o'zgartirish uchun ismini bosing:",
            default => "Kontaktini (telefon raqamini) ko'rish uchun ismini bosing:",
        };

        $rows = [];
        foreach ($members as $member) {
            $label = Role::formatPerson($member->full_name, $this->memberRoles($group, $member, $roleMap));
            $rows[] = [['text' => $label, 'callback_data' => "gm:{$group->id}:{$member->id}"]];
        }

        $header = match ($user->language) {
            User::LANG_RU => "👥 <b>Участники группы — {$group->name}</b>",
            User::LANG_UZ_CYRL => "👥 <b>Гуруҳ аъзолари — {$group->name}</b>",
            default => "👥 <b>Guruh a'zolari — {$group->name}</b>",
        };
        $this->api->sendMessage($chatId, "{$header}\n\n{$hint}", $rows);
    }

    /** Ism bosilganda: Moderator uchun rol tahrirlash, qolganlar uchun faqat kontakt kartasi. */
    private function handleMemberTap(int $chatId, User $viewer, int $groupId, int $memberId): void
    {
        $group = Group::findOne($groupId);
        if ($group === null) {
            return;
        }

        if ($group->moderator_user_id === $viewer->id) {
            $this->openMemberRoleScreen($chatId, $viewer, $groupId, $memberId);

            return;
        }

        $this->showMemberContact($chatId, $viewer, $group, $memberId);
    }

    private function showMemberContact(int $chatId, User $viewer, Group $group, int $memberId): void
    {
        $member = User::findOne($memberId);
        if ($member === null) {
            return;
        }

        $roles = $this->memberRoles($group, $member);
        $icon = Role::leadEmoji($roles);
        $noPhone = match ($viewer->language) {
            User::LANG_RU => "📱 Номер телефона пока не указан",
            User::LANG_UZ_CYRL => "📱 Телефон рақами ҳали кўрсатилмаган",
            default => "📱 Telefon raqami hali ko'rsatilmagan",
        };
        $phone = $member->phone ? "📱 <b>{$member->phone}</b>" : $noPhone;
        $positionIcon = "💼 ";
        $roleIcon = match ($viewer->language) {
            User::LANG_RU => "🎭 ",
            default => "🎭 ",
        };

        $text = trim("{$icon} <b>{$member->full_name}</b>") . "\n"
            . (($member->position ?? '') !== '' ? $positionIcon . htmlspecialchars($member->position) . "\n" : '')
            . $roleIcon . Role::namesOnly($roles) . "\n"
            . $phone;

        $back = match ($viewer->language) {
            User::LANG_RU => '⬅️ Назад',
            User::LANG_UZ_CYRL => '⬅️ Орқага',
            default => '⬅️ Orqaga',
        };
        $this->api->sendMessage($chatId, $text, [[['text' => $back, 'callback_data' => "gvb:{$group->id}"]]]);
    }

    /**
     * Ro'yxatlarda tartiblash uchun: Moderator birinchi, so'ng Kotib va boshqa rollar, Ishtirokchi oxirida.
     *
     * @param array<int,int[]>|null $roleMap Oldindan GroupMemberRole::mapForGroup() bilan olingan
     * user_id => role_id[] xaritasi — ro'yxat bo'yicha saralashda har bir a'zo uchun alohida so'rov
     * yubormaslik uchun. Berilmasa (masalan bitta a'zoni tekshirishda), o'zi bitta so'rov yuboradi.
     */
    private function memberPriority(Group $group, User $member, ?array $roleMap = null): int
    {
        if ($member->id === $group->moderator_user_id) {
            return Role::priority()[Role::CODE_MODERATOR];
        }

        $roleIds = $roleMap !== null ? ($roleMap[$member->id] ?? []) : GroupMemberRole::roleIdsFor($group->id, $member->id);
        if (!$roleIds) {
            return Role::priority()[Role::CODE_ISHTIROKCHI];
        }

        return Role::bestPriority(Role::byIds($roleIds));
    }

    /**
     * A'zoning rollari (Role obyektlari) — Moderator hisobga olingan holda.
     * «Ishtirokchi» — faqat hech qanday boshqa rol yo'q odam uchun (Moderator ham hisobga olinadi).
     * Moderator + Texnik xodim kabi kombinatsiyalar bo'lishi mumkin, lekin «rol + Ishtirokchi» hech qachon bo'lmaydi.
     *
     * @param array<int,int[]>|null $roleMap qarang memberPriority() izohi.
     * @return Role[]
     */
    private function memberRoles(Group $group, User $member, ?array $roleMap = null): array
    {
        $isModerator = $member->id === $group->moderator_user_id;
        $roleIds = $roleMap !== null ? ($roleMap[$member->id] ?? []) : GroupMemberRole::roleIdsFor($group->id, $member->id);

        if (!$roleIds && !$isModerator) {
            $ishtirokchi = Role::ishtirokchi();

            return $ishtirokchi ? [$ishtirokchi] : [];
        }

        $roles = Role::byIds($roleIds);
        if ($isModerator) {
            $moderator = Role::byCode(Role::CODE_MODERATOR);
            if ($moderator) {
                array_unshift($roles, $moderator);
            }
        }

        return $roles;
    }

    private function memberRoleKeyboard(int $groupId, int $userId, string $lang = User::LANG_UZ): array
    {
        $selected = GroupMemberRole::roleIdsFor($groupId, $userId);
        $rows = [];
        foreach (Role::assignable() as $role) {
            $checked = in_array($role->id, $selected, true);
            $rows[] = [[
                'text' => ($checked ? '☑ ' : '☐ ') . $role->label(),
                'callback_data' => "gt:{$groupId}:{$userId}:{$role->id}",
            ]];
        }
        $back = match ($lang) {
            User::LANG_RU => '⬅️ Назад',
            User::LANG_UZ_CYRL => '⬅️ Орқага',
            default => '⬅️ Orqaga',
        };
        $rows[] = [['text' => $back, 'callback_data' => "gb:{$groupId}:{$userId}"]];

        return $rows;
    }

    /**
     * Rol/guruh o'zgarganda foydalanuvchiga yangilangan menyu (tugmalar) darhol yuboriladi — /start kutish shart emas.
     * MUHIM: agar foydalanuvchi hali ro'yxatdan to'liq o'tmagan bo'lsa (masalan, admin uni guruhga
     * qo'shib qo'ygan, lekin u /start'ni oxirigacha bosmagan), tugmalar YUBORILMAYDI — aks holda u
     * hali ism/lavozim/telefon so'ralayotgan holatda ekan, tugma matni shu maydonlarga yozilib qolar edi
     * (masalan «👥 Guruh»ni bosishi ismi sifatida saqlanib ketishi mumkin edi).
     */
    public function notifyMenuRefresh(User $user, Group $group, string $text): void
    {
        if (!$user->isRegistered()) {
            $this->api->sendMessage($user->telegram_id, $text . "\n\n" . $this->finishRegistrationHint($user->language));

            return;
        }

        $this->api->sendMessage($user->telegram_id, $text, null, $this->mainMenuKeyboard($user, $group));
    }

    /**
     * notifyMenuRefresh'dan farqi — aniq bitta guruhga bog'liq emas (masalan, Kuzatuvchi
     * roli berilganda/olib tashlanganda, u guruh a'zosi bo'lmasligi mumkin). Foydalanuvchining
     * joriy (yoki yo'q) guruhini o'zi aniqlab, mos menyuni yuboradi.
     */
    public function notifyUserMenu(User $user, string $text): void
    {
        if (!$user->isRegistered()) {
            $this->api->sendMessage($user->telegram_id, $text . "\n\n" . $this->finishRegistrationHint($user->language));

            return;
        }

        $this->api->sendMessage($user->telegram_id, $text, null, $this->mainMenuKeyboard($user, $this->currentGroup($user)));
    }

    private function finishRegistrationHint(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "⚠️ Чтобы завершить регистрацию, напишите /start.",
            User::LANG_UZ_CYRL => "⚠️ Рўйхатдан охиригача ўтиш учун /start ёзинг.",
            default => "⚠️ Ro'yxatdan oxirigacha o'tish uchun /start yozing.",
        };
    }

    private function openMemberRoleScreen(int $chatId, User $user, int $groupId, int $memberId): void
    {
        $group = Group::findOne($groupId);
        if ($group === null || $group->moderator_user_id !== $user->id) {
            return;
        }

        $member = User::findOne($memberId);
        if ($member === null) {
            return;
        }

        $phone = $member->phone ? "📱 <b>{$member->phone}</b>\n" : '';
        $position = ($member->position ?? '') !== '' ? "💼 " . htmlspecialchars($member->position) . "\n" : '';
        $setRoles = match ($user->language) {
            User::LANG_RU => "Назначьте роль(и):",
            User::LANG_UZ_CYRL => "Учун рол(лар)ни белгиланг:",
            default => "Uchun rol(lar)ni belgilang:",
        };

        $this->api->sendMessage(
            $chatId,
            "<b>{$member->full_name}</b>\n{$position}{$phone}\n{$setRoles}",
            $this->memberRoleKeyboard($groupId, $memberId, $user->language)
        );
    }

    private function toggleMemberRole(int $chatId, int $messageId, User $user, int $groupId, int $memberId, int $roleId): void
    {
        $group = Group::findOne($groupId);
        if ($group === null || $group->moderator_user_id !== $user->id) {
            return;
        }

        GroupMemberRole::toggle($groupId, $memberId, $roleId);

        $this->api->editMessageReplyMarkup($chatId, $messageId, $this->memberRoleKeyboard($groupId, $memberId, $user->language));
    }

    // ---------------------------------------------------------- observer

    /**
     * Kuzatuvchi hech qanday guruhga a'zo emas, shuning uchun «Uchrashuvlar», «Guruh», «Tarix»
     * bosilganda avval qaysi guruh kerakligini so'raydi (inline ro'yxat, a'zolikdan qat'i nazar
     * BARCHA guruhlar). «Statistika» bundan mustasno — u Telegram Web App'ni ochadigan tugma
     * yuboradi, guruh/davr bo'yicha filtrlash shu veb-sahifa ichida (qarang: openObserverStatsWebApp).
     */
    /** @param string $promptKey 'upcoming'|'group'|'history' — qaysi bo'lim so'raganini bildiradi. */
    private function showObserverGroupPicker(int $chatId, User $user, string $prefix, string $promptKey): void
    {
        // «Umumiy» guruh faqat uning Moderatoriga ko'rinadi — Kuzatuvchiga ham ko'rsatilmaydi.
        $groups = Group::find()->where(['<>', 'type', Group::TYPE_UMUMIY])->orderBy(['name' => SORT_ASC])->all();
        if (!$groups) {
            $noGroups = match ($user->language) {
                User::LANG_RU => "Пока нет групп.",
                User::LANG_UZ_CYRL => "Ҳозирча гуруҳлар йўқ.",
                default => "Hozircha guruhlar yo'q.",
            };
            $this->api->sendMessage($chatId, $noGroups);

            return;
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [['text' => $group->name, 'callback_data' => "{$prefix}:{$group->id}"]];
        }

        $prompts = [
            'upcoming' => [
                User::LANG_RU => "📅 Встречи/тренинги какой группы хотите посмотреть?",
                User::LANG_UZ_CYRL => "📅 Қайси гуруҳнинг учрашувларини кўрмоқчисиз?",
                User::LANG_UZ => "📅 Qaysi guruhning uchrashuvlarini ko'rmoqchisiz?",
            ],
            'group' => [
                User::LANG_RU => "👥 Участников какой группы хотите посмотреть?",
                User::LANG_UZ_CYRL => "👥 Қайси гуруҳ аъзоларини кўрмоқчисиз?",
                User::LANG_UZ => "👥 Qaysi guruh a'zolarini ko'rmoqchisiz?",
            ],
            'history' => [
                User::LANG_RU => "🕘 Историю какой группы хотите посмотреть?",
                User::LANG_UZ_CYRL => "🕘 Қайси гуруҳнинг тарихини кўрмоқчисиз?",
                User::LANG_UZ => "🕘 Qaysi guruhning tarixini ko'rmoqchisiz?",
            ],
        ];
        $label = $prompts[$promptKey][$user->language] ?? $prompts[$promptKey][User::LANG_UZ];

        $this->api->sendMessage($chatId, $label, $rows);
    }

    /** Tanlangan guruhning kelayotgan uchrashuvlari — ko'rish uchun, harakat tugmalarisiz. */
    private function showObserverMeetings(int $chatId, User $user, int $groupId): void
    {
        if (!$user->is_observer) {
            return;
        }

        $group = Group::findOne($groupId);
        if ($group === null) {
            return;
        }

        $meetings = $this->activeMeetings($group);
        if (!$meetings) {
            $this->api->sendMessage($chatId, Texts::upcomingMeetingsEmpty($user->language));

            return;
        }

        $lang = $user->language;
        $header = match ($lang) {
            User::LANG_RU => "📅 <b>{$group->name} — ближайшие встречи:</b>\n\n",
            User::LANG_UZ_CYRL => "📅 <b>{$group->name} — яқинлашиб келаётган учрашувлар:</b>\n\n",
            default => "📅 <b>{$group->name} — yaqinlashib kelayotgan uchrashuvlar:</b>\n\n",
        };
        $statusWord = match ($lang) {
            User::LANG_RU => '📊 Статус: ',
            User::LANG_UZ_CYRL => '📊 Ҳолат: ',
            default => '📊 Holat: ',
        };
        $text = $header;
        foreach ($meetings as $meeting) {
            $text .= "📌 «{$meeting->topic}»\n"
                . "🗓 " . Texts::formatDate($meeting->meeting_at) . " · " . Texts::formatLabel($meeting->format, $lang) . "\n"
                . $statusWord . $this->meetingStatusLabel($meeting->status, $lang) . "\n\n";
        }

        $this->api->sendMessage($chatId, rtrim($text));
    }

    /** Yakunlangan uchrashuvlar tarixi — har biri uchun kelgan/kelmagan soni bilan. */
    private function showObserverMeetingHistory(int $chatId, User $user, int $groupId): void
    {
        if (!$user->is_observer) {
            return;
        }

        $group = Group::findOne($groupId);
        if ($group === null) {
            return;
        }

        $meetings = $group->getMeetings()
            ->andWhere(['status' => Meeting::STATUS_FINISHED])
            ->orderBy(['meeting_at' => SORT_DESC])
            ->limit(20)
            ->all();

        $lang = $user->language;
        if (!$meetings) {
            $noHistory = match ($lang) {
                User::LANG_RU => "В группе «{$group->name}» пока нет завершённых встреч.",
                User::LANG_UZ_CYRL => "«{$group->name}» гуруҳида ҳали якунланган учрашувлар йўқ.",
                default => "«{$group->name}» guruhida hali yakunlangan uchrashuvlar yo'q.",
            };
            $this->api->sendMessage($chatId, $noHistory);

            return;
        }

        $header = match ($lang) {
            User::LANG_RU => "🕘 <b>{$group->name} — прошедшие встречи:</b>\n\n",
            User::LANG_UZ_CYRL => "🕘 <b>{$group->name} — ўтган учрашувлар:</b>\n\n",
            default => "🕘 <b>{$group->name} — o'tgan uchrashuvlar:</b>\n\n",
        };
        $text = $header;
        foreach ($meetings as $meeting) {
            $present = $meeting->getAttendances()->andWhere(['status' => Attendance::STATUS_PRESENT])->count();
            $absent = $meeting->getAttendances()->andWhere(['status' => [Attendance::STATUS_ABSENT, Attendance::STATUS_EXCUSED]])->count();
            $line = match ($lang) {
                User::LANG_RU => "✅ {$present} пришли / ❌ {$absent} не пришли",
                User::LANG_UZ_CYRL => "✅ {$present} келди / ❌ {$absent} келмади",
                default => "✅ {$present} keldi / ❌ {$absent} kelmadi",
            };
            $text .= "📌 «{$meeting->topic}» — " . Texts::formatDate($meeting->meeting_at) . "\n{$line}\n\n";
        }

        $this->api->sendMessage($chatId, rtrim($text));
    }

    /**
     * «📊 Statistika» tugmasi — bot ichida matnli jadval endi chizilmaydi (Telegram rangli
     * ✅/❌ emojini o'zgaruvchan kenglikda chizadi, shuning uchun ustunlarni piksel
     * aniqligida tekislab bo'lmaydi). Buning o'rniga haqiqiy HTML jadval ko'rsatadigan
     * Telegram Web App'ni ochuvchi tugma yuboriladi (qarang: WebAppController,
     * ObserverStatsService) — davr/guruh bo'yicha filtrlash endi shu veb-sahifa ichida.
     */
    private function openObserverStatsWebApp(int $chatId, User $user): void
    {
        if (!$user->is_observer) {
            return;
        }

        $lang = $user->language;
        $baseUrl = rtrim((string) Yii::$app->params['app.baseUrl'], '/');
        if ($baseUrl === '') {
            $notConfigured = match ($lang) {
                User::LANG_RU => "Веб-приложение статистики ещё не настроено (APP_BASE_URL).",
                User::LANG_UZ_CYRL => "Статистика веб-иловаси ҳали созланмаган (APP_BASE_URL).",
                default => "Statistika veb-ilovasi hali sozlanmagan (APP_BASE_URL).",
            };
            $this->api->sendMessage($chatId, $notConfigured);

            return;
        }

        $url = "{$baseUrl}/web-app/stats?lang={$lang}";
        $buttonLabel = match ($lang) {
            User::LANG_RU => '🌐 Открыть',
            User::LANG_UZ_CYRL => '🌐 Очиш',
            default => '🌐 Ochish',
        };
        $prompt = match ($lang) {
            User::LANG_RU => "📊 Статистика посещаемости — откройте веб-приложение:",
            User::LANG_UZ_CYRL => "📊 Давомат статистикаси — веб-иловани очинг:",
            default => "📊 Davomat statistikasi — veb-ilovani oching:",
        };

        $this->api->sendMessage($chatId, $prompt, [[['text' => $buttonLabel, 'web_app' => ['url' => $url]]]]);
    }

    // ------------------------------------------------------------ meetings

    /**
     * Oddiy a'zo va Kotib — faqat matnli ro'yxat (ko'rish uchun).
     * Moderator uchun — interaktiv ro'yxat: har bir uchrashuvga bosib, uning "kartasi"
     * (boshlash/davomat/yakunlash/tahrirlash/bekor qilish) ochiladi.
     */
    private function showUpcomingMeetings(int $chatId, User $user): void
    {
        $group = $this->currentGroup($user);
        if ($group === null) {
            $this->api->sendMessage($chatId, Texts::noGroup($user->language));

            return;
        }

        $meetings = $this->activeMeetings($group);
        if (!$meetings) {
            $this->api->sendMessage($chatId, Texts::upcomingMeetingsEmpty($user->language, $group->isUmumiy()));

            return;
        }

        $isModerator = $group->moderator_user_id === $user->id;
        $isKotib = !$isModerator && $this->isKotibInGroup($group, $user);

        if (!$isModerator && !$isKotib) {
            $text = Texts::upcomingMeetingsHeader($user->language, $group->isUmumiy()) . "\n\n";
            foreach ($meetings as $meeting) {
                $myRoles = $meeting->getRolesOfUser($user->id);
                $text .= Texts::upcomingMeetingItem($user->language, $meeting, $myRoles) . "\n";
            }
            $this->api->sendMessage($chatId, $text);

            return;
        }

        $rows = [];
        foreach ($meetings as $meeting) {
            $prefix = $meeting->status === Meeting::STATUS_ATTENDANCE_MARKING ? '▶️ ' : '🕓 ';
            $rows[] = [[
                'text' => $prefix . Texts::formatDate($meeting->meeting_at) . ' — ' . $meeting->topic,
                'callback_data' => "mopen:{$meeting->id}",
            ]];
        }
        $detailsHint = match ($user->language) {
            User::LANG_RU => "\n\nНажмите для подробностей:",
            User::LANG_UZ_CYRL => "\n\nБатафсил учун босинг:",
            default => "\n\nBatafsil uchun bosing:",
        };
        $this->api->sendMessage($chatId, Texts::upcomingMeetingsHeader($user->language, $group->isUmumiy()) . $detailsHint, $rows);
    }

    /** @return Meeting[] */
    private function activeMeetings(Group $group): array
    {
        return $group->getMeetings()
            ->andWhere(['status' => [Meeting::STATUS_ANNOUNCED, Meeting::STATUS_ATTENDANCE_MARKING]])
            ->orderBy(['meeting_at' => SORT_ASC])
            ->all();
    }

    private function meetingStatusLabel(string $status, string $lang = User::LANG_UZ): string
    {
        return match ($lang) {
            User::LANG_RU => match ($status) {
                Meeting::STATUS_ANNOUNCED => "🕓 Анонсировано (ещё не началось)",
                Meeting::STATUS_ATTENDANCE_MARKING => "▶️ Идёт",
                Meeting::STATUS_FINISHED => "🏁 Завершено",
                Meeting::STATUS_CANCELLED => "❌ Отменено",
                default => $status,
            },
            User::LANG_UZ_CYRL => match ($status) {
                Meeting::STATUS_ANNOUNCED => "🕓 Эълон қилинган (ҳали бошланмаган)",
                Meeting::STATUS_ATTENDANCE_MARKING => "▶️ Давом этмоқда",
                Meeting::STATUS_FINISHED => "🏁 Якунланган",
                Meeting::STATUS_CANCELLED => "❌ Бекор қилинган",
                default => $status,
            },
            default => match ($status) {
                Meeting::STATUS_ANNOUNCED => "🕓 E'lon qilingan (hali boshlanmagan)",
                Meeting::STATUS_ATTENDANCE_MARKING => "▶️ Davom etmoqda",
                Meeting::STATUS_FINISHED => "🏁 Yakunlangan",
                Meeting::STATUS_CANCELLED => "❌ Bekor qilingan",
                default => $status,
            },
        };
    }

    private function meetingCardText(Meeting $meeting, string $lang = User::LANG_UZ): string
    {
        $format = Texts::formatLabel($meeting->format, $lang);
        $labels = match ($lang) {
            User::LANG_RU => ['status' => '📊 Статус:', 'started' => '▶️ Начато:', 'ended' => '🏁 Завершено:', 'reason' => '📝 Причина отмены:'],
            User::LANG_UZ_CYRL => ['status' => '📊 Ҳолат:', 'started' => '▶️ Бошланган:', 'ended' => '🏁 Тугаган:', 'reason' => '📝 Бекор қилиш сабаби:'],
            default => ['status' => '📊 Holat:', 'started' => '▶️ Boshlangan:', 'ended' => '🏁 Tugagan:', 'reason' => '📝 Bekor qilish sababi:'],
        };

        $lines = [
            "📌 <b>{$meeting->topic}</b>",
            "🗓 " . Texts::formatDate($meeting->meeting_at) . " · {$format}",
            $labels['status'] . ' ' . $this->meetingStatusLabel($meeting->status, $lang),
        ];
        if ($meeting->started_at) {
            $lines[] = $labels['started'] . ' ' . Texts::formatDate($meeting->started_at);
        }
        if ($meeting->ended_at) {
            $lines[] = $labels['ended'] . ' ' . Texts::formatDate($meeting->ended_at);
        }
        if ($meeting->status === Meeting::STATUS_CANCELLED) {
            $lines[] = $labels['reason'] . ' ' . ($meeting->cancel_reason ?: '—');
        }

        return implode("\n", $lines);
    }

    /**
     * Uchrashuv "kartasi" — umumiy holat va lifecycle tugmalari (boshlash/yakunlash/tahrirlash/bekor qilish).
     * MUHIM: bu yerda ishtirokchilarning davomat ro'yxati YO'Q — u boshqa ekranda (qarang: openAttendanceMarking),
     * chunki davomatni belgilash odatda boshqa odam (Kotib) ishi, Moderator boshlagandan keyin darhol
     * davomat ro'yxatini ko'rishi shart emas.
     */
    private function meetingCardKeyboard(Meeting $meeting, User $viewer, string $lang = User::LANG_UZ): array
    {
        $isModerator = $meeting->group->moderator_user_id === $viewer->id;
        // Kotib ham uchrashuvni boshlashi mumkin (davomatni odatda u belgilaydi — Moderator hozir bo'lmasa ham
        // ish to'xtab qolmasligi uchun), lekin tahrirlash/bekor qilish faqat Moderatorga tegishli.
        $isKotib = !$isModerator && $this->isKotibInGroup($meeting->group, $viewer);
        $isTrening = $meeting->group->isUmumiy();
        $rows = [];

        $l = match ($lang) {
            User::LANG_RU => [
                'finish' => $isTrening ? "🏁 Завершить тренинг" : "🏁 Завершить встречу",
                'start' => $isTrening ? "▶️ Начать тренинг" : "▶️ Начать встречу",
                'edit' => "✏️ Редактировать",
                'cancel' => "❌ Отменить",
            ],
            User::LANG_UZ_CYRL => [
                'finish' => "🏁 " . ($isTrening ? 'Тренингни' : 'Учрашувни') . " якунлаш",
                'start' => "▶️ " . ($isTrening ? 'Тренингни' : 'Учрашувни') . " бошлаш",
                'edit' => "✏️ Таҳрирлаш",
                'cancel' => "❌ Бекор қилиш",
            ],
            default => [
                'finish' => "🏁 " . ($isTrening ? 'Treningni' : 'Uchrashuvni') . " yakunlash",
                'start' => "▶️ " . ($isTrening ? 'Treningni' : 'Uchrashuvni') . " boshlash",
                'edit' => "✏️ Tahrirlash",
                'cancel' => "❌ Bekor qilish",
            ],
        };

        if ($meeting->status === Meeting::STATUS_ATTENDANCE_MARKING) {
            $rows[] = [['text' => $l['finish'], 'callback_data' => "mfinish:{$meeting->id}"]];
        }

        if (($isModerator || $isKotib) && $meeting->status === Meeting::STATUS_ANNOUNCED) {
            $rows[] = [['text' => $l['start'], 'callback_data' => "mstart:{$meeting->id}"]];
        }

        if ($isModerator && in_array($meeting->status, [Meeting::STATUS_ANNOUNCED, Meeting::STATUS_ATTENDANCE_MARKING], true)) {
            $rows[] = [
                ['text' => $l['edit'], 'callback_data' => "medit:{$meeting->id}"],
                ['text' => $l['cancel'], 'callback_data' => "mcancel:ask:{$meeting->id}"],
            ];
        }

        return $rows;
    }

    private function openMeetingCard(int $chatId, User $viewer, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $this->api->sendMessage($chatId, $this->meetingCardText($meeting, $viewer->language), $this->meetingCardKeyboard($meeting, $viewer, $viewer->language));
    }

    /** Faqat davomat ro'yxati — «✅ Davomat» bo'limi orqali ochiladi (Kotib/Moderator uchun alohida ekran). */
    private function attendanceMarkingKeyboard(Meeting $meeting, string $lang = User::LANG_UZ): array
    {
        $participants = $meeting->getParticipantsWithRoles();
        $attendances = $meeting->getAttendances()->indexBy('user_id')->all();

        $rows = [];
        foreach ($participants as $uid => $row) {
            $status = $attendances[$uid]->status ?? null;
            $label = $row['user']->full_name . ' — ' . Texts::attendanceRowStatus($lang, (string) $status);
            $rows[] = [['text' => $label, 'callback_data' => "att:{$meeting->id}:{$uid}"]];
        }
        $guestBtn = match ($lang) {
            User::LANG_RU => "👤 Добавить гостя",
            User::LANG_UZ_CYRL => "👤 Меҳмон қўшиш",
            default => "👤 Mehmon qo'shish",
        };
        $rows[] = [['text' => $guestBtn, 'callback_data' => "agst:{$meeting->id}"]];
        $rolesBtn = match ($lang) {
            User::LANG_RU => "✏️ Назначить роли",
            User::LANG_UZ_CYRL => "✏️ Рولларни белгилаш",
            default => "✏️ Rollarni belgilash",
        };
        $rows[] = [['text' => $rolesBtn, 'callback_data' => "mrp:{$meeting->id}"]];
        $isTrening = $meeting->group->isUmumiy();
        $finishBtn = match ($lang) {
            User::LANG_RU => $isTrening ? "🏁 Завершить тренинг" : "🏁 Завершить встречу",
            User::LANG_UZ_CYRL => "🏁 " . ($isTrening ? 'Тренингни' : 'Учрашувни') . " якунлаш",
            default => "🏁 " . ($isTrening ? 'Treningni' : 'Uchrashuvni') . " yakunlash",
        };
        $rows[] = [['text' => $finishBtn, 'callback_data' => "mfinish:{$meeting->id}"]];

        return $rows;
    }

    private function askGuestName(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ATTENDANCE_MARKING) {
            return;
        }

        $group = $meeting->group;
        $isModerator = $group->moderator_user_id === $user->id;
        if (!$isModerator && !$this->isKotibInGroup($group, $user)) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_wait_guest_name', ['meeting_id' => $meetingId]);
        $prompt = match ($user->language) {
            User::LANG_RU => "👤 Введите Ф.И.О. гостя (не зарегистрирован, может быть из другой команды/группы):",
            User::LANG_UZ_CYRL => "👤 Меҳмоннинг Ф.И.Ш.ини ёзинг (рўйхатдан ўтмаган, бошқа жамоа/гуруҳдан бўлиши мумкин):",
            default => "👤 Mehmonning F.I.Sh.ini yozing (ro'yxatdan o'tmagan, boshqa jamoa/guruhdan bo'lishi mumkin):",
        };
        $this->api->sendMessage($chatId, $prompt, $this->cancelKeyboard($user->language));
    }

    private function handleGuestNameInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $fullName = trim($text);
        if ($fullName === '') {
            $emptyName = match ($user->language) {
                User::LANG_RU => "Имя не может быть пустым. Введите заново:",
                User::LANG_UZ_CYRL => "Исм бўш бўлмаслиги керак. Қайтадан ёзинг:",
                default => "Ism bo'sh bo'lmasligi kerak. Qaytadan yozing:",
            };
            $this->api->sendMessage($chatId, $emptyName, $this->cancelKeyboard($user->language));

            return;
        }

        $context = $state->getContextData();
        $meeting = Meeting::findOne($context['meeting_id'] ?? null);
        UserState::clear($user->telegram_id);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ATTENDANCE_MARKING) {
            return;
        }

        $mehmonRoleId = Role::idByCode(Role::CODE_MEHMON);
        if (!$mehmonRoleId) {
            return;
        }

        $guest = User::createGuest($fullName);
        (new MeetingUserRole([
            'meeting_id' => $meeting->id,
            'user_id' => $guest->id,
            'role_id' => (int) $mehmonRoleId,
        ]))->save(false);

        $added = match ($user->language) {
            User::LANG_RU => "✅ «{$fullName}» добавлен(а) как гость. Теперь отметьте посещаемость:",
            User::LANG_UZ_CYRL => "✅ «{$fullName}» меҳмон сифатида қўшилди. Энди давоматини белгиланг:",
            default => "✅ «{$fullName}» mehmon sifatida qo'shildi. Endi davomatini belgilang:",
        };
        $this->api->sendMessage($chatId, $added);
        $this->openAttendanceMarking($chatId, $user, $meeting->id);
    }

    /**
     * Har bir uchrashuvda odamning roli har xil bo'lishi mumkin (Moderatordan tashqari) — shuning
     * uchun «✏️ Rollarni belgilash» orqali avval ishtirokchi tanlanadi, so'ng o'sha kishining
     * SHU uchrashuvdagi roli (guruh shabloniga tegmasdan) belgilanadi.
     */
    private function showMeetingRolePicker(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $group = $meeting->group;
        if ($group->moderator_user_id !== $user->id && !$this->isKotibInGroup($group, $user)) {
            return;
        }

        $participants = $meeting->getParticipantsWithRoles();
        $rows = [];
        foreach ($participants as $uid => $row) {
            if ($uid === $group->moderator_user_id) {
                continue;
            }
            $label = Role::formatPerson($row['user']->full_name, $row['roles']);
            $rows[] = [['text' => $label, 'callback_data' => "mrs:{$meetingId}:{$uid}"]];
        }

        if (!$rows) {
            return;
        }

        $prompt = match ($user->language) {
            User::LANG_RU => "✏️ Кому назначаем роль?",
            User::LANG_UZ_CYRL => "✏️ Кимга рол белгилаймиз?",
            default => "✏️ Kimga rol belgilaymiz?",
        };
        $this->api->sendMessage($chatId, $prompt, $rows);
    }

    private function meetingRoleKeyboard(int $meetingId, int $userId, string $lang): array
    {
        $selected = MeetingUserRole::roleIdsFor($meetingId, $userId);
        $rows = [];
        foreach (Role::assignable() as $role) {
            $checked = in_array($role->id, $selected, true);
            $rows[] = [[
                'text' => ($checked ? '☑ ' : '☐ ') . $role->label(),
                'callback_data' => "mrt:{$meetingId}:{$userId}:{$role->id}",
            ]];
        }
        $back = match ($lang) {
            User::LANG_RU => '⬅️ Назад',
            User::LANG_UZ_CYRL => '⬅️ Орқага',
            default => '⬅️ Orqaga',
        };
        $rows[] = [['text' => $back, 'callback_data' => "mrp:{$meetingId}"]];

        return $rows;
    }

    private function openMeetingRoleScreen(int $chatId, User $user, int $meetingId, int $userId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $group = $meeting->group;
        if ($group->moderator_user_id !== $user->id && !$this->isKotibInGroup($group, $user)) {
            return;
        }
        if ($userId === $group->moderator_user_id) {
            return;
        }

        $member = User::findOne($userId);
        if ($member === null) {
            return;
        }

        $setRoles = match ($user->language) {
            User::LANG_RU => "Назначьте роль(и) на эту встречу:",
            User::LANG_UZ_CYRL => "Шу учрашув учун рол(лар)ни белгиланг:",
            default => "Shu uchrashuv uchun rol(lar)ni belgilang:",
        };
        $this->api->sendMessage(
            $chatId,
            "<b>{$member->full_name}</b>\n{$setRoles}",
            $this->meetingRoleKeyboard($meetingId, $userId, $user->language)
        );
    }

    private function toggleMeetingRole(int $chatId, int $messageId, User $user, int $meetingId, int $userId, int $roleId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $group = $meeting->group;
        if ($group->moderator_user_id !== $user->id && !$this->isKotibInGroup($group, $user)) {
            return;
        }
        if ($userId === $group->moderator_user_id) {
            return;
        }

        MeetingUserRole::toggleAndNormalize($meetingId, $userId, $roleId);

        $this->api->editMessageReplyMarkup($chatId, $messageId, $this->meetingRoleKeyboard($meetingId, $userId, $user->language));
    }

    private function openAttendanceMarking(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ATTENDANCE_MARKING) {
            return;
        }

        $hint = match ($user->language) {
            User::LANG_RU => "\n\nОтметьте посещаемость участников:",
            User::LANG_UZ_CYRL => "\n\nИштирокчилар давоматини белгиланг:",
            default => "\n\nIshtirokchilar davomatini belgilang:",
        };
        $this->api->sendMessage(
            $chatId,
            $this->meetingCardText($meeting, $user->language) . $hint,
            $this->attendanceMarkingKeyboard($meeting, $user->language)
        );
    }

    /**
     * Moderator yoki Kotib boshlashi mumkin (davomatni odatda Kotib belgilaydi, shuning uchun
     * Moderator hozir bo'lmasa ham u boshlab, ishni to'xtatib qo'ymasligi kerak). Boshlagach
     * started_at yoziladi va status attendance_marking bo'ladi, lekin davomat ro'yxati BU YERDA
     * ko'rsatilmaydi — faqat lifecycle kartasi qaytariladi; davomatni «✅ Davomat» bo'limi orqali
     * alohida belgilanadi.
     */
    private function startMeeting(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ANNOUNCED) {
            return;
        }

        $group = $meeting->group;
        $isModerator = $group->moderator_user_id === $user->id;
        if (!$isModerator && !$this->isKotibInGroup($group, $user)) {
            return;
        }

        // Guruhda bir vaqtning o'zida faqat bitta uchrashuv «faol» (attendance_marking) bo'lishi mumkin —
        // aks holda «✅ Davomat» qaysi uchrashuvga tegishli ekanini aniqlab bo'lmay qoladi.
        $alreadyActive = $meeting->group->getMeetings()
            ->andWhere(['status' => Meeting::STATUS_ATTENDANCE_MARKING])
            ->exists();
        if ($alreadyActive) {
            $isTrening = $meeting->group->isUmumiy();
            $text = match (true) {
                $user->language === User::LANG_RU && $isTrening => "⚠️ В группе уже идёт другой тренинг. "
                    . "Сначала завершите его, затем сможете начать этот.",
                $user->language === User::LANG_RU => "⚠️ В группе уже идёт другая встреча. "
                    . "Сначала завершите её, затем сможете начать эту.",
                $user->language === User::LANG_UZ_CYRL => "⚠️ Гуруҳда аллақачон бошланган (давом этаётган) бошқа "
                    . ($isTrening ? 'тренинг' : 'учрашув') . " бор. Аввал ўшани якунланг, кейин бу "
                    . ($isTrening ? 'тренинг' : 'учрашув') . "ни бошлашингиз мумкин.",
                default => "⚠️ Guruhda allaqachon boshlangan (davom etayotgan) boshqa "
                    . ($isTrening ? 'trening' : 'uchrashuv') . " bor. Avval o'shani yakunlang, keyin bu "
                    . ($isTrening ? 'trening' : 'uchrashuv') . "ni boshlashingiz mumkin.",
            };
            $this->api->sendMessage($chatId, $text);

            return;
        }

        $meeting->status = Meeting::STATUS_ATTENDANCE_MARKING;
        $meeting->started_at = date('Y-m-d H:i:s');
        $meeting->save(false);

        $this->api->sendMessage($chatId, $this->meetingCardText($meeting, $user->language), $this->meetingCardKeyboard($meeting, $user, $user->language));
    }

    /** @return User[] Guruh Moderatori + shu guruhdagi barcha Kotiblar (davomatni belgilash uchun javobgarlar). */
    private function responsibleUsers(Group $group): array
    {
        $ids = [$group->moderator_user_id];

        $kotibRoleId = Role::idByCode(Role::CODE_KOTIB);
        if ($kotibRoleId) {
            $ids = array_merge($ids, GroupMemberRole::userIdsWithRole($group->id, $kotibRoleId));
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        return User::find()->where(['id' => $ids])->all();
    }

    /**
     * Vaqti kelgan (meeting_at o'tgan), lekin hali qo'lda boshlanmagan e'lon qilingan uchrashuvlarni
     * o'zi boshlaydi — Plesk Planировщик orqali har necha daqiqada bir chaqiriladi (qarang:
     * CronController::actionAutoStart). Moderator/Kotib endi "▶️ Boshlash"ni qo'lda bosishi shart emas,
     * lekin xohlasa hali ham bosishi mumkin (agar u boshlanmagan bo'lsa).
     */
    public function autoStartDueMeetings(): int
    {
        $due = Meeting::find()
            ->where(['status' => Meeting::STATUS_ANNOUNCED])
            ->andWhere(['<=', 'meeting_at', date('Y-m-d H:i:s')])
            ->all();

        $started = 0;
        foreach ($due as $meeting) {
            $group = $meeting->group;

            // Guruhda bir vaqtning o'zida faqat bitta uchrashuv «faol» bo'lishi mumkin — qo'lda
            // boshlashdagi bilan bir xil qoida, avvalgi uchrashuv yakunlanmaguncha kutamiz.
            $alreadyActive = $group->getMeetings()
                ->andWhere(['status' => Meeting::STATUS_ATTENDANCE_MARKING])
                ->exists();
            if ($alreadyActive) {
                continue;
            }

            $meeting->status = Meeting::STATUS_ATTENDANCE_MARKING;
            $meeting->started_at = date('Y-m-d H:i:s');
            $meeting->save(false);
            $started++;

            foreach ($this->responsibleUsers($group) as $user) {
                if (!$user->isRegistered()) {
                    continue;
                }
                $hint = match ($user->language) {
                    User::LANG_RU => "🔔 Наступило назначенное время — начато автоматически:",
                    User::LANG_UZ_CYRL => "🔔 Режалаштирилган вақт келди — автоматик бошланди:",
                    default => "🔔 Rejalashtirilgan vaqt keldi — avtomatik boshlandi:",
                };
                $this->api->sendMessage(
                    $user->telegram_id,
                    $hint . "\n\n" . $this->meetingCardText($meeting, $user->language),
                    $this->meetingCardKeyboard($meeting, $user, $user->language)
                );
            }
        }

        return $started;
    }

    // -------------------------------------------------------- meeting edit

    private function openEditMenu(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }
        if (!in_array($meeting->status, [Meeting::STATUS_ANNOUNCED, Meeting::STATUS_ATTENDANCE_MARKING], true)) {
            return;
        }

        $editMenu = match ($user->language) {
            User::LANG_RU => ['title' => "«{$meeting->topic}» — что редактируем?", 'topic' => '📌 Тема', 'date' => '📅 Дата и время', 'format' => '📍 Формат', 'back' => '⬅️ Назад'],
            User::LANG_UZ_CYRL => ['title' => "«{$meeting->topic}» — нимани таҳрирлаймиз?", 'topic' => '📌 Мавзу', 'date' => '📅 Сана ва вақт', 'format' => '📍 Формат', 'back' => '⬅️ Орқага'],
            default => ['title' => "«{$meeting->topic}» — nimani tahrirlaymiz?", 'topic' => '📌 Mavzu', 'date' => '📅 Sana va vaqt', 'format' => '📍 Format', 'back' => '⬅️ Orqaga'],
        };
        $this->api->sendMessage($chatId, $editMenu['title'], [
            [['text' => $editMenu['topic'], 'callback_data' => "eft:{$meeting->id}"]],
            [['text' => $editMenu['date'], 'callback_data' => "efd:{$meeting->id}"]],
            [['text' => $editMenu['format'], 'callback_data' => "eff:{$meeting->id}"]],
            [['text' => $editMenu['back'], 'callback_data' => "mopen:{$meeting->id}"]],
        ]);
    }

    private function startEditTopic(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_edit_topic', ['meeting_id' => $meetingId]);
        $prompt = match ($user->language) {
            User::LANG_RU => "Введите новую тему (текущая: «{$meeting->topic}»):",
            User::LANG_UZ_CYRL => "Янги мавзуни ёзинг (ҳозирги: «{$meeting->topic}»):",
            default => "Yangi mavzuni yozing (hozirgi: «{$meeting->topic}»):",
        };
        $this->api->sendMessage($chatId, $prompt, $this->cancelKeyboard($user->language));
    }

    private function handleEditTopicInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $emptyTopic = match ($user->language) {
                User::LANG_RU => "Тема не может быть пустой. Введите заново:",
                User::LANG_UZ_CYRL => "Мавзу бўш бўлмаслиги керак. Қайтадан ёзинг:",
                default => "Mavzu bo'sh bo'lmasligi kerak. Qaytadan yozing:",
            };
            $this->api->sendMessage($chatId, $emptyTopic, $this->cancelKeyboard($user->language));

            return;
        }

        $context = $state->getContextData();
        $meeting = Meeting::findOne($context['meeting_id'] ?? null);
        UserState::clear($user->telegram_id);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $meeting->topic = $text;
        $meeting->save(false);

        $updated = match ($user->language) {
            User::LANG_RU => "✅ Тема обновлена.",
            User::LANG_UZ_CYRL => "✅ Мавзу янгиланди.",
            default => "✅ Mavzu yangilandi.",
        };
        $this->api->sendMessage($chatId, $updated);
        $this->openMeetingCard($chatId, $user, $meeting->id);
    }

    private function startEditDate(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_edit_date', ['meeting_id' => $meetingId]);
        $current = Texts::formatDate($meeting->meeting_at);
        $prompt = match ($user->language) {
            User::LANG_RU => "Введите новую дату и время (текущая: {$current}).\n" . Texts::askMeetingDate($user->language),
            User::LANG_UZ_CYRL => "Янги сана ва вақтни ёзинг (ҳозирги: {$current}).\n" . Texts::askMeetingDate($user->language),
            default => "Yangi sana va vaqtni yozing (hozirgi: {$current}).\n" . Texts::askMeetingDate($user->language),
        };
        $this->api->sendMessage($chatId, $prompt, $this->cancelKeyboard($user->language));
    }

    private function handleEditDateInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $dt = $this->parseMeetingDate($text);
        if (!$dt) {
            $this->api->sendMessage($chatId, Texts::invalidDate($user->language), $this->cancelKeyboard($user->language));

            return;
        }

        $context = $state->getContextData();
        $meeting = Meeting::findOne($context['meeting_id'] ?? null);
        UserState::clear($user->telegram_id);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $meeting->meeting_at = $dt->format('Y-m-d H:i:s');
        $meeting->save(false);

        $updated = match ($user->language) {
            User::LANG_RU => "✅ Дата и время обновлены.",
            User::LANG_UZ_CYRL => "✅ Сана/вақт янгиланди.",
            default => "✅ Sana/vaqt yangilandi.",
        };
        $this->api->sendMessage($chatId, $updated);
        $this->openMeetingCard($chatId, $user, $meeting->id);
    }

    private function startEditFormat(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $prompt = match ($user->language) {
            User::LANG_RU => "Выберите новый формат:",
            User::LANG_UZ_CYRL => "Янги форматни танланг:",
            default => "Yangi formatni tanlang:",
        };
        $this->api->sendMessage($chatId, $prompt, [
            [
                ['text' => Texts::formatLabel(Meeting::FORMAT_OFFLINE, $user->language), 'callback_data' => "efs:{$meetingId}:offline"],
                ['text' => Texts::formatLabel(Meeting::FORMAT_ONLINE, $user->language), 'callback_data' => "efs:{$meetingId}:online"],
            ],
            ...$this->cancelKeyboard($user->language),
        ]);
    }

    private function setMeetingFormat(int $chatId, User $user, int $meetingId, string $format): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $meeting->format = $format;
        $meeting->save(false);

        $updated = match ($user->language) {
            User::LANG_RU => "✅ Формат обновлён.",
            User::LANG_UZ_CYRL => "✅ Формат янгиланди.",
            default => "✅ Format yangilandi.",
        };
        $this->api->sendMessage($chatId, $updated);
        $this->openMeetingCard($chatId, $user, $meeting->id);
    }

    /**
     * Bekor qilish 2 bosqichli: 1) sababni yozish (majburiy), 2) tasdiqlash — tasodifan bosib
     * yubormasligi va boshqalar sababini bilishi uchun.
     */
    private function askCancelReason(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_wait_cancel_reason', ['meeting_id' => $meetingId]);
        $isTrening = $meeting->group->isUmumiy();
        $prompt = match (true) {
            $user->language === User::LANG_RU => "Введите причину отмены «{$meeting->topic}»:",
            $user->language === User::LANG_UZ_CYRL => "«{$meeting->topic}» " . ($isTrening ? 'тренинг' : 'учрашув') . "ини бекор қилиш сабабини ёзинг:",
            default => "«{$meeting->topic}» " . ($isTrening ? 'trening' : 'uchrashuv') . "ini bekor qilish sababini yozing:",
        };
        $this->api->sendMessage($chatId, $prompt, $this->cancelKeyboard($user->language));
    }

    private function handleCancelReasonInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $emptyReason = match ($user->language) {
                User::LANG_RU => "Причину нельзя оставить пустой. Введите заново:",
                User::LANG_UZ_CYRL => "Сабабни бўш қолдириб бўлмайди. Қайтадан ёзинг:",
                default => "Sababni bo'sh qoldirib bo'lmaydi. Qaytadan yozing:",
            };
            $this->api->sendMessage($chatId, $emptyReason, $this->cancelKeyboard($user->language));

            return;
        }

        $context = $state->getContextData();
        $meeting = Meeting::findOne($context['meeting_id']);
        if ($meeting === null) {
            UserState::clear($user->telegram_id);

            return;
        }

        $context['reason'] = $text;
        UserState::set($user->telegram_id, 'meeting_confirm_cancel', $context);

        $date = Texts::formatDate($meeting->meeting_at);
        $confirm = match ($user->language) {
            User::LANG_RU => [
                "⚠️ Вы действительно хотите отменить?\n\n«{$meeting->topic}» — {$date}\nПричина: {$text}",
                "✅ Да, отменить", "❌ Нет",
            ],
            User::LANG_UZ_CYRL => [
                "⚠️ Ростдан ҳам бекор қиласизми?\n\n«{$meeting->topic}» — {$date}\nСабаб: {$text}",
                "✅ Ҳа, бекор қилиш", "❌ Йўқ",
            ],
            default => [
                "⚠️ Rostdan ham bekor qilasizmi?\n\n«{$meeting->topic}» — {$date}\nSabab: {$text}",
                "✅ Ha, bekor qilish", "❌ Yo'q",
            ],
        };

        $this->api->sendMessage(
            $chatId,
            $confirm[0],
            [[
                ['text' => $confirm[1], 'callback_data' => 'mcancel:confirm'],
                ['text' => $confirm[2], 'callback_data' => 'mcancel:abort'],
            ]]
        );
    }

    private function confirmCancelMeeting(int $chatId, User $user, UserState $state): void
    {
        $context = $state->getContextData();
        $meeting = Meeting::findOne($context['meeting_id'] ?? null);
        UserState::clear($user->telegram_id);

        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $group = $meeting->group;
        $reason = $context['reason'] ?? '';

        $meeting->status = Meeting::STATUS_CANCELLED;
        $meeting->cancel_reason = $reason;
        $meeting->cancelled_by = $user->id;
        $meeting->cancelled_at = date('Y-m-d H:i:s');
        $meeting->save(false);

        $post = $this->api->sendMessage($group->channel_id, Texts::meetingCancelledAnnouncement($meeting, $reason));
        $failWarning = match ($user->language) {
            User::LANG_RU => "\n\n⚠️ Не удалось отправить в канал: бот не администратор канала либо неверный ID канала.",
            User::LANG_UZ_CYRL => "\n\n⚠️ Каналга юбора олмадик: бот каналда админ эмас ёки канал ID нотўғри.",
            default => "\n\n⚠️ Kanalga yubora olmadik: bot kanalda admin emas yoki kanal ID noto'g'ri.",
        };
        $warning = empty($post['ok']) ? $failWarning : '';

        $cancelled = match (true) {
            $user->language === User::LANG_RU && $group->isUmumiy() => "❌ Тренинг отменён: «{$meeting->topic}».",
            $user->language === User::LANG_RU => "❌ Встреча отменена: «{$meeting->topic}».",
            $user->language === User::LANG_UZ_CYRL => "❌ " . ($group->isUmumiy() ? 'Тренинг' : 'Учрашув') . " бекор қилинди: «{$meeting->topic}».",
            default => "❌ " . ($group->isUmumiy() ? 'Trening' : 'Uchrashuv') . " bekor qilindi: «{$meeting->topic}».",
        };
        $this->sendMainMenu($chatId, $user, $group, $cancelled . $warning);
    }

    private function abortCancelMeeting(int $chatId, User $user, ?Group $group): void
    {
        UserState::clear($user->telegram_id);
        $notCancelled = match ($user->language) {
            User::LANG_RU => "Не отменено.",
            User::LANG_UZ_CYRL => "Бекор қилинмади.",
            default => "Bekor qilinmadi.",
        };
        $this->sendMainMenu($chatId, $user, $group, $notCancelled);
    }

    private function startMeetingCreation(int $chatId, User $user, ?Group $group): void
    {
        if ($group === null) {
            $this->api->sendMessage($chatId, Texts::noGroup($user->language));

            return;
        }
        if ($group->moderator_user_id !== $user->id) {
            $this->api->sendMessage($chatId, Texts::onlyModerator($user->language));

            return;
        }

        UserState::set($user->telegram_id, 'meeting_wait_topic', ['group_id' => $group->id]);
        $this->api->sendMessage($chatId, Texts::askMeetingTopic($user->language, $group->isUmumiy()), $this->cancelKeyboard($user->language));
    }

    /**
     * Odamlar har xil formatda yozadi (probel yoki ':' orasida, kun/oy/soat bitta raqam bilan ham) —
     * shuning uchun qat'iy Y-m-d H:i o'rniga moslashuvchan parser ishlatamiz.
     */
    private function parseMeetingDate(string $text): ?\DateTime
    {
        $text = trim($text);
        if (!preg_match('/^(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})[ T](\d{1,2})[:\s.](\d{1,2})$/u', $text, $m)) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute] = $m;
        if (!checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59) {
            return null;
        }

        $dt = new \DateTime();
        $dt->setDate((int) $year, (int) $month, (int) $day);
        $dt->setTime((int) $hour, (int) $minute, 0);

        return $dt;
    }

    private function handleMeetingDateInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $dt = $this->parseMeetingDate($text);
        if (!$dt) {
            $this->api->sendMessage($chatId, Texts::invalidDate($user->language), $this->cancelKeyboard($user->language));

            return;
        }

        $this->finishDateSelection($chatId, $user, $state->getContextData(), $dt);
    }

    private function proceedToFormatStep(int $chatId, User $user, array $context): void
    {
        unset($context['day']);
        UserState::set($user->telegram_id, 'meeting_wait_format', $context);

        $isTrening = Group::findOne($context['group_id'] ?? null)?->isUmumiy() ?? false;
        $this->api->sendMessage($chatId, Texts::askMeetingFormat($user->language, $isTrening), [
            [
                ['text' => Texts::formatLabel(Meeting::FORMAT_OFFLINE, $user->language), 'callback_data' => 'fmt:offline'],
                ['text' => Texts::formatLabel(Meeting::FORMAT_ONLINE, $user->language), 'callback_data' => 'fmt:online'],
            ],
            ...$this->cancelKeyboard($user->language),
        ]);
    }

    private function finishDateSelection(int $chatId, User $user, array $context, \DateTime $dt): void
    {
        $context['meeting_at'] = $dt->format('Y-m-d H:i:s');
        $this->proceedToFormatStep($chatId, $user, $context);
    }

    /** Kalendar: bugundan boshlab keyingi 14 kun tugma sifatida (2 tadan qatorda), + qo'lda yozish imkoniyati. */
    private function dayPickerKeyboard(string $lang = User::LANG_UZ): array
    {
        $weekdays = match ($lang) {
            User::LANG_RU => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
            User::LANG_UZ_CYRL => ['Як', 'Душ', 'Сеш', 'Чор', 'Пай', 'Жум', 'Шан'],
            default => self::UZ_WEEKDAYS,
        };
        $months = match ($lang) {
            User::LANG_RU => ['', 'янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'],
            User::LANG_UZ_CYRL => ['', 'Январ', 'Феврал', 'Март', 'Апрел', 'Май', 'Июн', 'Июл', 'Август', 'Сентябр', 'Октябр', 'Ноябр', 'Декабр'],
            default => self::UZ_MONTHS,
        };
        $customLabel = match ($lang) {
            User::LANG_RU => "✏️ Другая дата (ввести вручную)",
            User::LANG_UZ_CYRL => "✏️ Бошқа сана (қўлда ёзиш)",
            default => "✏️ Boshqa sana (qo'lda yozish)",
        };

        $rows = [];
        $row = [];
        for ($i = 0; $i < 14; $i++) {
            $d = (new \DateTime())->modify("+{$i} days");
            $label = $weekdays[(int) $d->format('w')] . ', ' . (int) $d->format('j') . '-' . $months[(int) $d->format('n')];
            $row[] = ['text' => $label, 'callback_data' => 'day:' . $d->format('Y-m-d')];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $rows[] = [['text' => $customLabel, 'callback_data' => 'day:custom']];
        $rows[] = $this->cancelKeyboard($lang)[0];

        return $rows;
    }

    private function timePickerKeyboard(string $lang = User::LANG_UZ): array
    {
        $times = ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'];
        $rows = [];
        $row = [];
        foreach ($times as $t) {
            $row[] = ['text' => $t, 'callback_data' => 'time:' . $t];
            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $customLabel = match ($lang) {
            User::LANG_RU => "✏️ Другое время (ввести вручную)",
            User::LANG_UZ_CYRL => "✏️ Бошқа вақт (қўлда ёзиш)",
            default => "✏️ Boshqa vaqt (qo'lda yozish)",
        };
        $rows[] = [['text' => $customLabel, 'callback_data' => 'time:custom']];
        $rows[] = $this->cancelKeyboard($lang)[0];

        return $rows;
    }

    private function handleDayPicked(int $chatId, User $user, UserState $state, string $value): void
    {
        $context = $state->getContextData();

        if ($value === 'custom') {
            UserState::set($user->telegram_id, 'meeting_wait_date', $context);
            $this->api->sendMessage($chatId, Texts::askMeetingDate($user->language), $this->cancelKeyboard($user->language));

            return;
        }

        $context['day'] = $value;
        UserState::set($user->telegram_id, 'meeting_wait_time', $context);
        $chooseTime = match ($user->language) {
            User::LANG_RU => "Выберите время:",
            User::LANG_UZ_CYRL => "Соатни танланг:",
            default => "Soatni tanlang:",
        };
        $this->api->sendMessage($chatId, $chooseTime, $this->timePickerKeyboard($user->language));
    }

    private function handleTimePicked(int $chatId, User $user, UserState $state, string $value): void
    {
        $context = $state->getContextData();

        if ($value === 'custom') {
            UserState::set($user->telegram_id, 'meeting_wait_time_custom', $context);
            $enterTime = match ($user->language) {
                User::LANG_RU => "Введите время (например 14:00):",
                User::LANG_UZ_CYRL => "Соатни ёзинг (масалан 14:00):",
                default => "Soatni yozing (masalan 14:00):",
            };
            $this->api->sendMessage($chatId, $enterTime, $this->cancelKeyboard($user->language));

            return;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i', $context['day'] . ' ' . $value);
        if (!$dt) {
            return;
        }

        $this->finishDateSelection($chatId, $user, $context, $dt);
    }

    private function handleCustomTimeInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $invalidFormat = match ($user->language) {
            User::LANG_RU => "❌ Неверный формат. Например: 14:00. Введите заново:",
            User::LANG_UZ_CYRL => "❌ Нотўғри формат. Масалан: 14:00. Қайтадан киритинг:",
            default => "❌ Noto'g'ri format. Masalan: 14:00. Qaytadan kiriting:",
        };
        if (!preg_match('/^(\d{1,2})[:\s.](\d{1,2})$/u', trim($text), $m)) {
            $this->api->sendMessage($chatId, $invalidFormat, $this->cancelKeyboard($user->language));

            return;
        }
        [, $hour, $minute] = $m;
        if ((int) $hour > 23 || (int) $minute > 59) {
            $invalidTime = match ($user->language) {
                User::LANG_RU => "❌ Неверное время. Например: 14:00. Введите заново:",
                User::LANG_UZ_CYRL => "❌ Нотўғри вақт. Масалан: 14:00. Қайтадан киритинг:",
                default => "❌ Noto'g'ri vaqt. Masalan: 14:00. Qaytadan kiriting:",
            };
            $this->api->sendMessage($chatId, $invalidTime, $this->cancelKeyboard($user->language));

            return;
        }

        $context = $state->getContextData();
        $dt = \DateTime::createFromFormat('Y-m-d H:i', $context['day'] . ' ' . sprintf('%02d:%02d', (int) $hour, (int) $minute));
        if (!$dt) {
            return;
        }

        $this->finishDateSelection($chatId, $user, $context, $dt);
    }

    // ------------------------------------------------------------ callbacks

    /** @param array<string,mixed> $callback */
    private function handleCallback(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'] ?? '';
        $user = $this->resolveUser($callback['from']);
        $state = UserState::get($user->telegram_id);

        // Async: tugmadagi «yuklanmoqda» animatsiyasi DB ishi bilan parallel tozalanadi (~200ms tejash)
        $this->api->answerCallbackQueryAsync($callback['id']);

        if (str_starts_with($data, 'swg:')) {
            $this->switchGroup($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'day:') && $state?->state === 'meeting_wait_day') {
            $this->handleDayPicked($chatId, $user, $state, substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'time:') && $state?->state === 'meeting_wait_time') {
            $this->handleTimePicked($chatId, $user, $state, substr($data, 5));

            return;
        }

        if (str_starts_with($data, 'fmt:') && $state?->state === 'meeting_wait_format') {
            $format = substr($data, 4);
            $context = $state->getContextData() + ['format' => $format];
            $this->finalizeMeeting($chatId, $user, $context);

            return;
        }

        if ($data === 'noop') {
            return;
        }

        if (str_starts_with($data, 'lang:')) {
            $this->setLanguage($chatId, $user, substr($data, 5));

            return;
        }

        if ($data === 'cancelflow') {
            $meetingId = $state?->getContextData()['meeting_id'] ?? null;
            UserState::clear($user->telegram_id);
            $cancelled = match ($user->language) {
                User::LANG_RU => "❌ Отменено.",
                User::LANG_UZ_CYRL => "❌ Бекор қилинди.",
                default => "❌ Bekor qilindi.",
            };

            if ($meetingId !== null) {
                $this->api->sendMessage($chatId, $cancelled);
                $this->openMeetingCard($chatId, $user, (int) $meetingId);

                return;
            }

            $this->sendMainMenu($chatId, $user, $user->is_observer ? null : $this->currentGroup($user), $cancelled);

            return;
        }

        if (str_starts_with($data, 'mopen:')) {
            $this->openMeetingCard($chatId, $user, (int) substr($data, 6));

            return;
        }

        if (str_starts_with($data, 'mstart:')) {
            $this->startMeeting($chatId, $user, (int) substr($data, 7));

            return;
        }

        if (str_starts_with($data, 'mfinish:')) {
            $this->finishMeeting($chatId, $messageId, $user, (int) substr($data, 8));

            return;
        }

        if (str_starts_with($data, 'medit:')) {
            $this->openEditMenu($chatId, $user, (int) substr($data, 6));

            return;
        }

        if (str_starts_with($data, 'eft:')) {
            $this->startEditTopic($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'efd:')) {
            $this->startEditDate($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'eff:')) {
            $this->startEditFormat($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'efs:')) {
            [, $meetingId, $format] = explode(':', $data);
            $this->setMeetingFormat($chatId, $user, (int) $meetingId, $format);

            return;
        }

        if (str_starts_with($data, 'mcancel:ask:')) {
            $this->askCancelReason($chatId, $user, (int) substr($data, 12));

            return;
        }

        if ($data === 'mcancel:confirm' && $state?->state === 'meeting_confirm_cancel') {
            $this->confirmCancelMeeting($chatId, $user, $state);

            return;
        }

        if ($data === 'mcancel:abort') {
            $this->abortCancelMeeting($chatId, $user, $this->currentGroup($user));

            return;
        }

        if (str_starts_with($data, 'att:open:')) {
            $this->openAttendanceMarking($chatId, $user, (int) substr($data, 9));

            return;
        }

        if (str_starts_with($data, 'att:')) {
            $parts = explode(':', $data);
            if (count($parts) === 3) {
                $this->cycleAttendance($chatId, $messageId, $user, (int) $parts[1], (int) $parts[2]);
            }

            return;
        }

        if (str_starts_with($data, 'agst:')) {
            $this->askGuestName($chatId, $user, (int) substr($data, 5));

            return;
        }

        if (str_starts_with($data, 'mrp:')) {
            $this->showMeetingRolePicker($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'mrs:')) {
            [, $meetingId, $participantId] = explode(':', $data);
            $this->openMeetingRoleScreen($chatId, $user, (int) $meetingId, (int) $participantId);

            return;
        }

        if (str_starts_with($data, 'mrt:')) {
            [, $meetingId, $participantId, $roleId] = explode(':', $data);
            $this->toggleMeetingRole($chatId, $messageId, $user, (int) $meetingId, (int) $participantId, (int) $roleId);

            return;
        }

        if (str_starts_with($data, 'gm:')) {
            [, $groupId, $memberId] = explode(':', $data);
            $this->handleMemberTap($chatId, $user, (int) $groupId, (int) $memberId);

            return;
        }

        if (str_starts_with($data, 'gt:')) {
            [, $groupId, $memberId, $roleId] = explode(':', $data);
            $this->toggleMemberRole($chatId, $messageId, $user, (int) $groupId, (int) $memberId, (int) $roleId);

            return;
        }

        if (str_starts_with($data, 'gb:')) {
            [, $groupId, $memberId] = explode(':', $data);
            $group = Group::findOne((int) $groupId);
            if ($group !== null) {
                $member = User::findOne((int) $memberId);
                if ($member !== null && $member->id !== $user->id) {
                    $roleUpdated = match ($member->language) {
                        User::LANG_RU => "🔄 Ваша роль в группе обновлена. Новое меню:",
                        User::LANG_UZ_CYRL => "🔄 Сизнинг гуруҳдаги ролингиз янгиланди. Янги меню:",
                        default => "🔄 Sizning guruhdagi rolingiz yangilandi. Yangi menyu:",
                    };
                    $this->notifyMenuRefresh($member, $group, $roleUpdated);
                }
            }
            $this->showGroupMembers($chatId, $user, $group);

            return;
        }

        if (str_starts_with($data, 'gvb:')) {
            $groupId = (int) substr($data, 4);
            $this->showGroupMembers($chatId, $user, Group::findOne($groupId));

            return;
        }

        if (str_starts_with($data, 'oum:')) {
            $this->showObserverMeetings($chatId, $user, (int) substr($data, 4));

            return;
        }

        if (str_starts_with($data, 'ogm:')) {
            if ($user->is_observer) {
                $this->showGroupMembers($chatId, $user, Group::findOne((int) substr($data, 4)));
            }

            return;
        }

        if (str_starts_with($data, 'ohm:')) {
            $this->showObserverMeetingHistory($chatId, $user, (int) substr($data, 4));
        }
    }

    /**
     * Ishtirokchilar va rollar endi qo'lda tanlanmaydi: guruhning BARCHA a'zolari avtomatik
     * uchrashuvga qo'shiladi, har birining roli esa "Guruh a'zolari" ekranida saqlangan
     * shablondan (`group_member_roles`) olinadi. Shablon bo'sh bo'lsa — «Ishtirokchi» beriladi.
     * Rolni o'zgartirish uchun endi uchrashuv yaratish jarayoni emas, «👥 Guruh a'zolari» ishlatiladi.
     */
    private function finalizeMeeting(int $chatId, User $creator, array $context): void
    {
        $group = Group::findOne($context['group_id']);
        if ($group === null) {
            UserState::clear($creator->telegram_id);

            return;
        }

        $members = $group->getMembers()->all();
        if (!$members) {
            $noMembers = match ($creator->language) {
                User::LANG_RU => "В группе нет участников — сначала добавьте участников.",
                User::LANG_UZ_CYRL => "Гуруҳда аъзолар йўқ — аввал аъзоларни қўшинг.",
                default => "Guruhda a'zolar yo'q — avval a'zolarni qo'shing.",
            };
            $this->api->sendMessage($chatId, $noMembers);
            UserState::clear($creator->telegram_id);

            return;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $meeting = new Meeting([
                'group_id' => $context['group_id'],
                'topic' => $context['topic'],
                'meeting_at' => $context['meeting_at'],
                'format' => $context['format'],
                'status' => Meeting::STATUS_ANNOUNCED,
                'created_by' => $creator->id,
                'announced_at' => date('Y-m-d H:i:s'),
            ]);
            $meeting->save(false);

            $moderatorRoleId = Role::idByCode(Role::CODE_MODERATOR);
            $ishtirokchiRoleId = Role::ishtirokchi()?->id;
            $roleMap = GroupMemberRole::mapForGroup($group->id);

            foreach ($members as $member) {
                $isCreator = $member->id === $creator->id;
                $roleIds = $roleMap[$member->id] ?? [];

                // «Ishtirokchi» faqat hech qanday boshqa rol (shu jumladan Moderator) yo'q odamga beriladi.
                if (!$roleIds && !$isCreator && $ishtirokchiRoleId !== null) {
                    $roleIds = [$ishtirokchiRoleId];
                }
                if ($isCreator) {
                    $roleIds[] = $moderatorRoleId;
                }

                foreach (array_unique($roleIds) as $roleId) {
                    (new MeetingUserRole([
                        'meeting_id' => $meeting->id,
                        'user_id' => $member->id,
                        'role_id' => (int) $roleId,
                    ]))->save(false);
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Yii::error('finalizeMeeting failed: ' . $e->getMessage());
            $errorMsg = match ($creator->language) {
                User::LANG_RU => 'Произошла ошибка, попробуйте ещё раз.',
                User::LANG_UZ_CYRL => 'Хатолик юз берди, қайтадан уриниб кўринг.',
                default => 'Xatolik yuz berdi, qaytadan urinib ko\'ring.',
            };
            $this->api->sendMessage($chatId, $errorMsg);

            return;
        }

        UserState::clear($creator->telegram_id);

        $post = $this->api->sendMessage($group->channel_id, Texts::announcement($meeting));
        $menuText = empty($post['ok'])
            ? Texts::meetingCreatedButChannelFailed($creator->language, $group->channel_id, $group->isUmumiy())
            : Texts::meetingCreated($creator->language, $group->isUmumiy());
        $this->sendMainMenu($chatId, $creator, $group, $menuText);
    }

    // ---------------------------------------------------------- attendance

    /**
     * «✅ Davomat» tugmasi — faqat allaqachon BOSHLANGAN (attendance_marking) uchrashuvlarni ko'rsatadi.
     * Uchrashuvni boshlash endi alohida, aniq amal («▶️ Boshlash» — faqat Moderator, uchrashuv kartasida).
     */
    private function showAttendanceMeetingList(int $chatId, User $user, ?Group $group): void
    {
        if ($group === null) {
            $this->api->sendMessage($chatId, Texts::noGroup($user->language));

            return;
        }

        $isModerator = $group->moderator_user_id === $user->id;

        $meetings = $group->getMeetings()
            ->andWhere(['status' => Meeting::STATUS_ATTENDANCE_MARKING])
            ->orderBy(['meeting_at' => SORT_DESC])
            ->all();

        $eligible = [];
        foreach ($meetings as $meeting) {
            $isKotib = MeetingUserRole::find()
                ->joinWith('role')
                ->where(['meeting_user_roles.meeting_id' => $meeting->id, 'meeting_user_roles.user_id' => $user->id, 'roles.code' => Role::CODE_KOTIB])
                ->exists();

            if (!$isModerator && !$isKotib) {
                continue;
            }

            $eligible[] = $meeting;
        }

        if (!$eligible) {
            $isTrening = $group->isUmumiy();
            $upcomingBtn = $this->btnLabel('upcoming', $user->language, $isTrening);
            $text = match (true) {
                $user->language === User::LANG_RU && $isTrening => "Пока нет проходящего тренинга.\n"
                    . "(Тренинг должен начать Модератор в разделе «{$upcomingBtn}» кнопкой «▶️ Начать».)",
                $user->language === User::LANG_RU => "Пока нет проходящей встречи.\n"
                    . "(Встречу должен начать Модератор в разделе «{$upcomingBtn}» кнопкой «▶️ Начать».)",
                $user->language === User::LANG_UZ_CYRL => "Ҳозирча давом этаётган " . ($isTrening ? 'тренинг' : 'учрашув') . " йўқ.\n"
                    . "(" . ($isTrening ? 'Тренинг' : 'Учрашув') . "ни Модератор «{$upcomingBtn}» бўлимидан «▶️ Бошлаш» тугмаси билан бошлаши керак.)",
                default => "Hozircha davom etayotgan " . ($isTrening ? 'trening' : 'uchrashuv') . " yo'q.\n"
                    . "(" . ($isTrening ? 'Trening' : 'Uchrashuv') . "ni Moderator «{$upcomingBtn}» bo'limidan «▶️ Boshlash» tugmasi bilan boshlashi kerak.)",
            };
            $this->api->sendMessage($chatId, $text);

            return;
        }

        // Guruhda odatda bir vaqtda faqat bitta uchrashuv boradi — shuning uchun to'g'ridan-to'g'ri
        // davomat ekranini ochamiz, «qaysi uchrashuv» deb ortiqcha tanlov ko'rsatmaymiz.
        if (count($eligible) === 1) {
            $this->openAttendanceMarking($chatId, $user, $eligible[0]->id);

            return;
        }

        $rows = [];
        foreach ($eligible as $meeting) {
            $rows[] = [['text' => Texts::formatDate($meeting->meeting_at) . ' — ' . $meeting->topic, 'callback_data' => "att:open:{$meeting->id}"]];
        }

        $isTrening = $group->isUmumiy();
        $prompt = match (true) {
            $user->language === User::LANG_RU && $isTrening => "Для какого тренинга отмечаем посещаемость?",
            $user->language === User::LANG_RU => "Для какой встречи отмечаем посещаемость?",
            $user->language === User::LANG_UZ_CYRL => "Қайси " . ($isTrening ? 'тренинг' : 'учрашув') . " учун давоматни белгилаймиз?",
            default => "Qaysi " . ($isTrening ? 'trening' : 'uchrashuv') . " uchun davomatni belgilaymiz?",
        };
        $this->api->sendMessage($chatId, $prompt, $rows);
    }

    private function cycleAttendance(int $chatId, int $messageId, User $marker, int $meetingId, int $userId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $existing = Attendance::find()->where(['meeting_id' => $meetingId, 'user_id' => $userId])->one();
        $next = match ($existing?->status) {
            Attendance::STATUS_PRESENT => Attendance::STATUS_ONLINE,
            Attendance::STATUS_ONLINE => Attendance::STATUS_ABSENT,
            Attendance::STATUS_ABSENT => Attendance::STATUS_EXCUSED,
            Attendance::STATUS_EXCUSED => Attendance::STATUS_PRESENT,
            default => Attendance::STATUS_PRESENT,
        };

        Attendance::mark($meetingId, $userId, $next, $marker->id);

        $this->api->editMessageReplyMarkup($chatId, $messageId, $this->attendanceMarkingKeyboard($meeting));
    }

    /** Uchrashuvni yakunlash — Moderator yoki Kotib bosishi mumkin, minimal davomiylik cheklovi yo'q. */
    private function finishMeeting(int $chatId, int $messageId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ATTENDANCE_MARKING) {
            return;
        }

        $group = $meeting->group;
        $isModerator = $group->moderator_user_id === $user->id;
        $isKotib = $this->isKotibInGroup($group, $user);
        if (!$isModerator && !$isKotib) {
            return;
        }

        $participants = $meeting->getParticipantsWithRoles();
        $marked = $meeting->getAttendances()->select('user_id')->column();
        foreach (array_keys($participants) as $uid) {
            if (!in_array($uid, $marked, true)) {
                Attendance::mark($meetingId, $uid, Attendance::STATUS_ABSENT, $meeting->created_by);
            }
        }

        $meeting->status = Meeting::STATUS_FINISHED;
        $meeting->ended_at = date('Y-m-d H:i:s');
        $meeting->results_published_at = date('Y-m-d H:i:s');
        $meeting->save(false);

        $resultsText = Texts::meetingResults($meeting);
        $post = $this->api->sendMessage($group->channel_id, $resultsText);
        $failWarning = match ($user->language) {
            User::LANG_RU => "\n\n⚠️ Не удалось отправить в канал: бот не администратор канала либо неверный ID канала.",
            User::LANG_UZ_CYRL => "\n\n⚠️ Каналга юбора олмадик: бот каналда админ эмас ёки канал ID нотўғри.",
            default => "\n\n⚠️ Kanalga yubora olmadik: bot kanalda admin emas yoki kanal ID noto'g'ri.",
        };
        $warning = empty($post['ok']) ? $failWarning : '';
        $this->api->editMessageText($chatId, $messageId, Texts::attendanceSaved($user->language) . $warning . "\n\n" . $resultsText);
    }
}
