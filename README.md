# Laravel com MongoDB, Redis e Docker

Este é um projeto Laravel que utiliza MongoDB como banco de dados, Redis para filas e cache, e Horizon para gerenciamento de filas. Todo o ambiente é executado em containers Docker.

## Pré-requisitos

- [Docker](https://docs.docker.com/get-docker/) (versão 20.10+)
- [Docker Compose](https://docs.docker.com/compose/install/) (versão 2.0+)

## Passo a passo para rodar o projeto

### 1. Clone o repositório
```bash
git clone <url-do-repositorio>
cd <nome-do-projeto>
```

### 2. Crie o arquivo de ambiente
Copie o arquivo de exemplo e ajuste se necessário:
```bash
cp .env.example .env
```
As credenciais padrão já funcionam com os containers (usuário: `laravel_user`, senha: `secret`). Você pode alterar se quiser.

### 3. Execute o script de configuração
Esse script instala as dependências PHP e JavaScript, compila os assets e ajusta as permissões dos diretórios de log/cache.
```bash
chmod +x setup.sh
./setup.sh
```

### 4. Inicie os containers
```bash
docker compose up -d
```

### 5. Gere as chaves da aplicação
Após os containers estarem rodando, execute:
```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
```

## Acessando a aplicação

- **Aplicação Laravel**: `http://localhost:8080` (ou a porta definida em `APP_PORT` no `.env`)
- **Mongo Express (admin do MongoDB)**: `http://localhost:8081`

## Comandos úteis

- Ver logs do container `app`: `docker compose logs -f app`
- Parar os containers: `docker compose down`
- Reiniciar: `docker compose restart`
- Executar comandos Artisan dentro do container: `docker compose exec app php artisan <comando>`

## Solução de problemas

### Erro de permissão nos logs
Se aparecer `Failed to open stream: Permission denied` ao tentar escrever no log, execute novamente o script de configuração:
```bash
./setup.sh
```
Isso irá corrigir as permissões.

### Container não sobe
Verifique os logs com `docker compose logs`. Certifique-se de que o arquivo `.env` foi criado e que as credenciais do MongoDB e Redis estão corretas.
