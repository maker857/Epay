<?php
declare(strict_types=1);

$login = file_get_contents(dirname(__DIR__) . '/admin/login.php');
$headers = file_get_contents(dirname(__DIR__) . '/docker/security-headers.conf');
$adminHead = file_get_contents(dirname(__DIR__) . '/admin/head.php');
if ($login === false) throw new RuntimeException('admin/login.php is missing');
if ($headers === false) throw new RuntimeException('security headers configuration is missing');
if ($adminHead === false) throw new RuntimeException('admin/head.php is missing');

if (strpos($login, '$csrf_token = csrf_token();') === false) {
    throw new RuntimeException('Login page does not generate a CSRF token');
}

if (!preg_match('/var\s+csrf_token\s*=\s*<\?php\s+echo\s+json_encode\(\$csrf_token/', $login)) {
    throw new RuntimeException('Login page does not expose CSRF token to JavaScript');
}

if (strpos($login, 'csrf_token:csrf_token') === false) {
    throw new RuntimeException('Login request does not submit CSRF token');
}

if (strpos($login, 'onsubmit="return submitlogin(event)"') === false || strpos($login, 'event.preventDefault()') === false) {
    throw new RuntimeException('Login form does not prevent default navigation');
}

if (strpos($headers, "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:") === false) {
    throw new RuntimeException('Global CSP does not explicitly allow required application scripts');
}

if (strpos($adminHead, 'Content-Security-Policy') !== false) {
    throw new RuntimeException('Admin pages must not emit a duplicate CSP header');
}

echo "Admin login CSRF token test passed.\n";
