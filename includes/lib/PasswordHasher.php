<?php

namespace lib;

class PasswordHasher
{
    public static function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algorithm);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    public static function isModern(?string $stored): bool
    {
        if (empty($stored)) return false;
        return password_get_info($stored)['algo'] !== null;
    }

    public static function verify(string $password, string $stored): bool
    {
        return self::isModern($stored) && password_verify($password, $stored);
    }

    public static function verifyAdmin(string $password, ?string $stored): bool
    {
        if (empty($stored)) return false;
        if (self::isModern($stored)) return password_verify($password, $stored);
        return hash_equals($stored, $password);
    }

    public static function verifyMerchant(string $password, ?string $stored, int $uid): bool
    {
        if (empty($stored)) return false;
        if (self::isModern($stored)) return password_verify($password, $stored);
        return hash_equals($stored, self::legacyMerchantHash($password, $uid));
    }

    public static function needsRehash(?string $stored): bool
    {
        if (!self::isModern($stored)) return true;
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($stored, $algorithm);
    }

    public static function legacyMerchantHash(string $password, int $uid): string
    {
        return md5(md5($password) . md5('1277180438' . $uid));
    }

    public static function sessionFingerprint(int $uid, string $key, string $passwordHash, string $pepper): string
    {
        return hash('sha256', $uid . "\0" . $key . "\0" . $passwordHash . "\0" . $pepper);
    }
}
