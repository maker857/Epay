<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';

$escaped = html_escape('<script>alert("x")</script>\"');
if ($escaped !== '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;\&quot;') {
    fwrite(STDERR, "HTML text/attribute escaping failed: {$escaped}\n");
    exit(1);
}

if (safe_color('#1a2B3c') !== '#1a2B3c') {
    fwrite(STDERR, "valid color was rejected\n");
    exit(1);
}
foreach (['red', '#12345678', 'javascript:alert(1)', '" onmouseover="alert(1)'] as $color) {
    if (safe_color($color) !== '') {
        fwrite(STDERR, "unsafe color accepted: {$color}\n");
        exit(1);
    }
}

$cashier = file_get_contents(dirname(__DIR__) . '/cashier.php');
$gonggao = file_get_contents(dirname(__DIR__) . '/admin/gonggao.php');
$uset = file_get_contents(dirname(__DIR__) . '/admin/uset.php');
foreach ([$cashier, $gonggao, $uset] as $source) {
    if ($source === false || strpos($source, 'html_escape(') === false) {
        fwrite(STDERR, "target template is missing centralized HTML escaping\n");
        exit(1);
    }
}

$forbidden = [
    "echo \$row['name']?>",
    "echo \$row['content']?></font>",
    "value=\"<?php echo \$row['phone']?>\"",
];
foreach ($forbidden as $pattern) {
    if (strpos($cashier . $gonggao . $uset, $pattern) !== false) {
        fwrite(STDERR, "unsafe raw output remains: {$pattern}\n");
        exit(1);
    }
}

echo "XSS output encoding tests passed." . PHP_EOL;
