<?php
declare(strict_types=1);

$login = file_get_contents(dirname(__DIR__) . '/admin/login.php');
$headers = file_get_contents(dirname(__DIR__) . '/docker/security-headers.conf');
if ($login === false) throw new RuntimeException('admin/login.php is missing');
if ($headers === false) throw new RuntimeException('security headers configuration is missing');

if (strpos($login, '$csrf_token = csrf_token();') === false) {
    throw new RuntimeException('Login page does not generate a CSRF token');
}

if (!preg_match('/var\s+csrf_token\s*=\s*<\?php\s+echo\s+json_encode\(\$csrf_token/', $login)) {
    throw new RuntimeException('Login page does not expose CSRF token to JavaScript');
}

if (strpos($login, 'csrf_token:csrf_token') === false) {
    throw new RuntimeException('Login request does not submit CSRF token');
}

if (strpos($headers, "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:") === false) {
    throw new RuntimeException('Global CSP does not explicitly allow required application scripts');
}

echo "Admin login CSRF token test passed.\n";
