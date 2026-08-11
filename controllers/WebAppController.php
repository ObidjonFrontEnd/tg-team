<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\User;
use app\services\ObserverStatsService;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Telegram Web App (Mini App) — kuzatuvchi uchun davomat statistikasini haqiqiy HTML
 * jadval sifatida ko'rsatadi. Bot xabarida ✅/❌ ustunlarini piksel aniqligida tekislab
 * bo'lmasdi (rangli emoji Telegram'da o'zgaruvchan kenglikda chiziladi) — web-app esa
 * oddiy HTML/CSS bo'lgani uchun bu muammo umuman yo'q.
 */
class WebAppController extends Controller
{
    public $enableCsrfValidation = false;

    /** Web App HTML qobig'i — ma'lumotni o'zi emas, sahifa yuklangach JS uni stats-data'dan so'raydi. */
    public function actionStats(): Response
    {
        $lang = (string) Yii::$app->request->get('lang', User::LANG_UZ);
        if (!in_array($lang, [User::LANG_UZ, User::LANG_UZ_CYRL, User::LANG_RU], true)) {
            $lang = User::LANG_UZ;
        }

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->data = $this->renderStatsPage($lang);

        return $response;
    }

    /** JS shu yerdan ma'lumot so'raydi — Telegram initData orqali kuzatuvchi ekanligi tasdiqlanadi. */
    public function actionStatsData(): Response
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $initData = (string) Yii::$app->request->post('initData', '');
        $telegramUser = $this->verifyInitData($initData);
        if ($telegramUser === null) {
            Yii::$app->response->statusCode = 401;

            return $this->json(['ok' => false, 'error' => 'invalid_init_data']);
        }

        $user = User::findOne(['telegram_id' => $telegramUser['id']]);
        if ($user === null || !$user->is_observer) {
            Yii::$app->response->statusCode = 403;

            return $this->json(['ok' => false, 'error' => 'not_observer']);
        }

        $groupId = (int) Yii::$app->request->post('group_id', 0);
        $period = (string) Yii::$app->request->post('period', ObserverStatsService::PERIOD_ALL);

