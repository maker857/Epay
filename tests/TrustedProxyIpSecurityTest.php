<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';

putenv('EPAY_TRUSTED_PROXY_IPS');
$_SERVER = ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
if (real_ip(0) !== '8.8.8.8') {
    fwrite(STDERR, "untrusted proxy header changed client IP\n");
    exit(1);
}

putenv('EPAY_TRUSTED_PROXY_IPS=127.0.0.1');
$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
if (real_ip(0) !== '1.1.1.1') {
    fwrite(STDERR, "trusted proxy header was not accepted\n");
    exit(1);
}

$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 1.1.1.1';
if (real_ip(0) !== '1.1.1.1') {
    fwrite(STDERR, "private forwarded IP was accepted\n");
    exit(1);
}

putenv('EPAY_TRUSTED_PROXY_IPS=127.0.0.0/8');
$_SERVER = ['REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_REAL_IP' => '9.9.9.9'];
if (real_ip(1) !== '9.9.9.9') {
    fwrite(STDERR, "trusted proxy CIDR was not honored\n");
    exit(1);
}

echo "Trusted proxy IP security tests passed." . PHP_EOL;
