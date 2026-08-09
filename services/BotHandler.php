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
    private const BTN_UPCOMING = '📅 Uchrashuvlar';
    private const BTN_CREATE_MEETING = '➕ Yangi uchrashuv';
    private const BTN_MARK_ATTENDANCE = '✅ Davomat';
    private const BTN_GROUP_MEMBERS = '👥 Guruh';
    private const BTN_SWITCH_GROUP = '🔀 Guruhni almashtirish';

    /** Regламент: uchrashuv kamida shuncha soat oldin e'lon qilinishi kerak. */
    private const MIN_ADVANCE_HOURS = 12;
    /** Davomatni belgilash faqat uchrashuv boshlanganidan keyin va shuncha soat ichida ochiq. */
    private const ATTENDANCE_WINDOW_HOURS = 12;
    /** Uchrashuv boshlanganidan keyin kamida shuncha soat o'tmaguncha «Yakunlash» tugmasi ishlamaydi. */
    private const MIN_MEETING_DURATION_HOURS = 1;

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

    /** @return Group[] Foydalanuvchi a'zo bo'lgan barcha guruhlar (id bo'yicha tartiblangan). */
    private function userGroups(User $user): array
    {
        return $user->getGroups()->orderBy(['{{%groups}}.id' => SORT_ASC])->all();
    }

    /**
     * Foydalanuvchining "faol" guruhi — bot menyusi shu kontekstda ishlaydi.
     * Bir kishi bir nechta guruhga a'zo bo'lishi mumkin, shuning uchun tanlov users.active_group_id'da
     * saqlanadi va «🔀 Guruhni almashtirish» orqali o'zgartiriladi. Agar hali tanlanmagan bo'lsa yoki
     * saqlangan guruhdan chiqarilgan bo'lsa — birinchi a'zolikka avtomatik qaytariladi.
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

        $user->active_group_id = $groups[0]->id;
        $user->save(false);

        return $groups[0];
    }

    /**
     * Menyu foydalanuvchi roliga qarab farq qiladi:
     * oddiy a'zo — 2 tugma (Uchrashuvlar, Guruh); Kotib — 3 (+ Davomat); Moderator — 4 (+ Yangi uchrashuv, Davomat).
     * Agar foydalanuvchi bir nechta guruhga a'zo bo'lsa, guruhni almashtirish tugmasi ham qo'shiladi.
     */
    private function mainMenuKeyboard(User $user, ?Group $group): array
    {
        if ($group === null) {
            return [];
        }

        $isModerator = $group->moderator_user_id === $user->id;
        $isKotib = !$isModerator && $this->isKotibInGroup($group, $user);

        $rows = [[self::BTN_UPCOMING, self::BTN_GROUP_MEMBERS]];

        $row2 = [];
        if ($isModerator) {
            $row2[] = self::BTN_CREATE_MEETING;
        }
        if ($isModerator || $isKotib) {
            $row2[] = self::BTN_MARK_ATTENDANCE;
        }
        if ($row2) {
            $rows[] = $row2;
        }

        if (count($this->userGroups($user)) > 1) {
            $rows[] = [self::BTN_SWITCH_GROUP];
        }

        return $rows;
    }

    /** Bir nechta guruhga a'zo bo'lsa — tanlash uchun inline ro'yxat. */
    private function showGroupSwitcher(int $chatId, User $user): void
    {
        $groups = $this->userGroups($user);
        if (count($groups) < 2) {
            return;
        }

        $rows = [];
        foreach ($groups as $group) {
            $prefix = $group->id === $user->active_group_id ? '✅ ' : '';
            $rows[] = [['text' => $prefix . $group->name, 'callback_data' => "swg:{$group->id}"]];
        }

        $this->api->sendMessage($chatId, "Qaysi guruh bilan ishlaysiz?", $rows);
    }

    private function switchGroup(int $chatId, User $user, int $groupId): void
    {
        $isMember = GroupMember::find()->where(['group_id' => $groupId, 'user_id' => $user->id])->exists();
        if (!$isMember) {
            return;
        }

        $user->active_group_id = $groupId;
        $user->save(false);

        $this->sendMainMenu($chatId, $user, $this->currentGroup($user), "✅ Faol guruh: " . Group::findOne($groupId)->name);
    }

    private function isKotibInGroup(Group $group, User $user): bool
    {
        $kotibRoleId = Role::find()->where(['code' => Role::CODE_KOTIB])->select('id')->scalar();
        if (!$kotibRoleId) {
            return false;
        }

        return in_array((int) $kotibRoleId, GroupMemberRole::roleIdsFor($group->id, $user->id), true);
    }

    private function isTestBypassUser(User $user): bool
    {
        $ids = array_filter(array_map('trim', explode(',', (string) (Yii::$app->params['test.bypassTelegramIds'] ?? ''))));

        return in_array((string) $user->telegram_id, $ids, true);
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
        $this->api->sendMessage(
            $chatId,
            Texts::askPhone(),
            null,
            [[['text' => '📱 Raqamni ulashish', 'request_contact' => true]]]
        );
    }

    private function handlePhoneInput(int $chatId, User $user, string $phone): void
    {
        $phone = trim($phone);
        if ($phone === '') {
            $this->api->sendMessage($chatId, "Raqam bo'sh bo'lmasligi kerak. Tugma orqali yuboring yoki qo'lda yozing:");

            return;
        }

        $user->phone = $phone;
        $user->save(false);
        UserState::clear($user->telegram_id);
        $this->sendMainMenu($chatId, $user, $this->currentGroup($user), Texts::registrationDone($user->full_name));
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

        if ($text === '/start') {
            UserState::clear($user->telegram_id);
            if (!$user->isRegistered()) {
                UserState::set($user->telegram_id, 'reg_wait_name');
                $this->api->sendMessage($chatId, Texts::welcome());
            } elseif (empty($user->phone)) {
                $this->askForPhone($chatId, $user);
            } else {
                $this->sendMainMenu($chatId, $user, $this->currentGroup($user), Texts::mainMenuHint());
            }

            return;
        }

        if (!$user->isRegistered() && $stateName !== 'reg_wait_name' && $stateName !== 'reg_wait_position') {
            UserState::set($user->telegram_id, 'reg_wait_name');
            $this->api->sendMessage($chatId, Texts::welcome());

            return;
        }

        switch ($stateName) {
            case 'reg_wait_name':
                $user->full_name = $text;
                $user->save(false);
                UserState::set($user->telegram_id, 'reg_wait_position');
                $this->api->sendMessage($chatId, Texts::askPosition());

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
                UserState::set($user->telegram_id, 'meeting_wait_day', $state->getContextData() + ['topic' => $text]);
                $this->api->sendMessage($chatId, "Uchrashuv sanasini tanlang:", $this->dayPickerKeyboard($user));

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
        }

        // idle state — обрабатываем кнопки главного меню
        $group = $this->currentGroup($user);

        switch ($text) {
            case self::BTN_UPCOMING:
                $this->showUpcomingMeetings($chatId, $user);
                break;

            case self::BTN_CREATE_MEETING:
                $this->startMeetingCreation($chatId, $user, $group);
                break;

            case self::BTN_GROUP_MEMBERS:
                $this->showGroupMembers($chatId, $user, $group);
                break;

            case self::BTN_MARK_ATTENDANCE:
                $this->showAttendanceMeetingList($chatId, $user, $group);
                break;

            case self::BTN_SWITCH_GROUP:
                $this->showGroupSwitcher($chatId, $user);
                break;

            default:
                $this->sendMainMenu($chatId, $user, $group, Texts::mainMenuHint());
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
            $this->api->sendMessage($chatId, Texts::noGroup());

            return;
        }

        $members = $group->getMembers()->all();
        if (!$members) {
            $this->api->sendMessage($chatId, "Guruhda hali a'zolar yo'q. Administrator qo'shishi kerak.");

            return;
        }

        usort($members, fn (User $a, User $b) => $this->memberPriority($group, $a) <=> $this->memberPriority($group, $b));

        $isModerator = $group->moderator_user_id === $user->id;
        $hint = $isModerator ? "Rolini o'zgartirish uchun ismini bosing:" : "Kontaktini (telefon raqamini) ko'rish uchun ismini bosing:";

        $rows = [];
        foreach ($members as $member) {
            $label = Role::formatPerson($member->full_name, $this->memberRoles($group, $member));
            $rows[] = [['text' => $label, 'callback_data' => "gm:{$group->id}:{$member->id}"]];
        }

        $this->api->sendMessage($chatId, "👥 <b>Guruh a'zolari — {$group->name}</b>\n\n{$hint}", $rows);
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

        $this->showMemberContact($chatId, $group, $memberId);
    }

    private function showMemberContact(int $chatId, Group $group, int $memberId): void
    {
        $member = User::findOne($memberId);
        if ($member === null) {
            return;
        }

        $roles = $this->memberRoles($group, $member);
        $icon = Role::leadEmoji($roles);
        $phone = $member->phone ? "📱 <b>{$member->phone}</b>" : "📱 Telefon raqami hali ko'rsatilmagan";

        $text = trim("{$icon} <b>{$member->full_name}</b>") . "\n"
            . (($member->position ?? '') !== '' ? "💼 " . htmlspecialchars($member->position) . "\n" : '')
            . "🎭 " . Role::namesOnly($roles) . "\n"
            . $phone;

        $this->api->sendMessage($chatId, $text, [[['text' => '⬅️ Orqaga', 'callback_data' => "gvb:{$group->id}"]]]);
    }

    /** Ro'yxatlarda tartiblash uchun: Moderator birinchi, so'ng Kotib va boshqa rollar, Ishtirokchi oxirida. */
    private function memberPriority(Group $group, User $member): int
    {
        if ($member->id === $group->moderator_user_id) {
            return Role::priority()[Role::CODE_MODERATOR];
        }

        $roleIds = GroupMemberRole::roleIdsFor($group->id, $member->id);
        if (!$roleIds) {
            return Role::priority()[Role::CODE_ISHTIROKCHI];
        }

        return Role::bestPriority(Role::find()->where(['id' => $roleIds])->all());
    }

    /**
     * A'zoning rollari (Role obyektlari) — Moderator hisobga olingan holda.
     * «Ishtirokchi» — faqat hech qanday boshqa rol yo'q odam uchun (Moderator ham hisobga olinadi).
     * Moderator + Texnik xodim kabi kombinatsiyalar bo'lishi mumkin, lekin «rol + Ishtirokchi» hech qachon bo'lmaydi.
     *
     * @return Role[]
     */
    private function memberRoles(Group $group, User $member): array
    {
        $isModerator = $member->id === $group->moderator_user_id;
        $roleIds = GroupMemberRole::roleIdsFor($group->id, $member->id);

        if (!$roleIds && !$isModerator) {
            $ishtirokchi = Role::ishtirokchi();

            return $ishtirokchi ? [$ishtirokchi] : [];
        }

        $roles = Role::find()->where(['id' => $roleIds])->all();
        if ($isModerator) {
            $moderator = Role::find()->where(['code' => Role::CODE_MODERATOR])->one();
            if ($moderator) {
                array_unshift($roles, $moderator);
            }
        }

        return $roles;
    }

    private function memberRoleKeyboard(int $groupId, int $userId): array
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
        $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => "gb:{$groupId}:{$userId}"]];

        return $rows;
    }

    /** Rol/guruh o'zgarganda foydalanuvchiga yangilangan menyu (tugmalar) darhol yuboriladi — /start kutish shart emas. */
    public function notifyMenuRefresh(User $user, Group $group, string $text): void
    {
        $this->api->sendMessage($user->telegram_id, $text, null, $this->mainMenuKeyboard($user, $group));
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

        $this->api->sendMessage(
            $chatId,
            "<b>{$member->full_name}</b>\n{$position}{$phone}\nUchun rol(lar)ni belgilang:",
            $this->memberRoleKeyboard($groupId, $memberId)
        );
    }

    private function toggleMemberRole(int $chatId, int $messageId, User $user, int $groupId, int $memberId, int $roleId): void
    {
        $group = Group::findOne($groupId);
        if ($group === null || $group->moderator_user_id !== $user->id) {
            return;
        }

        GroupMemberRole::toggle($groupId, $memberId, $roleId);

        $this->api->editMessageReplyMarkup($chatId, $messageId, $this->memberRoleKeyboard($groupId, $memberId));
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
            $this->api->sendMessage($chatId, Texts::noGroup());

            return;
        }

        $meetings = $this->activeMeetings($group);
        if (!$meetings) {
            $this->api->sendMessage($chatId, Texts::upcomingMeetingsEmpty());

            return;
        }

        $isModerator = $group->moderator_user_id === $user->id;

        if (!$isModerator) {
            $text = Texts::upcomingMeetingsHeader() . "\n\n";
            foreach ($meetings as $meeting) {
                $myRoles = $meeting->getRolesOfUser($user->id);
                $text .= Texts::upcomingMeetingItem($meeting, $myRoles) . "\n";
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
        $this->api->sendMessage($chatId, Texts::upcomingMeetingsHeader() . "\n\nBatafsil uchun bosing:", $rows);
    }

    /** @return Meeting[] */
    private function activeMeetings(Group $group): array
    {
        return $group->getMeetings()
            ->andWhere(['status' => [Meeting::STATUS_ANNOUNCED, Meeting::STATUS_ATTENDANCE_MARKING]])
            ->orderBy(['meeting_at' => SORT_ASC])
            ->all();
    }

    private function meetingStatusLabel(string $status): string
    {
        return match ($status) {
            Meeting::STATUS_ANNOUNCED => "🕓 E'lon qilingan (hali boshlanmagan)",
            Meeting::STATUS_ATTENDANCE_MARKING => "▶️ Davom etmoqda",
            Meeting::STATUS_FINISHED => "🏁 Yakunlangan",
            Meeting::STATUS_CANCELLED => "❌ Bekor qilingan",
            default => $status,
        };
    }

    private function meetingCardText(Meeting $meeting): string
    {
        $lines = [
            "📌 <b>{$meeting->topic}</b>",
            "🗓 " . Texts::formatDate($meeting->meeting_at) . " · {$meeting->formatLabel()}",
            "📊 Holat: " . $this->meetingStatusLabel($meeting->status),
        ];
        if ($meeting->started_at) {
            $lines[] = "▶️ Boshlangan: " . Texts::formatDate($meeting->started_at);
        }
        if ($meeting->ended_at) {
            $lines[] = "🏁 Tugagan: " . Texts::formatDate($meeting->ended_at);
        }
        if ($meeting->status === Meeting::STATUS_CANCELLED) {
            $lines[] = "📝 Bekor qilish sababi: " . ($meeting->cancel_reason ?: '—');
        }

        return implode("\n", $lines);
    }

    /**
     * Uchrashuv "kartasi" — umumiy holat va lifecycle tugmalari (boshlash/yakunlash/tahrirlash/bekor qilish).
     * MUHIM: bu yerda ishtirokchilarning davomat ro'yxati YO'Q — u boshqa ekranda (qarang: openAttendanceMarking),
     * chunki davomatni belgilash odatda boshqa odam (Kotib) ishi, Moderator boshlagandan keyin darhol
     * davomat ro'yxatini ko'rishi shart emas.
     */
    private function meetingCardKeyboard(Meeting $meeting, User $viewer): array
    {
        $isModerator = $meeting->group->moderator_user_id === $viewer->id;
        $rows = [];

        if ($meeting->status === Meeting::STATUS_ATTENDANCE_MARKING) {
            $rows[] = [['text' => "🏁 Uchrashuvni yakunlash", 'callback_data' => "mfinish:{$meeting->id}"]];
        }

        if ($isModerator) {
            if ($meeting->status === Meeting::STATUS_ANNOUNCED) {
                $rows[] = [['text' => "▶️ Uchrashuvni boshlash", 'callback_data' => "mstart:{$meeting->id}"]];
            }
            if (in_array($meeting->status, [Meeting::STATUS_ANNOUNCED, Meeting::STATUS_ATTENDANCE_MARKING], true)) {
                $rows[] = [
                    ['text' => "✏️ Tahrirlash", 'callback_data' => "medit:{$meeting->id}"],
                    ['text' => "❌ Bekor qilish", 'callback_data' => "mcancel:ask:{$meeting->id}"],
                ];
            }
        }

        return $rows;
    }

    private function openMeetingCard(int $chatId, User $viewer, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $this->api->sendMessage($chatId, $this->meetingCardText($meeting), $this->meetingCardKeyboard($meeting, $viewer));
    }

    /** Faqat davomat ro'yxati — «✅ Davomat» bo'limi orqali ochiladi (Kotib/Moderator uchun alohida ekran). */
    private function attendanceMarkingKeyboard(Meeting $meeting): array
    {
        $participants = $meeting->getParticipantsWithRoles();
        $attendances = $meeting->getAttendances()->indexBy('user_id')->all();

        $rows = [];
        foreach ($participants as $uid => $row) {
            $status = $attendances[$uid]->status ?? null;
            $label = $row['user']->full_name . ' — ' . Texts::attendanceRowStatus((string) $status);
            $rows[] = [['text' => $label, 'callback_data' => "att:{$meeting->id}:{$uid}"]];
        }
        $rows[] = [['text' => "🏁 Uchrashuvni yakunlash", 'callback_data' => "mfinish:{$meeting->id}"]];

        return $rows;
    }

    private function openAttendanceMarking(int $chatId, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->status !== Meeting::STATUS_ATTENDANCE_MARKING) {
            return;
        }

        $this->api->sendMessage(
            $chatId,
            $this->meetingCardText($meeting) . "\n\nIshtirokchilar davomatini belgilang:",
            $this->attendanceMarkingKeyboard($meeting)
        );
    }

    /**
     * Faqat Moderator boshlashi mumkin. Boshlagach started_at yoziladi va status attendance_marking bo'ladi,
     * lekin davomat ro'yxati BU YERDA ko'rsatilmaydi — Moderatorga faqat lifecycle kartasi qaytariladi.
     * Davomatni boshqa odam (Kotib) «✅ Davomat» bo'limi orqali alohida belgilaydi.
     */
    private function startMeeting(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id || $meeting->status !== Meeting::STATUS_ANNOUNCED) {
            return;
        }

        // Guruhda bir vaqtning o'zida faqat bitta uchrashuv «faol» (attendance_marking) bo'lishi mumkin —
        // aks holda «✅ Davomat» qaysi uchrashuvga tegishli ekanini aniqlab bo'lmay qoladi.
        $alreadyActive = $meeting->group->getMeetings()
            ->andWhere(['status' => Meeting::STATUS_ATTENDANCE_MARKING])
            ->exists();
        if ($alreadyActive) {
            $this->api->sendMessage(
                $chatId,
                "⚠️ Guruhda allaqachon boshlangan (davom etayotgan) boshqa uchrashuv bor. "
                . "Avval o'shani yakunlang, keyin bu uchrashuvni boshlashingiz mumkin."
            );

            return;
        }

        $meeting->status = Meeting::STATUS_ATTENDANCE_MARKING;
        $meeting->started_at = date('Y-m-d H:i:s');
        $meeting->save(false);

        $this->api->sendMessage($chatId, $this->meetingCardText($meeting), $this->meetingCardKeyboard($meeting, $user));
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

        $this->api->sendMessage($chatId, "«{$meeting->topic}» — nimani tahrirlaymiz?", [
            [['text' => '📌 Mavzu', 'callback_data' => "eft:{$meeting->id}"]],
            [['text' => '📅 Sana va vaqt', 'callback_data' => "efd:{$meeting->id}"]],
            [['text' => '📍 Format', 'callback_data' => "eff:{$meeting->id}"]],
            [['text' => '⬅️ Orqaga', 'callback_data' => "mopen:{$meeting->id}"]],
        ]);
    }

    private function startEditTopic(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_edit_topic', ['meeting_id' => $meetingId]);
        $this->api->sendMessage($chatId, "Yangi mavzuni yozing (hozirgi: «{$meeting->topic}»):");
    }

    private function handleEditTopicInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $this->api->sendMessage($chatId, "Mavzu bo'sh bo'lmasligi kerak. Qaytadan yozing:");

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

        $this->api->sendMessage($chatId, "✅ Mavzu yangilandi.");
        $this->openMeetingCard($chatId, $user, $meeting->id);
    }

    private function startEditDate(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        UserState::set($user->telegram_id, 'meeting_edit_date', ['meeting_id' => $meetingId]);
        $this->api->sendMessage(
            $chatId,
            "Yangi sana va vaqtni yozing (hozirgi: " . Texts::formatDate($meeting->meeting_at) . ").\n" . Texts::askMeetingDate()
        );
    }

    private function handleEditDateInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $dt = $this->parseMeetingDate($text);
        if (!$dt) {
            $this->api->sendMessage($chatId, Texts::invalidDate());

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

        $this->api->sendMessage($chatId, "✅ Sana/vaqt yangilandi.");
        $this->openMeetingCard($chatId, $user, $meeting->id);
    }

    private function startEditFormat(int $chatId, User $user, int $meetingId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null || $meeting->group->moderator_user_id !== $user->id) {
            return;
        }

        $this->api->sendMessage($chatId, "Yangi formatni tanlang:", [
            [
                ['text' => 'Oflayn', 'callback_data' => "efs:{$meetingId}:offline"],
                ['text' => 'Onlayn', 'callback_data' => "efs:{$meetingId}:online"],
            ],
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

        $this->api->sendMessage($chatId, "✅ Format yangilandi.");
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
        $this->api->sendMessage(
            $chatId,
            "«{$meeting->topic}» uchrashuvini bekor qilish sababini yozing:"
        );
    }

    private function handleCancelReasonInput(int $chatId, User $user, UserState $state, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $this->api->sendMessage($chatId, "Sababni bo'sh qoldirib bo'lmaydi. Qaytadan yozing:");

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

        $this->api->sendMessage(
            $chatId,
            "⚠️ Rostdan ham bekor qilasizmi?\n\n«{$meeting->topic}» — " . Texts::formatDate($meeting->meeting_at)
                . "\nSabab: {$text}",
            [[
                ['text' => "✅ Ha, bekor qilish", 'callback_data' => 'mcancel:confirm'],
                ['text' => "❌ Yo'q", 'callback_data' => 'mcancel:abort'],
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
        $warning = empty($post['ok']) ? "\n\n⚠️ Kanalga yubora olmadik: bot kanalda admin emas yoki kanal ID noto'g'ri." : '';

        $this->sendMainMenu($chatId, $user, $group, "❌ Uchrashuv bekor qilindi: «{$meeting->topic}»." . $warning);
    }

    private function abortCancelMeeting(int $chatId, User $user, ?Group $group): void
    {
        UserState::clear($user->telegram_id);
        $this->sendMainMenu($chatId, $user, $group, "Bekor qilinmadi.");
    }

    private function startMeetingCreation(int $chatId, User $user, ?Group $group): void
    {
        if ($group === null) {
            $this->api->sendMessage($chatId, Texts::noGroup());

            return;
        }
        if ($group->moderator_user_id !== $user->id) {
            $this->api->sendMessage($chatId, Texts::onlyModerator());

            return;
        }

        UserState::set($user->telegram_id, 'meeting_wait_topic', ['group_id' => $group->id]);
        $this->api->sendMessage($chatId, Texts::askMeetingTopic());
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
            $this->api->sendMessage($chatId, Texts::invalidDate());

            return;
        }

        $this->finishDateSelection($chatId, $user, $state->getContextData(), $dt);
    }

    /** Sinov uchun ruxsat berilgan foydalanuvchilarga 12 soatlik qoida qo'llanmaydi. */
    private function isMeetingTimeAllowed(\DateTime $dt, User $user): bool
    {
        if ($this->isTestBypassUser($user)) {
            return true;
        }

        return $dt >= new \DateTime('+' . self::MIN_ADVANCE_HOURS . ' hours');
    }

    private function proceedToFormatStep(int $chatId, User $user, array $context): void
    {
        unset($context['day']);
        UserState::set($user->telegram_id, 'meeting_wait_format', $context);

        $this->api->sendMessage($chatId, Texts::askMeetingFormat(), [
            [['text' => 'Oflayn', 'callback_data' => 'fmt:offline'], ['text' => 'Onlayn', 'callback_data' => 'fmt:online']],
        ]);
    }

    private function finishDateSelection(int $chatId, User $user, array $context, \DateTime $dt): void
    {
        if (!$this->isMeetingTimeAllowed($dt, $user)) {
            UserState::set($user->telegram_id, 'meeting_wait_day', $context);
            $this->api->sendMessage(
                $chatId,
                Texts::meetingTooSoon(self::MIN_ADVANCE_HOURS) . "\n\nSanani qaytadan tanlang:",
                $this->dayPickerKeyboard($user)
            );

            return;
        }

        $context['meeting_at'] = $dt->format('Y-m-d H:i:s');
        $this->proceedToFormatStep($chatId, $user, $context);
    }

    /**
     * Kalendar: keyingi 14 kun tugma sifatida (2 tadan qatorda), + qo'lda yozish imkoniyati.
     * Sinov foydalanuvchisi uchun bugundan boshlanadi, qolganlar uchun ertadan (12soatlik qoidaga yaqinroq mos kelsin uchun).
     */
    private function dayPickerKeyboard(User $user): array
    {
        $startOffset = $this->isTestBypassUser($user) ? 0 : 1;
        $rows = [];
        $row = [];
        for ($i = $startOffset; $i < $startOffset + 14; $i++) {
            $d = (new \DateTime())->modify("+{$i} days");
            $label = self::UZ_WEEKDAYS[(int) $d->format('w')] . ', ' . (int) $d->format('j') . '-' . self::UZ_MONTHS[(int) $d->format('n')];
            $row[] = ['text' => $label, 'callback_data' => 'day:' . $d->format('Y-m-d')];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $rows[] = [['text' => "✏️ Boshqa sana (qo'lda yozish)", 'callback_data' => 'day:custom']];

        return $rows;
    }

    private function timePickerKeyboard(): array
    {
        $times = ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'];
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
        $rows[] = [['text' => "✏️ Boshqa vaqt (qo'lda yozish)", 'callback_data' => 'time:custom']];

        return $rows;
    }

    private function handleDayPicked(int $chatId, User $user, UserState $state, string $value): void
    {
        $context = $state->getContextData();

        if ($value === 'custom') {
            UserState::set($user->telegram_id, 'meeting_wait_date', $context);
            $this->api->sendMessage($chatId, Texts::askMeetingDate());

            return;
        }

        $context['day'] = $value;
        UserState::set($user->telegram_id, 'meeting_wait_time', $context);
        $this->api->sendMessage($chatId, "Soatni tanlang:", $this->timePickerKeyboard());
    }

    private function handleTimePicked(int $chatId, User $user, UserState $state, string $value): void
    {
        $context = $state->getContextData();

        if ($value === 'custom') {
            UserState::set($user->telegram_id, 'meeting_wait_time_custom', $context);
            $this->api->sendMessage($chatId, "Soatni yozing (masalan 14:00):");

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
        if (!preg_match('/^(\d{1,2})[:\s.](\d{1,2})$/u', trim($text), $m)) {
            $this->api->sendMessage($chatId, "❌ Noto'g'ri format. Masalan: 14:00. Qaytadan kiriting:");

            return;
        }
        [, $hour, $minute] = $m;
        if ((int) $hour > 23 || (int) $minute > 59) {
            $this->api->sendMessage($chatId, "❌ Noto'g'ri vaqt. Masalan: 14:00. Qaytadan kiriting:");

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

        $this->api->answerCallbackQuery($callback['id']);

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
            $this->openAttendanceMarking($chatId, (int) substr($data, 9));

            return;
        }

        if (str_starts_with($data, 'att:')) {
            $parts = explode(':', $data);
            if (count($parts) === 3) {
                $this->cycleAttendance($chatId, $messageId, $user, (int) $parts[1], (int) $parts[2]);
            }

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
                    $this->notifyMenuRefresh($member, $group, "🔄 Sizning guruhdagi rolingiz yangilandi. Yangi menyu:");
                }
            }
            $this->showGroupMembers($chatId, $user, $group);

            return;
        }

        if (str_starts_with($data, 'gvb:')) {
            $groupId = (int) substr($data, 4);
            $this->showGroupMembers($chatId, $user, Group::findOne($groupId));
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
            $this->api->sendMessage($chatId, "Guruhda a'zolar yo'q — avval a'zolarni qo'shing.");
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

            $moderatorRoleId = Role::find()->where(['code' => Role::CODE_MODERATOR])->select('id')->scalar();
            $ishtirokchiRoleId = Role::ishtirokchi()?->id;

            foreach ($members as $member) {
                $isCreator = $member->id === $creator->id;
                $roleIds = GroupMemberRole::roleIdsFor($group->id, $member->id);

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
            $this->api->sendMessage($chatId, 'Xatolik yuz berdi, qaytadan urinib ko\'ring.');

            return;
        }

        UserState::clear($creator->telegram_id);

        $post = $this->api->sendMessage($group->channel_id, Texts::announcement($meeting));
        $menuText = empty($post['ok']) ? Texts::meetingCreatedButChannelFailed($group->channel_id) : Texts::meetingCreated();
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
            $this->api->sendMessage($chatId, Texts::noGroup());

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
            $this->api->sendMessage(
                $chatId,
                "Hozircha davom etayotgan uchrashuv yo'q.\n"
                . "(Uchrashuvni Moderator «📅 Uchrashuvlar» bo'limidan «▶️ Boshlash» tugmasi bilan boshlashi kerak.)"
            );

            return;
        }

        // Guruhda odatda bir vaqtda faqat bitta uchrashuv boradi — shuning uchun to'g'ridan-to'g'ri
        // davomat ekranini ochamiz, «qaysi uchrashuv» deb ortiqcha tanlov ko'rsatmaymiz.
        if (count($eligible) === 1) {
            $this->openAttendanceMarking($chatId, $eligible[0]->id);

            return;
        }

        $rows = [];
        foreach ($eligible as $meeting) {
            $rows[] = [['text' => Texts::formatDate($meeting->meeting_at) . ' — ' . $meeting->topic, 'callback_data' => "att:open:{$meeting->id}"]];
        }

        $this->api->sendMessage($chatId, 'Qaysi uchrashuv uchun davomatni belgilaymiz?', $rows);
    }

    private function cycleAttendance(int $chatId, int $messageId, User $marker, int $meetingId, int $userId): void
    {
        $meeting = Meeting::findOne($meetingId);
        if ($meeting === null) {
            return;
        }

        $existing = Attendance::find()->where(['meeting_id' => $meetingId, 'user_id' => $userId])->one();
        $next = match ($existing?->status) {
            Attendance::STATUS_PRESENT => Attendance::STATUS_ABSENT,
            Attendance::STATUS_ABSENT => Attendance::STATUS_EXCUSED,
            Attendance::STATUS_EXCUSED => Attendance::STATUS_PRESENT,
            default => Attendance::STATUS_PRESENT,
        };

        Attendance::mark($meetingId, $userId, $next, $marker->id);

        $this->api->editMessageReplyMarkup($chatId, $messageId, $this->attendanceMarkingKeyboard($meeting));
    }

    /**
     * Uchrashuvni yakunlash — Moderator yoki Kotib bosishi mumkin, lekin boshlanganidan keyin
     * kamida MIN_MEETING_DURATION_HOURS o'tmaguncha ishlamaydi (tasodifan darhol yakunlab qo'ymaslik uchun).
     */
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

        if (!$this->isTestBypassUser($user) && $meeting->started_at) {
            $minEnd = (new \DateTime($meeting->started_at))->modify('+' . self::MIN_MEETING_DURATION_HOURS . ' hours');
            if (new \DateTime() < $minEnd) {
                $leftMinutes = (int) ceil((strtotime($minEnd->format('Y-m-d H:i:s')) - time()) / 60);
                $this->api->sendMessage(
                    $chatId,
                    "⏳ Uchrashuv kamida " . self::MIN_MEETING_DURATION_HOURS . " soat davom etishi kerak. "
                        . "Yana {$leftMinutes} daqiqadan keyin yakunlashingiz mumkin."
                );

                return;
            }
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
        $warning = empty($post['ok']) ? "\n\n⚠️ Kanalga yubora olmadik: bot kanalda admin emas yoki kanal ID noto'g'ri." : '';
        $this->api->editMessageText($chatId, $messageId, Texts::attendanceSaved() . $warning . "\n\n" . $resultsText);
    }
}
