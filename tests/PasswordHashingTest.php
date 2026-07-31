<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/lib/PasswordHasher.php';

use lib\PasswordHasher;

$plain = 'Correct-Horse-2026!';
$hash = PasswordHasher::hash($plain);

if ($hash === $plain || strlen($hash) <= 32) {
    fwrite(STDERR, "new passwords must use a slow adaptive hash\n");
    exit(1);
}
if (!PasswordHasher::verify($plain, $hash) || PasswordHasher::verify('wrong-password', $hash)) {
    fwrite(STDERR, "modern password verification failed\n");
    exit(1);
}

$legacyAdmin = $plain;
if (!PasswordHasher::verifyAdmin($plain, $legacyAdmin) || PasswordHasher::isModern($legacyAdmin)) {
    fwrite(STDERR, "legacy plaintext admin password compatibility failed\n");
    exit(1);
}

$uid = 1001;
$legacyMerchant = md5(md5($plain) . md5('1277180438' . $uid));
if (!PasswordHasher::verifyMerchant($plain, $legacyMerchant, $uid)) {
    fwrite(STDERR, "legacy merchant MD5 compatibility failed\n");
    exit(1);
}
if (PasswordHasher::verifyMerchant('wrong-password', $legacyMerchant, $uid)) {
    fwrite(STDERR, "legacy merchant verification accepted a wrong password\n");
    exit(1);
}

echo "Password hashing compatibility tests passed." . PHP_EOL;