        return $this->json([
            'ok' => true,
            'stats' => ObserverStatsService::compute($groupId, $period),
            'groups' => ObserverStatsService::allGroups(),
        ]);
    }

    /**
     * Telegram'ning rasmiy algoritmi: https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
     * @return array<string,mixed>|null Tasdiqlangan bo'lsa — initData ichidagi `user` obyekti.
     */
    private function verifyInitData(string $initData): ?array
    {
        $token = (string) Yii::$app->params['bot.token'];
        if ($initData === '' || $token === '') {
            return null;
        }

        parse_str($initData, $params);
        if (!isset($params['hash']) || !is_string($params['hash'])) {
            return null;
        }
        $hash = $params['hash'];
        unset($params['hash']);

        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $hash)) {
            return null;
        }

        // 24 soatdan eski initData'ni rad etamiz — havolani ulashib qo'yishdan himoya.
        $authDate = (int) ($params['auth_date'] ?? 0);
        if ($authDate <= 0 || time() - $authDate > 86400) {
            return null;
        }

        $userJson = $params['user'] ?? null;
        if (!is_string($userJson)) {
            return null;
        }

        $userData = json_decode($userJson, true);

        return is_array($userData) && isset($userData['id']) ? $userData : null;
    }

    private function json(array $data): Response
    {
        $response = Yii::$app->response;
        $response->data = $data;

        return $response;
    }

    private function renderStatsPage(string $lang): string
    {
        $texts = match ($lang) {
            User::LANG_RU => [
                'title' => 'Статистика посещаемости',
                'eyebrow' => 'НАБЛЮДЕНИЕ',
                'heading' => 'Посещаемость',
                'fullscreen' => 'На весь экран',
                'exitFullscreen' => 'Свернуть',
                'allGroups' => 'Все группы',
                'period_a' => 'Всего',
                'period_w' => 'Неделя',
                'period_m' => 'Месяц',
                'meetings' => 'встреч',
                'stampLabel' => 'явка',
                'loading' => 'Загрузка…',
                'noData' => 'В этот период встреч ещё не было.',
                'errorAuth' => 'Не удалось подтвердить пользователя. Откройте страницу через кнопку в боте.',
                'errorForbidden' => 'Раздел доступен только наблюдателю.',
                'errorNetwork' => 'Не удалось загрузить данные. Проверьте соединение и попробуйте снова.',
            ],
            User::LANG_UZ_CYRL => [
                'title' => 'Давомат статистикаси',
                'eyebrow' => 'КУЗАТУВ',
                'heading' => 'Давомат',
                'fullscreen' => 'Бутун экранда',
                'exitFullscreen' => 'Йиғиш',
                'allGroups' => 'Барча гуруҳлар',
                'period_a' => 'Жами',
                'period_w' => 'Ҳафта',
                'period_m' => 'Ой',
                'meetings' => 'учрашув',
                'stampLabel' => 'давомат',
                'loading' => 'Юкланмоқда…',
                'noData' => 'Бу даврда ҳали учрашув бўлмаган.',
                'errorAuth' => 'Фойдаланувчини тасдиқлаб бўлмади. Саҳифани бот ичидаги тугма орқали очинг.',
                'errorForbidden' => 'Бу бўлим фақат кузатувчи учун.',
                'errorNetwork' => 'Маълумотни юклаб бўлмади. Алоқани текшириб, қайта уриниб кўринг.',
            ],
            default => [
                'title' => 'Davomat statistikasi',
                'eyebrow' => 'KUZATUV',
                'heading' => 'Davomat',
                'fullscreen' => 'Butun ekranda',
                'exitFullscreen' => "Yig'ish",
                'allGroups' => 'Barcha guruhlar',
                'period_a' => 'Jami',
                'period_w' => 'Hafta',
                'period_m' => 'Oy',
                'meetings' => 'uchrashuv',
                'stampLabel' => 'davomat',
                'loading' => 'Yuklanmoqda…',
                'noData' => "Bu davrda hali uchrashuv bo'lmagan.",
                'errorAuth' => "Foydalanuvchini tasdiqlab bo'lmadi. Sahifani bot ichidagi tugma orqali oching.",
                'errorForbidden' => "Bu bo'lim faqat kuzatuvchi uchun.",
                'errorNetwork' => "Ma'lumotni yuklab bo'lmadi. Aloqani tekshirib, qayta urinib ko'ring.",
            ],
        };
        $i18n = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $title = htmlspecialchars($texts['title'], ENT_QUOTES);
        $heading = htmlspecialchars($texts['heading'], ENT_QUOTES);
        $eyebrow = htmlspecialchars($texts['eyebrow'], ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$title}</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: var(--tg-theme-bg-color, #15130f);
    --surface: var(--tg-theme-secondary-bg-color, #201c16);
    --ink: var(--tg-theme-text-color, #f3ede0);
    --ink-dim: var(--tg-theme-hint-color, #a89a80);
    --hairline: rgba(168,154,128,0.16);
    --gold: #d3a24a;
    --gold-soft: rgba(211,162,74,0.14);
    --good: #0ca30c;
    --critical: #d03b3b;
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html { color-scheme: light dark; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 18px 16px calc(env(safe-area-inset-bottom, 0px) + 24px);
  }
  .scene { max-width: 1080px; margin: 0 auto; }

  header.topbar {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
  }
  .eyebrow {
    font: 700 10.5px/1 "Inter", sans-serif;
    letter-spacing: .16em;
    text-transform: uppercase;
    color: var(--gold);
    margin: 0 0 6px;
  }
  h1.page-title {
    font-family: "Fraunces", Georgia, serif;
    font-weight: 600;
    font-style: italic;
    font-size: 30px;
    line-height: 1;
    margin: 0;
    color: var(--ink);
    letter-spacing: -0.01em;
  }
  button.fs-btn {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid var(--gold);
    background: transparent;
    color: var(--gold);
    border-radius: 999px;
    padding: 9px 14px;
    font: 600 12px "Inter", sans-serif;
    white-space: nowrap;
  }
  button.fs-btn:active { transform: scale(.96); background: var(--gold-soft); }

  .filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
  }
  .segmented {
    display: flex;
    flex: 1 1 260px;
    max-width: 340px;
    background: var(--surface);
    border-radius: 12px;
    padding: 3px;
    gap: 2px;
    border: 1px solid var(--hairline);
  }
  .segmented button {
    flex: 1;
    border: none;
    background: transparent;
    color: var(--ink-dim);
    font: 600 12.5px "Inter", sans-serif;
    padding: 9px 4px;
    border-radius: 9px;
    transition: background .15s ease, color .15s ease;
  }
  .segmented button.active { background: var(--gold); color: #1a1305; }

  .select-wrap { position: relative; flex: 1 1 220px; max-width: 320px; }
  .select-wrap::after {
    content: "";
    position: absolute;
    right: 14px; top: 50%;
    width: 8px; height: 8px;
    border-right: 2px solid var(--ink-dim);
    border-bottom: 2px solid var(--ink-dim);
    transform: translateY(-70%) rotate(45deg);
    pointer-events: none;
  }
  select#groupFilter {
    appearance: none; -webkit-appearance: none;
    width: 100%;
    background: var(--surface);
    color: var(--ink);
    border: 1px solid var(--hairline);
    border-radius: 12px;
    padding: 11px 34px 11px 14px;
    font: 500 13.5px "Inter", sans-serif;
  }

  #content {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    align-items: start;
    gap: 14px;
  }
  .ledger-card {
    background: var(--surface);
    border: 1px solid var(--hairline);
    border-radius: 16px;
    padding: 16px 16px 8px;
    opacity: 0;
    animation: rise .4s ease both;
  }
  @keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
  @media (prefers-reduced-motion: reduce) {
    .ledger-card, .stamp { animation: none !important; opacity: 1 !important; transform: none !important; }
  }

  .ledger-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
  }
  .ledger-head h2 {
    font-family: "Fraunces", Georgia, serif;
    font-weight: 600;
    font-size: 17px;
    margin: 0 0 3px;
    color: var(--ink);
  }
  .ledger-head .meta {
    font: 500 11.5px "Inter", sans-serif;
    color: var(--ink-dim);
  }

  .stamp {
    flex: 0 0 auto;
    width: 54px; height: 54px;
    border-radius: 50%;
    border: 2px dashed var(--tone, var(--gold));
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    transform: rotate(-7deg);
    animation: stampIn .45s cubic-bezier(.22,1.5,.4,1) both;
  }
  @keyframes stampIn { from { opacity: 0; transform: rotate(-7deg) scale(1.6); } to { opacity: 1; transform: rotate(-7deg) scale(1); } }
  .stamp .v { font: 800 15px "Inter", sans-serif; color: var(--tone, var(--gold)); line-height: 1; }
  .stamp .l {
    font: 700 6.5px "Inter", sans-serif;
    letter-spacing: .07em; text-transform: uppercase;
    color: var(--tone, var(--gold)); opacity: .8; margin-top: 3px;
  }

  .row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 0;
    border-top: 1px solid var(--hairline);
  }
  .row:first-of-type { border-top: none; }
  .row .name {
    flex: 0 0 38%;
    max-width: 38%;
    font: 500 13px "Inter", sans-serif;
    color: var(--ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .row .meter {
    flex: 1 1 auto;
    height: 8px;
    min-width: 32px;
    border-radius: 4px;
    background: var(--hairline);
    display: flex;
    gap: 2px;
    overflow: hidden;
  }
  .row .meter .seg { height: 100%; }
  .row .meter .seg.good { background: var(--good); }
  .row .meter .seg.critical { background: var(--critical); }
  .row .count {
    flex: 0 0 auto;
    min-width: 38px;
    text-align: right;
    font: 600 12px "Inter", sans-serif;
    font-variant-numeric: tabular-nums;
    color: var(--ink-dim);
  }

  .state-card {
    grid-column: 1 / -1;
    text-align: center;
    padding: 54px 20px;
    color: var(--ink-dim);
  }
  .state-card .icon { font-size: 26px; margin-bottom: 10px; opacity: .85; }
  .state-card p { font: 500 13.5px/1.55 "Inter", sans-serif; margin: 0 auto; max-width: 280px; }
</style>
</head>
<body>
<div class="scene">
  <header class="topbar">
    <div>
      <p class="eyebrow">{$eyebrow}</p>
      <h1 class="page-title">{$heading}</h1>
    </div>
    <button class="fs-btn" id="fsBtn">⛶ <span id="fsBtnLabel"></span></button>
  </header>

  <div class="filters">
    <div class="segmented" id="periodTabs"></div>
    <div class="select-wrap"><select id="groupFilter"></select></div>
  </div>

  <main id="content"></main>
</div>

<script>
(function () {
  var I18N = {$i18n};
  var tg = window.Telegram ? window.Telegram.WebApp : null;

  var state = { period: 'a', groupId: 0 };

  var fsBtn = document.getElementById('fsBtn');
  var fsBtnLabel = document.getElementById('fsBtnLabel');
  function syncFsLabel() {
    var isFull = !!(tg && tg.isFullscreen);
    fsBtnLabel.textContent = isFull ? I18N.exitFullscreen : I18N.fullscreen;
  }
  syncFsLabel();

  if (tg) {
    tg.ready();
    if (typeof tg.onEvent === 'function') {
      tg.onEvent('fullscreenChanged', syncFsLabel);
    }
    // Kichik "kartochka" ko'rinishida emas, darhol to'liq ekranda ochiladi — ayniqsa
    // desktop Telegram'da web-app odatda kichik oynada ochiladi, bu holatda foydalanuvchi
    // qo'lda tugma bosishi shart bo'lmasin. Qo'llab-quvvatlanmasa (eski klient) — expand().
    try {
      if (typeof tg.requestFullscreen === 'function') {
        tg.requestFullscreen();
      } else {
        tg.expand();
      }
    } catch (e) {
      tg.expand();
    }
    try { if (tg.themeParams && tg.themeParams.bg_color && tg.setBackgroundColor) { tg.setBackgroundColor(tg.themeParams.bg_color); } } catch (e) {}
  }

  fsBtn.addEventListener('click', function () {
    if (!tg) { return; }
    try {
      if (tg.isFullscreen && typeof tg.exitFullscreen === 'function') {
        tg.exitFullscreen();
      } else if (typeof tg.requestFullscreen === 'function') {
        tg.requestFullscreen();
      } else {
        tg.expand();
      }
    } catch (e) {
      tg.expand();
    }
  });

  var tabsEl = document.getElementById('periodTabs');
  ['a', 'm', 'w'].forEach(function (p) {
    var btn = document.createElement('button');
    btn.textContent = I18N['period_' + p];
    btn.dataset.period = p;
    if (p === state.period) { btn.className = 'active'; }
    btn.addEventListener('click', function () {
      if (state.period === p) { return; }
      state.period = p;
      Array.prototype.forEach.call(tabsEl.children, function (b) {
        b.className = b.dataset.period === p ? 'active' : '';
      });
      load();
    });
    tabsEl.appendChild(btn);
  });

  var groupSelect = document.getElementById('groupFilter');
  groupSelect.addEventListener('change', function () {
    state.groupId = parseInt(groupSelect.value, 10) || 0;
    load();
  });

  var contentEl = document.getElementById('content');

  function setState(icon, message) {
    contentEl.innerHTML = '<div class="state-card"><div class="icon">' + icon + '</div><p>' + escapeHtml(message) + '</p></div>';
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML;
  }

  function renderGroups(groups) {
    if (!groups.length) {
      setState('🗂', I18N.noData);

      return;
    }

    var html = '';
    groups.forEach(function (g, i) {
      var totalPresent = 0, totalAll = 0;
      g.members.forEach(function (m) { totalPresent += m.present; totalAll += m.present + m.absent; });
      var rate = totalAll > 0 ? Math.round((totalPresent / totalAll) * 100) : null;
      var tone = rate === null ? '' : (rate >= 75 ? 'var(--good)' : 'var(--critical)');

      html += '<section class="ledger-card" style="animation-delay:' + Math.min(i * 55, 400) + 'ms">';
      html += '<div class="ledger-head"><div><h2>' + escapeHtml(g.name) + '</h2>'
        + '<div class="meta">' + g.meetings + ' ' + I18N.meetings + '</div></div>'
        + '<div class="stamp" style="--tone:' + (tone || 'var(--gold)') + '">'
        + '<span class="v">' + (rate === null ? '–' : rate + '%') + '</span>'
        + '<span class="l">' + I18N.stampLabel + '</span></div></div>';

      g.members.forEach(function (m) {
        var total = m.present + m.absent;
        html += '<div class="row"><span class="name">' + escapeHtml(m.name) + '</span><div class="meter">';
        if (total === 0) {
          html += '<span class="seg" style="flex:1"></span>';
        } else {
          if (m.present > 0) { html += '<span class="seg good" style="flex:' + m.present + '"></span>'; }
          if (m.absent > 0) { html += '<span class="seg critical" style="flex:' + m.absent + '"></span>'; }
        }
        html += '</div><span class="count">' + (total === 0 ? '–' : (m.present + ' / ' + m.absent)) + '</span></div>';
      });

      html += '</section>';
    });
    contentEl.innerHTML = html;
  }

  function populateGroupFilter(groups, selectedId) {
    if (groupSelect.dataset.filled === '1') { return; }
    groupSelect.dataset.filled = '1';
    var allOpt = document.createElement('option');
    allOpt.value = '0';
    allOpt.textContent = '🌐 ' + I18N.allGroups;
    groupSelect.appendChild(allOpt);
    groups.forEach(function (g) {
      var opt = document.createElement('option');
      opt.value = String(g.id);
      opt.textContent = g.name;
      groupSelect.appendChild(opt);
    });
    groupSelect.value = String(selectedId);
  }

  function load() {
    setState('⏳', I18N.loading);

    var initData = tg ? tg.initData : '';
    var body = 'initData=' + encodeURIComponent(initData || '')
      + '&group_id=' + encodeURIComponent(state.groupId)
      + '&period=' + encodeURIComponent(state.period);

    fetch('stats-data', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json.ok) {
          setState('🔒', json.error === 'not_observer' ? I18N.errorForbidden : I18N.errorAuth);

          return;
        }
        populateGroupFilter(json.groups, state.groupId);
        renderGroups(json.stats.groups);
      })
      .catch(function () {
        setState('📡', I18N.errorNetwork);
      });
  }

  load();
})();
</script>
</body>
</html>
HTML;
    }
}
