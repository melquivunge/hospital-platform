# Estado atual do projeto

Atualizado em 19/10/2026. Leia este arquivo e o [AGENTS.md](../AGENTS.md) antes de começar uma nova sessão.

## Em uma frase

Plataforma hospitalar SaaS (multi-cliente) para escalas de plantão. Base web pronta: login, isolamento entre clientes, unidades, setores, pessoas e papéis, catálogo de regras herdadas, profissionais, funções, habilitações, elegibilidade, escala (publicação segura e agenda) importação CSV, acesso do profissional, "Meus plantões", vagas com candidatura e escolha justificada anúncio do próprio plantão para substituição transferência direta e troca entre dois plantões com aceite e aprovação presença por localização e ocorrências com saída ajustada pela coordenação, com identidade visual própria. Entrega 4b concluída (22/10/2026). Entrega 4c (estoque da farmácia) aprovada em 22/10/2026 (ADR-0007, `plans/web-v5-estoque.md`). Próximo passo: entrega 4c concluída (23/10/2026). Entrega 5 (exames e cirurgia): decisões do usuário registradas (23/10/2026: exames na 5, cirurgia na 5b; papéis Laboratório e Imagem; equipe lê o prontuário durante a execução); ADR-0008 e plano `plans/web-v6-exames.md` aceitos e implementação autorizada em 24/10/2026. Entrega 5 (exames) concluída e mesclada (5.6, PR #87, 24/10/2026). Entrega 5b (cirurgia): ADR-0009 e plano `plans/web-v7-cirurgia.md` aceitos e implementação autorizada em 24/10/2026 (equipe escalada lê o prontuário; centro cirúrgico ou equipe confirma o checklist; farmácia mantém os materiais). 5b.1a mesclada (PR #90). Próximo passo: revisão e merge da 5b.1b (importação CSV dos procedimentos); depois a 5b.2 (pedido de cirurgia).

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
| #30 | Etapa 3.3: unificação de cadastros | Mesclado |
| #31 | Etapa 3.4a: grade de agenda | Mesclado |
| #32 | Etapa 3.4b: agendamento pela recepção | Mesclado |
| #33 | Etapa 3.5: chegada, fila do dia e convênios | Mesclado |
| #34 | Etapa 3.6a: atendimento, evolução e trilha de leitura | Mesclado |
| #35 | Etapa 3.6b: acesso de emergência e trilha de acessos | Mesclado |
| #36 | Etapa 3.7: documentos anexos | Mesclado |
| #37 | Entrega 4: contrato, ADR-0004 e plano | Mesclado |
| #38 | Etapa 3.8: revisão integrada da entrega 3 | Mesclado |
| #39 | Etapa 4.1: leitos | Mesclado |
| #40 | Etapa 4.2: chegada e classificação de risco | Mesclado |
| #41 | Etapa 4.3: atendimento de urgência | Mesclado |
| #42 | Etapa 4.4a: pedido de internação e alocação de leito | Mesclado |
| #43 | Etapa 4.4b: acesso da equipe do setor e evolução de internação | Mesclado |
| #44 | Etapa 4.4b: anexar documento na internação | Mesclado |
| #45 | Etapa 4.5: movimentação interna | Mesclado |
| #46 | Etapa 4.6: transferência entre unidades | Mesclado |
| #47 | Etapa 4.7: alta e censo | Mesclado |
| #48 | Etapa 4.8: revisão integrada da entrega 4 | Mesclado |
| #49 | Etapa 4.9: pendências da revisão de segurança | Mesclado |
| #50 | Entrega 4b: contrato, ADR-0005, ADR-0006 e plano | Mesclado |
| #51 | Etapa 4b.1a: catálogo de medicamentos e regras de segurança | Mesclado |
| #52 | Etapa 4b.1b: importação CSV do catálogo e das interações | Mesclado |
| #53 | Atualização do `vite-plus` para 1.3.0 (alertas altos do `npm audit`) | Mesclado |
| #54 | Etapa 4b.1c: alergias do paciente | Mesclado |
| #55 | Etapa 4b.2a: prescrição com alertas, justificativa e assinatura | Mesclado |
| #56 | Etapa 4b.2b: cópia, suspensão de item e aprazamento | Mesclado |
| #57 | Etapa 4b.3a: papel Farmácia e avaliação por item | Mesclado |
| #58 | Etapa 4b.3b: dispensação e devolução | Mesclado |
| #59 | Etapa 4b.4: administração pela enfermagem | Mesclado |
| #60 | Etapa 4b.5: assinatura digital da prescrição | Mesclado |
| #61 | Etapa 4b.6: revisão integrada da entrega 4b | Mesclado |
| #62 | Entrega 4c: contrato proposto | Mesclado |
| #63 | Entrega 4c: decisões, ADR-0007 e plano | Mesclado |
| #64 | Etapa 4c.1a: lotes, entradas, saldo e estoque mínimo | Mesclado |
| #65 | Etapa 4c.1b: importação do saldo inicial | Mesclado |
| #66 | Correção: papel Farmácia relido a cada linha da importação de estoque | Mesclado |
| #67 | Etapa 4c.2: dispensação e devolução com estoque | Mesclado |
| #68 | Travas do estoque no banco e descarte da devolução em lote vencido | Mesclado |
| #69 | Descartes dos últimos 30 dias na tela de estoque | Mesclado |
| #70 | Histórico completo de descartes, paginado | Mesclado |
| #71 | 4c.3a: descarte manual com motivo | Mesclado |
| #72 | 4c.3b: inventário com ajuste aprovado pelo gestor | Mesclado |
| #73 | 4c.4a: avisos diários de estoque e serviço `scheduler` | Mesclado |
| #74 | 4c.4b: relatório de movimentos com CSV | Mesclado |
| #75 | 4c.4c: indicadores de estoque e visão da rede | Mesclado |
| #76 | 4c.5: revisão integrada da entrega 4c | Mesclado |
| #77 | Aviso ao gestor no descarte de controlado, opção A | Mesclado |
| #78 | Entrega 5: contrato, decisões, ADR-0008 e plano | Mesclado |
| #79 | 5.1a: catálogo de exames e papéis Laboratório e Imagem | Mesclado |
| #80 | 5.1b: importação CSV do catálogo de exames e analitos | Mesclado |
| #81 | 5.2: pedido de exame com assinatura e cancelamento | Mesclado |
| #82 | 5.3a: filas de Laboratório e Imagem, execução e leitura do prontuário | Mesclado |
| #83 | Riscos da 5.3a: tempos-alvo por rede e prescrições na execução | Mesclado |
| #84 | 5.3b: pedido sem coleta há 7 dias ao gestor | Mesclado |
| #85 | 5.4: resultado e laudo, retificação por versão | Mesclado |
| #86 | 5.5: resultado crítico, ciência e escalonamento ao gestor | Mesclado |
| #87 | 5.6: revisão integrada dos exames | Mesclado |
| #90 | 5b.1a: procedimentos, salas e papel Centro cirúrgico | Mesclado |
| — | 5b.1b: importação CSV dos procedimentos (branch `feat/surgery-catalog-import`) | Em revisão |

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
  - Escopo (decisão técnica, a confirmar): o gestor de qualquer unidade unifica cadastros da rede inteira, porque o paciente pertence à rede. Sem cadeias: um cadastro que é principal de outra unificação não vira duplicado sem desfazer a anterior. Cadastro unificado não é editado (redireciona para o principal). A fila é calculada a cada visita (limite conhecido para cadastros muito grandes).
  - Locks nos dois cadastros em ordem de id.
- **3.4a (grade de agenda; a 3.4 foi dividida em 3.4a e 3.4b):**
  - Tela "Agendas" para o gestor da unidade e o administrador de rede, com seletor de unidade. Grade semanal por profissional habilitado na unidade (dia, início, fim e duração fixa da consulta, no horário da unidade) e bloqueios de período com motivo.
  - Regras imutáveis: para mudar, remove-se e adiciona-se outra. Restrição no banco: o mesmo profissional não tem faixas sobrepostas no mesmo dia da semana, nem em unidades diferentes. Tudo auditado (`schedule.*`). Sem dados de paciente, então as tabelas não têm RLS.
- **3.4b (agendamento pela recepção):**
  - Tela "Agendamentos" (menu da Recepção, por unidade): profissional, dia e os horários do dia (livre, ocupado, bloqueado, passou), no fuso da unidade. O paciente é escolhido na ficha dele ("Agendar consulta"), então nome ou documento não entram em URL; só o id. Depois de agendar, o paciente sai da seleção (evita agendar a mesma pessoa duas vezes sem querer).
  - Horário normal só na fronteira de uma consulta da grade; **encaixe** explícito em qualquer minuto da faixa do profissional (no próprio dia, mesmo atrasado), marcado como tal. Bloqueios fecham os dois.
  - Cancelar exige motivo; **remarcar** cancela o anterior com motivo e liga o novo (`rescheduled_from_id`, único); **falta** só depois do horário. Nada é apagado. Auditoria `appointment.created|cancelled|no_show` só com ids.
  - `appointments` é tabela clínica (RLS forçado). Restrição de exclusão no banco: dois agendamentos vivos do mesmo profissional não se sobrepõem, salvo encaixe.
  - Corrida real (cliente sintético "Rede Corrida Agenda", dois processos e conexões): a primeira rodada achou **deadlock** entre inserts checando a restrição de exclusão (40P01, erro 500 para uma recepção). Corrigido travando a linha do profissional no início da transação (mesma ordem dos outros fluxos: profissional primeiro). Depois: 8 rodadas de agendamento e 4 de remarcação, alternando quem começa: sempre um vencedor e o outro recusado com mensagem clara.
  - Navegador (Playwright, 1440 e 390 px): agendar, encaixe, remarcar e cancelar com motivo; sem erro de JavaScript ou 5xx, sem rolagem horizontal.
  - Revisão independente (sem crítico/alto). Corrigido: o paciente é relido com lock compartilhado dentro da transação e a remarcação também vai para o cadastro principal se houve unificação no meio; a habilitação do profissional é conferida de novo depois do lock; encaixe no fim da faixa termina com a faixa; erro de falta aparece na tela. Limite conhecido: os horários são montados no relógio da unidade, então um fuso com horário de verão deslocaria a consulta que cair na hora pulada (as unidades atuais não têm).
- **3.5 (recepção: chegada, fila e convênios):**
  - Tela "Convênios" (administrador de rede): nome único sem distinção de maiúsculas, registro ANS opcional (6 dígitos), desativar em vez de excluir. Tabela da rede, sem dados de paciente, fora do RLS. Auditoria `insurer.*` com os nomes dos campos alterados.
  - Tela "Fila do dia" (Recepção): hoje no fuso da unidade, todos os profissionais ou um; aguardando chegada, chegaram (na ordem de chegada, com modalidade) e faltas e cancelamentos, com contadores.
  - Confirmar chegada só no dia da consulta e com o agendamento em aberto: modalidade particular, convênio ou SUS; convênio exige convênio ativo, número da carteira e validade não vencida (carteira vencida é recusada: confere-se a validade ou registra-se outra modalidade). Depois da chegada não há cancelamento nem falta. O número da carteira fica só na tabela clínica; a auditoria (`appointment.arrived`) guarda modalidade e id do convênio. Restrição no banco amarra status, chegada, modalidade e dados do convênio.
  - Corrida real chegada × cancelamento do mesmo agendamento (5 rodadas válidas, nas duas ordens): sempre uma ação vence, a outra é recusada com mensagem, um único evento de auditoria.
  - Navegador (1440 e 390 px): convênio cadastrado pelo administrador, chegada por convênio e cancelamento na fila; sem erro de JavaScript ou 5xx, sem rolagem horizontal.
  - Revisão independente (aprovada, sem crítico/alto). Corrigido: o convênio é conferido como ativo de novo dentro da transação (lock compartilhado); os botões repetidos de cada linha (chegada, falta, cancelar) têm nome acessível com paciente e horário. Limite conhecido: a fila aberta depois da meia-noite fica no dia anterior até recarregar (a chegada é recusada com mensagem).
- **3.6a (atendimento e evolução; a 3.6 foi dividida em 3.6a e 3.6b):**
  - Tela "Meus atendimentos" (profissional com conta ligada): pacientes que já chegaram para ele e atendimentos em andamento, em qualquer unidade. "Iniciar atendimento" só depois da chegada confirmada (decisão técnica: o encaixe também passa pela confirmação de chegada na fila). Duas abas ou dois cliques caem no mesmo atendimento (lock no agendamento + atendimento único por agendamento). Agendamento: chegou → em atendimento → atendido.
  - **Acesso clínico centralizado** (`App\Clinical\ClinicalAccess`): lê o prontuário só quem tem atendimento aberto do paciente em qualquer unidade da rede; cadastros unificados formam um prontuário só. Recepção, gestão e administração nunca leem. Cada abertura do atendimento grava uma leitura em `clinical_reads` (só inserção, garantido por gatilho no banco).
  - Evolução: rascunho visto só pelo autor, salvo quantas vezes quiser; **assinatura com a senha** (sem ICP-Brasil) torna a evolução imutável: um gatilho no banco recusa alterar evolução assinada e apagar qualquer evolução. Correção é um **adendo**, também rascunho → assinado, ligado à evolução original. Encerrar o atendimento exige os rascunhos dele assinados; depois do encerramento, o prontuário deixa de abrir para esse profissional.
  - Nenhum texto clínico vai para a auditoria (`visit.opened|closed`, `clinical_note.created|signed` só com ids), URLs, notificações ou mensagens.
  - Corrida real (duas abas abrindo o mesmo atendimento, 6 rodadas): sempre um atendimento e um evento de auditoria.
  - Navegador (1440 e 390 px): rascunho, encerramento recusado com rascunho pendente, senha errada, assinatura, adendo e encerramento; sem erro de JavaScript ou 5xx. O teste no navegador achou um defeito corrigido: rascunho de outro atendimento do mesmo paciente aparecia e bloqueava o encerramento.
  - Revisão de segurança independente (sem crítico). Corrigido: rascunho gravado com o atendimento travado (corrida real encerrar × salvar rascunho, 6 rodadas: nunca fica rascunho em atendimento encerrado); rascunho de um atendimento só se edita ou assina com ele aberto; um rascunho de evolução por autor e atendimento (índice no banco); gatilhos ampliados: evolução nasce rascunho, autor e data de criação não mudam, atendimento encerrado não reabre nem troca paciente, profissional ou agendamento, e esvaziar as três tabelas é recusado; senha errada na assinatura: 5 tentativas a cada 15 minutos por usuário (só as erradas contam) e cada falha vai para a auditoria (`clinical_note.sign_failed`). O rollback da migration devolve agendamentos em atendimento ou atendidos para "chegou".
- **3.6b (acesso de emergência e trilha de acessos):**
  - Botão "Prontuário" na ficha do paciente (profissional com conta ligada): com atendimento aberto vai ao atendimento; sem ele, pede **acesso de emergência**: unidade onde está atendendo e justificativa (mínimo 20 caracteres). Concedido na hora, **só leitura, por 4 horas**; renovar é nova concessão com nova justificativa. `emergency_accesses` é clínica (RLS), só de inserção (gatilho) e o banco exige validade de exatamente 4 horas.
  - `ClinicalAccess` aceita a concessão válida; cada leitura em emergência vai para `clinical_reads` apontando para a concessão. Evolução continua exigindo atendimento aberto.
  - O gestor da unidade (sem gestor, o administrador de rede) recebe a notificação "Acesso de emergência a um prontuário", sem dado do paciente. A auditoria (`clinical.emergency_access`) guarda só ids; a justificativa fica na tabela clínica.
  - Tela "Acessos ao prontuário" (últimos 30 dias): gestor vê as concessões das suas unidades, administrador de rede as da rede, auditor as concessões e todas as leituras (quem, quando, paciente com CPF mascarado, motivo). Nunca conteúdo clínico.
  - Navegador (1440 e 390 px): pedido com justificativa, prontuário só leitura com o aviso de validade, notificação e trilha para o administrador; sem erro de JavaScript ou 5xx.
  - Revisão de segurança independente (sem IDOR, quebra de isolamento ou desvio de autorização). Corrigido: com atendimento aberto ou concessão válida, novo pedido não cria concessão nem notifica (e o limite é de 3 pedidos a cada 10 minutos); o formulário avisa para não escrever diagnóstico nem sintomas na justificativa, que a gestão e a auditoria leem; a trilha avisa quando a lista foi cortada (200 por bloco); a migration volta e é reaplicada com dados (a trilha só de inserção mantém as leituras antigas). Decisão registrada: gestão e auditoria veem o nome do paciente com CPF mascarado, para saber de quem é o prontuário acessado. Limite conhecido: o horário da concessão vem da aplicação (o banco exige só as 4 horas de validade).
- **3.7 (documentos anexos):**
  - No atendimento aberto, o profissional anexa PDF, JPG ou PNG de até 10 MB. O tipo vem dos **bytes do arquivo** (`finfo`) e precisa bater com a extensão: página HTML salva como .pdf ou PNG renomeado são recusados. (O arquivo falso dos testes do Laravel informa o tipo pela extensão; por isso a verificação não usa a regra `mimetypes`.)
  - Disco privado, em `clinical/{cliente}/` com nome aleatório; a linha guarda nome original limpo, tipo, tamanho e SHA-256. O banco exige que o caminho esteja sob o prefixo do próprio cliente, recusa apagar e só aceita registrar a retirada, uma vez.
  - Download só com acesso clínico (atendimento aberto ou emergência válida), sempre como anexo (`nosniff`, CSP `sandbox`, sem cache); cada download vai para a trilha de leitura e para a auditoria (`clinical_document.downloaded`). Documento de outro cliente: 404.
  - Retirar: só quem anexou, com o atendimento aberto e motivo; o arquivo fica guardado e a lista mostra "Retirado em ... : motivo". Na emergência, a lista é só leitura.
  - Infraestrutura: PHP aceita 10 MB por arquivo e 12 MB por requisição (`docker/php/uploads.ini`); Nginx 12 MB.
  - Navegador (1440 e 390 px): PDF pequeno e de 9,9 MB aceitos, HTML disfarçado e arquivo de 11 MB recusados com mensagem, download com nome e tamanho corretos, retirada com motivo; sem erro de JavaScript ou 5xx.
  - Revisão de segurança independente (sem crítico/alto). Corrigido: retirar só com o atendimento do próprio documento aberto (um atendimento novo não reabre documentos antigos); arquivo ausente no disco dá 404 sem registrar leitura; até 20 documentos por atendimento. Decisões registradas: documento retirado não é baixado por ninguém (o arquivo fica no disco para a auditoria); arquivo poliglota (PDF com conteúdo extra) é aceito porque é sempre entregue como anexo, `nosniff` e `sandbox`. Limite conhecido: se o processo cair entre gravar o arquivo e confirmar a linha, sobra um arquivo sem registro (falhas comuns já o apagam); uma varredura periódica resolve se necessário.
- **Entrega 3:** etapas 3.1 a 3.7 mescladas (#28 a #36).
- **3.8 (revisão integrada da entrega 3):**
  - `tests/Feature/Clinical/OutpatientJourneyTest.php`: a jornada inteira num cliente novo, só pelas rotas: provisionamento, unidades, convênio, convites (recepção, gestão, auditoria), profissionais com habilitação e acesso, grade, cadastro provisório completado, duplicado unificado, agendamento pelo id do duplicado (cai no principal) e remarcação, chegada por convênio, atendimento, evolução assinada com adendo, documento, encerramento, recepção e gestão sem leitura, emergência da enfermeira de outra unidade. Depois confere a trilha de leitura, a notificação só ao gestor da unidade da emergência, a tela da auditoria e a auditoria sem texto clínico, carteira, CPF ou nome.
  - Telas da entrega 3 nos três perfis (recepção, profissional, administração) em 360, 768 e 1440 px: 33 visualizações sem erro de JavaScript ou 5xx, sem rolagem horizontal, um `h1` e todos os controles com nome. O verificador foi validado antes numa página com esses defeitos plantados.
  - Limites de produção da entrega 3 no README.
  - Revisão de segurança independente da entrega inteira: nenhum caminho fora do RLS ou do `ClinicalAccess`, sem vazamento em props, mensagens ou auditoria, sem ciclo de locks, conta revogada perde tudo. **Duas decisões de produto pendentes** (no README): prazo para atendimento esquecido aberto e teto diário de acessos de emergência. Demais achados registrados como limites (unificação amplia o acesso por contrato; concessão vale 4 horas mesmo com habilitação revogada; mensagem de CPF já cadastrado).
- **4.1 (leitos):**
  - Tela "Leitos" (mapa por setor, contadores por estado, estado em texto e cor) para gestor da unidade, administrador de rede e os papéis novos **Gestão de leitos** e **Higienização**, por unidade.
  - Gestor e administrador cadastram leitos (quarto, código único por setor, tipo enfermaria/apartamento/UTI, restrição de sexo, isolamento, leito extra de superlotação) e desativam leito livre ou bloqueado. Um setor passa a ser de internação quando tem leitos.
  - Gestão de leitos bloqueia (com motivo), libera e envia para higienização; a higienização (e a gestão de leitos) marca como pronto. Cada mudança é uma atualização condicional (`WHERE status = esperado`) e gera uma linha em `bed_events` (só inserção, gatilho). Sem dado de paciente ainda: não é tabela clínica.
  - Corrida real (duas pessoas da gestão de leitos no mesmo leito, 6 rodadas): uma ação vence, a outra é recusada com mensagem.
  - Navegador (1440 e 390 px): cadastro, bloqueio com motivo, liberação, higienização e pronto; sem erro de JavaScript ou 5xx.
  - Revisão independente (aprovada, sem crítico/alto). Corrigido: a desativação entra no histórico do leito; o rollback remove antes as atribuições dos papéis novos (ida e volta testada no banco local); limites de requisição em todas as ações; testes de conta revogada, gestor de outra unidade e administrador (configura, não opera).
  - Próxima: **4.2** (chegada e classificação de risco), feita a seguir.
- **4.2 (chegada e classificação de risco)** (PR #40):
  - Tela "Classificação de risco" (administrador de rede): escala de 5 níveis da rede com ordem e cores fixas (vermelho a azul); a rede ajusta nome e tempo-alvo. Os padrões (0, 10, 60, 120 e 240 min) são ponto de partida, não protocolo licenciado; validar com a equipe clínica antes do uso real. Auditoria `triage_level.updated` com os nomes dos campos.
  - Tela "Urgência" (recepção e profissionais habilitados na unidade, com seletor de unidade): a recepção registra a chegada de paciente cadastrado (botão "Chegada na urgência" na ficha, só o id na URL) ou de **"Não identificado"** (sexo, idade aparente, descrição; cadastro provisório). Um episódio aberto por pessoa na rede: índice único no banco e conferência de todos os cadastros unificados no mesmo prontuário.
  - Fila: primeiro quem aguarda classificação, depois nível e espera; tempo-alvo estourado com borda e aviso em texto. A recepção vê nome e nível, nunca queixa nem sinais vitais (não são carregados para ela).
  - Classificação por profissional com habilitação aprovada **e vigente hoje** na unidade (datas no fuso da unidade, como na elegibilidade) e conta ativa: nível, queixa principal e sinais vitais opcionais com faixas no banco. `triages` é clínica (RLS), só de inserção (gatilho, inclusive TRUNCATE); a reclassificação aponta para a anterior (`previous_id` único: cadeia linear). O formulário envia a classificação que a pessoa viu; se outra entrou no meio, é recusada com mensagem (sem sobrescrever às cegas). Auditoria `emergency.arrived|triaged` só com ids.
  - "Não identificado" unificável depois: ao receber um nome na edição do cadastro, deixa de ser não identificado (idade aparente e descrição são limpas) e o par aparece na fila de unificação. A unificação é recusada se os dois cadastros estiverem na urgência ao mesmo tempo.
  - Corrida real (cliente "Rede Corrida Agenda", processos e conexões próprias, 6 rodadas alternando a ordem): duas recepções registrando a chegada do mesmo paciente: sempre um episódio e um evento de auditoria, a outra recusada; duas classificações simultâneas do mesmo episódio: uma vence, a outra é recusada com mensagem.
  - Navegador (1440 e 390 px): ajuste de tempo-alvo, chegada de paciente cadastrado e de não identificado, classificação e reclassificação; sem erro de JavaScript ou 5xx, sem rolagem horizontal, um `h1`. O teste no navegador achou um defeito corrigido: depois de classificar, o formulário ficava aberto com os valores antigos.
  - Revisão de segurança independente (sem crítico). Corrigido: unificação podia deixar a mesma pessoa com dois episódios abertos (alto); habilitação vencida ou ainda não vigente classificava; classificações carregadas só para quem classifica; ficha aberta por cadastro unificado mostra o principal; só a mudança de nome tira o "não identificado". Ida e volta da migration no banco local.
  - Decisão do usuário (18/10/2026): a classificação não abre o prontuário nem grava leitura em `clinical_reads`; o prontuário abre pelo atendimento ou pelo acesso de emergência. Os episódios só ficavam "aguardando" até a 4.3.
- **4.3 (atendimento de urgência)** (PR #41):
  - O atendimento (`visits`) nasce de um agendamento **ou** de um episódio de urgência: exatamente um dos dois (restrição no banco), `emergency_episode_id` único. O gatilho de imutabilidade do atendimento passou a comparar com `IS DISTINCT FROM` (com coluna anulável, `<>` deixaria passar a troca de origem).
  - "Chamar e atender" na fila: profissional com habilitação vigente na unidade, só paciente já classificado e aguardando. Lock no episódio: duas chamadas resultam num atendimento só (o mesmo profissional cai nele; outro recebe "já está em atendimento com ..."). Evolução, adendo, documentos e trilha de leitura são os da entrega 3, sem mudança; o atendimento aparece em "Meus atendimentos" (Urgência em andamento).
  - Episódio: aguardando → em atendimento → alta da urgência, pedido de internação ou evasão, com horário e autor (`ended_at`, `ended_by_user_id`, restrição no banco). O atendimento de urgência só encerra com desfecho e com os rascunhos assinados. Evasão pela fila só para quem ainda aguarda (recepção ou profissional da unidade); em atendimento, a evasão é o desfecho registrado por quem atende. Auditoria `emergency.ended` com o desfecho, só ids.
  - Um episódio aberto por pessoa passa a incluir "em atendimento" e "aguardando internação". A fila mostra quem aguarda (por classificação, nível e espera), depois em atendimento (com o nome de quem atende) e aguardando internação; o tempo-alvo só conta enquanto aguarda.
  - A migration converteu os episódios `closed` do banco local em `discharged`. A primeira tentativa no banco local falhou (índice antigo ainda ativo durante a conversão, vários episódios do mesmo paciente); corrigido e reaplicado, ida e volta testada. O `down` recusa voltar se já existir atendimento de urgência (atendimentos nunca são apagados).
  - Corrida real (6 rodadas): duas chamadas simultâneas do mesmo paciente, sempre um atendimento e um evento `visit.opened`; chamada × evasão, uma vence e a outra é recusada com mensagem.
  - Navegador (1440 e 390 px): chegada, classificação, chamar e atender, rascunho, encerramento recusado sem assinatura, assinatura, fila da recepção com "Em atendimento com ...", encerramento com pedido de internação; sem erro de JavaScript ou 5xx, sem rolagem horizontal, um `h1`.
  - Revisão de segurança independente: sem crítico ou alto. Decisões do usuário (18/10/2026): a recepção vê o nome de quem atende; quem tem a habilitação vencida no meio do atendimento ainda o encerra (igual ao ambulatório), mas não chama nem classifica.
  - Limite até a 4.4: no pedido de internação, o atendimento da urgência encerra e o prontuário deixa de abrir até a internação (setor pedido e médico responsável entram na 4.4).
- **4.4a (pedido de internação e alocação de leito; a 4.4 foi dividida em 4.4a e 4.4b)** (PR #42):
  - O pedido nasce no encerramento do atendimento de urgência com o desfecho "Pedido de internação": setor de internação da unidade (setor com leitos ativos), médico responsável (habilitação aprovada e vigente na unidade, com conta ativa; o próprio profissional vem marcado) e "precisa de isolamento". `admissions` é clínica (RLS).
  - Tela "Internações" (papel Gestão de leitos da unidade): pedidos aguardando leito e internados, só com identificação (nome, sexo, idade, setor pedido, responsável, isolamento). Cada leito livre mostra o que fica fora da regra para aquele paciente; fora da regra de sexo ou de isolamento, a alocação exige justificativa, guardada só na tabela clínica (a auditoria `admission.admitted` leva só ids e a marca "fora da regra").
  - Alocar: lock no pedido, leito livre → ocupado por atualização condicional (com linha em `bed_events`), internação `admitted` e o episódio de urgência sai da fila (`admitted`).
  - Banco: uma internação ativa por paciente e um paciente por leito (índices únicos parciais); a unificação recusa dois cadastros com internação ativa, e o pedido confere todos os cadastros do mesmo prontuário.
  - Corrida real (6 rodadas, alternando a ordem): duas pessoas da gestão de leitos alocando o mesmo leito a dois pacientes; sempre um internado e o outro recusado com mensagem, um evento "ocupado" por rodada.
  - Navegador (1440 e 390 px): pedido com isolamento, aviso de leito fora da regra, alocação recusada sem justificativa e aceita com ela; sem erro de JavaScript ou 5xx, sem rolagem horizontal.
  - Revisão de segurança independente: sem crítico ou alto. Corrigido: unificação com duas internações ativas; responsável com conta revogada; pedido simultâneo dava erro 500. Aceito: os internados continuam listados para a gestão de leitos até a alta (4.7).
  - Ainda não: acesso da equipe do setor ao prontuário e evolução de internação (4.4b, ADR-0004). O `down` da migration apaga as internações e não libera os leitos (só para desenvolvimento).
- **4.4b (acesso da equipe do setor e evolução de internação, ADR-0004)** (PR #43):
  - `ClinicalAccess` ganhou a terceira base, **internação ativa**: o médico responsável lê e escreve desde o pedido; quem tem habilitação aprovada e vigente (data no fuso da unidade) no setor do leito atual lê e escreve enquanto o paciente estiver internado lá. Avaliada a cada requisição; cada leitura vai para `clinical_reads` com o motivo `admission` e a internação.
  - Tela "Internados" (menu de quem tem conta de profissional): os internados nos setores da pessoa e aqueles de quem ela é responsável, só com identificação (sem leitura gravada, esclarecido na ADR-0004). "Prontuário" abre a internação: evoluções assinadas com adendos, documentos (download pela internação, gravado na trilha) e a evolução da internação, um rascunho por autor (índice no banco), assinado com a senha como na entrega 3. O botão "Prontuário" da ficha do paciente leva à internação quando há essa base.
  - Banco: a evolução pertence a um atendimento, a uma internação ou é adendo (exatamente um, `num_nonnulls`); o gatilho de imutabilidade passou a guardar também a internação. O `down` recusa voltar se já houver evolução ou leitura de internação.
  - A evolução e o adendo foram extraídos para `resources/js/components/clinical-notes.tsx`, usado pelo atendimento e pela internação (sem mudança de comportamento no atendimento).
  - Corrida real (6 rodadas, um paciente novo por rodada): duas abas encerrando a urgência com pedido de internação geram uma internação e um evento; duas abas criando a evolução da internação geram um rascunho. A corrida achou um defeito corrigido: o rascunho de evolução da internação era tratado como adendo e travava o encerramento de outro atendimento do mesmo paciente (e aparecia nele); teste de regressão falhava antes e passa depois.
  - Navegador (1440 e 390 px): lista, prontuário da internação, rascunho e assinatura; a gestão de leitos recebe 403 em "Internados"; sem erro de JavaScript ou 5xx, sem rolagem horizontal.
  - Revisão de segurança independente: sem crítico ou alto. Corrigido: o acesso é conferido de novo depois do lock ao salvar a evolução. Registrado: rascunhos abertos na alta (a decidir na 4.7); a lista "Internados" sem leitura gravada.
  - **Documento na internação** (PR #44): quem tem a base da internação anexa PDF, JPG ou PNG na tela da internação, com as mesmas regras da 3.7 (tipo pelos bytes, 10 MB, até 20 por internação, disco privado). O banco exige exatamente uma origem (atendimento ou internação); a regra de imutabilidade da 3.7 já cobre a coluna nova. Retirar: só quem anexou, enquanto ainda tem a base da internação. Navegador (1440 e 390 px): anexo e download com o nome certo, sem erros.
  - Troca de leito e de setor vem na 4.5.
- **4.5 (movimentação interna)** (PR #45):
  - `admission_movements` (clínica, RLS, só de inserção por gatilho): cada alocação, troca de leito ou de setor e troca de médico responsável é uma linha nova; nada é reescrito. A migration copiou as alocações existentes cliente a cliente (com o cliente na sessão, porque o RLS é forçado também para o dono), testado com dados no banco local.
  - Troca de leito (inclusive de setor) pela gestão de leitos na tela "Internações": leito novo livre → ocupado (atualização condicional), leito anterior → em higienização (a higienização da 4.1 o devolve a livre), justificativa fora da regra de sexo ou isolamento; recusada se o leito atual não estiver ocupado. O acesso da equipe acompanha na hora (o setor anterior perde, o novo ganha), porque é calculado pelo setor do leito atual. Auditoria `admission.moved` só com ids.
  - Troca de médico responsável pelo responsável atual, na tela da internação, para outro profissional habilitado na unidade com vínculo e conta ativos (conferido de novo sob lock), com motivo opcional. Quem passou deixa de ler, salvo se for da equipe do setor do leito. Auditoria `admission.responsible_changed`.
  - Histórico de leitos e responsáveis na tela da internação; a gestão de leitos vê só o histórico de leitos (o motivo da troca de responsável pode ter detalhe clínico).
  - Bloqueio e desbloqueio com motivo já existiam (4.1).
  - Corrida real (6 rodadas): duas pessoas da gestão de leitos movendo dois internados para o mesmo leito livre; sempre um vence, o outro é recusado com mensagem, uma linha de histórico.
  - Navegador (1440 e 390 px): troca de leito pela tela, histórico completo, painel de passar a responsabilidade; sem erro de JavaScript ou 5xx.
  - Revisão de segurança independente: sem crítico ou alto. Corrigido: histórico de responsável (com motivo) fora da tela da gestão de leitos; conta desativada deixa de ser opção de responsável (vale também para o pedido de internação); conferência do novo responsável sob lock; histórico tolera profissional ausente; troca recusada se o leito atual não está ocupado. Aceito: passar a responsabilidade ainda no pedido (antes do leito), como no próprio pedido.
- **4.6 (transferência entre unidades da rede)** (PR #46):
  - `admission_transfers` (clínica, RLS): solicitada → aceita (leito reservado no destino) → saída → concluída; recusada ou cancelada com motivo e autor. Uma transferência aberta por internação e uma reserva por leito (índices no banco). A internação ganhou o estado "em trânsito" (sem leito) entre a saída e o recebimento.
  - Pede o médico responsável (na tela da internação: unidade, setor e responsável no destino, habilitado e com conta ativa). A gestão de leitos do destino aceita (reserva leito livre, justificativa fora da regra), recusa com motivo e registra o recebimento; a da origem registra a saída (leito de origem → higienização) e pode cancelar; o responsável atual também cancela antes da saída (o leito reservado volta a livre).
  - No recebimento, a mesma internação passa para o destino (unidade, setor do leito, leito e responsável), com o histórico de leitos e responsáveis; o prontuário segue: o novo responsável e a equipe do setor do destino leem, o médico da origem deixa de ler.
  - Avisos à gestão de leitos das duas unidades em cada passo, sem dado do paciente; auditoria `transfer.*` só com ids.
  - Com transferência aberta, troca de leito e passagem de responsabilidade são recusadas.
  - Corrida real (6 rodadas): saída pela origem × cancelamento pelo médico na mesma transferência; sempre um resultado coerente (a saída venceu em todas as rodadas; o cancelamento recusado com mensagem). A primeira rodada da corrida tinha preparo errado (leito de origem livre) e foi refeita.
  - Navegador (1440 e 390 px): recebimento pela tela "Internações" e pedido de transferência na internação; sem erros.
  - Revisão de segurança independente: sem crítico ou alto. Corrigido: responsável do destino conferido de novo no aceite e no recebimento; setor da internação vem do leito reservado; sem passagem de responsabilidade nem troca de leito com transferência aberta; cancelamento só pelo responsável atual ou pela gestão de leitos da origem; `down` recusa com transferências abertas. Decisão registrada: a gestão de leitos do destino vê nome, sexo e isolamento já no pedido (necessário para escolher o leito).
  - Para a 4.7 (alta): encerrar ou cancelar transferências abertas na alta (senão sobra leito reservado), e prever saída para um paciente "em trânsito" cujo leito reservado deixe de estar disponível (hoje nenhum fluxo muda um leito reservado).
- **4.7 (alta e censo)** (PR #47):
  - Alta pela tela da internação, só pelo médico responsável, com o paciente num leito: melhora, a pedido e transferência (para fora da rede) exigem o **sumário de alta assinado com a senha** (uma evolução da internação, imutável, marcada "Sumário de alta" no prontuário); óbito e evasão registram o horário informado (fuso da unidade, precisão de minuto, nem no futuro nem antes da internação) e o autor. A restrição no banco amarra estado, tipo, horário, autor e sumário.
  - O leito vai de ocupado para higienização (com evento no histórico do leito) e o acesso da equipe termina na hora (a internação sai de `ACTIVE`). A auditoria `admission.discharged` guarda só ids, nunca o tipo (óbito incluído); senha errada gera `admission.discharge_sign_failed` e conta no mesmo limite de tentativas da assinatura.
  - Rascunhos (pendência da ADR-0004): o rascunho do próprio médico precisa ser assinado antes da alta; os de outros profissionais ficam sem assinatura, nunca apagados, e a tela avisa quantos são. Com transferência entre unidades aberta, a alta é recusada até cancelá-la (resolve a pendência da 4.6 sobre leito reservado). Paciente "em trânsito" não recebe alta pela tela.
  - Tela "Censo" (gestor da unidade, administrador de rede e gestão de leitos; não a higienização): por setor e total da unidade, leitos, ocupados, livres, reservados, em higienização, bloqueados, ocupação (ocupados sobre leitos ativos não bloqueados), altas e tempo médio de internação dos últimos 30 dias pelo setor do último leito. Só números; contagens da mesma consulta do mapa de leitos (teste compara as duas).
  - Corrida real (cliente sintético "Rede Corrida Alta", 8 rodadas, dois processos): alta × alta da mesma internação, sempre uma vence e a outra é recusada com mensagem; alta × troca de leito nas duas ordens, sempre coerente (nenhum leito ocupado sobra, um evento de auditoria, um sumário).
  - Navegador (1440 e 390 px): alta por melhora com senha errada e depois certa, óbito pelo celular, censo; sem erro de JavaScript ou 5xx, sem rolagem horizontal da página (a tabela do censo rola dentro do cartão).
  - Corrigido durante os testes: o horário de óbito/evasão era gravado sem fuso (3 horas de deslocamento).
  - Pendente da 4.6, sem fluxo ainda: saída para um paciente "em trânsito" cujo leito reservado deixe de estar disponível.
- **4.8 (revisão integrada da entrega 4)** (PR #48):
  - `tests/Feature/Clinical/InpatientJourneyTest.php`: a jornada inteira num cliente novo, só pelas rotas. Passa por provisionamento, duas unidades, setores e leitos, convites (recepção, gestão de leitos das duas unidades, higienização, gestão e auditoria) e quatro profissionais habilitados por setor. Depois: chegada, classificação, atendimento de urgência com evolução assinada, pedido de internação, alocação, evolução da enfermagem do setor, troca para a UTI (o acesso acompanha o leito), higienização, transferência para a outra unidade (pedido, aceite, saída, recebimento) e alta com sumário assinado. Por fim, confere o mapa de leitos contra o censo nas duas unidades, a trilha de leitura, as notificações sem dado do paciente e a auditoria sem texto clínico, CPF, nome ou tipo de alta.
  - Telas da entrega 4 em seis perfis (médico, recepção, gestão de leitos, higienização, gestão, administração) em 360, 768 e 1440 px: 39 visualizações sem erro de JavaScript ou 5xx, sem rolagem horizontal, com um `h1` e todos os controles com nome. O verificador foi validado antes numa página com esses defeitos plantados.
  - Limites de produção da entrega 4 no README.
  - Revisão de segurança independente da entrega inteira: sem crítico ou alto, sem leitura fora do `ClinicalAccess`, tabelas clínicas completas no RLS, sem vazamento em props, auditoria ou notificações, ordem de locks sem ciclo. Corrigido: primeira leitura simultânea da escala de risco dava 500 (`createOrFirst`); limite de 120 leituras por minuto na internação e no atendimento (a trilha não pode ser inundada). **Decisões de produto pendentes** (no README): saída para pedido de internação sem leito e para paciente em trânsito; transferência já saída cujo responsável no destino perdeu a habilitação; quem pode chamar paciente da fila da urgência; leitura da classificação fora da trilha.
- **4c.5 (revisão integrada da entrega 4c):**
  - Jornada num cliente novo, só por HTTP, na jornada de prescrição: importação do saldo inicial, entrada (vencido recusado), dispensação dividida entre dois lotes, sem saldo → entrada rápida → dispensação, devolução aos lotes, descarte, inventário aprovado pelo gestor, aviso diário e CSV de movimentos conferido com o livro.
  - Varredura de telas da entrega (22 combinações de tela, papel e largura) sem problemas.
  - Revisão de segurança independente: sem crítico ou alto. Corrigido o lote no último dia de validade marcado como vencido na tela de estoque (fuso da unidade); contagem cega documentada como convenção de processo; nome do CSV com a unidade. Pendente de decisão: segunda pessoa ou aviso ao gestor no descarte de controlados.
  - Limites de produção da entrega 4c no README.
- **Depois da 4c.5 (23/10/2026, decisão do usuário: opção A):** cada descarte de controlado avisa os gestores da unidade (sem gestor, os administradores da rede), com medicamento, quantidade e motivo.
- **4c.4c (indicadores, ADR-0007):**
  - Menu "Indicadores de estoque" para farmácia, gestor e administrador da rede (este vê todas as unidades, só consulta): abaixo do mínimo, a vencer em 30 dias, vencidos com saldo e perdas por motivo no período; detalhe da unidade escolhida.
- **4c.4b (relatório de movimentos, ADR-0007):**
  - Menu "Movimentos de estoque" para a farmácia e o gestor: período em dias da unidade, medicamento, só controlados; resumo por medicamento (controlados primeiro) e lista paginada; CSV auditado.
  - Sem dado de paciente: dispensação e devolução só pelo tipo.
- **4c.4a (avisos diários, ADR-0007):**
  - Novo serviço `scheduler` no `compose.yaml` (`schedule:work`); `stock:alerts` de hora em hora, um aviso por unidade e dia a partir das 7 h locais (vencendo em 30 dias, vencidos com saldo, abaixo do mínimo só com lotes válidos, como o selo da tela), para a farmácia e o gestor da unidade.
  - Inventário enviado avisa o gestor; o gestor vê o estoque só para consulta.
  - Corrida real: três execuções simultâneas do comando geram um aviso só.
- **4c.3b (inventário, ADR-0007):**
  - Menu "Inventários" para a farmácia e o gestor da unidade. A farmácia abre um por unidade (todos os medicamentos ou alguns), conta lote por lote às cegas, inclui lote encontrado sem cadastro e envia.
  - O gestor da unidade, que não abriu, contou nem enviou, vê saldo na contagem e diferença e aprova (um ajuste por lote) ou recusa com motivo.
  - O ajuste usa o saldo lido com o lote travado na contagem: o que saiu depois não é ajustado de novo; ajuste que deixaria lote negativo é recusado.
  - Corrida real: aprovar × dispensar e aprovar × aprovar, 6/6 consistentes.
- **4c.3a (descarte manual, ADR-0007):**
  - Na tela de estoque, cada lote tem "Descartar": motivo vencido (só lote vencido, que já vem com o saldo inteiro), avaria, perda ou outro com descrição; quantidade até o saldo.
  - O banco aceita só esses códigos e exige a descrição só em "outro". A auditoria guarda o código, não o texto.
  - Corrida real: descarte × descarte e descarte × dispensação do último saldo, 6/6 consistentes.
- **Travas depois da 4c.2 (23/10/2026, a pedido do usuário):**
  - O banco recusa movimento de dispensação de lote de outra unidade, de outro medicamento ou vencido (fuso da unidade). A leitura da dispensação respeita o RLS: sem contexto clínico, recusa.
  - Devolução para lote que venceu nesse meio-tempo: entra e sai como descarte (motivo `expired`) na mesma transação, e o aviso pede para separar as unidades. O tipo "descarte" entra agora; a tela de descarte segue na 4c.3.
  - A tela de estoque lista todos os descartes da unidade, do mais recente, 20 por página (data, medicamento, lote, quantidade, motivo; sem dado de paciente). O relatório completo de movimentos segue na 4c.4.
- **4c.2 (dispensação e devolução com estoque, ADR-0007):**
  - A dispensação baixa os lotes da unidade: um movimento por lote, ligado só ao id da dispensação. A tela sugere os lotes válidos que vencem primeiro, já preenchidos até a quantidade das doses; a farmácia troca ou divide. Sem lotes enviados, o servidor aplica a mesma sugestão.
  - Recusados no servidor: lote vencido (fuso da unidade), lote de outra unidade ou de outro medicamento, mais que o saldo do lote, soma diferente da quantidade, lote repetido, todos zerados. Sem saldo, a mensagem aponta "Registrar entrada de lote", na própria tela (questão 4).
  - A devolução volta para os lotes que a dispensação usou, até o que cada um deu, começando pela validade mais longa. Dispensação anterior ao estoque devolve sem movimento. O banco recusa devolução para lote que a dispensação não usou ou acima do que ele deu.
  - Locks: internação → item → lotes por id crescente. Corrida real: duas dispensações simultâneas do último saldo de um lote, 5 de 5 rodadas com uma baixa só e saldo 0.
  - Revisão de segurança independente (sem crítico/alto). Corrigido: lotes todos zerados caíam na sugestão; lote repetido; devolução protegida também no banco; testes de lote de outro medicamento, dispensação anterior ao estoque e devolução dividida entre lotes.
- **4c.1b (importação do saldo inicial, ADR-0007):**
  - Tipo de importação "Saldo inicial do estoque da farmácia" (fluxo da V1: prévia sem gravar, confirmação, aplicação retomável), aberto pelo botão da tela Estoque. Colunas: source_id, unidade, princípio ativo, forma, concentração, lote, validade (2027-05-31 ou 31/05/2027), quantidade, fabricante. Cada linha é uma entrada de lote pela mesma regra da tela (`StockController::enter`).
  - Só quem tem o papel Farmácia importa estoque, nas suas unidades; confirma só quem gerou a prévia (o administrador da rede vê, não move). Um farmacêutico sem papel de importação não vê mais profissionais e plantões (não poderia aplicá-los). Na aplicação o papel, o medicamento ativo, a validade e a identidade são conferidos de novo.
  - Reprocessar não entra o saldo de novo: o source_id vale por unidade e origem; o mesmo id com o mesmo conteúdo é "idêntico", com conteúdo diferente é conflito (corrigir saldo é pelo inventário, 4c.3).
  - Navegador (1440 e 360 px): botão do Estoque, tipo já marcado, prévia (1 pronta, 1 recusada), confirmação e saldo na tela; sem erros.
  - Revisão de segurança independente (sem crítico/alto). Corrigido: o mesmo source_id em outra unidade era tratado como já importado (o saldo dela não entrava); "idêntico" não comparava o conteúdo; nome de unidade ambíguo; testes de data inválida (31/02), validade e medicamento mudados depois da prévia, ids por unidade e tipos por papel. Depois do merge, o papel Farmácia do criador passou a ser relido a cada linha: retirá-lo no meio de um arquivo grande para as linhas restantes.
- **4c.1a (lotes, entradas, saldo e estoque mínimo, ADR-0007):**
  - Catálogo: cada medicamento ganha a unidade de estoque (comprimido, ampola, frasco...); os já cadastrados ficam com "unidade" até o administrador ajustar. A importação CSV do catálogo não tem a coluna (fica "unidade").
  - Tela "Estoque" (papel Farmácia, por unidade): saldo por medicamento e lote, vencido, a vencer em 30 dias e abaixo do mínimo. Entrada de lote (medicamento, lote, validade, quantidade, fabricante opcional): o mesmo lote digitado de outro jeito ("ab 12-3" e "AB12-3") soma no mesmo; lote vencido, validade além de 10 anos, medicamento inativo, unidade alheia e fabricante diferente do lote já cadastrado são recusados. Estoque mínimo por medicamento e unidade (vazio retira).
  - Banco: movimento só de inserção; o saldo do lote é mantido por gatilho a partir dos movimentos, com `CHECK (balance >= 0)`; o lote nasce vazio e só muda pelo gatilho do saldo, com a mudança anunciada (depth 2 e valor exato); nada é apagado. O `down` recusa se houver movimentos (conferido no banco local). Estoque fora do RLS: sem dado de paciente.
  - Corrida real (3 processos, a mesma entrada de lote novo, 8 rodadas): sempre um lote, saldo 30 igual à soma dos movimentos (uma rodada inicial falhou no próprio script antes de chegar ao banco).
  - Navegador (1440, 768 e 360 px): menu, lote inválido recusado, entrada, mínimo; sem erros.
  - Revisão de segurança independente (sem crítico/alto). Corrigido: a trava do lote aceitava qualquer saldo vindo de dentro de um gatilho (agora só a mudança anunciada pelo gatilho do saldo); fabricante diferente era descartado em silêncio; saldo em 64 bits; validade com teto de 10 anos; auditoria do mínimo só quando muda; testes de outro cliente e sem papel Farmácia.
- **4b.6 (revisão integrada da entrega 4b):**
  - `tests/Feature/Clinical/PrescriptionJourneyTest.php`: a jornada inteira num cliente novo, só pelas rotas: catálogo, papéis (recepção, leitos, farmácia, auditoria), profissionais com habilitação, internação, alergia, prescrição com alerta justificado assinada com senha e certificado de teste, avaliação e dispensação, administração com dupla checagem, renovação no dia seguinte (a anterior só para devolução), alta encerrando a prescrição. Depois confere a trilha de leitura da farmácia e a auditoria sem nome, CPF, medicamento ou texto clínico.
  - Limite de requisições por rota: o `throttle:X,Y` do Laravel somava todas as rotas limitadas da pessoa num contador só; agora cada rota tem o seu (`ThrottleRequestsPerRoute`). Os limitadores nomeados (login, 2FA, recuperação de senha) e as tentativas de senha (assinatura, segunda pessoa) não mudam.
  - Revisão de segurança independente da entrega inteira (sem crítico). Corrigido, cada um com teste que falha sem a correção:
    - **Alto — transferência entre unidades:** a prescrição ficava presa à unidade de origem (a farmácia da origem seguia avaliando e dispensando; a do destino via na fila e não abria). **Decisão aplicada (opção mais segura, reversível):** a transferência encerra a prescrição na origem, como a alta; a farmácia da origem só recebe devoluções; o médico do destino prescreve de novo (o rascunho copia os itens para revisão); rascunho começado na origem é descartado.
    - O certificado só assina a prescrição válida, por quem ainda prescreve ali (não depois de vencer, da alta ou da transferência).
    - A dupla checagem fica gravada no item ao prescrever: desmarcar "controlado" ou "alto alerta" no catálogo não a retira das prescrições assinadas; item antigo sem a marca lê o catálogo e, sem medicamento, exige a segunda pessoa.
    - Alergia só é inativada por quem a registrou ou por quem prescreve (a inativação desliga alertas).
    - Segunda pessoa: limite de senha também por pessoa, e conferida de novo sob o lock; o registro de administração confere o acesso antes de validar o formulário.
  - Navegador: alergias, prescrição, farmácia, dispensação, administração, certificado e a varredura de telas por perfil (360, 768 e 1440 px), sem erros.
- **4b.5 (assinatura digital da prescrição, ADR-0006, PR #60):**
  - O CPF do profissional passa a ser cadastrado pela administração (validado, único na rede). O médico vincula o assinador uma vez em Configurações › Certificado digital; a credencial fica cifrada e é apagada ao revogar o vínculo ou desativar a conta.
  - Ao assinar com a senha, se houver certificado vinculado, o sistema gera o documento canônico (PDF), assina com o certificado depois do commit e valida antes de guardar: assinatura sobre o PDF, cadeia até as âncoras configuradas e CPF do titular igual ao do profissional. Assinatura recusada vai para a auditoria (motivo `invalid` ou `cpf_mismatch`) e a prescrição continua com a senha; o autor tenta de novo pelo botão "Assinar com certificado digital".
  - A prescrição mostra "Assinatura eletrônica simples (senha)" ou a assinatura digital (assinador, titular, data) com o PDF e o `.p7s` para baixar, com o mesmo acesso dos documentos clínicos (leitura gravada). A assinatura guardada nunca muda (gatilho, RLS).
  - **Só existe o assinador de teste** (AC local, titular "NOME:CPF" como no e-CPF, assinatura CMS destacada), identificado como "certificado de teste (sem validade jurídica)" e recusado em produção. O PSC real continua **BLOCKED_EXTERNAL** (contrato e credenciais); o adaptador real será escrito pela documentação do fornecedor e entregará PAdES.
  - Corrida real: duas assinaturas digitais simultâneas da mesma prescrição gravam uma só, sem arquivo órfão (3 execuções, consistente).
  - Revisão de segurança independente (sem crítico). Corrigido: a validação confiava também no diretório de CAs do sistema, não só nas âncoras configuradas (alto; agora só as âncoras, com teste que falha sem a correção); falha inesperada depois da senha vira aviso, não erro 500; `ICP_SIGNER` inválido desliga a assinatura digital em vez de derrubar as telas clínicas; o PDF de teste traz a faixa "Assinatura de teste: sem validade jurídica" e os avisos dizem "certificado de teste"; o assinador de teste só é aceito em `local` e `testing` (e o `.env.example` vem sem ele); o vínculo é emitido antes de trocar a conta, numa transação; o PDF baixado é conferido pelo hash; documento e credencial marcados como parâmetros sensíveis.
  - Fica para o adaptador real: ler o CPF do campo ICP-Brasil (OID 2.16.76.1.3.1) e conferir uso de chave e política, em vez do nome comum. A tabela de contas não tem RLS de propósito: a desativação da conta roda sem cliente selecionado e precisa apagar as credenciais de todas as redes.
  - Achado na verificação: o limite `throttle:X,Y` do Laravel sem prefixo conta todas as rotas limitadas do mesmo usuário juntas; as rotas do certificado usam um prefixo próprio. As demais rotas do sistema seguem o padrão antigo (registrado para a 4b.6).
  - Navegador: aviso de CPF ausente, vínculo, prescrição assinada com senha e certificado, assinatura exibida, PDF e `.p7s` baixados, em 1440 e 360 px, sem erros.
- **4b.4 (administração pela enfermagem, PR #59):**
  - Só a equipe do setor do leito (habilitação aprovada e vigente para o setor) registra, só na prescrição válida da internação ativa, e só item não suspenso e não recusado pela farmácia. Atendimento de urgência fica fora (não tem equipe de setor).
  - Cada horário é registrado uma vez: administrado (horário real, digitado no relógio da unidade, até 12 horas atrás), recusado pelo paciente ou não realizado, com motivo. O horário abre para registro a partir de 1 hora antes. Item "se necessário" é administrado respeitando o intervalo mínimo. Registros só de inserção.
  - Item não dispensado só como exceção, com motivo registrado. Controlado ou alto alerta: dupla checagem com um colega da equipe do setor, que confirma com a própria senha (tentativas limitadas; senha errada auditada).
  - No prontuário, cada horário mostra o registro (quem, quando, segunda pessoa, exceção, motivo) ou "atrasado" (30 minutos sem registro, calculado, nunca gravado). A lista "Internados" mostra só a contagem de horários atrasados e o próximo horário (sem leitura gravada, sem nome de medicamento).
  - Auditoria só com ids e marcações; o motivo não vai para a auditoria.
  - Corrida real (3 execuções, tudo consistente): dois registros do mesmo horário resultam num só; duas doses "se necessário" simultâneas, numa só; registro × suspensão e registro × alta consistentes.
  - Achados na verificação no navegador: (1) o horário digitado no relógio da unidade era gravado sem conversão para UTC (3 horas antes); (2) bug da 4b.2b: suspensão e novo rascunho no mesmo segundo pareciam simultâneos (colunas arredondadas ao segundo) e o rascunho pedia justificativa de uma suspensão anterior; `prescriptions.created_at` e `prescription_items.suspended_at` agora guardam microssegundos. Os dois têm teste de regressão.
  - Revisão de segurança independente (sem crítico). Corrigido: a senha da segunda pessoa ia para a sessão como "entrada antiga" quando a validação falhava (alto; agora nunca é guardada, com teste); o horário informado não pode ser mais de 1 hora anterior ao previsto; bloqueio de tentativas da senha por par (quem registra e segunda pessoa), para um colega não bloquear a segunda pessoa para todos; dispensação devolvida por inteiro volta a exigir exceção; o banco amarra o horário ao item; a farmácia não carrega registros de administração; contagem da lista só com prescrições válidas.
  - **Decisão de produto pendente:** registro errado (por exemplo, "não realizado" por engano) não tem correção; hoje fica gravado e o horário não pode ser registrado de novo.
  - Navegador: lista com contagens, horário registrado como exceção, registro exibido em 1440 e 360 px, sem erros.
- **4b.3b (dispensação e devolução, PR #58):**
  - Item aprovado (com ou sem observação), não suspenso, da prescrição válida é dispensado uma vez para as 24 horas, com a quantidade em unidades (a tela sugere o número de horários previstos). Item sem avaliação, recusado ou suspenso não é dispensado. O médico e a enfermagem veem "Dispensado: N" no item.
  - Devolução registrada uma vez por dispensação, com quantidade (até a dispensada) e motivo, até 48 horas depois da dispensação, também depois da renovação, do vencimento ou da alta. Essas prescrições aparecem em "Devoluções" na tela da farmácia e abrem só para devolver (leitura gravada na trilha). O banco só aceita a devolução, uma vez; o resto da dispensação nunca muda.
  - Auditoria só com quantidades e vínculos (o motivo da devolução pode ser clínico e não vai para a auditoria).
  - Revisão de segurança independente (sem crítico/alto; acesso por unidade, ordem de locks e auditoria conferidos). Corrigido: consulta N+1 na lista de devoluções; rótulo "Aguardando leito" da fila preservado; dispensação repetida recusada sob o lock antes do banco; testes de validação, outra unidade, prescrição vencida e substituída, item aprovado com observação, trilha de leitura e gatilho (segunda devolução e exclusão).
  - Corrida real (2 execuções): duas dispensações simultâneas do mesmo item resultam numa só; dispensação × suspensão e dispensação × alta consistentes; duas devoluções simultâneas resultam numa só.
  - Navegador: aprovar, dispensar, devolução acima do dispensado bloqueada, devolução registrada, em 1440 e 360 px, sem erros.
- **4b.3a (farmácia: papel e avaliação, PR #57; a 4b.3 foi dividida em 4b.3a e 4b.3b dispensação e devolução):**
  - Papel novo **Farmácia**, por unidade (ADR-0005), concedido como os demais papéis de unidade. Menu "Farmácia" com a fila das prescrições válidas da unidade (internação ativa ou atendimento de urgência aberto): paciente, local, hora da assinatura e itens a avaliar. A fila não grava leitura.
  - Abrir uma prescrição grava leitura na trilha com o motivo "farmácia" e o vínculo com a prescrição. A tela mostra só a prescrição, as alergias e a identificação do paciente; a farmácia não chega ao prontuário nem ao atendimento. Prescrição substituída, vencida ou de atendimento encerrado fica fora de alcance.
  - Cada item é aprovado, aprovado com observação ou recusado com motivo, uma vez (o banco não deixa mudar). Item suspenso não é avaliado. A recusa avisa o médico prescritor (notificação sem paciente nem medicamento). O médico e a enfermagem veem a avaliação no item.
  - Revisão de segurança independente (sem crítico/alto; escopo da ADR-0005 mantido). Corrigido: a avaliação confere de novo, sob o lock do atendimento, se a prescrição ainda é a válida; a farmácia vê a idade, não a data de nascimento; a tela "Acessos ao prontuário" mostrava toda leitura que não fosse de atendimento como "Emergência" (inclusive as de internação, desde a entrega 4) e agora distingue internação e farmácia; limite de requisições na fila.
  - Corrida real: duas avaliações simultâneas do mesmo item resultam numa só; avaliação × suspensão fica consistente.
  - Achado ao migrar o banco local: a regra da trilha de leitura precisa ser recriada como "só para linhas novas" (como desde a 3.6b), porque a trilha só de inserção guarda linhas antigas que regras anteriores permitiam. O CI não pegaria (banco vazio).
  - Navegador: fila, abertura, aprovação com observação e recusa, em 1440 e 360 px, sem erros.
- **4b.2b (renovação, suspensão e aprazamento, PR #56):**
  - Nova prescrição nasce como cópia da última assinada do atendimento ou da internação (decisão 4): sem itens suspensos nem medicamentos desativados; os alertas são recalculados e a justificativa só fica se ainda cobre todos; o item por intervalo mantém a fase (08, 16, 00 continuam 08, 16, 00). Revisada, é assinada de novo.
  - Suspender item da prescrição válida, com motivo, uma vez (o banco só aceita essa alteração num item assinado). Para mudar a dose: suspender e prescrever de novo.
  - Aprazamento na assinatura: horários previstos de cada item (a cada N horas desde o início, ou os horários fixos de cada dia no fuso da unidade) dentro das 24 horas de validade; "se necessário" não tem horário. Horários só de inserção; aparecem no prontuário.
  - Revisão independente (sem crítico/alto). Corrigido: item suspenso depois que o rascunho o copiou vira alerta pendente (não volta em silêncio); a suspensão confere de novo a prescrição válida sob o lock do atendimento; rascunho e itens copiados numa só transação; horários anteriores à assinatura não são aprazados. Regra registrada para a 4b.4: só se administra horário de item não suspenso da prescrição válida.
  - Corrida real: duas suspensões simultâneas do mesmo item resultam numa só (4 rodadas).
  - Navegador: assinatura, suspensão com motivo, nova prescrição avisando o que substitui, horários exibidos.
- **4b.2a (prescrição, PR #55; a 4b.2 foi dividida em 4b.2a e 4b.2b cópia, suspensão e aprazamento):**
  - Quem prescreve: o médico (conselho CRM, habilitação aprovada e vigente na unidade, em função que atende pacientes) com acesso de escrita ao paciente: a internação (ADR-0004) ou o próprio atendimento de urgência aberto. A enfermagem do setor lê a prescrição, não prescreve. O atendimento ambulatorial não tem prescrição (receita para casa está fora da 4b).
  - Seção "Prescrição" no prontuário da internação e do atendimento de urgência: a prescrição válida (assinada há menos de 24 horas; a mais nova substitui a anterior), as anteriores e o acesso ao rascunho.
  - Rascunho do autor (um por atendimento ou internação): itens do catálogo ativo com dose na unidade do medicamento, via cadastrada, intervalo (1 a 24 h) ou horários fixos, "se necessário" com condição e intervalo mínimo, início no fuso da unidade e observação.
  - Alertas ao incluir o item: alergia registrada (chave sem acento), dose acima do máximo por administração, dose do dia acima do máximo por dia e interação com item anterior (indicada uma vez, no item mais novo). Item com alerta exige justificativa. Na assinatura com senha, os alertas são recalculados: alerta novo (por exemplo, alergia registrada depois) bloqueia até ser justificado.
  - Assinada, a prescrição e os itens não mudam (o banco recusa alteração, inclusão e exclusão); o rascunho pode ser descartado. Tabelas clínicas com RLS forçado. Auditoria só com ids e contagens.
  - Revisão independente (sem crítico/alto). Corrigido: a dose máxima por dia soma todos os itens do mesmo princípio ativo, com alerta de princípio ativo repetido; o editor avisa quantos itens a prescrição válida tem, porque assinar substitui o esquema inteiro; retirar item e descartar rascunho usam o mesmo lock da assinatura; o início do item fica entre 1 hora atrás e as próximas 24 horas; justificativa só em item com alerta. Limites conhecidos: rascunho de atendimento encerrado fica parado (sem efeito); doses de um princípio ativo em unidades diferentes não são convertidas.
  - Corrida real (dois processos, duas conexões): duas assinaturas do mesmo rascunho resultam numa só; assinatura × alta nas duas ordens: ou a alta vem antes e a assinatura é recusada, ou a assinatura fica antes da alta.
  - Navegador: nova prescrição, item "se necessário", senha errada recusada, assinatura e prescrição válida no prontuário; sem erro de JavaScript, sem 5xx, sem rolagem horizontal em 360 px, controles com nome.
- **4b.1c (alergias do paciente, PR #54):**
  - Bloco "Alergias" no topo do prontuário (atendimento, internação e acesso de emergência), em destaque quando há alergia ativa: substância, gravidade (leve, moderada, grave, não informada), reação opcional, data e autor.
  - Registra e inativa quem escreve no prontuário (atendimento aberto ou internação, ADR-0004); o acesso de emergência só lê. A substância é texto livre com sugestões dos princípios ativos do catálogo; a chave normalizada (a mesma do catálogo) é o que a prescrição vai conferir na 4b.2.
  - Uma alergia ativa por substância e paciente (índice único parcial). Só de inserção: o banco aceita apenas a inativação, uma vez, com motivo, e nunca apaga. Tabela clínica com RLS forçado. Auditoria só com ids.
  - Revisão independente (sem crítico/alto). Corrigido: a chave ignora acentos ("acido acetilsalicilico" casa com "ácido acetilsalicílico"); a duplicata é conferida em todo o prontuário fundido; dica na tela para usar o princípio ativo, não o nome comercial.
  - Corrida real (dois processos, duas conexões, 3 rodadas): dois registros simultâneos da mesma alergia resultam num só; duas inativações simultâneas, numa só.
  - Navegador: registro, duplicata recusada com mensagem, inativação com motivo e histórico; sem erro de JavaScript, sem 5xx, sem rolagem horizontal em 360 px.
- **#53:** o `npm audit` (alto) passou a falhar o CI por um `launch-editor` embutido no `vite-plus@0.3.3`; atualizado para 1.3.0.
- **4b.1b (importação CSV do catálogo e das interações, PR #52):**
  - Na tela "Nova importação", o administrador de rede (só ele) ganha dois tipos: catálogo de medicamentos (colunas do catálogo; vias separadas por "|"; controlado e alto alerta como sim ou não; doses com vírgula ou ponto, até 3 casas) e interações (dois princípios ativos já no catálogo, gravidade pelo código ou pelo nome em português, orientação). A tela "Medicamentos" tem o atalho para importar.
  - Mesmo fluxo da V1: a prévia não grava nada, a confirmação aplica em segundo plano, e a aplicação pode ser retomada sem duplicar. Apresentação ou par já cadastrado nunca é alterado: com os mesmos valores fica "idêntico"; com valores diferentes, vira "conflito". Linhas repetidas, valores inválidos e texto que começa como fórmula de planilha são rejeitados. A permissão é conferida de novo na confirmação e na aplicação.
  - Corrigido junto: a prévia dizia "cria N profissionais" para qualquer tipo, inclusive plantões.
  - Navegador: importação real pela tela, com o worker aplicando (2 criadas, 1 rejeitada com o motivo).
  - Revisão independente (sem crítico/alto). Corrigido: dose como "1.000" ou "2,500" é recusada como ambígua (milhar ou decimal?), em vez de ser lida mil vezes menor; linha com mais campos que o cabeçalho recusa o arquivo (antes o texto com ";" sem aspas era cortado em silêncio; vale para todas as importações); unidade de dose aceita maiúsculas e minúsculas; orientação da interação normalizada igual na tela e no CSV.
- **4b.1a (catálogo de medicamentos e regras de segurança, PR #51; a 4b.1 foi dividida em 4b.1a, 4b.1b importação CSV e 4b.1c alergias):**
  - Tela "Medicamentos" (administrador de rede): apresentações com princípio ativo (DCB), forma, concentração, unidade de dose, vias permitidas, marcação de controlado e de alto alerta, dose máxima por administração e por dia (opcionais). A apresentação é única por princípio ativo, forma e concentração, sem diferenciar maiúsculas nem espaços. Desativa em vez de excluir. Busca por princípio ativo, até 100 resultados.
  - Interações entre **princípios ativos** (valem para todas as apresentações), com gravidade (leve, moderada, grave, contraindicada) e orientação ao prescritor. Cada par fica gravado uma vez, em ordem fixa (restrição no banco), e só com princípios ativos do catálogo. Desativa em vez de excluir.
  - Sem dado de paciente: tabelas da rede, fora do RLS. A auditoria guarda os campos alterados e os valores das regras (`medication.*`, `medication_interaction.*`).
  - A tela avisa que só gera alerta o que estiver cadastrado.
  - Navegador (administrador, 360, 768 e 1440 px): cadastro de duas apresentações, recusa da dose diária menor que a por administração, interação grave e busca; sem erro de JavaScript ou 5xx, sem rolagem horizontal. A primeira versão do roteiro enviava o segundo formulário enquanto a tela ainda era limpa pelo primeiro; o roteiro foi corrigido e o resultado conferido no banco.
  - Corrigido pelos testes: dose máxima por dia era recusada com a dose por administração vazia; valores decimais apareciam como alterados sem mudança.
  - Revisão independente (sem crítico/alto). Corrigido: o princípio ativo não muda na edição (as interações apontam para ele; para mudar, desativa-se e cadastra-se outra apresentação); dose com mais de 3 casas é recusada (antes dava 500); busca com valor que não é texto é ignorada; textos normalizados (espaços e acentos em NFC); contagem de interações com total; formulários de edição atualizados depois de salvar.
- **Entrega 4b (prescrição e farmácia) em definição** (19/10/2026): contrato no PRODUCT.md, seção "Contrato da entrega 4b". Decisões do usuário (todas as propostas aceitas): ciclo completo (prescrição, avaliação farmacêutica, dispensação e administração), dispensação por 24 horas com devolução, estoque numa entrega 4c, prescrição válida por 24 horas e renovada por cópia revisada, alergia com aviso e justificativa, dupla checagem para controlados e alto alerta, prescrição só por função que atende e com CRM. Riscos tratados (19/10/2026): tabela da rede de doses máximas e interações, com alerta e justificativa; prescrição assinada com ICP-Brasil em nuvem ([ADR-0006](adr/0006-icp-brasil-cloud-signature.md)), com fornecedor e credenciais ainda pendentes (bloqueio externo). [ADR-0005](adr/0005-pharmacy-prescription-access.md), ADR-0006 e plano `plans/web-v4-prescricao.md` (4b.1 a 4b.6) **aceitos; implementação autorizada em 19/10/2026**.
- **4.9 (pendências da revisão de segurança da 4.8; o usuário mandou seguir com as recomendações em 19/10/2026):**
  - Alta também para quem aguarda leito ou está em trânsito, pelo médico responsável. O episódio da urgência é encerrado (evadido na evasão, alta nos demais tipos) e a transferência já saída é cancelada com o leito reservado de volta a livre (auditoria `transfer.cancelled`). As restrições no banco foram ajustadas: internação com alta pode não ter leito nem data de internação, e transferência cancelada pode ter saída registrada. O censo ignora altas sem leito.
  - Recebimento de transferência: a gestão de leitos do destino escolhe o responsável (vem preenchido com o do pedido), conferido como habilitado no destino sob o lock. Os erros dos passos da transferência passaram a aparecer na tela "Internações" (antes ficavam invisíveis, defeito da 4.6).
  - Funções com a marca "atende pacientes" (padrão: sim). Só elas chamam da fila da urgência, porque chamar abre o prontuário; classificar continua igual. O campo aparece na tela "Funções".
  - ADR-0004: a classificação na fila fica fora da trilha de leitura (decisão de 18/10 mantida).
  - Corrida real (6 rodadas, "Rede Corrida Alta"): alta em trânsito (óbito) × recebimento no destino. Óbito primeiro: transferência cancelada, leito do destino livre, recebimento recusado com mensagem. Recebimento primeiro: internado no destino e alta depois, leito em higienização. Sempre coerente.
- **Entrega 4 (urgência, internação e leitos) em definição** (18/10/2026): contrato no PRODUCT.md, seção "Contrato da entrega 4". Decisões do usuário: jornada primeiro (prescrição e farmácia na entrega 4b), escala de risco própria de 5 níveis (sem Manchester), papel Gestão de leitos, transferência dentro da rede; acesso na internação pelo médico responsável e pela equipe do setor ([ADR-0004](adr/0004-ward-team-record-access.md), aceita); "Não identificado" unificável depois; compatibilidade do leito com aviso e justificativa; sumário de alta obrigatório exceto óbito e evasão; tempo-alvo com destaque visual; censo diário. Plano em `plans/web-v3-internacao.md` (3.8, depois 4.1 a 4.8). ADR-0004 e plano aceitos e **implementação autorizada** pelo usuário (18/10/2026), uma etapa por vez, começando pela 3.8.

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

Último resultado: 462 testes passando (5b.1b), PHPStan sem erros, lint e TypeScript limpos.

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
