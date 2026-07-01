FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    unzip curl libzip-dev libonig-dev \
    && docker-php-ext-install pdo pdo_mysql zip mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8100

CMD ["php", "-S", "0.0.0.0:8100", "router.php"]
