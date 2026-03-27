FROM serversideup/php:8.4-fpm-nginx

USER root

# Instala dependências do sistema + extensões MongoDB, Redis e Node.js
RUN apt-get update && apt-get install -y \
        curl \
        libssl-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        pkg-config \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis \
    # Node.js 22 LTS
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instala Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Define diretório de trabalho
WORKDIR /var/www/html

# Copia os arquivos da aplicação
COPY . .

# Instala dependências PHP
RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist

# Instala dependências JS e compila assets
RUN npm install && npm run build

# Salva os IDs do usuário e grupo para ajustar permissões
ARG USER_ID
ARG GROUP_ID

# Ajusta permissões para o usuário www-data
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Volta ao usuário não-root da imagem base
USER www-data
