<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/lib/AdminTotpLogin.php';

use lib\AdminTotpLogin;

$session = [];
AdminTotpLogin::begin($session, 'admin', '203.0.113.10', 1000);

if (!AdminTotpLogin::isPendingValid($session, 'admin', '203.0.113.10', 1001)) {
    fwrite(STDERR, "fresh pending login should be valid\n");
    exit(1);
}
if (AdminTotpLogin::isPendingValid($session, 'other', '203.0.113.10', 1001)) {
    fwrite(STDERR, "pending login must be bound to the admin account\n");
    exit(1);
}
if (AdminTotpLogin::isPendingValid($session, 'admin', '203.0.113.11', 1001)) {
    fwrite(STDERR, "pending login must be bound to the client IP\n");
    exit(1);
}
if (AdminTotpLogin::isPendingValid($session, 'admin', '203.0.113.10', 1301)) {
    fwrite(STDERR, "expired pending login must be rejected\n");
    exit(1);
}

for ($i = 0; $i < AdminTotpLogin::MAX_ATTEMPTS; $i++) {
    AdminTotpLogin::recordFailure($session);
}
if (!AdminTotpLogin::attemptsExceeded($session)) {
    fwrite(STDERR, "five failed TOTP attempts must invalidate the challenge\n");
    exit(1);
}

AdminTotpLogin::clear($session);
if (AdminTotpLogin::isPendingValid($session, 'admin', '203.0.113.10', 1001)) {
    fwrite(STDERR, "cleared pending login must not be reusable\n");
    exit(1);
}

echo "Admin TOTP login guard tests passed." . PHP_EOL;
