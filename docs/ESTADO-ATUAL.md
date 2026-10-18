# Estado atual do projeto

Atualizado em 18/10/2026. Leia este arquivo e o [AGENTS.md](../AGENTS.md) antes de começar uma nova sessão.

## Em uma frase

Plataforma hospitalar SaaS (multi-cliente) para escalas de plantão. Base web pronta: login, isolamento entre clientes, unidades, setores, pessoas e papéis, catálogo de regras herdadas, profissionais, funções, habilitações, elegibilidade, escala (publicação segura e agenda) importação CSV, acesso do profissional, "Meus plantões", vagas com candidatura e escolha justificada anúncio do próprio plantão para substituição transferência direta e troca entre dois plantões com aceite e aprovação presença por localização e ocorrências com saída ajustada pela coordenação, com identidade visual própria. Próximo passo: etapa 9 (notificações, relatórios e revisão integrada).

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
| #13 | Etapa 5a: importação CSV de profissionais | Mesclado |
| #14 | Etapa 5b: importação CSV de plantões | Mesclado |
| #15 | Etapa 6a: acesso do profissional e "Meus plantões" | Mesclado |
| #16 | Etapa 6b: vagas abertas, candidatura e escolha justificada | Mesclado |
| #17 | Etapa 6c: anúncio do próprio plantão para substituição | Mesclado |
| #18 | Etapa 7a: transferência direta | Mesclado |
| #19 | Etapa 7b: troca entre dois plantões | Mesclado |
| #20 | Etapa 8a: presença por localização | Mesclado |
| #21 | Etapa 8b: ocorrências e ajuste administrativo | Mesclado |
| #22 | Etapa 9a: notificações internas e barra superior | Mesclado |
| #23 | Foto de perfil e configurações em português | Mesclado |
| #24 | Etapa 9b: relatórios e exportação CSV/PDF | Mesclado |
| #25 | Etapa 9c: revisão integrada | Mesclado |
| #26 | Revogação libera plantões futuros | Mesclado |
| #27 | Entrega 3: contrato, ADR-0003 e plano | Mesclado |
| #28 | Etapa 3.1: fundação clínica | Mesclado |
| #29 | Etapa 3.2: pacientes | Mesclado |
| — | Etapa 3.3: unificação de cadastros (branch `feat/patient-merge`) | Em revisão |

Regra de ouro: nunca trabalhar na `main`. Uma branch por tarefa, PR com CI verde e revisão. O merge só acontece com autorização do usuário.

## Plano: o que está feito e o que falta

