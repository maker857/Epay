#!/bin/sh
set -eu

php /usr/local/bin/init-postgres.php
exec docker-php-entrypoint "$@"
