<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * .env qiymatini o'qiydi. Faqat getenv()ga tayanmaydi — ba'zi hostinglarda
     * (xavfsizlik uchun) putenv() o'chirilgan bo'ladi, shunday holatda phpdotenv
     * qiymatlarni faqat $_ENV/$_SERVER'ga yozadi, getenv() esa bo'sh qaytaveradi.
     * Shuning uchun avval $_ENV/$_SERVER'ni tekshiramiz, keyin getenv()ga tushamiz.
     */
    function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $value = getenv($key);

        return $value !== false && $value !== '' ? $value : $default;
    }
}
