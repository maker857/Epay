<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/admin/login.php');
$checks = [
    'password stage creates pending TOTP state' => "\$_SESSION['admin_totp_pending']",
    'TOTP stage requires pending state' => "empty(\$_SESSION['admin_totp_pending'])",
    'pending state has expiry' => "admin_totp_pending_expires",
    'TOTP failures are rate limited' => 'TOTP验证失败',
    'successful TOTP rotates session id' => 'session_regenerate_id(true)',
    'pending state is cleared after success' => "unset(\$_SESSION['admin_totp_pending'])",
];

$failures = [];
foreach ($checks as $label => $needle) {
    if (strpos($source, $needle) === false) {
        $failures[] = $label . ': missing ' . $needle;
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Admin TOTP security regression checks passed." . PHP_EOL;
