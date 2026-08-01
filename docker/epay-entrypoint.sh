#!/bin/sh
set -eu

mkdir -p /var/www/html/assets/uploads /var/www/html/plugins/sandpay/logs
chown www-data:www-data /var/www/html/assets/uploads /var/www/html/plugins/sandpay/logs

php /usr/local/bin/init-postgres.php
exec docker-php-entrypoint "$@"
