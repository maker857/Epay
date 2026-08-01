<?php
declare(strict_types=1);

require dirname(__DIR__) . '/plugins/paypal/paypal_plugin.php';

$valid = [
    'https://api-m.paypal.com/v1/notifications/certs/test',
    'https://api-m.sandbox.paypal.com/v1/notifications/certs/test?x=1',
];
foreach ($valid as $url) {
    if (!\PayPalWebhookSecurity::validateCertUrl($url)) {
        fwrite(STDERR, "official PayPal certificate URL should be accepted: {$url}\n");
        exit(1);
    }
}

$invalid = [
    'http://api-m.paypal.com/v1/notifications/certs/test',
    'https://evil.example/v1/notifications/certs/test',
    'https://api-m.paypal.com.evil.example/v1/notifications/certs/test',
    'https://api-m.paypal.com:8443/v1/notifications/certs/test',
    'https://user:pass@api-m.paypal.com/v1/notifications/certs/test',
    'https://api-m.paypal.com/other/path',
    'https://127.0.0.1/v1/notifications/certs/test',
];
foreach ($invalid as $url) {
    if (\PayPalWebhookSecurity::validateCertUrl($url)) {
        fwrite(STDERR, "unsafe PayPal certificate URL should be rejected: {$url}\n");
        exit(1);
    }
}

foreach (['127.0.0.1', '10.0.0.1', '::1', '169.254.169.254'] as $ip) {
    if (\PayPalWebhookSecurity::isPublicIp($ip)) {
        fwrite(STDERR, "private or reserved IP accepted: {$ip}\n");
        exit(1);
    }
}

echo "PayPal webhook security tests passed." . PHP_EOL;
