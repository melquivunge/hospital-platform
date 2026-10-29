# Estado atual do projeto

Atualizado em 28/10/2026. Leia este arquivo e o [AGENTS.md](../AGENTS.md) antes de começar uma nova sessão. Decisões por etapa, PRs e o que cada etapa fez: [HISTORICO.md](HISTORICO.md).

## Em uma frase

Plataforma hospitalar SaaS multi-cliente. Entregas concluídas e mescladas: V1 (escalas, presença, trocas), 2 a 4c (ambulatório, urgência, internação e leitos, prescrição e farmácia, estoque), 5 e 5b (exames e cirurgia) e 6 (faturamento de convênio, TISS). Em andamento: a entrega 6b (faturamento SUS: BPA e AIH; plano `plans/web-v9-sus.md`, ADR-0011), mesclada até a 6b.6b1 (#143); as tabelas oficiais do SISAIH01 estão em `resources/sus/aih/` (#144, #145). Último CI verde da `main`: `a77ab03`.

## Ponto exato de retomada

1. **6b.7b:** linhas do BPA guardadas no arquivo, retorno do gestor por linha e reapresentação dos itens rejeitados; depois **6b.7c** (relatórios SUS). Decisões e a 6b.7a (retorno da AIH, reapresentação, reabertura da conta: `AihFileController::show/recordReturn`, `BillingAccountController::reopen`, função `sus_aih_rejected`) no plano. A 6b.6 (arquivos BPA e AIH) está feita: `App\Billing\BpaFile` e `App\Billing\AihFile`, telas `sus-bpa` e `sus-aih`; a lista do que só a importação real no SISAIH01 confirma está no plano ("Não verificado"). Texto de layouts em PDF: extrair com o PDFKit do macOS (`osascript -l JavaScript`).
2. **6b.8:** revisão integrada da entrega 6b.
3. Depois: entrega 7 (portal do paciente e indicadores), sem contrato ainda.

- **GitHub:** repositório público desde 28/10/2026, então o CI roda; se voltar a ser privado, o Actions fica bloqueado pela cobrança e a proteção da `main` exige plano Pro.
- **Bloqueios externos:** fornecedor ICP-Brasil (só há o assinador de teste), importação real nos programas do DATASUS (só Windows), hospedagem.
- **Pendências de produto do SUS** (no plano): tabela do item pelo formato do código, número da autorização na guia, codificação do hash.

## Onde está cada coisa

| Assunto | Arquivo |
|---|---|
| Regras de trabalho (obrigatórias) | [AGENTS.md](../AGENTS.md) |
| Contrato do produto (requisitos V1-01 a V1-14) | [PRODUCT.md](../PRODUCT.md) |
| Plano em etapas | [plans/web-v1.md](../plans/web-v1.md) |
| Arquitetura | [docs/adr/0001-modular-web-platform.md](adr/0001-modular-web-platform.md) |
| Decisão sobre RLS (não adotar na V1) | [docs/adr/0002-no-postgres-rls-in-v1.md](adr/0002-no-postgres-rls-in-v1.md) |
| Direção visual | [docs/design/direction.md](design/direction.md) |
| Como subir e verificar | [README.md](../README.md) |
| Histórico (decisões por etapa, PRs) | [docs/HISTORICO.md](HISTORICO.md) |

Stack: Laravel 13 / PHP 8.5, React 19 + Inertia 3 + TypeScript, PostgreSQL 18, Docker Compose, GitHub Actions. Repositório: `melquivunge/hospital-platform`.

## Como testar localmente

### 1. Subir

Requisito: Docker Desktop ligado.

```sh
sh scripts/setup-local.sh
docker compose up --build -d --wait
```

- Aplicação: http://localhost:8080
- E-mails (Mailpit): http://localhost:8025. Nenhum e-mail sai para a internet.

### 2. Usuário de teste

Contas no ambiente local:

- `melquivunge@gmail.com` (usuário real do projeto): administrador de rede da **Rede Aurora** e da **Rede Insightcare Demo**.
- `admin@aurora.example` (sintético): administrador da **Rede Aurora** (unidades e setores de teste) e do **Hospital Horizonte**.
- `verificacao@horizonte.example` (sintético, usado nas verificações automáticas): administrador do **Hospital Horizonte**.

Pacientes não existem: estão fora da V1 (PRODUCT.md).

Nenhuma senha fica gravada no projeto. Para entrar:

1. Abra http://localhost:8080/login e clique em **Esqueceu sua senha?**
2. Informe `admin@aurora.example` e envie.
3. Abra http://localhost:8025, abra o e-mail "Reset your password" e clique no link.
4. Defina uma senha e entre (o e-mail fica confirmado nesse passo).
5. Com mais de uma instituição, escolha uma. A gestão de equipe fica em **Pessoas**.

### 3. Criar outro cliente e administrador

Para pessoas de um cliente existente, use a tela **Pessoas** (convite). Para um cliente novo:

```sh
docker compose exec app php artisan tenant:provision "Nome do Cliente" --admin-email=pessoa@exemplo.test --admin-name="Nome"
```

Depois, entre com esse e-mail pelo mesmo caminho do "Esqueceu sua senha?". Use domínios fictícios (`.test` ou `.example`).

### 4. Rodar as verificações

```sh
npm run build    # os testes renderizam páginas e precisam do build atualizado
docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=hospital_test -v "$PWD:/var/www/html" app php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=512M
npm run types:check
npm run check
sh scripts/test-branch-guards.sh
```

Último resultado: 492 testes passando (5b.7), PHPStan sem erros, lint e TypeScript limpos.

## Armadilhas conhecidas

- **Reconstrua `app` e `web` juntos:** `docker compose build app web && docker compose up -d --wait --force-recreate app web worker`. Reconstruir só um deles causa 502 ou páginas sem CSS.
- **Rotas tipadas:** depois de criar uma rota, rode `php artisan wayfinder:generate --with-form` antes do TypeScript.
- **Testes sempre no banco `hospital_test`:** uma trava aborta a suíte em qualquer outro banco.
- **CI só em PRs para `main`:** se a base de um PR mudar, feche e reabra o PR para disparar o CI.
- **Proteção remota da `main`:** inativa, porque o GitHub exige plano Pro em repositório privado. Hoje só os hooks locais protegem a `main`.

## Pendências registradas

- Telas de segurança e aparência (conteúdo) e o e-mail de redefinição de senha ainda estão em inglês.
- Proxy confiável (`trustProxies`) e e-mail de recuperação em fila: definir quando houver hospedagem.
- Catálogo de regras: rascunho/aprovação, agendamento de vigência e as demais regras de presença (raio, janelas de entrada e saída) ficaram fora da 2c por decisão do usuário. O raio deve entrar junto com os pontos autorizados (etapa 8).
- RLS não adotado na V1 (ADR-0002); reavaliar antes da produção.
- Profissionais: a mensagem de duplicidade (registro no conselho ou identificador externo) revela a um gestor que o dado já existe em outra unidade da rede. Risco aceito, porque a unicidade vale para a rede inteira. A ligação do profissional a uma conta de acesso ainda não existe.
- Habilitações: quem pede pode aprovar se também tiver papel no destino (por exemplo, o administrador de rede). O solicitante fica só na auditoria. Editar setores ou validade exige revogar e pedir de novo. A aprovação não exige unidade completa (a elegibilidade bloqueia depois).
- Decisões de produto em aberto:
  - Validar se a UF combina com o fuso?
  - Impedir apagar UF ou fuso de unidade com escalas publicadas?
- Exclusão de unidades e setores não existe de propósito, para preservar o histórico.
- Convite: decidir se vincular uma conta já existente exige aceite da pessoa (hoje o vínculo é imediato).
- Dados sintéticos no ambiente local: unidades "Hospital Teste …" criadas durante as verificações.
