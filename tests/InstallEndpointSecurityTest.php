<?php
declare(strict_types=1);

$htaccess = file_get_contents(dirname(__DIR__) . '/install/.htaccess');
$dockerfile = file_get_contents(dirname(__DIR__) . '/Dockerfile');
$entrypoint = file_get_contents(dirname(__DIR__) . '/docker/epay-entrypoint.sh');

if ($htaccess === false || strpos($htaccess, 'Require all denied') === false) {
    fwrite(STDERR, "install directory is not blocked by Apache\n");
    exit(1);
}
if ($dockerfile === false || strpos($dockerfile, 'AllowOverride All') === false) {
    fwrite(STDERR, "Apache overrides are not enabled for install protection\n");
    exit(1);
}
if ($entrypoint === false || strpos($entrypoint, 'init-postgres.php') === false) {
    fwrite(STDERR, "PostgreSQL container migration entrypoint is missing\n");
    exit(1);
}

echo "Install endpoint security tests passed." . PHP_EOL;
