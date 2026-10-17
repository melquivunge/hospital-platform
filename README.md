# Plataforma Hospitalar

Primeira base web: Laravel 13 / PHP 8.5, React 19 / TypeScript / Inertia 3 e PostgreSQL 18. Autenticação com cadastro público fechado. Escalas, isolamento entre clientes e demais módulos continuam no [plano](plans/web-v1.md); este scaffold não é a plataforma completa.

Estado atual, próximos passos e como testar com o usuário de teste: [docs/ESTADO-ATUAL.md](docs/ESTADO-ATUAL.md).

## Subir localmente com Docker

Requisitos: Docker Desktop/Engine ativo, Compose v2, Git e OpenSSL. Não precisa instalar PHP, Composer ou Node no computador para subir os serviços.

```sh
sh scripts/setup-local.sh
docker compose up --build -d --wait
```

- Aplicação: http://localhost:8080
- Caixa de e-mails local: http://localhost:8025
- PostgreSQL fica na rede interna, sem porta pública.
- `init` executa migrations aditivas antes de app e worker. Não executa seed nem cria usuário/senha padrão.
- O script preserva `.env` existente; gera chave e senha aleatórias somente na primeira execução. Nunca versionar esse arquivo.
- `APP_PORT`, `APP_URL` e `MAILPIT_PORT` podem ser ajustados no `.env` caso haja conflito de portas. Geolocalização real exigirá HTTPS fora de localhost.
- Mailpit captura e-mails localmente; não há entrega externa configurada.

```sh
docker compose ps
docker compose logs --tail=80
docker compose exec app php artisan migrate:status
docker compose down
```

`down` preserva os volumes. Não usar `down -v` em dados a preservar. O Compose é exclusivo para desenvolvimento: servidor de produção, TLS, backups e CD serão definidos com a hospedagem.

## Checklist de produção

Obrigatório antes de qualquer ambiente com dados reais. A aplicação recusa iniciar com `APP_ENV=production` se `APP_DEBUG=true` ou `SESSION_SECURE_COOKIE=false`, e força URLs `https`.

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` próprio do ambiente e `APP_URL` com `https://`.
- HTTPS obrigatório no terminador TLS, com redirecionamento de HTTP e `Strict-Transport-Security` (não enviado pelo Nginx local, que é só HTTP).
- `SESSION_SECURE_COOKIE=true` (padrão quando a variável não existe; o `.env` local desliga explicitamente).
- Proxies confiáveis configurados com IPs específicos, nunca `*`, para `X-Forwarded-*` não ser falsificável (Laravel `trustProxies` e, se houver proxy antes do Nginx, `set_real_ip_from`). Ainda não configurado: sem isso, atrás de um balanceador todos os clientes compartilham o IP do proxy e os limites por IP de login e de recuperação de senha viram globais.
- Credenciais de banco e e-mail exclusivas do ambiente, guardadas fora do repositório; backup e restauração testados.
- Cabeçalhos de segurança (CSP, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) presentes na resposta final, mesmo atrás de proxy/CDN. Ao alterar o script inline de tema em `resources/views/app.blade.php`, atualizar o hash em `docker/nginx/default.conf`; `SecurityConfigTest` falha se divergirem.

## Verificações

O CI executa testes PHP com PostgreSQL descartável, Pint, PHPStan, TypeScript, lint/formatação, build, auditorias de dependências e smoke test dos containers. As credenciais do serviço de teste em CI são descartáveis, sem relação com ambientes reais.

```sh
composer install
npm ci
php artisan wayfinder:generate --with-form
npm run types:check
npm run check
npm run build
composer test
sh scripts/test-branch-guards.sh
```

Para executar os testes com o PHP da imagem e as dependências de desenvolvimento instaladas no checkout:

```sh
docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=hospital_test -v "$PWD:/var/www/html" app php artisan test
```

`composer test` usa exclusivamente o banco `hospital_test` configurado em phpunit.xml. Defina DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD para um PostgreSQL de teste acessível; não use credenciais de produção. O Compose inicializa `hospital_test` apenas ao criar o volume pela primeira vez.

## Regra de ouro: nunca trabalhar na main

Crie uma branch por tarefa. `sh scripts/setup-local.sh` instala os hooks locais; não contorná-los. Commit na main/master e push direto para essas branches são bloqueados localmente. Abra PR, aguarde CI e revisão. A referência main inicial contém somente a base documental.

Proteção remota não foi ativada: GitHub respondeu HTTP 403 exigindo Pro para repositório privado. Hooks são contornáveis e não substituem essa proteção. Após habilitar o recurso, exigir os checks `quality` e `containers`, uma revisão, conversas resolvidas, bloqueio de force push e exclusão, incluindo administradores.

## Referências e privacidade

As 24 imagens originais foram preservadas em `references/insightcare-screenshots`, excluídas do Git e do contexto Docker. Documentação e contrato: [PRODUCT.md](PRODUCT.md). Arquitetura: [ADR-0001](docs/adr/0001-modular-web-platform.md). Direção visual: [design](docs/design/direction.md).

Nenhum deploy de produção, envio real de e-mail ou migração de dados legados foi realizado.
