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
- Banco (ADR-0003): migrations com o dono das tabelas (`php artisan migrate --database=pgsql_owner`); a aplicação e os workers com um login próprio, membro só do papel `app_runtime` (sem superusuário e sem `BYPASSRLS`). Em desenvolvimento e CI, o mesmo login assume o papel. Se houver pooler de conexões, conferir que `app.tenant_id` não vaza entre requisições. Com o PostgreSQL 16 ou mais novo, conceder `app_runtime` ao login da aplicação exige superusuário ou `CREATEROLE` (passo manual de provisionamento).
- Cabeçalhos de segurança (CSP, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) presentes na resposta final, mesmo atrás de proxy/CDN. Ao alterar o script inline de tema em `resources/views/app.blade.php`, atualizar o hash em `docker/nginx/default.conf`; `SecurityConfigTest` falha se divergirem.

### Limites conhecidos da V1 (revisão integrada, etapa 9c)

Pendentes antes de operação real, além do checklist acima:

- **Processos:** o worker da fila precisa rodar sempre (notificações e importações). Sem ele, nada se perde, mas as notificações atrasam até ele voltar.
- **Backup:** banco e disco privado (`storage/app/private`: arquivos de importação e fotos de perfil) precisam de backup e restauração conjuntos.
- **Presença:** teste em aparelho físico via HTTPS e política de retenção de coordenadas (decisão de produto pendente no PRODUCT.md).
- **Fotos de perfil:** hoje são entregues só ao dono. Antes de mostrá-las a colegas, reprocessar a imagem no servidor (instalar GD ou Imagick), porque hoje só o navegador remove metadados.
- **Relatórios:** o PDF é gerado na hora e limitado a 2.000 linhas; acima disso, usar CSV ou passar para exportação em fila.
- **Notificações:** um único cursor serializa as entregas de todos os clientes; particionar por cliente se o volume exigir.
- **Isolamento:** sem RLS no PostgreSQL ([ADR-0002](docs/adr/0002-no-postgres-rls-in-v1.md)); reavaliar antes da produção.
- **Repositório:** proteção remota da `main` inativa (exige plano pago do GitHub); hoje só os hooks locais protegem.
- **Idioma:** o conteúdo das telas de segurança e aparência e o e-mail de redefinição de senha ainda estão em inglês.
- **Capacidade:** sem metas de disponibilidade, recuperação, tempo de resposta e volume; nenhuma garantia de escala foi validada.

### Limites conhecidos da entrega 3 (revisão integrada, etapa 3.8)

Pendentes antes de uso com pacientes reais, além dos limites da V1:

- **Banco:** a aplicação precisa de um login próprio, membro só de `app_runtime` (sem dono, sem superusuário, sem `BYPASSRLS`). O RLS protege contra erro de escopo da aplicação, não contra injeção de SQL com o login do dono ([ADR-0003](docs/adr/0003-rls-on-clinical-tables.md)). Revisar o pooler de conexões, porque a variável de cliente é por sessão.
- **Backup:** incluir os documentos clínicos (`storage/app/private/clinical/{cliente}/`) no backup conjunto com o banco. Se o processo cair entre gravar o arquivo e registrá-lo, sobra um arquivo sem linha; uma varredura periódica resolve.
- **Validade legal:** a assinatura de evolução usa a senha, sem ICP-Brasil, e não equivale a assinatura digital qualificada. Prazo de guarda do prontuário e base legal de tratamento (LGPD) ainda precisam de validação com especialista. Nada clínico é excluído.
- **Acesso de emergência:** o horário da concessão vem do relógio da aplicação; o banco exige só a validade de 4 horas.
- **Trilha de acessos:** a tela mostra os últimos 30 dias, até 200 linhas por bloco; para auditorias maiores, criar filtros e paginação.
- **Documentos:** PDF com conteúdo extra embutido é aceito, porque é sempre entregue como download (`nosniff`, `sandbox`); nunca o exibir dentro do navegador sem reprocessamento. Limite de 20 por atendimento.
- **Agenda:** horários montados no relógio da unidade; um fuso com horário de verão deslocaria a consulta na hora pulada. A fila do dia aberta depois da meia-noite precisa ser recarregada.
- **Decisões de produto pendentes (revisão integrada 3.8):** atendimento esquecido aberto mantém a leitura do prontuário sem prazo (definir expiração ou aviso à gestão); o acesso de emergência não tem teto diário por pessoa (hoje só o limite de 3 pedidos a cada 10 minutos, a notificação ao gestor e a trilha).
- **Unificação:** juntar cadastros amplia o acesso ao prontuário dos dois (um prontuário por pessoa, por contrato). Desfazer não separa o que foi escrito enquanto estavam unificados, e a trilha de leitura registra o cadastro principal da época. Unificar só com evidência de identidade.
- **Concessão de emergência:** vale pelas 4 horas mesmo se a habilitação do profissional for revogada nesse intervalo (a conta revogada perde o acesso na hora).
- **Cadastro:** a mensagem de CPF ou CNS já cadastrado confirma a existência do documento; é necessária para o cadastro único e fica protegida pelo limite de 30 tentativas por minuto.
- **Dados de teste:** o cliente sintético "Rede Corrida Agenda" existe só no banco local (corridas e verificações).

