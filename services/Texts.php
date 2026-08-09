<?php

declare(strict_types=1);

namespace app\services;

use app\models\Meeting;
use app\models\Role;
use app\models\User;

/**
 * Barcha bot matnlari faqat o'zbek tilida (lotin yozuvi).
 * Все тексты интерфейса — только на узбекском (латиница).
 */
class Texts
{
    public static function welcome(): string
    {
        return "Assalomu alaykum! 👋\n"
            . "Bu bot ish guruhlarida uchrashuvlarni tashkil qilish va davomatni yuritish uchun mo'ljallangan.\n\n"
            . "Ro'yxatdan o'tish uchun to'liq ismingizni kiriting (F.I.Sh.):";
    }

    public static function askPosition(): string
    {
        return "Rahmat! Endi lavozimingiz yoki bo'limingizni kiriting:";
    }

    public static function askPhone(): string
    {
        return "Endi telefon raqamingizni yuboring — bu boshqa ishtirokchilar sizni tezroq topishi uchun kerak.\n"
            . "Pastdagi «📱 Raqamni ulashish» tugmasini bosing (Telegram'dagi raqamingiz avtomatik olinadi) "
            . "yoki xohlasangiz qo'lda yozing:";
    }

    public static function registrationDone(string $fullName): string
    {
        return "✅ Ro'yxatdan muvaffaqiyatli o'tdingiz, {$fullName}!\n\nQuyidagi menyudan foydalanishingiz mumkin.";
    }

    public static function mainMenuHint(): string
    {
        return "Asosiy menyu:";
    }

    public static function notRegistered(): string
    {
        return "Avval ro'yxatdan o'ting: /start";
    }

    public static function noGroup(): string
    {
        return "Siz hali hech qanday ish guruhiga qo'shilmagansiz. Administrator sizni guruhga qo'shishi kerak.";
    }

    public static function onlyModerator(): string
    {
        return "Bu amalni faqat guruh Moderatori bajarishi mumkin.";
    }

    public static function askMeetingTopic(): string
    {
        return "Uchrashuv mavzusini kiriting:";
    }

    public static function askMeetingDate(): string
    {
        return "Sana va vaqtni kiriting, masalan: <b>2026-08-10 15:00</b>\n"
            . "(kun/soatni bitta raqam bilan ham yozsa bo'ladi: 2026-8-10 15:00, ':' o'rniga probel ham ishlaydi)";
    }

    public static function invalidDate(): string
    {
        return "❌ Tushunmadim. Iltimos, shu tartibda yozing: <b>YIL-OY-KUN SOAT:DAQIQA</b>\n"
            . "Masalan: 2026-08-10 15:00. Qaytadan kiriting:";
    }

    public static function meetingTooSoon(int $minHours): string
    {
        return "❌ Reglament bo'yicha uchrashuv kamida {$minHours} soat oldin belgilanishi kerak. "
            . "Iltimos, kechroq vaqt kiriting:";
    }

    public static function askMeetingFormat(): string
    {
        return "Uchrashuv formatini tanlang:";
    }

    public static function meetingCreated(): string
    {
        return "✅ Uchrashuv yaratildi va kanalga e'lon qilindi!\n\n"
            . "Barcha guruh a'zolari avtomatik qo'shildi, rollar «👥 Guruh a'zolari» bo'limidagi shablon bo'yicha berildi.";
    }

    public static function meetingCreatedButChannelFailed(string $channelId): string
    {
        return "⚠️ Uchrashuv yaratildi, lekin kanalga ({$channelId}) e'lonni yubora olmadik.\n"
            . "Bot shu kanalga administrator sifatida (post joylash huquqi bilan) qo'shilganini tekshiring "
            . "yoki boshqaruv panelidan kanal ID'ni to'g'rilang.";
    }

    public static function upcomingMeetingsEmpty(): string
    {
        return "Hozircha rejalashtirilgan uchrashuvlar yo'q.";
    }

    public static function upcomingMeetingsHeader(): string
    {
        return "📅 <b>Yaqinlashib kelayotgan uchrashuvlar:</b>";
    }

    public static function upcomingMeetingItem(Meeting $meeting, array $myRoles): string
    {
        $date = self::formatDate($meeting->meeting_at);
        $roles = $myRoles ? implode(', ', array_map(fn (Role $r) => $r->label(), $myRoles)) : '—';

        return "📌 «{$meeting->topic}»\n"
            . "🗓 {$date} · {$meeting->formatLabel()}\n"
            . "🎭 Sizning rolingiz: {$roles}\n";
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

    /** Post-anons shablon kanal uchun (Модуль 2). */
    public static function announcement(Meeting $meeting): string
    {
        $group = $meeting->group;
        $date = self::formatDate($meeting->meeting_at);
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

        return "📢 <b>KELGUSI UCHRASHUV HAQIDA E'LON!</b>\n"
            . "👥 <b>Guruh:</b> {$group->name}\n"
            . "📌 <b>Mavzu:</b> «{$meeting->topic}»\n"
            . "📅 <b>Sana va vaqt:</b> {$date}\n"
            . "📍 <b>Format:</b> {$meeting->formatLabel()}\n\n"
            . "🎭 <b>Uchrashuvdagi rollar taqsimoti:</b>\n"
            . "{$rolesText}";
    }

    /** Uchrashuv bekor qilinganda kanalga post. */
    public static function meetingCancelledAnnouncement(Meeting $meeting, string $reason): string
    {
        $group = $meeting->group;
        $date = self::formatDate($meeting->meeting_at);

        return "❌ <b>UCHRASHUV BEKOR QILINDI!</b>\n"
            . "👥 <b>Guruh:</b> {$group->name}\n"
            . "📌 <b>Mavzu:</b> «{$meeting->topic}»\n"
            . "📅 <b>Rejalashtirilgan sana/vaqt:</b> {$date}\n\n"
            . "📝 <b>Sabab:</b> {$reason}";
    }

    public static function attendanceRowStatus(string $status): string
    {
        return match ($status) {
            'present' => '✅ Keldi',
            'absent' => '❌ Kelmadi',
            'excused' => '⚠️ Sababli kelmadi',
            default => '— Belgilanmagan',
        };
    }

    public static function attendanceSaved(): string
    {
        return "✅ Davomat saqlandi.";
    }

    /** Uchrashuv yakunlari — kanalga post (Модуль 4). */
    public static function meetingResults(Meeting $meeting): string
    {
        $date = self::formatDate($meeting->meeting_at);
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
            }
            if ($status === 'present') {
                $present[] = $line;
            } else {
                $absent[] = $line;
            }
        }

        $text = "📢 <b>Uchrashuv yakunlari:</b> «{$meeting->topic}»\n"
            . "📅 <b>Sana va vaqt:</b> {$date}\n\n"
            . "✅ <b>Ishtirok etganlar:</b>\n" . (implode("\n", $present) ?: '—') . "\n\n"
            . "❌ <b>Kelmaganlar:</b>\n" . (implode("\n", $absent) ?: '—');

        return $text;
    }

    /** Haftalik hisobot — kanalga post (Модуль 5). */
    public static function weeklyReport(int $meetingsCount, int $totalPresent, int $totalAbsent, array $perUser): string
    {
        $lines = [];
        foreach ($perUser as $row) {
            $lines[] = "• {$row['full_name']}: ✅ {$row['present']} keldi / ❌ {$row['absent']} kelmadi";
        }

        return "📊 <b>Haftalik davomat hisoboti:</b>\n"
            . "🔹 <b>Haftadagi jami uchrashuvlar:</b> {$meetingsCount}\n"
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
