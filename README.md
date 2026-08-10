# Telegram-bot: uchrashuvlar va davomat (MVP)

Yii2 + PostgreSQL bot. Interfeys — faqat o'zbek tilida. Batafsil talablar: [CLAUDE.md](./CLAUDE.md). Bosqichma-bosqich checklist: [PLAN.md](./PLAN.md).

## Lokal ishga tushirish (Docker)

```bash
cp .env.example .env
# .env faylida BOT_TOKEN, CHANNEL_ID, SYSTEM_MIGRATE_TOKEN qiymatlarini kiriting

docker-compose up -d --build
```

Ilova: `http://localhost:8080/` — login qilinmagan bo'lsa kirish sahifasiga, login qilingan bo'lsa boshqaruv paneliga yo'naltiradi.

### Boshqaruv paneli (login/parol)

```
http://localhost:8080/site/login
```

Login/parol — `.env` dagi `ADMIN_LOGIN` / `ADMIN_PASSWORD`. Kirgandan so'ng yuqori navigatsiya orqali:
- **Foydalanuvchilar** — barcha ro'yxatdan o'tgan foydalanuvchilar va ular a'zo bo'lgan guruhlar
- **Guruhlar** — guruh nomi, kanal, moderator, a'zolar soni; guruh nomini yoki a'zolar sonini bosib ichiga kirish mumkin. Shu yerda: **yangi guruh yaratish**, mavjud (ro'yxatdan o'tgan) foydalanuvchini guruhga **qo'shish**, uni **Moderator qilib tayinlash** va guruh **kanalini o'zgartirish** (agar noto'g'ri kiritilgan bo'lsa)
- **Uchrashuvlar** — barcha uchrashuvlar, holati, yakunlangan bo'lsa — Keldi/Kelmadi soni
- **Migratsiyalar** — `migrate/up` ni bir tugma bilan ishga tushirish (konsolsiz)

⚠️ **Guruh yaratish va foydalanuvchini guruhga qo'shish faqat shu boshqaruv panelidan qilinadi** — botning o'zida bunday tugmalar yo'q (mahsulot talabiga ko'ra olib tashlangan). Foydalanuvchi botga `/start` yozib ro'yxatdan o'tadi, so'ng admin uni kerakli guruhga shu paneldan qo'shadi.

**Kuzatuvchi (observer):** «Foydalanuvchilar» ro'yxatida har bir odam yonida «🔭 Kuzatuvchi qilish» tugmasi bor (bosilgan zahoti foydalanuvchiga botda yangilangan menyu bilan xabar ketadi — /start kutish shart emas). Kuzatuvchi hech qanday guruhga a'zo bo'lmasa ham, botning asosiy menyusida 4 ta doimiy tugmani ko'radi: 📅 Uchrashuvlar, 👥 Guruh, 🕘 Tarix, 📊 Statistika. Har birini bosganda bot avval «qaysi guruh?» deb so'raydi (ro'yxatda — a'zolikdan qat'i nazar BARCHA guruhlar), so'ng o'sha guruh bo'yicha natijani ko'rsatadi:
- 📅 Uchrashuvlar — tanlangan guruhning kelayotgan uchrashuvlari
- 👥 Guruh — tanlangan guruh a'zolari va rollari
- 🕘 Tarix — tanlangan guruhning yakunlangan uchrashuvlari, har biri natijasi (✅/❌) bilan
- 📊 Statistika — a'zolar bo'yicha kim nechta marta keldi/kelmadi, **bu hafta / bu oy / jami** bo'yicha alohida-alohida (shunda bitta yomon hafta butun tarixni yomon ko'rsatmaydi), shu bilan birga guruhda bu hafta/oy/jami nechta uchrashuv bo'lgani ham ko'rsatiladi

Bir nechta odam bir vaqtda Kuzatuvchi bo'lishi mumkin.

### Bot menyusi — rolga qarab farq qiladi

