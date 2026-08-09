<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * Единственный админ веб-панели, логин/пароль берутся из .env (ADMIN_LOGIN/ADMIN_PASSWORD).
 * Без отдельной таблицы в БД — для одного администратора это не нужно.
 */
class AdminIdentity extends BaseObject implements IdentityInterface
{
    public string $id = 'admin';

    public static function findIdentity($id): ?self
    {
        return $id === 'admin' ? new self() : null;
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        return null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAuthKey(): string
    {
        return 'admin-auth-key-' . Yii::$app->params['admin.login'];
    }

    public function validateAuthKey($authKey): bool
    {
        return $authKey === $this->getAuthKey();
    }

    public static function validateCredentials(string $login, string $password): bool
    {
        $expectedLogin = (string) Yii::$app->params['admin.login'];
        $expectedPassword = (string) Yii::$app->params['admin.password'];

        return hash_equals($expectedLogin, $login) && hash_equals($expectedPassword, $password);
    }
}
