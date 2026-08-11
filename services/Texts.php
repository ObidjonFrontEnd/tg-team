<?php

declare(strict_types=1);

namespace app\services;

use app\models\Meeting;
use app\models\Role;
use app\models\User;

/**
 * Botning SHAXSIY (foydalanuvchiga yozilgan) matnlari 3 tilda: 'uz' (lotin), 'uz_cyrl' (kirill), 'ru' (ruscha) —
 * qarang User::LANG_*. Rol nomlari (Moderator, Kotib...) va format (Oflayn/Onlayn) atayin tarjima qilinmagan —
 * ular qisqa domen atamalari sifatida barcha tillarda o'zbekcha qoladi.
 *
 * KANAL postlari (announcement/meetingResults/meetingCancelledAnnouncement/weeklyReport) BU YERGA kirmaydi —
 * ular umumiy auditoriya (butun guruh) uchun va har doim faqat o'zbek tilida qoladi, chunki bitta kanal
 * postining "kimning tiliga" moslashi mumkin emas.
 */
class Texts
{
    public static function welcome(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Здравствуйте! 👋\n"
                . "Этот бот предназначен для организации встреч в рабочих группах и учёта посещаемости.\n\n"
                . "Для регистрации введите своё полное имя (Фамилия Имя):",
            User::LANG_UZ_CYRL => "Ассалому алайкум! 👋\n"
                . "Бу бот иш гуруҳларида учрашувларни ташкил қилиш ва давоматни юритиш учун мўлжалланган.\n\n"
                . "Рўйхатдан ўтиш учун тўлиқ исмингизни киритинг (Ф.И.Ш.):",
            default => "Assalomu alaykum! 👋\n"
                . "Bu bot ish guruhlarida uchrashuvlarni tashkil qilish va davomatni yuritish uchun mo'ljallangan.\n\n"
                . "Ro'yxatdan o'tish uchun to'liq ismingizni kiriting (F.I.Sh.):",
        };
    }

    public static function askPosition(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Спасибо! Теперь укажите вашу должность или отдел:",
            User::LANG_UZ_CYRL => "Раҳмат! Энди лавозимингиз ёки бўлимингизни киритинг:",
            default => "Rahmat! Endi lavozimingiz yoki bo'limingizni kiriting:",
        };
    }

    public static function askPhone(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Теперь отправьте свой номер телефона — это нужно, чтобы другие участники могли быстрее вас найти.\n"
                . "Нажмите кнопку «📱 Поделиться номером» внизу (номер из Telegram возьмётся автоматически) "
                . "или введите вручную:",
            User::LANG_UZ_CYRL => "Энди телефон рақамингизни юборинг — бу бошқа иштирокчилар сизни тезроқ топиши учун керак.\n"
                . "Пастдаги «📱 Рақамни улашиш» тугмасини босинг (Telegram'даги рақамингиз автоматик олинади) "
                . "ёки хоҳласангиз қўлда ёзинг:",
            default => "Endi telefon raqamingizni yuboring — bu boshqa ishtirokchilar sizni tezroq topishi uchun kerak.\n"
                . "Pastdagi «📱 Raqamni ulashish» tugmasini bosing (Telegram'dagi raqamingiz avtomatik olinadi) "
                . "yoki xohlasangiz qo'lda yozing:",
        };
    }

    public static function registrationDone(string $lang, string $fullName): string
    {
        return match ($lang) {
            User::LANG_RU => "✅ Вы успешно зарегистрированы, {$fullName}!\n\n"
                . "Теперь вы можете пользоваться меню ниже. "
                . "Ботом можно пользоваться как с телефона, так и с компьютера через Telegram — аккаунт один и тот же.",
            User::LANG_UZ_CYRL => "✅ Рўйхатдан муваффақиятли ўтдингиз, {$fullName}!\n\n"
                . "Қуйидаги менюдан фойдаланишингиз мумкин. "
                . "Ботдан телефонингиздаги Telegram орқали ҳам, компьютерингиздаги (PC) Telegram орқали ҳам "
                . "бемалол фойдаланишингиз мумкин — ҳисобингиз бир хил.",
            default => "✅ Ro'yxatdan muvaffaqiyatli o'tdingiz, {$fullName}!\n\n"
                . "Quyidagi menyudan foydalanishingiz mumkin. "
                . "Botdan telefoningizdagi Telegram orqali ham, kompyuteringizdagi (PC) Telegram orqali ham bemalol foydalanishingiz mumkin — hisobingiz bir xil.",
        };
    }

    public static function mainMenuHint(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Главное меню:",
            User::LANG_UZ_CYRL => "Асосий меню:",
            default => "Asosiy menyu:",
        };
    }

    public static function notRegistered(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Сначала зарегистрируйтесь: /start",
            User::LANG_UZ_CYRL => "Аввал рўйхатдан ўтинг: /start",
            default => "Avval ro'yxatdan o'ting: /start",
        };
    }

    public static function noGroup(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Вы пока не состоите ни в одной рабочей группе. Администратор должен добавить вас в группу.",
            User::LANG_UZ_CYRL => "Сиз ҳали ҳеч қандай иш гуруҳига қўшилмагансиз. Администратор сизни гуруҳга қўшиши керак.",
            default => "Siz hali hech qanday ish guruhiga qo'shilmagansiz. Administrator sizni guruhga qo'shishi kerak.",
        };
    }

    public static function onlyModerator(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Это действие может выполнить только Модератор группы.",
            User::LANG_UZ_CYRL => "Бу амални фақат гуруҳ Модератори бажариши мумкин.",
            default => "Bu amalni faqat guruh Moderatori bajarishi mumkin.",
        };
    }

    /** Uchrashuv/trening so'zi — faqat shu ikkalasi orasida tanlanadi, boshqa hech narsa tarjima qilinmaydi. */
    private static function meetingWord(string $lang, bool $isTrening, string $form = 'nom'): string
    {
        $forms = [
            'uz' => ['nom' => ['Uchrashuv', 'Trening'], 'plural' => ['uchrashuvlar', 'treninglar']],
            'uz_cyrl' => ['nom' => ['Учрашув', 'Тренинг'], 'plural' => ['учрашувлар', 'тренинглар']],
            'ru' => [
                'nom' => ['Встреча', 'Тренинг'],
                'gen' => ['встречи', 'тренинга'],
                'plural_nom' => ['встречи', 'тренинги'],
                'plural_gen' => ['встреч', 'тренингов'],
            ],
        ];
        $lang = in_array($lang, [User::LANG_UZ_CYRL, User::LANG_RU], true) ? $lang : User::LANG_UZ;
        $set = $forms[$lang][$form] ?? $forms[$lang]['nom'];

        return $set[$isTrening ? 1 : 0];
    }

    public static function askMeetingTopic(string $lang, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "Введите тему " . self::meetingWord($lang, $isTrening, 'gen') . ':',
            User::LANG_UZ_CYRL => self::meetingWord($lang, $isTrening) . " мавзусини киритинг:",
            default => self::meetingWord($lang, $isTrening) . " mavzusini kiriting:",
        };
    }

    public static function askMeetingDate(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "Введите дату и время, например: <b>2026-08-10 15:00</b>\n"
                . "(день/час можно писать и одной цифрой: 2026-8-10 15:00, вместо ':' можно ставить пробел)",
            User::LANG_UZ_CYRL => "Сана ва вақтни киритинг, масалан: <b>2026-08-10 15:00</b>\n"
                . "(кун/соатни битта рақам билан ҳам ёзса бўлади: 2026-8-10 15:00, ':' ўрнига пробел ҳам ишлайди)",
            default => "Sana va vaqtni kiriting, masalan: <b>2026-08-10 15:00</b>\n"
                . "(kun/soatni bitta raqam bilan ham yozsa bo'ladi: 2026-8-10 15:00, ':' o'rniga probel ham ishlaydi)",
        };
    }

    public static function invalidDate(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "❌ Не понял. Пожалуйста, напишите в таком порядке: <b>ГОД-МЕСЯЦ-ДЕНЬ ЧАС:МИНУТА</b>\n"
                . "Например: 2026-08-10 15:00. Введите заново:",
            User::LANG_UZ_CYRL => "❌ Тушунмадим. Илтимос, шу тартибда ёзинг: <b>ЙИЛ-ОЙ-КУН СОАТ:ДАҚИҚА</b>\n"
                . "Масалан: 2026-08-10 15:00. Қайтадан киритинг:",
            default => "❌ Tushunmadim. Iltimos, shu tartibda yozing: <b>YIL-OY-KUN SOAT:DAQIQA</b>\n"
                . "Masalan: 2026-08-10 15:00. Qaytadan kiriting:",
        };
    }

    public static function askMeetingFormat(string $lang, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "Выберите формат " . self::meetingWord($lang, $isTrening, 'gen') . ':',
            User::LANG_UZ_CYRL => self::meetingWord($lang, $isTrening) . " форматини танланг:",
            default => self::meetingWord($lang, $isTrening) . " formatini tanlang:",
        };
    }

    public static function meetingCreated(string $lang, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "✅ Анонс " . self::meetingWord($lang, $isTrening, 'gen') . " опубликован в канале!\n\n"
                . "Все участники группы добавлены автоматически, роли выданы по шаблону из раздела «👥 Участники группы».",
            User::LANG_UZ_CYRL => "✅ " . self::meetingWord($lang, $isTrening) . " яратилди ва каналга эълон қилинди!\n\n"
                . "Барча гуруҳ аъзолари автоматик қўшилди, роллар «👥 Гуруҳ аъзолари» бўлимидаги шаблон бўйича берилди.",
            default => "✅ " . self::meetingWord($lang, $isTrening) . " yaratildi va kanalga e'lon qilindi!\n\n"
                . "Barcha guruh a'zolari avtomatik qo'shildi, rollar «👥 Guruh a'zolari» bo'limidagi shablon bo'yicha berildi.",
        };
    }

    public static function meetingCreatedButChannelFailed(string $lang, string $channelId, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "⚠️ Данные " . self::meetingWord($lang, $isTrening, 'gen') . " сохранены, "
                . "но отправить пост в канал ({$channelId}) не удалось.\n"
                . "Проверьте, что бот добавлен в канал как администратор (с правом публикации постов), "
                . "либо исправьте ID канала в панели управления.",
            User::LANG_UZ_CYRL => "⚠️ " . self::meetingWord($lang, $isTrening) . " яратилди, лекин каналга ({$channelId}) эълонни юбора олмадик.\n"
                . "Бот шу каналга администратор сифатида (пост жойлаш ҳуқуқи билан) қўшилганини текширинг "
                . "ёки бошқарув панелидан канал ID'ни тўғриланг.",
            default => "⚠️ " . self::meetingWord($lang, $isTrening) . " yaratildi, lekin kanalga ({$channelId}) e'lonni yubora olmadik.\n"
                . "Bot shu kanalga administrator sifatida (post joylash huquqi bilan) qo'shilganini tekshiring "
                . "yoki boshqaruv panelidan kanal ID'ni to'g'rilang.",
        };
    }

    public static function upcomingMeetingsEmpty(string $lang, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "Пока нет запланированных " . self::meetingWord($lang, $isTrening, 'plural_gen') . '.',
            User::LANG_UZ_CYRL => "Ҳозирча режалаштирилган " . self::meetingWord($lang, $isTrening, 'plural') . " йўқ.",
            default => "Hozircha rejalashtirilgan " . self::meetingWord($lang, $isTrening, 'plural') . " yo'q.",
        };
    }

    public static function upcomingMeetingsHeader(string $lang, bool $isTrening = false): string
    {
        return match ($lang) {
            User::LANG_RU => "📅 <b>Ближайшие " . self::meetingWord($lang, $isTrening, 'plural_nom') . ':</b>',
            User::LANG_UZ_CYRL => "📅 <b>Яқинлашиб келаётган " . self::meetingWord($lang, $isTrening, 'plural') . ":</b>",
            default => "📅 <b>Yaqinlashib kelayotgan " . self::meetingWord($lang, $isTrening, 'plural') . ":</b>",
        };
    }

    public static function upcomingMeetingItem(string $lang, Meeting $meeting, array $myRoles): string
    {
        $date = self::formatDate($meeting->meeting_at);
        $roles = $myRoles ? implode(', ', array_map(fn (Role $r) => $r->label(), $myRoles)) : '—';
        $format = self::formatLabel($meeting->format, $lang);

        return match ($lang) {
            User::LANG_RU => "📌 «{$meeting->topic}»\n"
                . "🗓 {$date} · {$format}\n"
                . "🎭 Ваша роль: {$roles}\n",
            User::LANG_UZ_CYRL => "📌 «{$meeting->topic}»\n"
                . "🗓 {$date} · {$format}\n"
                . "🎭 Сизнинг рўлингиз: {$roles}\n",
            default => "📌 «{$meeting->topic}»\n"
                . "🗓 {$date} · {$format}\n"
                . "🎭 Sizning rolingiz: {$roles}\n",
        };
    }

    /** Format nomi (Oflayn/Onlayn) — rol nomlari kabi domen atamasi, lekin bu bittasi qisqa va tabiiy tarjima qilinadi. */
    public static function formatLabel(string $format, string $lang): string
    {
        $isOnline = $format === Meeting::FORMAT_ONLINE;

        return match ($lang) {
            User::LANG_RU => $isOnline ? 'Онлайн' : 'Оффлайн',
            User::LANG_UZ_CYRL => $isOnline ? 'Онлайн' : 'Офлайн',
            default => $isOnline ? 'Onlayn' : 'Oflayn',
        };
    }

    public static function attendanceRowStatus(string $lang, string $status): string
    {
        return match ($lang) {
            User::LANG_RU => match ($status) {
                'present' => '✅ Пришёл',
                'absent' => '❌ Не пришёл',
                'online' => '💻 Онлайн',
                'excused' => '⚠️ Отсутствовал по причине',
                default => '— Не отмечено',
            },
            User::LANG_UZ_CYRL => match ($status) {
                'present' => '✅ Келди',
                'absent' => '❌ Келмади',
                'online' => '💻 Онлайн',
                'excused' => '⚠️ Сабабли келмади',
                default => '— Белгиланмаган',
            },
            default => match ($status) {
                'present' => '✅ Keldi',
                'absent' => '❌ Kelmadi',
                'online' => '💻 Onlayn',
                'excused' => '⚠️ Sababli kelmadi',
                default => '— Belgilanmagan',
            },
        };
    }

    public static function attendanceSaved(string $lang): string
    {
        return match ($lang) {
            User::LANG_RU => "✅ Посещаемость сохранена.",
            User::LANG_UZ_CYRL => "✅ Давомат сақланди.",
            default => "✅ Davomat saqlandi.",
        };
    }

    /**
     * Rol muhimligi bo'yicha tartiblaydi: Moderator birinchi, so'ng Kotib, boshqa maxsus rollar,
     * Ishtirokchi esa har doim oxirida (u — bo'sh o'rin uchun default, muhim emas).
     *
     * @param array<int, array{user: User, roles: Role[]}> $participants
     * @return array<int, array{user: User, roles: Role[]}>
     */
    private static function sortByRolePriority(array $participants): array
    {
        uasort($participants, fn ($a, $b) => Role::bestPriority($a['roles']) <=> Role::bestPriority($b['roles']));

        return $participants;
    }

    /**
     * Post-anons shablon kanal uchun (Модуль 2). «Umumiy» guruh uchun ishtirokchilar ro'yxati
     * ko'rsatilmaydi — u barcha guruhlar a'zolarini jamlagani uchun ro'yxat juda uzun bo'lib ketadi.
     * MUHIM: kanal postlari HAR DOIM o'zbek tilida — kanalni butun guruh o'qiydi, "kimning tili"
     * degan tushuncha unga tegishli emas.
     */
    public static function announcement(Meeting $meeting): string
    {
        $group = $meeting->group;
        $date = self::formatDate($meeting->meeting_at);
        $word = $group->isUmumiy() ? 'TRENING' : 'UCHRASHUV';

        $header = "📢 <b>KELGUSI {$word} HAQIDA E'LON!</b>\n"
            . "👥 <b>Guruh:</b> {$group->name}\n"
            . "📌 <b>Mavzu:</b> «{$meeting->topic}»\n"
            . "📅 <b>Sana va vaqt:</b> {$date}\n"
            . "📍 <b>Format:</b> {$meeting->formatLabel()}";

        if ($group->isUmumiy()) {
            return $header;
        }

        $participants = self::sortByRolePriority($meeting->getParticipantsWithRoles());

        $rolesLines = [];
        foreach ($participants as $row) {
            /** @var User $user */
            $user = $row['user'];
            $icon = Role::leadEmoji($row['roles']);
            $roleNames = Role::namesOnly($row['roles']);
            $rolesLines[] = "• {$icon} <b>{$user->full_name}</b> — {$roleNames}";
        }
        $rolesText = implode("\n", $rolesLines) ?: '—';

        return "{$header}\n\n"
            . "👥 <b>Ishtirokchilar ro'yxati:</b>\n"
            . "{$rolesText}";
    }

    /** Uchrashuv bekor qilinganda kanalga post (har doim o'zbekcha — qarang announcement() izohi). */
    public static function meetingCancelledAnnouncement(Meeting $meeting, string $reason): string
    {
        $group = $meeting->group;
        $date = self::formatDate($meeting->meeting_at);
        $word = $group->isUmumiy() ? 'TRENING' : 'UCHRASHUV';

        return "❌ <b>{$word} BEKOR QILINDI!</b>\n"
            . "👥 <b>Guruh:</b> {$group->name}\n"
            . "📌 <b>Mavzu:</b> «{$meeting->topic}»\n"
            . "📅 <b>Rejalashtirilgan sana/vaqt:</b> {$date}\n\n"
            . "📝 <b>Sabab:</b> {$reason}";
    }

    /** Uchrashuv yakunlari — kanalga post (Модуль 4, har doim o'zbekcha — qarang announcement() izohi). */
    public static function meetingResults(Meeting $meeting): string
    {
        $date = self::formatDate($meeting->meeting_at);
        $word = $meeting->group->isUmumiy() ? 'Trening' : 'Uchrashuv';
        $participants = self::sortByRolePriority($meeting->getParticipantsWithRoles());
        $attendances = $meeting->getAttendances()->indexBy('user_id')->all();

        $present = [];
        $absent = [];
        foreach ($participants as $uid => $row) {
            /** @var User $user */
            $user = $row['user'];
            $icon = Role::leadEmoji($row['roles']);
            $roleNames = Role::namesOnly($row['roles']);
            $status = $attendances[$uid]->status ?? null;
            $line = "• {$icon} <b>{$user->full_name}</b> — <i>{$roleNames}</i>";
            if ($status === 'excused') {
                $line .= ' (sababli)';
            } elseif ($status === 'online') {
                $line .= ' (onlayn)';
            }
            if ($status === 'present' || $status === 'online') {
                $present[] = $line;
            } else {
                $absent[] = $line;
            }
        }

        $startedLine = $meeting->started_at ? date('H:i', strtotime($meeting->started_at)) : '—';
        $endedLine = $meeting->ended_at ? date('H:i', strtotime($meeting->ended_at)) : '—';

        $text = "📢 <b>{$word} yakunlari:</b> «{$meeting->topic}»\n"
            . "📅 <b>Sana va vaqt:</b> {$date}\n"
            . "🕐 <b>Boshlandi:</b> {$startedLine} — <b>Tugadi:</b> {$endedLine}\n\n"
            . "✅ <b>Ishtirok etganlar:</b>\n" . (implode("\n", $present) ?: '—') . "\n\n"
            . "❌ <b>Qatnashmaganlar:</b>\n" . (implode("\n", $absent) ?: '—');

        return $text;
    }

    /** Haftalik hisobot — kanalga post (Модуль 5, har doim o'zbekcha — qarang announcement() izohi). */
    public static function weeklyReport(int $meetingsCount, int $totalPresent, int $totalAbsent, array $perUser, bool $isTrening = false): string
    {
        $word = $isTrening ? 'treninglar' : 'uchrashuvlar';
        $lines = [];
        foreach ($perUser as $row) {
            $lines[] = "• {$row['full_name']}: ✅ {$row['present']} keldi / ❌ {$row['absent']} kelmadi";
        }

        return "📊 <b>Haftalik davomat hisoboti:</b>\n"
            . "🔹 <b>Haftadagi jami {$word}:</b> {$meetingsCount}\n"
            . "✅ <b>Jami tashriflar (Keldi):</b> {$totalPresent} marta\n"
            . "❌ <b>Jami sabablar (Kelmadi):</b> {$totalAbsent} marta\n\n"
            . "📈 <b>Ishtirokchilar bo'yicha tafsilot:</b>\n"
            . (implode("\n", $lines) ?: '—');
    }

    public static function formatDate(string $dateTime): string
    {
        $ts = strtotime($dateTime);

        return $ts ? date('d.m.Y H:i', $ts) : $dateTime;
    }
}