- **Oddiy a'zo** — 2 tugma: 📅 Uchrashuvlar (ko'rish), 👥 Guruh (a'zolar ro'yxati — faqat ko'rish)
- **Kotib** (guruh shablonida shu rol berilgan bo'lsa) — 3 tugma: yuqoridagi ikkitasi + ✅ Davomat
- **Moderator** — 4 tugma: 📅 Uchrashuvlar, 👥 Guruh, ➕ Yangi uchrashuv, ✅ Davomat

**➕ Yangi uchrashuv** — 3 qadam: mavzu → sana/vaqt → format → **shu zahoti yaratiladi va kanalga e'lon qilinadi**.
- Sana/vaqt endi **kalendar orqali** tanlanadi (bugundan boshlab keyingi 14 kun tugma sifatida, so'ng soat tugmalari) — erkin matn yozish shart emas, lekin «✏️ Boshqa sana/vaqt» tugmasi bilan qo'lda ham yozish mumkin (turli formatlarni tushunadi: `2026-8-8 16 00`, `2026-08-08 16:00` va h.k.). Kamida qancha vaqt oldin yaratish kerakligi bo'yicha cheklov **yo'q** — hoziroq boshlanadigan uchrashuv ham yaratsa bo'ladi.
- Ishtirokchilar va rollar bu yerda tanlanmaydi — guruhning barcha a'zolari avtomatik qo'shiladi, rollari esa «👥 Guruh» bo'limidagi shablondan olinadi (hech narsa belgilanmagan bo'lsa — «Ishtirokchi»).
- Uchrashuvni Moderator **bekor qilishi** mumkin (📅 Uchrashuvlar bo'limi ochilganda pastda bekor qilish ro'yxati chiqadi) — bekor qilingan uchrashuv bazada `cancelled` holatda saqlanadi, endi hech qayerda faol ko'rsatilmaydi.

**👥 Guruh** — Moderator uchun interaktiv: ismni bosib, o'sha a'zoning **doimiy roli**ni belgilash/o'zgartirish mumkin (bir nechta rol birga bo'lishi mumkin). **Rollarni faqat shu yerdan o'zgartiring** — bu keyingi barcha yangi uchrashuvlarga avtomatik qo'llanadi. Boshqalar uchun — oddiy ko'rish rejimida (ism, lavozim, joriy rollar), o'zgartirib bo'lmaydi.

**✅ Davomat** — faqat uchrashuv boshlangandan keyin 12 soat ichida ko'rinadi (Moderator va Kotib uchun).

### Sinov rejimi (vaqt cheklovlarisiz)

`.env` dagi `TEST_BYPASS_TELEGRAM_IDS` ga telegram_id yozilgan foydalanuvchilar uchun uchrashuvni boshlagandan keyin kamida 1 soat o'tmasdan ham «🏁 Yakunlash» tugmasi ishlaydi (odatda shu vaqt o'tmaguncha yakunlab bo'lmaydi) — sinov/demo uchun qulay. Productionda bu qatorni bo'sh qoldiring.

### Migratsiyalarni qo'llash (ikkinchi, token orqali usul)

Boshqaruv panelidagi «Migratsiyalar» bo'limi bilan bir xil ishlaydi, lekin login talab qilmaydi — avtomatlashtirish/CI uchun qulay:

```
http://localhost:8080/system/migrate?token=<SYSTEM_MIGRATE_TOKEN>
```

### Webhookni sozlash (lokal test uchun ngrok/tunnel kerak)

```
https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://<public-domain>/bot/webhook
```

Botni ishga tushirish uchun kanalga admin sifatida qo'shing, so'ng botga `/start` yozing.

⚠️ **Muhim:** har bir guruhga biriktirilgan kanalga bot **albatta admin sifatida** (post joylash huquqi bilan) qo'shilgan bo'lishi kerak — aks holda e'lonlar/hisobotlar «Bad Request: chat not found» xatosi bilan jim yuborilmaydi (loglarda ko'rinadi, lekin foydalanuvchiga xato ko'rsatilmaydi). Kanal ID/username'ini `admin/group-members` sahifasidagi «Kanal» formasidan istalgan payt tekshirish/o'zgartirish mumkin.

## Haftalik hisobotni qo'lda sinash

```bash
docker-compose exec app php yii cron/weekly-report
```

## Deploy (Plesk, konsolsiz)

1. Domenni Plesk'da yarating, git orqali ushbu repo'ni deploy qiling (PHP versiyasi ≥ 8.2, tavsiya 8.3).
2. Plesk'dagi «PHP Composer» funksiyasi orqali `composer install` ni ishga tushiring (SSH kerak emas).
3. `.env` faylini serverga qo'ying (BOT_TOKEN, CHANNEL_ID, DB_*, SYSTEM_MIGRATE_TOKEN, ADMIN_LOGIN, ADMIN_PASSWORD) — Plesk PHP sozlamalarida environment variables sifatida ham qo'yish mumkin. **`TEST_BYPASS_TELEGRAM_IDS`ni productionda bo'sh qoldiring.**
4. Brauzerda `https://<domain>/system/migrate?token=...` sahifasini oching va migratsiyalarni qo'llang (yoki boshqaruv paneliga kirib «Migratsiyalar» bo'limidan).
5. Telegram webhookni sozlang: `https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://<domain>/bot/webhook`.
6. Plesk → «Sayt va domenlar → Rejalashtiruvchi vazifalar»da bitta vazifa qo'shing:
   - Turi: **PHP-skriptni bajarish**
   - Yo'l: `yii` (loyihaning ildiz papkasida)
   - Argumentlar: `cron/weekly-report`
   - Vaqt: har shanba, 09:30–10:00 oralig'ida (masalan 09:45)
   - PHP versiyasi: 8.2+

Batafsil — [CLAUDE.md](./CLAUDE.md) bo'lim 0.1.

## Loyihaning holati

Amalga oshirilgan (MVP):
- Ro'yxatdan o'tish (bot), guruh yaratish va a'zo qo'shish (faqat boshqaruv paneli)
- Rolga qarab menyu (2/3/4 tugma: oddiy a'zo/Kotib/Moderator)
- Guruh darajasidagi default rollar (Moderator botda belgilaydi, hammaga ko'rinadi) + avtomatik «Ishtirokchi» fallback
- Uchrashuv yaratish — kalendar orqali sana/vaqt tanlash, ishtirokchilar va rollar guruh shablonidan avtomatik, kanalga avtomatik e'lon
- Uchrashuvni bekor qilish (Moderator)
- Davomatni belgilash (qo'lda ochiladi — Moderator/Kotib tugma orqali, faqat 12 soatlik oynada), natijalarni kanalga e'lon qilish
- Haftalik hisobot (konsol buyrug'i + Plesk Rejalashtiruvchisi)

Keyingi bosqich uchun qoldirilgan (MVP'ga kirmaydi, [PLAN.md](./PLAN.md) ga qarang):
- Uchrashuvdan 1 soat/15 daqiqa oldin avtomatik shaxsiy eslatmalar (tez-tez ishlaydigan cron talab qiladi)
- Uchrashuv boshlanish vaqtida davomat ekranini avtomatik ochish (hozircha Moderator/Kotib buni qo'lda «✅ Davomatni belgilash» tugmasi orqali ochadi)
# tg-team
