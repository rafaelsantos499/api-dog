FROM php:8.4-fpm

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libonig-dev \
    libxml2-dev \
    netcat-openbsd \
    build-essential \
    pkg-config \
    libzip-dev \
 && rm -rf /var/lib/apt/lists/*

# Configurar e instalar extensões do PHP (GD com suporte a jpeg/webp/freetype)
RUN docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd

# Instalar extensão phpredis via PECL
RUN pecl install redis \
 && docker-php-ext-enable redis

# Instalar composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["php-fpm"]