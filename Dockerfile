FROM composer:2.8 AS composer

FROM php:8.3.8-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
        libonig-dev libpq-dev libzip-dev libgmp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath curl gd gmp mbstring pdo_pgsql zip \
    && a2enmod rewrite headers expires \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
COPY docker/epay-entrypoint.sh /usr/local/bin/epay-entrypoint.sh
COPY docker/init-postgres.php /usr/local/bin/init-postgres.php
COPY docker/security-headers.conf /etc/apache2/conf-available/epay-security-headers.conf

RUN sed -i 's/\r$//' /usr/local/bin/epay-entrypoint.sh \
    && a2enconf epay-security-headers \
    && composer install --working-dir=/var/www/html/includes --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    && touch install/install.lock \
    && chown -R www-data:www-data /var/www/html \
    && chmod 755 /usr/local/bin/epay-entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/ >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/epay-entrypoint.sh"]
CMD ["apache2-foreground"]
