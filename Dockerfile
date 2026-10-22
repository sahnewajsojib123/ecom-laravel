FROM php:8.4-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libzip-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring zip intl bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x render-start.sh

RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    DocumentRoot /var/www/html/public' \
    '    <Directory /var/www/html/public>' \
    '        AllowOverride All' \
    '        Require all granted' \
    '        Options -Indexes +FollowSymLinks' \
    '    </Directory>' \
    '    ErrorLog /proc/self/fd/2' \
    '    CustomLog /proc/self/fd/1 combined' \
    '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

EXPOSE 10000
CMD ["./render-start.sh"]
