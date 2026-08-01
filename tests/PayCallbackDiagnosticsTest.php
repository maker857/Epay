<?php

$source = file_get_contents(__DIR__ . '/../pay.php');

if (strpos($source, "[pay callback]") === false) {
    throw new RuntimeException('pay.php does not log callback exceptions');
}

foreach (['appsecret', 'appkey', 'resp_data'] as $sensitive) {
    $logLine = substr($source, strpos($source, "error_log('[pay callback]"));
    if (strpos($logLine, $sensitive) !== false) {
        throw new RuntimeException('pay.php diagnostics must not log sensitive callback data: '.$sensitive);
    }
}

echo "Pay callback diagnostics test passed.\n";