### Limites conhecidos da entrega 4 (revisão integrada, etapa 4.8)

Pendentes antes de uso com pacientes reais, além dos limites da V1 e da entrega 3:

- **Classificação de risco:** escala própria de 5 níveis, **não é Manchester** nem equivalente. Os tempos-alvo padrão (0, 10, 60, 120 e 240 min) são ponto de partida e precisam ser validados pela equipe clínica antes do uso.
- **Validade legal:** o sumário de alta é assinado com a senha, sem ICP-Brasil. A declaração de óbito fica fora do sistema; óbito e evasão registram só o horário e o autor.
- **Acesso pela equipe do setor ([ADR-0004](docs/adr/0004-ward-team-record-access.md)):** quem tem habilitação aprovada no setor lê o prontuário de todos os internados dali. Uma habilitação aprovada por engano dá acesso clínico; a aprovação é auditada e cada leitura fica na trilha.
- **Rascunhos na alta:** rascunhos de evolução de outros profissionais ficam sem assinatura e inacessíveis depois da alta (nada é apagado). A tela de alta avisa quantos são.
- **Superlotação:** o leito "extra" cobre macas improvisadas, mas precisa ser cadastrado; o banco recusa dois pacientes no mesmo leito.
- **Transferência:** só dentro da rede. A transferência para fora da rede é registrada só como tipo de alta. O leito reservado no destino não muda até o recebimento ou o cancelamento.
- **Censo:** é um retrato do momento, não uma série histórica. A ocupação é calculada sobre os leitos ativos não bloqueados. O tempo médio usa as altas dos últimos 30 dias, pelo setor do último leito e desde a internação original (inclui o tempo em outra unidade da rede). O cálculo é feito em memória por unidade.
- **Fuso horário:** horários de óbito e evasão são digitados no fuso da unidade, com precisão de minuto.
- **Decisões da revisão de segurança 4.8 (aplicadas na 4.9):**
  - O médico responsável dá alta também a quem aguarda leito ou está em trânsito. A alta encerra o episódio da urgência e cancela a transferência já saída, liberando o leito reservado. Essas altas não entram no censo, porque não ocuparam leito na unidade.
  - Ao receber uma transferência, a gestão de leitos do destino escolhe o médico responsável, que pode ser outro se o escolhido no pedido saiu.
  - Só funções marcadas como "atende pacientes" chamam da fila da urgência. Todas vêm marcadas, e o administrador desmarca as que não atendem (por exemplo, administrativas). A classificação continua aberta a quem é habilitado na unidade.
  - Só funções que atendem pacientes podem ser escolhidas como médico responsável (pedido, passagem, transferência e recebimento).
  - A queixa e os sinais vitais da classificação ficam fora da trilha de leitura, como registrado na ADR-0004.
  - Limites aceitos: quem recebe alta em trânsito não conta estada no censo da origem. O médico responsável que perdeu a habilitação ainda dá alta à própria internação (como antes).
- **Texto livre:** o motivo de bloqueio de leito é visto por gestão de leitos e higienização; não deve conter informação clínica.
- **Dados de teste:** o cliente sintético "Rede Corrida Alta" existe só no banco local (corridas e verificações de tela).

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
