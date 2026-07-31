<?php

namespace lib;

class AdminTotpLogin
{
    public const MAX_ATTEMPTS = 5;
    public const TTL = 300;

    private const KEYS = [
        'admin_totp_pending',
        'admin_totp_pending_user',
        'admin_totp_pending_ip',
        'admin_totp_pending_expires',
        'admin_totp_pending_attempts',
    ];

    public static function begin(array &$session, string $user, string $ip, ?int $now = null): void
    {
        $now = $now ?? time();
        $session['admin_totp_pending'] = bin2hex(random_bytes(32));
        $session['admin_totp_pending_user'] = $user;
        $session['admin_totp_pending_ip'] = $ip;
        $session['admin_totp_pending_expires'] = $now + self::TTL;
        $session['admin_totp_pending_attempts'] = 0;
    }

    public static function isPendingValid(array $session, string $user, string $ip, ?int $now = null): bool
    {
        $now = $now ?? time();
        return !empty($session['admin_totp_pending'])
            && ($session['admin_totp_pending_user'] ?? '') === $user
            && ($session['admin_totp_pending_ip'] ?? '') === $ip
            && intval($session['admin_totp_pending_expires'] ?? 0) >= $now;
    }

    public static function recordFailure(array &$session): int
    {
        $attempts = intval($session['admin_totp_pending_attempts'] ?? 0) + 1;
        $session['admin_totp_pending_attempts'] = $attempts;
        return $attempts;
    }

    public static function attemptsExceeded(array $session): bool
    {
        return intval($session['admin_totp_pending_attempts'] ?? 0) >= self::MAX_ATTEMPTS;
    }

    public static function clear(array &$session): void
    {
        foreach (self::KEYS as $key) {
            unset($session[$key]);
        }
    }
}
