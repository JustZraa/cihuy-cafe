FROM php:8.2-apache

ENV TZ=Asia/Jakarta

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl mbstring mysqli pdo_mysql zip \
    && ln -snf /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
    && echo Asia/Jakarta > /etc/timezone \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN { \
        echo 'date.timezone=Asia/Jakarta'; \
        echo 'upload_max_filesize=10M'; \
        echo 'post_max_size=12M'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/proc/self/fd/2'; \
        echo 'expose_php=Off'; \
        echo 'session.cookie_httponly=1'; \
        echo 'session.cookie_samesite=Lax'; \
    } > /usr/local/etc/php/conf.d/cozycafe.ini

COPY . /var/www/html/

RUN mkdir -p \
        /var/www/html/uploads/products \
        /var/www/html/uploads/banners \
        /var/www/html/uploads/payment-proofs \
        /var/www/html/database/backups \
    && sed -i \
        -e 's#ErrorDocument 403 /cozycafe/403.php#ErrorDocument 403 /403.php#' \
        -e 's#ErrorDocument 404 /cozycafe/404.php#ErrorDocument 404 /404.php#' \
        -e 's#ErrorDocument 500 /cozycafe/500.php#ErrorDocument 500 /500.php#' \
        /var/www/html/.htaccess \
    && chown -R www-data:www-data \
        /var/www/html/uploads \
        /var/www/html/database/backups \
    && chmod -R u+rwX,g+rwX,o-rwx \
        /var/www/html/uploads \
        /var/www/html/database/backups

WORKDIR /var/www/html
