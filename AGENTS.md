# Instruções do projeto

Plataforma hospitalar SaaS multi-cliente: escalas, prontuário, farmácia, exames, cirurgia e faturamento (convênio TISS e SUS).

## Regra de ouro — nunca trabalhar na main

- Antes de editar, verificar a branch atual. Nunca desenvolver ou fazer commits diretamente em `main` ou `master`.
- Criar uma branch por tarefa (`feat/`, `fix/`, `chore/` ou `docs/`) a partir da base atualizada.
- Integrar por pull request, com CI aprovado e revisão. Não fazer push direto ou force push na main.
- Hooks locais reforçam a regra; nunca desativá-los para contorná-la. Proteções remotas devem ser verificadas, não presumidas.
- A criação inicial da referência main pode apontar para um commit-base já existente; isso não autoriza desenvolvimento nela.

Regra solicitada pelo usuário em 17/10/2026.

## ECC como método padrão

- Sempre usar o ECC neste projeto, escolhendo e lendo a skill apropriada à tarefa antes de executar o trabalho.
- Aplicar o ECC também em brainstorming, definição de produto, planejamento, implementação e revisão, conforme a etapa autorizada pelo usuário.
- Não avançar de brainstorming para implementação sem autorização do usuário.
- Se nenhuma skill ECC for aplicável ou estiver disponível, explicar brevemente a limitação e seguir as instruções do usuário com as ferramentas adequadas.
- Reutilizar as decisões e documentos existentes para evitar perguntas e trabalho repetidos.

Regra registrada a pedido do usuário em 17/10/2026. Escopo: este projeto.

## Stack

Laravel 13 / PHP 8.5, React 19 + Inertia 3 + TypeScript (Vite+ `vp`, rotas tipadas com Wayfinder), PostgreSQL 18 com RLS, Docker Compose, GitHub Actions. Gerenciadores: Composer e npm (`package-lock.json`).

## Comandos

O ambiente roda no Docker; não é preciso PHP nem Node no computador para subir e testar.

| Para | Comando |
|---|---|
| Primeira configuração (`.env`, hooks) | `sh scripts/setup-local.sh` |
| Subir | `docker compose up --build -d --wait` (app em http://localhost:8080, e-mails em http://localhost:8025) |
| Ver código novo no ambiente local | `docker compose up -d --build --wait --force-recreate app web worker` (reconstruir `app` e `web` juntos) |
| Migrations no banco local | `docker compose exec app php artisan migrate --database=pgsql_owner` |
| Rotas tipadas (depois de criar rota) | `php artisan wayfinder:generate --with-form` |
| Lint e formatação do frontend (inclui JSON) | `npm run check` (corrigir: `npm run check:fix`) |
| Tipos TypeScript | `npm run types:check` |
| Lint PHP (Pint) | `composer lint:check` (corrigir: `composer lint`) |
| PHPStan (nível 7, Larastan) | `composer types:check` |
| Build (antes da suíte, para o manifesto do Vite) | `DB_HOST=127.0.0.1 npm run build` |
| Testes | `docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=hospital_test -v "$PWD:/var/www/html" app php artisan test` |
| Pint + PHPStan + testes, como no CI | `composer test` |
| Guardas da main | `sh scripts/test-branch-guards.sh` |

Ordem do CI (`.github/workflows/ci.yml`): wayfinder → `npm run types:check` → `npm run check` → `npm run build` → `composer test` → `composer audit` → `npm audit --audit-level=high` → guardas da main; depois, o smoke test dos containers.

## Restrições

- Fechar cada etapa com revisão independente (`ecc:security-reviewer` e `ecc:code-reviewer`).
- Testes só no banco `hospital_test` (uma trava aborta a suíte em outro banco). Nunca rodar duas suítes ao mesmo tempo: elas dividem esse banco.
- Não usar `docker compose down -v` em dados a preservar (apaga os volumes).
- Mesclar só depois de ler o resultado de todos os checks do PR; não encadear o merge na espera do CI.
- Auditoria só com ids, nunca conteúdo clínico (CID, justificativa, procedimento ligado ao paciente).
- Tabelas clínicas: escopo `BelongsToTenant` + RLS forçado (`App\Clinical\ClinicalSchema`), leitura registrada por `ClinicalAccess::recordRead`; tabelas de histórico só de inserção (gatilhos no banco).
- As regras gerais (segurança, Git, testes, proibições, formato do relatório final) estão no ciclo de engenharia (resumo abaixo; texto completo em `docs/prompts/universal-engineering-loop.md`) e valem por inteiro.

## Onde está cada coisa

| Assunto | Arquivo |
|---|---|
| Estado atual e ponto de retomada | [docs/ESTADO-ATUAL.md](docs/ESTADO-ATUAL.md) |
| Subir, checklist de produção, limites conhecidos | [README.md](README.md) |
| Contrato do produto | [PRODUCT.md](PRODUCT.md) |
| Planos por entrega | [plans/](plans/) |
| Decisões de arquitetura | [docs/adr/](docs/adr/) |
| Direção visual | [docs/design/direction.md](docs/design/direction.md) |
| Configuração dos agentes: o que foi verificado e o que falta | [docs/setup.md](docs/setup.md) |
| Ciclo de engenharia completo (observar → verificar → revisar → registrar; formato do relatório final) | [docs/prompts/universal-engineering-loop.md](docs/prompts/universal-engineering-loop.md) |

## Ciclo de engenharia (resumo)

O texto completo, que vale por inteiro, está em [docs/prompts/universal-engineering-loop.md](docs/prompts/universal-engineering-loop.md). Ler a seção correspondente quando a tarefa tocar nela.

- **Regra zero:** descobrir e ler as skills, ferramentas e agentes relevantes antes de planejar ou codar; nunca inventar uma skill ou um agente, nem alegar revisão ou teste que não ocorreu.
- **Ciclo:** observar → investigar → planejar (plano curto) → implementar só o escopo → verificar → revisar → registrar → decidir. O repositório existente é a fonte da verdade: preservar primeiro, melhorar depois; a menor alteração correta.
- **Segurança é parte da implementação:** validar entradas, autorizar no servidor, não confiar no cliente, sem segredos no código, nunca expor o `.env`, PII mínima em logs.
- **Proibido sem autorização explícita:** deploy, cobrança ou mensagens reais, apagar dados persistentes, `migrate:fresh`, `db:wipe`, `git reset --hard`, `git clean -fd`, force push, mexer em secrets, instalar ferramentas ou frameworks não pedidos, grandes refatorações fora da tarefa.
- **Nunca inventar:** resultados de testes, saídas de comandos, arquivos, APIs, colunas, regras de negócio, documentação. Usar UNKNOWN, NOT VERIFIED ou BLOCKED_EXTERNAL.
- **Bug:** reproduzir, achar a causa raiz, teste que falha antes e passa depois. **Feature:** fatias pequenas com testes.
- **Ao fim de cada ciclo**, decidir o status (COMPLETE, PARTIAL, BLOCKED_EXTERNAL, BLOCKED_TECHNICAL, NEEDS_PRODUCT_DECISION, UNSAFE) e responder no formato da seção 31 do texto completo.
