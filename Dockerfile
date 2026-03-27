# =========================
# 1. BASE (extensões comuns)
# =========================
FROM serversideup/php:8.4-cli AS base

USER root

RUN apt-get update && apt-get install -y \
    libssl-dev \
    pkg-config \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# =========================
# 2. BUILDER (dependências)
# =========================
FROM base AS builder

COPY . .

RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist

RUN npm install && npm run build

# =========================
# 3A. APP (nginx + fpm)
# =========================
FROM serversideup/php:8.4-fpm-nginx AS app

USER root

# Copia PHP com extensões do base
COPY --from=base /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=base /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

WORKDIR /var/www/html

COPY --from=builder /var/www/html /var/www/html

ARG USER_ID
ARG GROUP_ID

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

USER www-data

# =========================
# 3B. QUEUE (horizon)
# =========================
FROM base AS queue

WORKDIR /var/www/html

COPY --from=builder /var/www/html /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

CMD ["php", "artisan", "horizon"]