- Feito: etapa 1 (ambiente e login), 2a (isolamento), 2b-1 (unidades e setores), identidade visual (PR #5), 2b-2 (pessoas e papéis, PR #7), 2c (catálogo de regras e decisão sobre RLS, PR #8), 3a (cadastro de profissionais, PR #9), 3b (funções, habilitações e elegibilidade, PR #10), 4a (escala, PR #11), 4b (agenda, PR #12), 5a (importação de profissionais, PR #13), 5b (importação de plantões, PR #14), 6a (acesso do profissional, PR #15), 6b (vagas abertas, PR #16), 6c (anúncio para substituição, PR #17), 7a (transferência direta, PR #18), 7b (troca, PR #19), 8a (presença por localização, PR #20), 8b (ocorrências, PR #21), 9a (notificações e barra superior, PR #22).
- **Entrega 3 (atendimento ambulatorial) em definição** (18/10/2026, decisão do usuário de seguir a ordem aprovada): contrato no PRODUCT.md, seção "Contrato da entrega 3". Decididos: identidade (CPF/CNS opcionais e únicos, cadastro provisório), unificação manual, prontuário só para quem atende com emergência auditada, recorte (agenda e recepção, atendimento e evolução, documentos, modalidade sem faturamento). Questões abertas respondidas (todas as propostas aceitas), ADR-0003 aceita (RLS nas tabelas clínicas) e plano em `plans/web-v2-ambulatorio.md`. Implementação autorizada, uma etapa por vez.
- **3.1 (fundação clínica):**
  - A aplicação e os workers rodam como o papel `app_runtime`, sem superusuário e sem `BYPASSRLS` (`server_options` na conexão `pgsql`); as migrations usam `pgsql_owner`. O papel é criado no init do Docker, no CI e, uma vez, no volume local; as permissões vêm da migration `create_app_runtime_role`.
  - `CurrentTenant::set()` grava `app.tenant_id` na sessão do banco, e cada job começa sem cliente.
  - `App\Clinical\ClinicalSchema::protect()` aplica RLS forçado com a política `tenant_isolation`; `ClinicalSchema::TABLES` lista as tabelas clínicas, e um teste exige que a lista e as políticas coincidam.
  - Prova no banco: uma consulta crua sem escopo da aplicação não vê linhas de outro cliente, nem sem cliente definido, e gravar para outro cliente é recusado.
  - Papel **Recepção** por unidade.
  - Verificado: a suíte inteira e as 29 telas rodando como `app_runtime`, além do worker entregando notificações.
- **3.2 (pacientes):**
  - Tela "Pacientes" para recepção e profissionais de qualquer unidade da rede. O cadastro é buscado, nunca listado inteiro: busca por nome (palavras, sem acento), CPF, CNS ou data dd/mm/aaaa, com no mínimo 3 caracteres, mostrando só o necessário para identificar e os documentos mascarados.
  - Cadastro com nome civil e nome social, nascimento, sexo, CPF, CNS, nome da mãe, contato e responsável legal. CPF validado pelos dígitos e CNS pelo algoritmo do Ministério (definitivo 1/2, provisório 7/8/9), ambos únicos na rede (índice parcial e validação). Sem documento, nascimento ou sexo, o cadastro fica **provisório** (restrição no banco).
  - Possível duplicado (mesmo nascimento e mesmo nome, ou mesmos primeiro e último nome): o cadastro é recusado com a lista de parecidos, sem o nome em nenhuma URL, até a pessoa confirmar que é outra; a confirmação fica na auditoria.
  - A auditoria registra criação e alteração com os **nomes** dos campos alterados, nunca os valores. `patients` é tabela clínica com RLS forçado.
  - Gestor e administrador não veem pacientes por serem gestores (a unificação, na 3.3, terá tela própria).
  - Revisão de segurança aplicada: a busca vai por **POST** (`patients/search`), então nome, CPF e data não entram em URL, logs ou histórico. Termos sem uma palavra de 3 letras, documento completo ou data válida não encontram ninguém, e há limite de 30 por minuto na busca e nas gravações. O histórico do Inertia é criptografado nas páginas de paciente e limpo no logout.
- **3.3 (unificação):**
  - Tela "Unificar cadastros" para o gestor da unidade e o administrador de rede, só com dados de identificação (documentos mascarados). Mostra uma fila de pares suspeitos (mesmo nascimento e mesmo nome, ou mesmos primeiro e último nome); pares cuja unificação foi desfeita não voltam.
  - O gestor escolhe o cadastro principal e informa o motivo. O duplicado fica `merged`, aponta para o principal (`merged_into_id`) e nada é apagado. A decisão fica em `patient_merges` (RLS) e a auditoria (`patient.merged`) guarda só ids.
  - Na busca, o duplicado some e o CPF ou CNS dele leva ao principal; abrir o duplicado redireciona para o principal, que lista "Cadastros unificados neste".
  - Desfazer exige motivo e gera nova decisão auditada (`patient.merge_undone`).
  - Locks nos dois cadastros em ordem de id. Corrida real (6 rodadas, A→B contra B→A): uma vence, a outra é recusada, sem ciclo nem travamento.
- **Plano web V1 completo** (PRs #1 a #26). Antes de operação real: os limites listados em "Limites conhecidos da V1" no README. Hospedagem: adiada por decisão do usuário (18/10/2026).
- **Decisões do usuário (18/10/2026) após a 9c:**
  - **Acesso revogado ou conta desativada:** os plantões futuros (rascunho ou publicados, ainda não iniciados e sem entrada registrada) voltam a ser vaga. Os pedidos pendentes em que a pessoa participa são cancelados, as candidaturas ativas dela são retiradas, o anúncio é encerrado, cada plantão gera `shift.vacated` na auditoria e o coordenador da unidade é notificado (`Shift::vacateFor`). Locks na mesma ordem dos demais fluxos (profissional → plantões por id → pedidos). Corrida real entre revogação e cancelamento do plantão (cliente sintético "Rede Corrida", 12 rodadas nas duas ordens): sem deadlock, um resultado coerente por rodada, um único evento de auditoria por pedido.
  - **Meia-noite (V1-05):** o plantão que atravessa a meia-noite fica visível até o **fim previsto**, como já funcionava; o PRODUCT.md foi ajustado.
- **9c (revisão integrada, 18/10/2026):**
  - `tests/Feature/JourneyTest.php`: a jornada inteira num cliente novo, só pelas rotas HTTP, passando por provisionamento, unidade, setor, função, convites de coordenação e gestão, profissionais com habilitação aprovada e acesso, regras e ponto de presença, escala publicada, candidatura e escolha, transferência aceita e aprovada, entrada por localização e saída ajustada. Depois confere as notificações por pessoa, o relatório e o CSV contra o que aconteceu, e a auditoria sem mistura entre clientes.
  - Telas: 29 combinações de perfil e tela (administrador, coordenadora e profissional) em 360, 768 e 1440 px, sem erro 5xx ou de JavaScript, sem rolagem horizontal, com um `h1` por página, todos os controles com nome acessível e foco visível nas primeiras paradas do teclado. O verificador foi validado antes numa página com esses defeitos plantados.
  - Dependências: `composer audit` e `npm audit` sem vulnerabilidades; `composer validate` ok. Não existe `npm run test:e2e` nos manifests: a jornada é coberta pelo `JourneyTest` e pela verificação no navegador.
  - Limites de produção consolidados no README ("Limites conhecidos da V1").
  - Revisão independente da integração (sem bloqueadores). Corrigido: aprovação de transferência ou troca e escolha de candidato agora recusam profissional cujo acesso foi revogado no meio do fluxo; pedidos encerrados pelo cancelamento do plantão ou pela entrada registrada ganham evento próprio na auditoria (`shift.*_cancelled` / `shift.*_expired`), e os envolvidos passam a ser notificados.
  - Em aberto (exibição): a candidatura escolhida continua "selecionada" num plantão cancelado.
- **Decisões do usuário (18/10/2026) para a 9b:** indicadores de cobertura, solicitações, presença e horas por profissional; PDF gerado no servidor; acesso igual ao da Escala (coordenador, gestor, admin de rede e auditor, cada um nas suas unidades).
- **9b-1 (relatórios e CSV, V1-11):**
  - Tela "Relatórios" (menu de quem vê a Escala): unidade e período (até 92 dias; inválido volta ao mês atual). Tudo conta os plantões da unidade que **começam** no período, no fuso da unidade, e cada grupo fecha com o total: publicados = preenchidos + anunciados + vagas abertas (mais rascunhos e cancelados); solicitações pela situação atual (a troca conta nas duas unidades que a aprovam, uma vez em cada); presença dos publicados já iniciados com responsável (com/sem entrada, saída registrada, saída ajustada, ocorrências abertas); horas por profissional.
  - Botão "Exportar": Escala e Presença, cada uma em PDF ou planilha (CSV) com exatamente as linhas contadas: `;`, BOM UTF-8, células que começam com `= + - @` neutralizadas, sem coordenadas, precisão ou distância. Geradas na hora para quem pediu (nada fica guardado) e auditadas (`report.exported`, com o formato).
- **9b-2 (PDF):** `dompdf/dompdf` 3.1 (LGPL-2.1, PHP puro; exige só `dom` e `mbstring`; `composer audit` limpo), sem recursos remotos nem PHP embutido. Mesmas linhas do CSV, escapadas pelo Blade (`resources/views/reports/table.blade.php`), A4 paisagem, com rede, unidade, período, horário de geração e "Página X de Y". Gerado em memória, limitado a 2.000 linhas (acima disso, a mensagem orienta usar a planilha ou um período menor); passar para exportação em fila se unidades grandes precisarem.
- **Foto de perfil:** em Meu perfil, cada usuário escolhe, troca ou remove a foto. O navegador recorta em quadrado (512 px) e regrava em JPEG, o que descarta metadados como GPS. O servidor aceita só JPG/PNG/WebP reais, até 2 MB, de 64 a 4096 px; guarda com nome aleatório no disco privado e entrega a foto só ao dono (`settings/profile/photo`). Sem GD/Imagick no container, o servidor não reprocessa a imagem. A foto aparece na barra superior; sem foto, as iniciais.
- **Configurações:** layout, perfil e exclusão de conta em português. As rotas de configurações carregam a instituição atual quando existe (`tenant:optional`), para o menu e o sino não perderem itens.
- **9a (notificações internas, V1-12):**
  - Tela "Notificações" no menu, com o número de não lidas; "Marcar todas como lidas". Cada item leva à tela relacionada (Meus plantões, Solicitações ou o plantão). Horário mostrado no fuso do navegador de quem lê.
  - Derivadas de `audit_events` (gravado na mesma transação da mudança) pelo job `DeliverNotifications`, despachado depois do commit. Um cursor (`notification_cursors`) com lock de linha serializa as entregas; o índice único (usuário, evento) impede duplicar. Eventos dos últimos 5 minutos são relidos para pegar transações confirmadas fora da ordem dos ids. Falha da notificação não desfaz a operação nem vira erro para o usuário; um evento com defeito é reportado e pulado, sem travar o cursor.
  - Destinatários: só envolvidos (responsável, colega do pedido, escolhido e substituído) e coordenadores da unidade (das duas unidades na troca entre hospitais). Unidade sem coordenador ativo: avisa os administradores de rede, que também decidem (decisão técnica, a confirmar pelo usuário). Acesso revogado na hora da entrega não recebe. O responsável de "plantão publicado" é o do momento do evento.
  - A migração inicia o cursor no último evento existente: o histórico anterior não vira notificação.
  - Em aberto (produto): visibilidade da justificativa da escolha para o candidato.
- **8b (ocorrências):**
  - Em "Meus plantões", "Relatar problema na presença" abre uma ocorrência de entrada ou saída com descrição (uma aberta por tipo e plantão). Estados: aberta → deferida ou indeferida (o passo "em análise" foi omitido por não mudar nenhuma regra).
  - O coordenador da unidade (ou admin de rede) decide no detalhe do plantão, com justificativa obrigatória; ocorrências abertas aparecem em "Solicitações". Quem é participante não decide.
  - Saída deferida exige horário informado (fuso da unidade, entre a entrada e agora) e encerra a presença administrativamente, marcada como ajustada; os eventos originais não mudam e a saída por localização passa a ser recusada. Entrada deferida nunca cria check-in.
- **Decisões do usuário (18/10/2026) para a etapa 8:** duas janelas configuradas pela rede/unidade (entrada a partir de X min antes do início, até o fim; saída até Y min depois do fim); pontos de presença cadastrados pelo gestor da unidade e pelo administrador de rede.
- **8a (presença por localização):**
  - Regras no catálogo (sem valor padrão): precisão máxima, antecedência da entrada e prazo da saída. Pontos por unidade (nome, coordenadas, raio), imutáveis: para mudar, cadastra-se outro e desativa-se o antigo; o ponto usado fica registrado em cada decisão.
  - Em "Meus plantões", "Registrar entrada/saída" lê a localização uma vez (sem rastreamento). O servidor aceita só se a unidade estiver configurada, dentro da janela, com medição recente (tolerância técnica de 2 min, não regra de produto), precisão dentro do limite e distância + precisão dentro do raio de um ponto. Tentativas recusadas ficam guardadas com o motivo; `attendance_events` é só de inserção; repetir a mesma tentativa devolve o primeiro resultado; limite de 20 tentativas por minuto.
  - Entrada registrada expira transferências e trocas pendentes, encerra o anúncio e bloqueia anunciar, transferir, trocar e cancelar o plantão.
  - O coordenador vê no detalhe do plantão as tentativas com ponto, distância e precisão, sem coordenadas. Coordenadas dos pontos só para quem os gere.
  - Sem ponto ativo, a saída por localização fica impossível; a saída ajustada pelo coordenador vem na 8b.
  - Ainda pendente antes de operação real: teste em aparelho físico via HTTPS e política de retenção de coordenadas (PRODUCT.md).
- **7b (troca):**
  - Em "Meus plantões", "Trocar com um colega" lista plantões futuros de colegas com acesso, na mesma UF, em que os dois lados têm habilitação aprovada (sem valores). Mesmo fluxo da transferência (`shift_requests`, tipo `swap`, `counterpart_shift_id`).
  - Cada lado precisa ser elegível no plantão que recebe, comparando com a UF do plantão que deixa. Conflito avaliado na escala resultante (sem os plantões trocados) já no pedido, no aceite e em cada aprovação; a restrição do banco garante na aplicação.
  - Aprovação: uma, se os dois plantões são da mesma unidade; das duas unidades, se são hospitais diferentes (quem gere as duas registra as duas, se não participar). Qualquer recusa encerra. A troca aplica os dois lados ou nenhum e é auditada como `shift.swapped`.
  - Respostas, cancelamentos e decisões de transferência e troca são os mesmos métodos (`Shift::respondToRequest`, `cancelRequest`, `decideRequest`), com lock de profissionais → plantões em ordem de id → pedido.
- **7a (transferência direta):**
  - Em "Meus plantões", "Transferir a um colega" escolhe um colega com acesso ativo e habilitação aprovada na unidade e função. Fluxo: aguardando o colega → aguardando a coordenação → concluída; recusa, cancelamento e expiração são terminais (`shift_requests`).
  - Aprova o coordenador da unidade do plantão (ou o administrador de rede); quem participa não decide. Recusa da coordenação exige motivo. A UF de referência é a da unidade-base do destinatário.
  - Elegibilidade conferida no pedido, no aceite e na aprovação; a aprovação trava os dois profissionais, depois o plantão e o pedido, revalida conflito e troca o responsável. Um pedido pendente por plantão (índice único); não convive com anúncio; cancelar o plantão encerra os pedidos.
  - Expiração ao atingir o início, aplicada ao abrir Solicitações ou Meus plantões e antes de cada ação, com motivo e auditoria (`shift.transfer_expired`).
  - Página "Solicitações" (referência IMG_0918): minhas solicitações (enviadas e recebidas) e, para coordenadores, "Para aprovar".
  - Correção junto: os helpers de exibição de plantão saíram de `pages/shifts/index.tsx` para `components/shift-display.tsx`; importar uma página a partir de outra podia tirar a página do manifesto do Vite (verificado: no build local, a Escala sumia do manifesto).
- **6c (anúncio para substituição):**
  - Em "Meus plantões", o responsável anuncia um plantão publicado e futuro ("Anunciar para substituição") ou cancela o anúncio. Ele continua responsável até a escolha (regra do PRODUCT.md).
  - O plantão anunciado aparece em "Vagas" para os demais profissionais habilitados, marcado como "Substituição", sem o nome de quem anunciou. Quem anunciou não se candidata ao próprio plantão.
  - A escolha do coordenador trava o responsável atual e o candidato, depois o plantão; troca o responsável, encerra o anúncio, marca a escolha anterior como "Selecionada, depois substituída" (`replaced`) e registra `replaced_professional_id` na auditoria. O coordenador que anunciou não escolhe o próprio substituto.
  - Cancelar o anúncio encerra as candidaturas; cancelar o plantão encerra o anúncio. Restrição `shifts_announced_check`: só plantão publicado e com responsável pode estar anunciado.
  - Em vaga aberta, o profissional só vê a candidatura da rodada atual; o resultado anterior aparece quando a vaga fecha.
  - Verificação: testes, navegador (Playwright, 1440 e 390 px) e corrida real entre escolha e cancelamento do anúncio (um resultado só, sem mistura).
- **6b (vagas abertas):**
  - **Vaga:** plantão publicado sem responsável. A tela "Vagas" mostra ao profissional as vagas futuras nas unidades e funções em que ele tem habilitação não revogada; o detalhe tem "Candidate-se" e "Retirar-se" (referências IMG_0928 a 0932). O valor não é mostrado ao profissional.
  - **Candidatura:** `shift_applications`, situações `active`, `withdrawn`, `selected` e `not_selected`. Candidatar-se não atribui o plantão. A elegibilidade é verificada na candidatura e de novo na escolha. Retirar e voltar a se candidatar cria um novo registro e preserva o histórico.
  - **Escolha:** no detalhe do plantão, o coordenador da unidade (ou o administrador de rede) escolhe um candidato com justificativa obrigatória. Trava o profissional e depois o plantão, revalida elegibilidade, horário e conflitos, grava a versão de elegibilidade e encerra os demais candidatos como "não selecionado". Evento `shift.assigned` na auditoria, com a justificativa.
  - **Garantias:** a segunda escolha concorrente encontra a vaga preenchida; candidato que se retirou ou perdeu elegibilidade não é substituído automaticamente; ninguém escolhe a si mesmo; cancelar o plantão encerra as candidaturas ativas. Índices únicos parciais: uma candidatura ativa por profissional e vaga e um único selecionado por vaga.
  - **Em aberto (produto):** visibilidade da justificativa para o candidato e destinatários das notificações (etapa 9).
- **6a:**
  - **Convite:** botão "Convidar para acessar" na tela do profissional, para o administrador ou o gestor da unidade-base. Usa o e-mail do cadastro e reaproveita a conta se ela já existir; senão, cria a conta e envia o link de senha.
  - **Papel:** dá o papel Profissional na unidade-base, sem remover os outros papéis de um vínculo ativo.
  - **Ligação:** `professionals.membership_id` é única, ou seja, uma conta por profissional.
  - **"Meus plantões":** mostra só os plantões publicados do próprio profissional (referência IMG_0911).
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
- **5b (plantões):**
  - **Colunas:** `source_id`, `unit`, `sector`, `function`, `start`, `end` (data, hora e fuso, por exemplo `2026-11-10T07:00-03:00`) e `professional_source_id` (vazio = vaga aberta).
  - **Profissional:** encontrado só pela identidade de importação da mesma origem, nunca pelo nome. Unidade, setor, função ou profissional desconhecidos deixam a linha pendente, com o motivo.
  - **Aplicação:** cria sempre rascunho, pelo `Shift::createDraft`, com as mesmas regras da tela (elegibilidade, sobreposição, plantão já iniciado). Linha que viola alguma regra vira conflito, com o motivo. Nunca publica.
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

Último resultado: 276 testes passando (etapa 3.3), PHPStan sem erros, lint e TypeScript limpos.

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
