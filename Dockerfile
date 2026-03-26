FROM serversideup/php:8.4-fpm-nginx

USER root

# Instala dependências do sistema + extensões MongoDB e Redis
RUN apt-get update && apt-get install -y \
    libssl-dev \
    pkg-config \
    && pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instala Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Define diretório de trabalho
WORKDIR /var/www/html

# Copia os arquivos da aplicação
COPY . .

# Instala dependências PHP sem devDependencies
RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist

# Salva os IDs do usuário e grupo para ajustar permissões
ARG USER_ID
ARG GROUP_ID

# Ajusta permissões para o usuário www-data (padrão da imagem)
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Volta ao usuário não-root da imagem base
USER www-data
