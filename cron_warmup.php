<?php
/**
 * Plesk scheduler uchun warmup script.
 *
 * Sozlash:
 *   Plesk → Saytlar va domenlar → Rejalashtiruvchi → Yangi vazifa
 *   Vazifa turi: PHP skriptini bajarish
 *   PHP fayli: /path/to/project/cron_warmup.php
 *   Jadval: har 5 daqiqada
 *
 * Nima qiladi:
 *   APP_BASE_URL ga GET so'rov yuboradi — PHP-FPM worker ishga kiradi,
 *   opcache isiydi, schema FileCache'da saqlanadi.
 */

$url = (getenv('APP_BASE_URL') ?: 'https://yourdomain.com') . '/site/ping';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "[warmup] ERROR: $err\n";
    exit(1);
}

echo "[warmup] $status — $body\n";
