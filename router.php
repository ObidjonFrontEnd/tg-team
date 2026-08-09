<?php

/**
 * Роутер для встроенного PHP-сервера (php -S), чтобы работали "чистые" URL
 * при enablePrettyUrl=true/showScriptName=false. В продакшене (Apache/Plesk)
 * этот файл не используется — там работает web/.htaccess.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__ . '/web' . $uri)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/web/index.php';
