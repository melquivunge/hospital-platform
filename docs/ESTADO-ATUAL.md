# Estado atual do projeto

Atualizado em 17/10/2026, ao fim da sessão. Leia este arquivo e o [AGENTS.md](../AGENTS.md) antes de começar uma nova sessão.

## Em uma frase

Plataforma hospitalar SaaS (multi-cliente) para escalas de plantão. Base web pronta: login, isolamento entre clientes, unidades, setores, pessoas e papéis, catálogo de regras herdadas, profissionais, funções, habilitações, elegibilidade, escala (publicação segura e agenda) e importação CSV de profissionais, com identidade visual própria. Próximo passo: etapa 5b (importação de plantões em rascunho).

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

Stack: Laravel 13 / PHP 8.5, React 19 + Inertia 3 + TypeScript, PostgreSQL 18, Docker Compose, GitHub Actions. Repositório: `melquivunge/hospital-platform`.

## Situação do Git

| PR | Conteúdo | Situação |
|---|---|---|
| #1 | Base web, Docker, CI | Mesclado |
| #2 | Endurecimento: recuperação de senha, produção, cabeçalhos HTTP, pt-BR | Mesclado |
| #3 | Etapa 2a: isolamento entre clientes, vínculos, papéis, auditoria | Mesclado |
| #4 | Etapa 2b-1: seletor de instituição, unidades e setores | Mesclado |
| #5 | Identidade visual no app inteiro e página de erro própria | Mesclado |
| #6 | Este arquivo de estado | Mesclado |
| #7 | Etapa 2b-2: pessoas e papéis (convite, papéis, revogar/reativar, último administrador) | Mesclado após revisão de segurança |
| #8 | Etapa 2c: catálogo de regras e ADR do RLS | Mesclado |
| #9 | Etapa 3a: cadastro de profissionais e unidade-base | Mesclado |
| #10 | Etapa 3b: funções, habilitações e elegibilidade | Mesclado |
| #11 | Etapa 4a: plantões, publicação e cancelamento | Mesclado |
| #12 | Etapa 4b: agenda e itens das referências | Mesclado |
| — | Etapa 5a: importação CSV de profissionais (branch `feat/import-professionals`) | Em revisão |

Regra de ouro: nunca trabalhar na `main`. Uma branch por tarefa, PR com CI verde e revisão. O merge só acontece com autorização do usuário.

## Plano: o que está feito e o que falta

