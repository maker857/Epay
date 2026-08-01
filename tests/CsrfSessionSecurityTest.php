<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$token = csrf_token();
if (!preg_match('/^[a-f0-9]{64}$/', $token) || !csrf_verify($token)) {
    fwrite(STDERR, "CSRF token generation/verification failed\n");
    exit(1);
}
if (csrf_verify(str_repeat('0', 64)) || csrf_verify('')) {
    fwrite(STDERR, "forged CSRF token accepted\n");
    exit(1);
}

$functions = file_get_contents(dirname(__DIR__) . '/includes/functions.php');
$common = file_get_contents(dirname(__DIR__) . '/includes/common.php');
$plugin = file_get_contents(dirname(__DIR__) . '/admin/pay_plugin.php');
foreach ([$functions, $common, $plugin] as $source) {
    if ($source === false) {
        fwrite(STDERR, "security source file missing\n");
        exit(1);
    }
}
foreach (['httponly', 'samesite', 'secure_setcookie'] as $needle) {
    if (stripos($functions . $common, $needle) === false) {
        fwrite(STDERR, "missing secure session/cookie control: {$needle}\n");
        exit(1);
    }
}
if (strpos($plugin, "if(\$my=='refresh' && \$_SERVER['REQUEST_METHOD'] === 'POST')") === false || strpos($plugin, 'csrf_require') === false) {
    fwrite(STDERR, "plugin refresh is missing POST/CSRF protection\n");
    exit(1);
}
if (strpos($plugin, 'href="./pay_plugin.php?my=refresh"') !== false) {
    fwrite(STDERR, "plugin refresh is still exposed as a GET link\n");
    exit(1);
}

echo "CSRF and session security tests passed." . PHP_EOL;
