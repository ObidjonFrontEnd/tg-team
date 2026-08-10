<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\MigrationRunner;
use app\models\Attendance;
use app\models\Group;
use app\models\GroupMember;
use app\models\GroupMemberRole;
use app\models\Meeting;
use app\models\User;
use app\services\BotHandler;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\Response;

/**
 * Веб-панель администратора: списки пользователей/групп/встреч + запуск миграций.
 * Доступ только после логина (см. SiteController::actionLogin).
 */
class AdminController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    public function actionIndex(): Response
    {
        return $this->redirect(['admin/users']);
    }

    public function actionUsers(): Response
    {
        $users = User::find()->orderBy(['id' => SORT_DESC])->all();
        $deleteUserUrl = Url::to(['admin/user-delete']);
        $toggleObserverUrl = Url::to(['admin/user-toggle-observer']);

        $rows = '';
        foreach ($users as $u) {
            $groupNames = implode(', ', array_map(fn (Group $g) => $g->name, $u->groups)) ?: '—';
            $nameEsc = htmlspecialchars($u->full_name, ENT_QUOTES);
            $editUrl = Url::to(['admin/user-edit', 'id' => $u->id]);
            $editBtn = '<a href="' . $editUrl . '" style="font-size:12px;padding:3px 10px;border:1px solid #2c3e50;color:#2c3e50;border-radius:10px;text-decoration:none;">✏️ Tahrirlash</a>';
            $observerBtnLabel = $u->is_observer ? '🔭 Kuzatuvchidan chiqarish' : '🔭 Kuzatuvchi qilish';
            $observerBtnStyle = $u->is_observer
                ? 'border:1px solid #8e44ad;background:#8e44ad;color:#fff;'
                : 'border:1px solid #8e44ad;background:#fff;color:#8e44ad;';
            $observerBtn = "<form method=\"post\" action=\"{$toggleObserverUrl}\" style=\"display:inline;\">"
                . "<input type=\"hidden\" name=\"user_id\" value=\"{$u->id}\">"
                . "<button type=\"submit\" style=\"font-size:12px;padding:3px 10px;{$observerBtnStyle}border-radius:10px;cursor:pointer;\">{$observerBtnLabel}</button>"
                . '</form>';
            $deleteBtn = "<form method=\"post\" action=\"{$deleteUserUrl}\" style=\"display:inline;\" onsubmit=\"return confirm('«{$nameEsc}»ni butunlay o\\'chirmoqchimisiz?');\">"
                . "<input type=\"hidden\" name=\"user_id\" value=\"{$u->id}\">"
                . '<button type="submit" style="font-size:12px;padding:3px 10px;border:1px solid #c0392b;background:#fff;color:#c0392b;border-radius:10px;cursor:pointer;">🗑 O\'chirish</button>'
                . '</form>';
            $rows .= '<tr>'
                . '<td>' . $u->id . '</td>'
                . '<td>' . htmlspecialchars($u->full_name) . '</td>'
                . '<td>' . htmlspecialchars((string) $u->position) . '</td>'
                . '<td>' . htmlspecialchars((string) $u->phone) . '</td>'
                . '<td>' . $u->telegram_id . '</td>'
                . '<td>' . htmlspecialchars((string) $u->telegram_username) . '</td>'
                . '<td>' . htmlspecialchars($groupNames) . '</td>'
                . '<td>' . htmlspecialchars((string) $u->created_at) . '</td>'
                . '<td style="white-space:nowrap;">' . $editBtn . ' ' . $observerBtn . ' ' . $deleteBtn . '</td>'
                . '</tr>';
        }

        $body = $this->table(
            ['ID', 'F.I.Sh.', 'Lavozim', 'Telefon', 'Telegram ID', 'Username', 'Guruhlar', 'Ro\'yxatdan o\'tgan', ''],
            $rows,
            "Jami: " . count($users) . ' <span style="color:#aaa;">(🔭 Kuzatuvchi — hech qanday guruhga a\'zo bo\'lmasdan barcha guruhlar statistikasini ko\'ra oladi)</span>'
        );

        return $this->page('Foydalanuvchilar', 'users', $body);
    }

    public function actionGroups(): Response
    {
        $groups = Group::find()->orderBy(['id' => SORT_DESC])->all();

        $deleteGroupUrl = Url::to(['admin/group-delete']);

        $rows = '';
        foreach ($groups as $g) {
            $membersCount = $g->getGroupMembers()->count();
            $meetingsCount = $g->getMeetings()->count();
            $moderatorName = $g->moderator->full_name ?? '—';
            $membersUrl = Url::to(['admin/group-members', 'id' => $g->id]);
            $nameEsc = htmlspecialchars($g->name, ENT_QUOTES);
            $deleteBtn = "<form method=\"post\" action=\"{$deleteGroupUrl}\" onsubmit=\"return confirm('«{$nameEsc}» guruhini va uning BARCHA uchrashuvlarini butunlay o\\'chirmoqchimisiz? Bu amalni ortga qaytarib bo\\'lmaydi.');\">"
                . "<input type=\"hidden\" name=\"group_id\" value=\"{$g->id}\">"
                . '<button type="submit" style="font-size:12px;padding:3px 10px;border:1px solid #c0392b;background:#fff;color:#c0392b;border-radius:10px;cursor:pointer;">🗑 O\'chirish</button>'
                . '</form>';
            $rows .= '<tr>'
                . '<td>' . $g->id . '</td>'
                . '<td><a href="' . $membersUrl . '" style="color:#2c3e50;font-weight:bold;text-decoration:none;">' . htmlspecialchars($g->name) . ' →</a></td>'
                . '<td>' . htmlspecialchars($g->channel_id) . '</td>'
                . '<td>' . htmlspecialchars($moderatorName) . '</td>'
                . '<td><a href="' . $membersUrl . '">' . $membersCount . ' — a\'zolarni ko\'rish</a></td>'
                . '<td>' . $meetingsCount . '</td>'
                . '<td><code>' . $g->id . '</code> <span style="color:#888;">(qo\'shilish kodi)</span></td>'
                . '<td>' . $deleteBtn . '</td>'
                . '</tr>';
        }

        $table = $this->table(
            ['ID', 'Nomi', 'Kanal', 'Moderator', 'A\'zolar', 'Uchrashuvlar', 'Qo\'shilish kodi', ''],
            $rows,
            "Jami: " . count($groups) . ' <span style="color:#aaa;">(guruh nomini yoki a\'zolar sonini bosing — ishtirokchilar ro\'yxati ochiladi)</span>'
        );

        $createGroupUrl = Url::to(['admin/group-create']);
        $createForm = <<<HTML
<details style="margin-bottom:20px;background:#fff;padding:14px 18px;border-radius:6px;">
<summary style="cursor:pointer;font-weight:bold;color:#2c3e50;">+ Yangi guruh yaratish</summary>
<form method="post" action="{$createGroupUrl}" style="margin-top:14px;max-width:420px;">
<div style="margin-bottom:10px;"><label>Guruh nomi<br><input type="text" name="name" required style="width:100%;padding:8px;box-sizing:border-box;"></label></div>
<div style="margin-bottom:10px;"><label>Kanal ID / username (masalan @kanal yoki -100...)<br><input type="text" name="channel_id" required style="width:100%;padding:8px;box-sizing:border-box;"></label></div>
<button type="submit" style="padding:8px 18px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Yaratish</button>
</form>
</details>
HTML;

        return $this->page('Guruhlar', 'groups', $createForm . $table);
    }

    public function actionGroupCreate(): Response
    {
        if (Yii::$app->request->isPost) {
            $name = trim((string) Yii::$app->request->post('name'));
            $channelId = trim((string) Yii::$app->request->post('channel_id'));
            if ($name !== '' && $channelId !== '') {
                $group = new Group(['name' => $name, 'channel_id' => $channelId]);
                $group->save(false);
            }
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionGroupAddMember(): Response
    {
        if (Yii::$app->request->isPost) {
            $groupId = (int) Yii::$app->request->post('group_id');
            $userId = (int) Yii::$app->request->post('user_id');
            $group = Group::findOne($groupId);
            if ($group !== null && $userId > 0 && !$group->hasMember($userId)) {
                (new GroupMember(['group_id' => $groupId, 'user_id' => $userId]))->save(false);
                // Alohida rol berilmagan bo'lsa, "Ishtirokchi" avtomatik ko'rsatiladi (GroupMemberRole::roleIdsFor fallback'i) — bazaga yozilmaydi.

                $member = User::findOne($userId);
                if ($member !== null) {
                    // Yangi qo'shilgan guruh o'sha zahoti "faol" bo'ladi — foydalanuvchi shu haqda xabar oladi,
                    // shu guruh menyusini ko'radi va keyingi safar botga kirganda ham o'sha bilan davom etadi.
                    $member->active_group_id = $group->id;
                    $member->save(false);

                    (new BotHandler(Yii::$app->telegram))
                        ->notifyMenuRefresh($member, $group, "👋 Siz «{$group->name}» guruhiga qo'shildingiz. Yangi menyu:");
                }
            }

            return $this->redirect(['admin/group-members', 'id' => $groupId]);
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionGroupUpdateChannel(): Response
    {
        if (Yii::$app->request->isPost) {
            $groupId = (int) Yii::$app->request->post('group_id');
            $name = trim((string) Yii::$app->request->post('name'));
            $channelId = trim((string) Yii::$app->request->post('channel_id'));
            $group = Group::findOne($groupId);
            if ($group !== null && $name !== '' && $channelId !== '') {
                $group->name = $name;
                $group->channel_id = $channelId;
                $group->save(false);
                Yii::$app->session->setFlash('success', "Guruh ma'lumotlari yangilandi.");
            }

            return $this->redirect(['admin/group-members', 'id' => $groupId]);
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionGroupDelete(): Response
    {
        if (Yii::$app->request->isPost) {
            $group = Group::findOne((int) Yii::$app->request->post('group_id'));
            if ($group !== null) {
                $name = $group->name;
                try {
                    $group->delete();
                    Yii::$app->session->setFlash('success', "«{$name}» guruhi (va uning barcha uchrashuvlari) o'chirildi.");
                } catch (\Throwable $e) {
                    Yii::error('Group delete failed: ' . $e->getMessage());
                    Yii::$app->session->setFlash('error', "Guruhni o'chirib bo'lmadi: " . $e->getMessage());
                }
            }
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionGroupRemoveMember(): Response
    {
        if (Yii::$app->request->isPost) {
            $groupId = (int) Yii::$app->request->post('group_id');
            $userId = (int) Yii::$app->request->post('user_id');
            $group = Group::findOne($groupId);
            if ($group !== null) {
                GroupMember::deleteAll(['group_id' => $groupId, 'user_id' => $userId]);
                GroupMemberRole::deleteAll(['group_id' => $groupId, 'user_id' => $userId]);
                if ($group->moderator_user_id === $userId) {
                    $group->moderator_user_id = null;
                    $group->save(false);
                }
                Yii::$app->session->setFlash('success', "A'zo guruhdan chiqarildi.");
            }

            return $this->redirect(['admin/group-members', 'id' => $groupId]);
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionUserEdit(int $id): Response
    {
        $user = User::findOne($id);
        if ($user === null) {
            return $this->page('Foydalanuvchi topilmadi', 'users', '<p>Bunday foydalanuvchi topilmadi. <a href="' . Url::to(['admin/users']) . '">Orqaga</a></p>');
        }

        if (Yii::$app->request->isPost) {
            $fullName = trim((string) Yii::$app->request->post('full_name'));
            if ($fullName !== '') {
                $user->full_name = $fullName;
                $user->position = trim((string) Yii::$app->request->post('position'));
                $user->phone = trim((string) Yii::$app->request->post('phone'));
                $user->save(false);
                Yii::$app->session->setFlash('success', "Foydalanuvchi ma'lumotlari yangilandi.");

                return $this->redirect(['admin/users']);
            }
        }

        $backUrl = Url::to(['admin/users']);
        $actionUrl = Url::to(['admin/user-edit', 'id' => $user->id]);
        $fullNameEsc = htmlspecialchars($user->full_name, ENT_QUOTES);
        $positionEsc = htmlspecialchars((string) $user->position, ENT_QUOTES);
        $phoneEsc = htmlspecialchars((string) $user->phone, ENT_QUOTES);

        $form = <<<HTML
<p><a href="{$backUrl}" style="color:#2c3e50;">← Foydalanuvchilar ro'yxatiga qaytish</a></p>
<form method="post" action="{$actionUrl}" style="background:#fff;padding:14px 18px;border-radius:6px;max-width:420px;">
<div style="margin-bottom:10px;"><label>F.I.Sh.<br><input type="text" name="full_name" value="{$fullNameEsc}" required style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;"></label></div>
<div style="margin-bottom:10px;"><label>Lavozim<br><input type="text" name="position" value="{$positionEsc}" style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;"></label></div>
<div style="margin-bottom:10px;"><label>Telefon<br><input type="text" name="phone" value="{$phoneEsc}" style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;"></label></div>
<p style="color:#888;font-size:13px;">Telegram ID: {$user->telegram_id} (o'zgartirib bo'lmaydi)</p>
<button type="submit" style="padding:8px 18px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Saqlash</button>
</form>
HTML;

        return $this->page("«{$user->full_name}»ni tahrirlash", 'users', $form);
    }

    public function actionUserToggleObserver(): Response
    {
        if (Yii::$app->request->isPost) {
            $user = User::findOne((int) Yii::$app->request->post('user_id'));
            if ($user !== null) {
                $user->is_observer = !$user->is_observer;
                $user->save(false);

                (new BotHandler(Yii::$app->telegram))->notifyUserMenu(
                    $user,
                    $user->is_observer
                        ? "👁 Sizga «Kuzatuvchi» roli berildi. Pastdagi tugmalardan birini bosing — so'ng qaysi guruh kerakligini so'raydi va o'sha guruhning uchrashuvlari/a'zolari/tarixi/statistikasini ko'rsatadi. Yangi menyu:"
                        : "ℹ️ Sizning «Kuzatuvchi» rolingiz olib tashlandi. Yangi menyu:"
                );

                Yii::$app->session->setFlash(
                    'success',
                    $user->is_observer
                        ? "«{$user->full_name}» endi Kuzatuvchi."
                        : "«{$user->full_name}» Kuzatuvchilikdan chiqarildi."
                );
            }
        }

        return $this->redirect(['admin/users']);
    }

    public function actionUserDelete(): Response
    {
        if (Yii::$app->request->isPost) {
            $user = User::findOne((int) Yii::$app->request->post('user_id'));
            if ($user !== null) {
                $name = $user->full_name;
                try {
                    $user->delete();
                    Yii::$app->session->setFlash('success', "«{$name}» o'chirildi.");
                } catch (\Throwable $e) {
                    Yii::error('User delete failed: ' . $e->getMessage());
                    Yii::$app->session->setFlash(
                        'error',
                        "«{$name}»ni o'chirib bo'lmadi — u qandaydir uchrashuv(lar)ni yaratgan (created_by). "
                            . "Avval «Uchrashuvlar» bo'limidan o'sha uchrashuvlarni bekor qiling/o'chiring, keyin qaytadan urinib ko'ring."
                    );
                }
            }
        }

        return $this->redirect(['admin/users']);
    }

    public function actionGroupSetModerator(): Response
    {
        if (Yii::$app->request->isPost) {
            $groupId = (int) Yii::$app->request->post('group_id');
            $userId = (int) Yii::$app->request->post('user_id');
            $group = Group::findOne($groupId);
            if ($group !== null && $group->hasMember($userId)) {
                $previousModeratorId = $group->moderator_user_id;
                $group->moderator_user_id = $userId;
                $group->save(false);

                $bot = new BotHandler(Yii::$app->telegram);
                $member = User::findOne($userId);
                if ($member !== null) {
                    $bot->notifyMenuRefresh($member, $group, "👑 Siz «{$group->name}» guruhining Moderatori etib tayinlandingiz. Yangi menyu:");
                }
                if ($previousModeratorId && $previousModeratorId !== $userId) {
                    $previousModerator = User::findOne($previousModeratorId);
                    if ($previousModerator !== null) {
                        $bot->notifyMenuRefresh($previousModerator, $group, "ℹ️ Endi «{$group->name}» guruhining Moderatori boshqa shaxs. Yangi menyu:");
                    }
                }
            }

            return $this->redirect(['admin/group-members', 'id' => $groupId]);
        }

        return $this->redirect(['admin/groups']);
    }

    public function actionGroupMembers(int $id): Response
    {
        $group = Group::findOne($id);
        if ($group === null) {
            return $this->page('Guruh topilmadi', 'groups', '<p>Bunday guruh topilmadi. <a href="' . Url::to(['admin/groups']) . '">Orqaga</a></p>');
        }

        $members = $group->getGroupMembers()->with('user')->all();
        $memberIds = array_map(fn (GroupMember $gm) => $gm->user_id, $members);

        $setModeratorUrl = Url::to(['admin/group-set-moderator']);
        $removeMemberUrl = Url::to(['admin/group-remove-member']);

        $rows = '';
        foreach ($members as $gm) {
            $u = $gm->user;
            if ($u === null) {
                continue;
            }
            $isModerator = $group->moderator_user_id === $u->id;
            $badge = $isModerator
                ? '<span style="background:#2c3e50;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px;">Moderator</span>'
                : "<form method=\"post\" action=\"{$setModeratorUrl}\" style=\"display:inline;\">"
                    . "<input type=\"hidden\" name=\"group_id\" value=\"{$group->id}\">"
                    . "<input type=\"hidden\" name=\"user_id\" value=\"{$u->id}\">"
                    . '<button type="submit" style="font-size:12px;padding:3px 10px;border:1px solid #2c3e50;background:#fff;color:#2c3e50;border-radius:10px;cursor:pointer;">Moderator qilish</button>'
                    . '</form>';
            $nameEsc = htmlspecialchars($u->full_name, ENT_QUOTES);
            $removeBtn = "<form method=\"post\" action=\"{$removeMemberUrl}\" onsubmit=\"return confirm('«{$nameEsc}»ni guruhdan chiqarasizmi?');\">"
                . "<input type=\"hidden\" name=\"group_id\" value=\"{$group->id}\">"
                . "<input type=\"hidden\" name=\"user_id\" value=\"{$u->id}\">"
                . '<button type="submit" style="font-size:12px;padding:3px 10px;border:1px solid #c0392b;background:#fff;color:#c0392b;border-radius:10px;cursor:pointer;">🗑 Chiqarish</button>'
                . '</form>';
            $rows .= '<tr>'
                . '<td>' . $u->id . '</td>'
                . '<td>' . htmlspecialchars($u->full_name) . '</td>'
                . '<td>' . htmlspecialchars((string) $u->position) . '</td>'
                . '<td>' . htmlspecialchars((string) $u->phone) . '</td>'
                . '<td>' . $u->telegram_id . '</td>'
                . '<td>' . htmlspecialchars((string) $u->telegram_username) . '</td>'
                . '<td>' . htmlspecialchars((string) $gm->created_at) . '</td>'
                . '<td>' . $badge . '</td>'
                . '<td>' . $removeBtn . '</td>'
                . '</tr>';
        }

        $table = $this->table(
            ['ID', 'F.I.Sh.', 'Lavozim', 'Telefon', 'Telegram ID', 'Username', 'Qo\'shilgan sana', '', ''],
            $rows,
            "Jami a'zolar: " . count($members)
        );

        $candidates = User::find()->andWhere($memberIds ? ['not in', 'id', $memberIds] : '1=1')->orderBy(['full_name' => SORT_ASC])->all();
        $options = '';
        foreach ($candidates as $c) {
            $options .= '<option value="' . $c->id . '">' . htmlspecialchars($c->full_name) . ' (' . htmlspecialchars((string) $c->position) . ')</option>';
        }
        $addMemberUrl = Url::to(['admin/group-add-member']);

        $addForm = $candidates
            ? <<<HTML
<form method="post" action="{$addMemberUrl}" style="margin:16px 0;background:#fff;padding:14px 18px;border-radius:6px;max-width:480px;">
<input type="hidden" name="group_id" value="{$group->id}">
<label>A'zo qo'shish<br>
<select name="user_id" required style="width:100%;padding:8px;margin-top:6px;box-sizing:border-box;">
<option value="">— foydalanuvchini tanlang —</option>
{$options}
</select>
</label>
<button type="submit" style="margin-top:10px;padding:8px 18px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Qo'shish</button>
</form>
HTML
            : '<p style="color:#888;">Qo\'shish mumkin bo\'lgan (hali ro\'yxatdan o\'tgan, lekin guruhga kiritilmagan) foydalanuvchi yo\'q. Foydalanuvchi botga /start yozib ro\'yxatdan o\'tishi kerak.</p>';

        $updateChannelUrl = Url::to(['admin/group-update-channel']);
        $nameEscVal = htmlspecialchars($group->name, ENT_QUOTES);
        $channelIdEsc = htmlspecialchars($group->channel_id);
        $channelForm = <<<HTML
<form method="post" action="{$updateChannelUrl}" style="margin:10px 0;background:#fff;padding:14px 18px;border-radius:6px;max-width:480px;">
<input type="hidden" name="group_id" value="{$group->id}">
<div style="margin-bottom:10px;"><label style="font-size:14px;">Guruh nomi<br>
<input type="text" name="name" value="{$nameEscVal}" required style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;">
</label></div>
<div style="margin-bottom:10px;"><label style="font-size:14px;">Kanal<br>
<input type="text" name="channel_id" value="{$channelIdEsc}" required style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;">
</label></div>
<button type="submit" style="padding:8px 18px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Saqlash</button>
<p style="color:#888;font-size:13px;margin:8px 0 0;">⚠️ Bot shu kanalda administrator (post joylash huquqi bilan) bo'lishi kerak, aks holda e'lonlar ketmaydi.</p>
</form>
HTML;

        $info = '<p><a href="' . Url::to(['admin/groups']) . '" style="color:#2c3e50;">← Guruhlar ro\'yxatiga qaytish</a></p>'
            . $channelForm
            . '<p><b>Qo\'shilish kodi:</b> <code>' . $group->id . '</code></p>';

        return $this->page("«{$group->name}» guruhi a'zolari", 'groups', $info . $table . $addForm);
    }

    public function actionMeetings(): Response
    {
        $meetings = Meeting::find()->with(['group', 'creator'])->orderBy(['meeting_at' => SORT_DESC])->all();

        $statusLabels = [
            Meeting::STATUS_SCHEDULED => 'Rejalashtirilgan',
            Meeting::STATUS_ANNOUNCED => 'E\'lon qilingan',
            Meeting::STATUS_ATTENDANCE_MARKING => 'Davomat belgilanmoqda',
            Meeting::STATUS_FINISHED => 'Yakunlangan',
            Meeting::STATUS_CANCELLED => 'Bekor qilingan',
        ];

        $rows = '';
        foreach ($meetings as $m) {
            $presentCount = $m->getAttendances()->andWhere(['status' => 'present'])->count();
            $absentCount = $m->getAttendances()->andWhere(['status' => ['absent', 'excused']])->count();
            $attendanceText = $m->isFinished() ? "✅ {$presentCount} / ❌ {$absentCount}" : '—';

            $rows .= '<tr>'
                . '<td>' . $m->id . '</td>'
                . '<td>' . htmlspecialchars($m->group->name ?? '—') . '</td>'
                . '<td>' . htmlspecialchars($m->topic) . '</td>'
                . '<td>' . htmlspecialchars($m->meeting_at) . '</td>'
                . '<td>' . htmlspecialchars($m->formatLabel()) . '</td>'
                . '<td>' . htmlspecialchars($statusLabels[$m->status] ?? $m->status) . '</td>'
                . '<td>' . htmlspecialchars($m->creator->full_name ?? '—') . '</td>'
                . '<td>' . $attendanceText . '</td>'
                . '</tr>';
        }

        $body = $this->table(
            ['ID', 'Guruh', 'Mavzu', 'Sana/vaqt', 'Format', 'Holat', 'Yaratdi', 'Keldi/Kelmadi'],
            $rows,
            "Jami: " . count($meetings)
        );

        return $this->page('Uchrashuvlar', 'meetings', $body);
    }

    /** Har bir foydalanuvchi bo'yicha svodka: necha marta keldi, necha marta kelmadi. */
    public function actionAttendance(): Response
    {
        $rows = Attendance::find()
            ->select(['user_id', 'status', 'cnt' => 'COUNT(*)'])
            ->groupBy(['user_id', 'status'])
            ->asArray()
            ->all();

        $stats = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $stats[$uid] ??= ['present' => 0, 'absent' => 0, 'excused' => 0];
            $stats[$uid][$row['status']] = (int) $row['cnt'];
        }

        $users = User::find()->where(['id' => array_keys($stats)])->orderBy(['full_name' => SORT_ASC])->all();

        $tableRows = '';
        foreach ($users as $u) {
            $s = $stats[$u->id];
            $total = $s['present'] + $s['absent'] + $s['excused'];
            $rate = $total > 0 ? round($s['present'] / $total * 100) . '%' : '—';
            $detailUrl = Url::to(['admin/attendance-user', 'id' => $u->id]);
            $tableRows .= '<tr>'
                . '<td><a href="' . $detailUrl . '" style="color:#2c3e50;font-weight:bold;text-decoration:none;">' . htmlspecialchars($u->full_name) . ' →</a></td>'
                . '<td>' . $total . '</td>'
                . '<td style="color:#1e8449;">✅ ' . $s['present'] . '</td>'
                . '<td style="color:#c0392b;">❌ ' . $s['absent'] . '</td>'
                . '<td style="color:#b8860b;">⚠️ ' . $s['excused'] . '</td>'
                . '<td>' . $rate . '</td>'
                . '</tr>';
        }

        $body = $this->table(
            ['Foydalanuvchi', 'Jami belgilangan', 'Keldi', 'Kelmadi', 'Sababli', 'Foiz'],
            $tableRows,
            "Jami: " . count($users) . ' <span style="color:#aaa;">(ismni bosing — sana bo\'yicha batafsil ro\'yxat ochiladi)</span>'
        );

        return $this->page('Davomat', 'attendance', $body);
    }

    /** Bitta foydalanuvchining barcha uchrashuvlardagi davomati — sanasi bilan. */
    public function actionAttendanceUser(int $id): Response
    {
        $user = User::findOne($id);
        if ($user === null) {
            return $this->page('Foydalanuvchi topilmadi', 'attendance', '<p>Bunday foydalanuvchi topilmadi. <a href="' . Url::to(['admin/attendance']) . '">Orqaga</a></p>');
        }

        $attendances = Attendance::find()
            ->where(['user_id' => $id])
            ->with(['meeting', 'meeting.group', 'markedByUser'])
            ->all();

        usort($attendances, fn (Attendance $a, Attendance $b) => strcmp($b->meeting->meeting_at ?? '', $a->meeting->meeting_at ?? ''));

        $statusLabels = [
            Attendance::STATUS_PRESENT => '<span style="color:#1e8449;">✅ Keldi</span>',
            Attendance::STATUS_ABSENT => '<span style="color:#c0392b;">❌ Kelmadi</span>',
            Attendance::STATUS_EXCUSED => '<span style="color:#b8860b;">⚠️ Sababli kelmadi</span>',
        ];

        $rows = '';
        $present = 0;
        $absent = 0;
        foreach ($attendances as $a) {
            $meeting = $a->meeting;
            if ($meeting === null) {
                continue;
            }
            if ($a->status === Attendance::STATUS_PRESENT) {
                $present++;
            } else {
                $absent++;
            }
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($meeting->group->name ?? '—') . '</td>'
                . '<td>' . htmlspecialchars($meeting->topic) . '</td>'
                . '<td>' . htmlspecialchars($meeting->meeting_at) . '</td>'
                . '<td>' . ($statusLabels[$a->status] ?? htmlspecialchars($a->status)) . '</td>'
                . '<td>' . htmlspecialchars((string) $a->marked_at) . '</td>'
                . '<td>' . htmlspecialchars($a->markedByUser->full_name ?? '—') . '</td>'
                . '</tr>';
        }

        $table = $this->table(
            ['Guruh', 'Uchrashuv', 'Sana/vaqt', 'Holat', 'Belgilangan vaqt', 'Kim belgiladi'],
            $rows,
            "Jami: {$present} keldi, {$absent} kelmadi/sababli"
        );

        $info = '<p><a href="' . Url::to(['admin/attendance']) . '" style="color:#2c3e50;">← Davomat ro\'yxatiga qaytish</a></p>';

        return $this->page("«{$user->full_name}» davomati", 'attendance', $info . $table);
    }

    public function actionMigrate(): Response
    {
        $output = '';
        if (Yii::$app->request->isPost) {
            $output = MigrationRunner::up();
        }

        $outputHtml = $output ? '<pre style="background:#f4f4f4;padding:15px;white-space:pre-wrap;border-radius:4px;">' . htmlspecialchars($output) . '</pre>' : '';

        $body = <<<HTML
<form method="post">
<button type="submit" style="padding:10px 20px;font-size:15px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">Migratsiyalarni bajarish (migrate/up)</button>
</form>
{$outputHtml}
HTML;

        return $this->page('Migratsiyalar', 'migrate', $body);
    }

    public function actionWebhook(): Response
    {
        $info = Yii::$app->telegram->getWebhookInfo();
        $result = $info['result'] ?? [];

        $currentUrl = (string) ($result['url'] ?? '');
        $suggestedUrl = Yii::$app->request->hostInfo . '/bot/webhook';

        $rows = [
            'Hozirgi webhook URL' => $currentUrl !== '' ? htmlspecialchars($currentUrl) : '<span style="color:#c0392b;">o\'rnatilmagan</span>',
            'Navbatdagi apdeytlar (pending_update_count)' => (string) ($result['pending_update_count'] ?? 0),
            'Oxirgi yetkazib berish xatosi' => !empty($result['last_error_message'])
                ? '<span style="color:#c0392b;">' . htmlspecialchars($result['last_error_message']) . '</span> ('
                    . date('Y-m-d H:i:s', (int) ($result['last_error_date'] ?? 0)) . ')'
                : '<span style="color:#1e8449;">yo\'q</span>',
            'IP manzil' => htmlspecialchars((string) ($result['ip_address'] ?? '—')),
        ];
        $infoRows = '';
        foreach ($rows as $label => $value) {
            $infoRows .= '<tr><td style="padding:6px 10px;color:#666;">' . htmlspecialchars($label) . '</td><td style="padding:6px 10px;"><b>' . $value . '</b></td></tr>';
        }

        $matchesSuggested = $currentUrl === $suggestedUrl;
        $matchNote = $matchesSuggested
            ? '<p style="color:#1e8449;">✅ Hozirgi webhook shu serverga (' . htmlspecialchars($suggestedUrl) . ') o\'rnatilgan.</p>'
            : '<p style="color:#b8860b;">⚠️ Hozirgi webhook shu serverga mos kelmayapti. Pastdagi tugma bilan «' . htmlspecialchars($suggestedUrl) . '» ga o\'rnating.</p>';

        $setWebhookUrl = Url::to(['admin/webhook-set']);
        $suggestedUrlEsc = htmlspecialchars($suggestedUrl, ENT_QUOTES);
        $form = <<<HTML
<div style="background:#fff;padding:14px 18px;border-radius:6px;max-width:640px;">
<table style="border-collapse:collapse;">{$infoRows}</table>
{$matchNote}
<form method="post" action="{$setWebhookUrl}" style="margin-top:14px;">
<label>Webhook URL<br>
<input type="text" name="url" value="{$suggestedUrlEsc}" required style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;">
</label>
<button type="submit" style="margin-top:10px;padding:10px 20px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">🔌 Webhookni faollashtirish</button>
</form>
</div>
HTML;

        $commandsHtml = $this->commandsSection();

        return $this->page('Webhook', 'webhook', $form . $commandsHtml);
    }

    /** Telegram'dagi «/» buyruqlar menyusi (chat oynasida taklif qilinadigan buyruqlar ro'yxati). */
    private function commandsSection(): string
    {
        $current = Yii::$app->telegram->getMyCommands();
        $commands = $current['result'] ?? [];

        $currentRows = '';
        foreach ($commands as $c) {
            $currentRows .= '<tr><td style="padding:6px 10px;color:#666;">/' . htmlspecialchars((string) ($c['command'] ?? ''))
                . '</td><td style="padding:6px 10px;">' . htmlspecialchars((string) ($c['description'] ?? '')) . '</td></tr>';
        }
        $currentTable = $currentRows
            ? '<table style="border-collapse:collapse;">' . $currentRows . '</table>'
            : '<p style="color:#888;">Hali birorta buyruq ro\'yxatga olinmagan.</p>';

        $setCommandsUrl = Url::to(['admin/commands-set']);

        return <<<HTML
<div style="background:#fff;padding:14px 18px;border-radius:6px;max-width:640px;margin-top:20px;">
<h3 style="margin-top:0;">Buyruqlar menyusi («/» tugmasi)</h3>
{$currentTable}
<form method="post" action="{$setCommandsUrl}" style="margin-top:14px;">
<button type="submit" style="padding:10px 20px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;">/start ni ro'yxatga olish</button>
</form>
</div>
HTML;
    }

    public function actionCommandsSet(): Response
    {
        if (Yii::$app->request->isPost) {
            $result = Yii::$app->telegram->setMyCommands([
                ['command' => 'start', 'description' => "Botni ishga tushirish / ro'yxatdan o'tish"],
            ]);
            Yii::$app->session->setFlash(
                !empty($result['ok']) ? 'success' : 'error',
                !empty($result['ok'])
                    ? "Buyruqlar menyusi yangilandi."
                    : 'Xatolik: ' . htmlspecialchars((string) ($result['description'] ?? 'noma\'lum xatolik'))
            );
        }

        return $this->redirect(['admin/webhook']);
    }

    public function actionWebhookSet(): Response
    {
        if (Yii::$app->request->isPost) {
            $url = trim((string) Yii::$app->request->post('url'));
            if ($url !== '') {
                $result = Yii::$app->telegram->setWebhook($url);
                Yii::$app->session->setFlash(
                    !empty($result['ok']) ? 'success' : 'error',
                    !empty($result['ok'])
                        ? "Webhook o'rnatildi: {$url}"
                        : 'Xatolik: ' . htmlspecialchars((string) ($result['description'] ?? 'noma\'lum xatolik'))
                );
            }
        }

        return $this->redirect(['admin/webhook']);
    }

    // ------------------------------------------------------------- helpers

    private function renderFlash(): string
    {
        $html = '';
        $error = Yii::$app->session->getFlash('error');
        $success = Yii::$app->session->getFlash('success');
        if ($error) {
            $html .= '<div style="background:#fdecea;color:#c0392b;padding:10px 14px;border-radius:6px;margin-bottom:14px;">' . htmlspecialchars($error) . '</div>';
        }
        if ($success) {
            $html .= '<div style="background:#eafaf1;color:#1e8449;padding:10px 14px;border-radius:6px;margin-bottom:14px;">' . htmlspecialchars($success) . '</div>';
        }

        return $html;
    }

    private function table(array $headers, string $rows, string $footerNote): string
    {
        $headHtml = implode('', array_map(fn ($h) => '<th style="text-align:left;padding:8px;border-bottom:2px solid #ddd;">' . htmlspecialchars($h) . '</th>', $headers));

        return <<<HTML
<p style="color:#888;">{$footerNote}</p>
<div style="overflow-x:auto;">
<table style="border-collapse:collapse;width:100%;background:#fff;">
<thead><tr>{$headHtml}</tr></thead>
<tbody>{$rows}</tbody>
</table>
</div>
HTML;
    }

    private function page(string $title, string $active, string $bodyHtml): Response
    {
        $nav = [
            'users' => ['Foydalanuvchilar', 'admin/users'],
            'groups' => ['Guruhlar', 'admin/groups'],
            'meetings' => ['Uchrashuvlar', 'admin/meetings'],
            'attendance' => ['Davomat', 'admin/attendance'],
            'migrate' => ['Migratsiyalar', 'admin/migrate'],
            'webhook' => ['Webhook', 'admin/webhook'],
        ];

        $navHtml = '';
        foreach ($nav as $key => [$label, $route]) {
            $style = $key === $active
                ? 'color:#fff;background:#2c3e50;'
                : 'color:#2c3e50;';
            $navHtml .= '<a href="' . Url::to(['/' . $route]) . '" style="' . $style . 'padding:8px 16px;text-decoration:none;border-radius:4px;margin-right:6px;display:inline-block;">' . $label . '</a>';
        }

        $logoutUrl = Url::to(['/site/logout']);
        $flashHtml = $this->renderFlash();

        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>{$title} — Boshqaruv paneli</title></head>
<body style="font-family: sans-serif; margin:0; background:#f7f7f7;">
<nav style="background:#fff;padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
<div>{$navHtml}</div>
<a href="{$logoutUrl}" style="color:#c0392b;text-decoration:none;">Chiqish</a>
</nav>
<main style="padding:24px;">
<h2 style="margin-top:0;">{$title}</h2>
{$flashHtml}
{$bodyHtml}
</main>
</body></html>
HTML;

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->data = $html;
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
