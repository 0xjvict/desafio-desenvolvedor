#!/bin/bash
set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}🚀 Configurando ambiente...${NC}"

# Verifica se o Docker está disponível
if ! command -v docker &> /dev/null; then
    echo -e "${YELLOW}⚠️  Docker não encontrado. Instale o Docker antes de continuar.${NC}"
    exit 1
fi

# Constrói a imagem builder localmente (inclui PHP, Composer, Node, e extensões mongodb, gd)
echo -e "${GREEN}🔨 Construindo imagem builder local...${NC}"
docker build --target builder -t laravel_builder .

# Instala dependências PHP usando a imagem builder
echo -e "${GREEN}📦 Instalando dependências PHP...${NC}"
docker run --rm \
    -v "$(pwd)":/var/www/html \
    -w /var/www/html \
    -u "$(id -u):$(id -g)" \
    laravel_builder \
    composer install \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# Prepara diretório de cache npm local com permissões adequadas
echo -e "${GREEN}📁 Preparando cache npm local...${NC}"
mkdir -p .npm-cache
chown "$(id -u):$(id -g)" .npm-cache

# Remove node_modules antigo para evitar conflitos
echo -e "${GREEN}🧹 Removendo node_modules antigo...${NC}"
rm -rf node_modules

# Instala dependências JavaScript usando a mesma imagem builder
echo -e "${GREEN}📦 Instalando dependências JavaScript...${NC}"
docker run --rm \
    -v "$(pwd)":/var/www/html \
    -w /var/www/html \
    -u "$(id -u):$(id -g)" \
    laravel_builder \
    npm ci --cache ./.npm-cache --no-clean

# Compila assets
echo -e "${GREEN}🔨 Compilando assets...${NC}"
docker run --rm \
    -v "$(pwd)":/var/www/html \
    -w /var/www/html \
    -u "$(id -u):$(id -g)" \
    laravel_builder \
    npm run build

## Ajusta permissões dos diretórios de storage e cache (crítico para logs)
#echo -e "${GREEN}🔧 Ajustando permissões de diretórios para logs e cache...${NC}"
#mkdir -p storage/logs storage/framework/{sessions,views,cache}
#chmod -R 775 storage bootstrap/cache
#chown -R "$(id -u):$(id -g)" storage bootstrap/cache
#touch storage/logs/laravel.log
#chmod 664 storage/logs/laravel.log

echo -e "${GREEN}✅ Setup concluído! Agora execute: docker compose up -d${NC}"
