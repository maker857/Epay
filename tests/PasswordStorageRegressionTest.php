<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expectations = [
    'admin/login.php' => ['PasswordHasher::verifyAdmin', 'PasswordHasher::hash'],
    'admin/set.php' => ['PasswordHasher::verifyAdmin', 'PasswordHasher::hash'],
    'user/ajax.php' => ['PasswordHasher::verifyMerchant', 'PasswordHasher::hash'],
    'user/ajax2.php' => ['PasswordHasher::verifyMerchant', 'PasswordHasher::hash'],
    'docker/init-postgres.php' => ['PasswordHasher::hash'],
];

$failures = [];
foreach ($expectations as $relative => $needles) {
    $source = file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            $failures[] = $relative . ': missing ' . $needle;
        }
    }
}

$schema = file_get_contents($root . '/install/install.sql');
if (strpos($schema, '`pwd` varchar(255)') === false) {
    $failures[] = 'install/install.sql: merchant password column is not wide enough';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Password storage regression checks passed." . PHP_EOL;