- Feito: etapa 1 (ambiente e login), 2a (isolamento), 2b-1 (unidades e setores), identidade visual (PR #5), 2b-2 (pessoas e papéis, PR #7), 2c (catálogo de regras e decisão sobre RLS, PR #8), 3a (cadastro de profissionais, PR #9), 3b (funções, habilitações e elegibilidade, PR #10), 4a (escala, PR #11), 4b (agenda, PR #12), 5a (importação de profissionais).
- **Próximo:** 5b (plantões por CSV, criados como rascunho), depois as etapas 6 a 9.
- **Decisão do usuário para a etapa 6 (17/10/2026):** o profissional ganha acesso por convite na tela dele, usando o e-mail do cadastro. Isso cria o vínculo com o papel Profissional e o liga ao cadastro.

### Etapa 5a: como funciona a importação

- **Tela "Importações":** para o administrador de rede (qualquer unidade) e para o importador, que só importa nas suas unidades e só vê os próprios lotes.
- **Arquivo:** CSV UTF-8, com `;` ou `,`, até 2 MB e 2.000 linhas. Colunas: `source_id`, `name`, `base_unit`, `council`, `council_number`, `council_uf`, `email` e `phone`. Há um modelo para baixar. O arquivo original fica privado, com nome aleatório.
- **Prévia:** nada é gravado na operação. Cada linha recebe um resultado: será criada, idêntica já existente, conflitante ou rejeitada, com o motivo. As contagens fecham com o total recebido.
- **Identidade:** cliente + sistema de origem + tipo + `source_id`.
  - Reimportar o mesmo conteúdo não duplica.
  - Conteúdo diferente vira conflito, sem alterar nada.
  - Um cadastro manual com o mesmo identificador ou registro também vira conflito, sem ligação automática.
- **Confirmação:** o job `ApplyImportBatch` roda no worker e aplica linha a linha, com lock. Pode ser retomado sem duplicar. A tela atualiza sozinha.
- **Segurança:**
  - O job aplica só como o criador do lote e revalida a permissão na hora. Uma linha cuja unidade deixou de ser permitida vira conflito.
  - Há um único job por lote (`ShouldBeUnique`), e o contexto é limpo ao final.
  - Os limites de colunas e de tamanho de campo protegem contra arquivos abusivos.
  - Valores que começam como fórmula (`=`, `+`, `-`, `@`) são rejeitados.
  - A auditoria registra o envio, a confirmação e a conclusão do lote.
- **Testes:** dentro do container, a fila efetiva é `database` (a variável do Docker vence o `phpunit.xml`). Por isso os testes interceptam o envio e executam o job explicitamente.

### Etapa 4a: decisões do usuário (17/10/2026) e como funciona

- **Conflito de horário:** conta rascunho e publicado. Uma restrição de exclusão no PostgreSQL (`shifts_no_overlap`, extensão `btree_gist`) impede o mesmo profissional em dois plantões não cancelados sobrepostos `[início, fim)`, inclusive com publicações simultâneas.
- **Publicado:** só pode ser cancelado, com motivo e antes do início. O responsável muda só pelos fluxos das etapas 6 e 7.
- **Quem monta a escala:** o coordenador de plantões da unidade e o administrador de rede. O gestor da unidade e o auditor só consultam.
- **Criar, editar e publicar:** cada operação trava os profissionais (ordem de id) e depois o plantão, e revalida a elegibilidade. A publicação grava a `eligibility_version` e a habilitação usadas.
- **Plantão já iniciado:** não pode ser criado, editado nem publicado (padrão conservador; registrado como decisão técnica).
- **Horários:** digitados no fuso da unidade e guardados em UTC. Horário inexistente no fuso é rejeitado. A lista é semanal, por unidade.
- **Limite de duração:** não há duração máxima de plantão; o limite precisa ser definido pelo produto.
- **4b (agenda):** visões de dia, semana e mês na tela "Escala", no fuso da unidade.
  - A visão de dia lista também os plantões iniciados na véspera que terminam nesse dia (V1-05).
  - O mês é uma grade no computador e uma lista no celular.
- **Itens das fotos de referência** (references/insightcare-screenshots, IMG_0911 a 0917), escolhidos pelo usuário em 17/10/2026:
  - **Faixa de dias no celular:** com ponto nos dias em que começa algum plantão.
  - **Filtro de vagas:** "Mostrar também vagas abertas".
  - **Busca de profissional:** por nome ou registro no conselho.
  - **Turno:** calculado pelo início, no horário da unidade. Diurno de 07:00 a 18:59, noturno nos demais (decisão do usuário).
  - **Valor:** opcional, em R$ (`value_cents`), visível a quem consulta a escala: coordenador, gestor, admin e auditor (decisão do usuário).
  - **Fora desta etapa:** colegas de plantão, menu de troca/ceder/check-in e "meus plantões" dependem das etapas 6 a 8 e da conta do profissional.
- **Eventos para notificação:** não há tabela de outbox separada. Os eventos `shift.published` e `shift.cancelled` já são gravados em `audit_events`, só de inserção, na mesma transação da mudança. A etapa 9 deve consumi-los com um cursor de entrega e deduplicação, e criar a tabela própria só se o consumo exigir.
- **Visão "meus plantões" do profissional:** depende de ligar o cadastro do profissional a uma conta de acesso, que ainda não existe.

### Etapa 3: decisões do usuário (17/10/2026)

- O profissional é um cadastro independente da conta de acesso. A ligação a uma conta fica para quando houver portal do profissional.
- Habilitação é um registro por profissional, unidade e função, com status (pendente, aprovada, suspensa, revogada) e validade. Inclui os setores autorizados.
- Dados do cadastro: nome, registro no conselho (conselho, número e UF), identificador externo e contato (e-mail e telefone).
- 3a (feita): cadastro, unidade-base obrigatória, troca de base só por administrador de rede ou por gestor das duas unidades, com motivo e histórico na auditoria.
- 3b (feita):
  - **Funções:** a tela "Funções" é só do administrador de rede.
  - **Habilitações:** começam pendentes. Podem pedir o administrador de rede ou o gestor da unidade-base, para qualquer unidade da rede. Decide o administrador de rede ou o gestor da unidade de destino. Suspensão e revogação exigem motivo, e a revogação é definitiva. Cada mudança trava o profissional e incrementa `eligibility_version`.
  - **Elegibilidade:** `App\Professionals\Eligibility::check()` devolve os motivos de bloqueio: outra rede, unidade incompleta, UF diferente, sem habilitação, pendente, suspensa, revogada, fora da validade, setor não autorizado ou horário inválido. A validade é em datas locais da unidade, e a etapa 4 deve chamar o serviço dentro da transação com o lock do profissional.
  - **Gestor do destino:** vê o profissional enquanto houver habilitação não revogada na unidade dele. Vê só as habilitações da própria unidade e não recebe e-mail nem telefone.

### Etapa 2c: como funciona o catálogo de regras

- Tela **Regras** (administrador de rede, auditor e gestor de unidade). Hoje há uma única regra, a precisão máxima da localização (m), escolhida pelo usuário. Ela não tem valor padrão: sem publicação da rede, a regra fica "não configurada".
- A rede publica o valor, se o hospital pode alterar e os limites. O gestor da unidade publica um valor local dentro desses limites; se deixar o campo vazio, a unidade volta ao valor herdado.
- Cada publicação é uma nova versão em `policy_versions`, só de inserção (trigger no banco), com autor, motivo e vigência. Por decisão do usuário, a publicação é direta e imediata: não há rascunho/aprovação nem agendamento.
- O valor efetivo (`PolicyVersion::effective`) é calculado na leitura. Um valor local fora dos limites atuais da rede é ignorado e vale o da rede. Quando a rede aperta os limites, a publicação lista as unidades afetadas e exige confirmação.
- Duas publicações sobre a mesma versão: a segunda recebe um aviso de conflito (versão esperada, lock do cliente e índice único).
- Etapas futuras (presença) devem chamar `effective()` no momento da ação e registrar os ids das versões usadas.

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

Último resultado: 181 testes passando (etapa 5a), PHPStan sem erros, lint e TypeScript limpos.

## Armadilhas conhecidas

- **Reconstrua `app` e `web` juntos:** `docker compose build app web && docker compose up -d --wait --force-recreate app web worker`. Reconstruir só um deles causa 502 ou páginas sem CSS.
- **Rotas tipadas:** depois de criar uma rota, rode `php artisan wayfinder:generate --with-form` antes do TypeScript.
- **Testes sempre no banco `hospital_test`:** uma trava aborta a suíte em qualquer outro banco.
- **CI só em PRs para `main`:** se a base de um PR mudar, feche e reabra o PR para disparar o CI.
- **Proteção remota da `main`:** inativa, porque o GitHub exige plano Pro em repositório privado. Hoje só os hooks locais protegem a `main`.

## Pendências registradas

- Telas de configurações (perfil, segurança, aparência) e o e-mail de redefinição de senha ainda estão em inglês.
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
