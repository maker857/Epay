<?php
declare(strict_types=1);

$conf = ['proxy' => 0];
require dirname(__DIR__) . '/includes/functions.php';

$valid = curl_get('https://example.com/');
if ($valid === false || $valid === '') {
    fwrite(STDERR, "expected valid HTTPS request to succeed\n");
    exit(1);
}

$invalid = curl_get('https://self-signed.badssl.com/');
if ($invalid !== false && $invalid !== '') {
    fwrite(STDERR, "expected self-signed HTTPS certificate to be rejected\n");
    exit(1);
}

echo "TLS certificate validation integration test passed." . PHP_EOL;
