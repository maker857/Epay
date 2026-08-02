<?php

foreach (['admin/ajax_pay.php', 'user/ajax.php'] as $path) {
    $source = file_get_contents(__DIR__ . '/../'.$path);
    $testPayBlock = substr($source, strpos($source, "case 'testpay':"));

    if (strpos($testPayBlock, 'SELECT uid FROM pre_user WHERE uid=:uid') === false) {
        throw new RuntimeException($path.' does not validate the configured merchant UID');
    }
}

echo "Test payment merchant validation test passed.\n";
