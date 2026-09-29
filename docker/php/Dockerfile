FROM php:8.5-fpm

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libpq-dev \
    libldap2-dev \
    ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture --query DEB_BUILD_MULTIARCH)" \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        ldap \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /tmp/laravel-views \
    && chown www-data:www-data /tmp/laravel-views

COPY docker/php/klios-samba-ca.crt /usr/local/share/ca-certificates/klios-samba-ca.crt
RUN update-ca-certificates

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 9000

CMD ["sh", "-c", "chown -R www-data:www-data /tmp/laravel-views && exec php-fpm"]