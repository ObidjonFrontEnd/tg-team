<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\base\Model;

class LoginForm extends Model
{
    public string $username = '';
    public string $password = '';
    public bool $rememberMe = true;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            ['rememberMe', 'boolean'],
            ['password', 'validatePassword'],
        ];
    }

    public function validatePassword(string $attribute, array|null $params): void
    {
        if (!$this->hasErrors() && !AdminIdentity::validateCredentials($this->username, $this->password)) {
            $this->addError($attribute, "Login yoki parol noto'g'ri.");
        }
    }

    public function login(): bool
    {
        if ($this->validate()) {
            return Yii::$app->user->login(new AdminIdentity(), $this->rememberMe ? 3600 * 24 * 30 : 0);
        }

        return false;
    }
}
