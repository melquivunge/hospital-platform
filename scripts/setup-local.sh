#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if [ -e .env ]; then
    echo '.env já existe; preservado. Confira as variáveis antes de subir.'
else
    umask 077
    cp .env.example .env
    app_key=$(openssl rand -base64 32)
    db_password=$(openssl rand -hex 24)
    sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$app_key|;s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" .env
    rm .env.bak
    echo '.env local criado com segredos aleatórios; não versionar.'
fi
git config core.hooksPath .githooks
printf '%s\n' 'Próximo: docker compose up --build -d --wait'
