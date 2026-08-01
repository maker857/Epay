<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dockerfile = file_get_contents($root . '/Dockerfile');
$compose = file_get_contents($root . '/docker-compose.yml');
$lock = $root . '/includes/composer.lock';

if ($dockerfile === false || $compose === false) {
    throw new RuntimeException('Docker configuration files are missing');
}

if (!is_writable('/var/lib/php/sessions')) {
    throw new RuntimeException('PHP session save path is not writable');
}

if (!is_file($lock)) {
    throw new RuntimeException('includes/composer.lock is missing');
}

$lockData = json_decode((string) file_get_contents($lock), true);
if (!is_array($lockData) || empty($lockData['packages'])) {
    throw new RuntimeException('composer.lock is invalid or empty');
}

foreach (['cccyun/alipay-sdk', 'cccyun/wechatpay-sdk', 'cccyun/qqpay-sdk', 'lpilp/guomi'] as $package) {
    $found = false;
    foreach ($lockData['packages'] as $locked) {
        if (($locked['name'] ?? '') === $package) {
            $found = true;
            break;
        }
    }
    if (!$found) throw new RuntimeException("Missing locked package: {$package}");
}

foreach (['no-new-privileges:true', 'cap_drop:', 'read_only: true', '/var/lib/php/sessions'] as $directive) {
    if (strpos($compose, $directive) === false) {
        throw new RuntimeException("Missing compose hardening directive: {$directive}");
    }
}

if (strpos($dockerfile, 'composer install') === false || strpos($dockerfile, '--no-dev') === false) {
    throw new RuntimeException('Dockerfile must install dependencies from the lock file');
}

echo "SEC-12 container hardening tests passed.\n";
