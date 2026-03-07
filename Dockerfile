FROM --platform=linux/amd64 php:8.4-fpm

# Instala dependências do sistema
ENV DEBIAN_FRONTEND=noninteractive
RUN rm -rf /var/lib/apt/lists/* && apt-get update && apt-get install -y \
    -o Dpkg::Options::="--force-confold" \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
