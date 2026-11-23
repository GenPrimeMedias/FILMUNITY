FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends git curl unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql zip gd bcmath intl mbstring pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && npm run build \
    && rm -f .env \
    && chmod +x scripts/railway-start.sh \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
CMD ["bash", "scripts/railway-start.sh"]
