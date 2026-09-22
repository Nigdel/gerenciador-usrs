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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /tmp/laravel-views \
    && chown www-data:www-data /tmp/laravel-views

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 9000

CMD ["sh", "-c", "chown -R www-data:www-data /tmp/laravel-views && exec php-fpm"]
