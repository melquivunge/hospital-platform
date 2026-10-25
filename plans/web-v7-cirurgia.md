# Plano — entrega 5b: cirurgia

Data: 24/10/2026. Estado: **aceito; implementação autorizada pelo usuário em 24/10/2026, uma etapa por vez.** Uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização do usuário.
Contrato: ../PRODUCT.md, seção "Contrato da entrega 5" (decisões do usuário de 23/10/2026, questões 6 a 9). Arquitetura: ../docs/adr/0009-surgical-center-access-and-materials.md, ../docs/adr/0007-pharmacy-stock-ledger.md e ../docs/adr/0008-exam-team-record-access.md. Regras de trabalho: ../AGENTS.md.

## Princípios herdados

- Cirurgia, equipe, checklist, termo e descrição são clínicos: RLS forçado, leitura na trilha, nada clínico em logs, notificações e auditoria (só ids e marcadores).
- Assinatura com senha no pedido, na descrição e no adendo, até o ICP-Brasil real.
- Descrição assinada e etapa do checklist confirmada nunca mudam (gatilho); correção por adendo.
- Locks e corrida real em toda disputa (agendar a mesma sala, confirmar a mesma etapa, consumir o último saldo).
- Testes negativos de acesso: outra unidade, fora da equipe, cirurgia encerrada, papel revogado.
- Telas verificadas em 360 e 1440 px; revisão de segurança independente em toda etapa.

## Etapas

### 5b.1 Catálogo, salas e papel
- **Procedimentos da rede** (administrador): nome, código TUSS/SIGTAP opcional, duração prevista e porte; importação CSV pelo mecanismo existente.
- **Salas por unidade** (administrador): nome e ativa ou inativa.
- **Papel Centro cirúrgico**, por unidade, na tela de pessoas.
- **Saída:** unicidade e validação; papel com escopo de unidade.
- Divisão (24/10/2026): 5b.1a procedimentos, salas e papel; 5b.1b importação CSV dos procedimentos.
- **Feito na 5b.1a:** tela "Procedimentos e salas" do administrador da rede: procedimentos (nome único na rede, duração prevista de 5 a 1440 minutos, porte pequeno, médio ou grande, código TUSS/SIGTAP opcional) e salas por unidade (nome único na unidade; a sala nunca muda de unidade). Desativados, nunca apagados; o banco confere os valores. Papel Centro cirúrgico por unidade, concedido na tela de pessoas. Auditoria só com ids e campos alterados.
- **Feito na 5b.1b:** importação CSV dos procedimentos pelo mecanismo existente (`procedure_name;duration_minutes;size;code`, porte em português ou inglês), só pelo administrador da rede: prévia sem gravar, confirmação, aplicação retomável; procedimento existente nunca muda (idêntico ou conflito). As salas continuam na tela, por serem poucas.

### 5b.2 Pedido de cirurgia
- O profissional que atende pede no atendimento, na urgência ou na internação: procedimento, lateralidade quando houver, indicação, caráter (eletiva ou urgência), data desejada e assinatura com senha.
- Cancelamento por quem pediu, com motivo, antes do início.
- O pedido aparece no prontuário.
- **Saída:** pedido só com acesso clínico válido; nada clínico na auditoria.
- **Feito na 5b.2:** o prontuário do atendimento e da internação ganha o botão "Cirurgias", ao lado de "Exames", que abre os pedidos daquele encontro. Pedido com procedimento do catálogo (nome gravado como pedido), lateralidade sempre explícita ("não se aplica", esquerdo, direito ou bilateral), caráter, data desejada (de hoje até um ano, no fuso da unidade) e indicação, assinado com a senha. O pedido trava o atendimento ou a internação; corrida real contra a alta: o pedido nasce antes da alta ou recebe 403, nunca numa internação encerrada. O autor cancela com motivo, também depois do fechamento do encontro (corrida real de dois e três cancelamentos: um só). Auditoria só com unidade e caráter. Um pedido que fica aberto depois da alta continua "pedido" (como os exames); a 5b.3 decide o acesso por ele.

### 5b.3 Agenda, equipe e acesso (ADR-0009)
- Agenda da unidade por sala e dia; o centro cirúrgico agenda sala, início e duração e escala a equipe (cirurgião obrigatório).
- Restrição de exclusão contra sobreposição na sala; reagendamento e cancelamento com motivo.
- Urgência: agendada na hora, com motivo, numa sala livre (a eletiva que ocupa a sala é reagendada antes).
- Base "cirurgia" no `ClinicalAccess`, com o motivo `surgery` na trilha; a agenda não grava leitura.
- Cirurgia agendada com horário vencido há mais de 24 horas aparece para o gestor (risco da ADR-0009).
- **Saída:** corrida real em agendar a mesma sala e horário; acesso termina com a conclusão ou o cancelamento (teste).
- Divisão (25/10/2026): 5b.3a agenda, sala, equipe, reagendamento e cancelamento; 5b.3b base "cirurgia" no prontuário e trilha; 5b.3c cirurgia agendada vencida há 24 horas ao gestor.
- **Feito na 5b.3a:** tela "Agenda cirúrgica" do papel Centro cirúrgico: as salas do dia (horário, paciente, procedimento, lado e cirurgião) e os pedidos aguardando, urgentes primeiro, sem a indicação clínica e sem gravar leitura. Na cirurgia: sala ativa da unidade, início no fuso da unidade e duração (padrão: a do procedimento), cirurgião obrigatório e anestesista opcional. Eletiva só no futuro (até um ano); urgência com motivo, podendo começar até 30 minutos antes de agora. Reagendamento com motivo guarda o agendamento anterior; cancelamento pelo centro com motivo; o autor do pedido também cancela a cirurgia agendada. Equipe: cirurgião, auxiliar, anestesista, instrumentação e circulante, só profissionais com habilitação vigente na unidade; retirada guarda a linha; o último cirurgião não sai. O banco recusa duas cirurgias agendadas na mesma sala com horários sobrepostos (restrição de exclusão), e a sala é travada antes de agendar. Corrida real: três agendamentos da mesma sala e horário, um só; agendar contra o cancelamento do autor, consistente nos dois sentidos; incluir a mesma pessoa duas vezes, uma; retirar os dois cirurgiões ao mesmo tempo, um fica; dois reagendamentos trocando de sala ao mesmo tempo, os dois passam (antes da correção da revisão, um caía em impasse). Uma cirurgia agendada com horário vencido continua cancelável e reagendável, porque pode não ter acontecido; a primeira etapa do checklist (5b.4) a põe em andamento e fecha isso.
- **Feito na 5b.3b:** base "cirurgia" no acesso ao prontuário: o Centro cirúrgico da unidade e a equipe ativa leem o prontuário enquanto a cirurgia está pedida ou agendada (a equipe só existe depois do agendamento). Tela "Prontuário para a cirurgia" com a cirurgia (indicação, lado, sala, horário e equipe), alergias, prescrição só para leitura, resultados de exames, evoluções assinadas e documentos; cada abertura grava uma linha na trilha com o motivo `surgery` e a cirurgia. "Minhas cirurgias" lista as cirurgias abertas em que o profissional está na equipe, sem gravar leitura; a agenda ganha "Abrir prontuário". Documentos e laudos em PDF baixam pela mesma base. O acesso termina com o cancelamento ou a retirada da equipe (testes). Revisão de segurança: um pedido ainda não agendado só abre o prontuário até 7 dias depois da data desejada (mais restrito que a ADR-0009, para que um pedido esquecido não dê acesso para sempre), e a 5b.3c leva ao gestor também o pedido parado; a equipe precisa continuar com habilitação vigente na unidade; vínculo revogado encerra o acesso (testes).
- **Feito na 5b.3c:** o comando `surgeries:overdue` roda de hora em hora no `scheduler`. Uma vez por dia de cada unidade, a partir das 7 h, ele avisa os gestores (ou, sem gestor, os administradores da rede) quantas cirurgias seguem agendadas mais de 24 horas depois do fim previsto (conta pelo fim, para não acusar uma cirurgia longa ainda em curso) e quantos pedidos não foram agendados 7 dias depois da data desejada. A tela "Cirurgias paradas" mostra quando, sala, local e quem pediu, sem paciente nem procedimento. Três execuções simultâneas geraram um único aviso por dia (corrida real).

### 5b.4 Checklist de cirurgia segura e termo
- As três etapas da OMS (antes da anestesia, antes da incisão, antes da saída da sala), com os itens fixos da lista da OMS; cada etapa grava quem confirmou e quando.
- Termo de consentimento assinado anexado (PDF, JPG ou PNG, conferido pelo conteúdo) antes de confirmar a primeira etapa; urgência sem termo com motivo.
- Confirmar a primeira etapa põe a cirurgia em andamento.
- **Saída:** etapa confirmada imutável no banco; ordem das etapas garantida; corrida real em confirmar a mesma etapa.
- **Feito na 5b.4:** na tela "Prontuário para a cirurgia", o Centro cirúrgico da unidade ou a equipe ativa (com habilitação vigente) anexa o termo assinado (PDF, JPG ou PNG, conferido pelo conteúdo; um novo substitui o anterior, nenhum muda) ou, só na urgência, registra o motivo de seguir sem termo. Depois confirma as três etapas da lista da OMS, na ordem e uma vez cada, respondendo cada item fixo (confirmado, sim ou não, ou "não se aplica" onde cabe), sem senha; grava quem e quando. A primeira etapa exige o termo e põe a cirurgia em andamento: a partir daí não há mais cancelamento, reagendamento nem troca de termo, a sala continua ocupada e a equipe pode mudar. O banco recusa alterar ou apagar etapa e termo, etapa fora de ordem, primeira etapa sem termo e dispensa de termo em eletiva (gatilho). O termo baixa com registro na trilha. Corrida real: duas e três pessoas confirmando a mesma etapa, uma só; primeira etapa contra cancelamento, consistente nos dois sentidos. Revisão: o gatilho também exige o status certo (termo só antes do início; primeira etapa só agendada, as outras só em andamento); até 5 termos por cirurgia; o `down()` confere com `row_security` desligado e recusa se houver termo ou etapa. Depois da terceira etapa a cirurgia segue em andamento até a descrição assinada (5b.5): o cirurgião precisa do prontuário para descrever, e o aviso de cirurgia parada ao gestor cobra a descrição que falta.

### 5b.5 Descrição cirúrgica
- O cirurgião da equipe registra a descrição (achados, técnica, intercorrências, peças enviadas) e assina com senha; com as três etapas confirmadas, a cirurgia fica concluída.
- Adendo assinado depois, com o original visível.
- Quem pediu é avisado da conclusão, sem dado clínico no texto.
- **Saída:** descrição imutável no banco; só o cirurgião escalado assina.
- **Feito na 5b.5:** na tela "Prontuário para a cirurgia", depois das três etapas do checklist, um cirurgião da equipe ativa (com habilitação vigente na unidade) assina com a senha a descrição: achados, técnica, intercorrências e, opcionais, peças enviadas. A assinatura conclui a cirurgia: o acesso do centro cirúrgico e da equipe ao prontuário termina, a sala fica livre e quem pediu é avisado ("Uma cirurgia que você pediu foi concluída", com link para o prontuário, sem dado clínico). Por 30 dias os cirurgiões da equipe abrem a cirurgia, sem o prontuário, para assinar adendos com a senha; o original continua visível e quem pediu também é avisado do adendo. A descrição e os adendos aparecem no prontuário de quem atende (ambulatório, urgência, internação, acesso de emergência, exames e cirurgia). O banco recusa alterar ou apagar a descrição, uma segunda descrição na mesma cirurgia, descrição antes da terceira etapa e adendo antes da conclusão (gatilho e índice único); cirurgia concluída exige a hora da conclusão. Até 20 adendos por descrição. A agenda do dia mostra as cirurgias em andamento e concluídas. Corrida real: o cirurgião assinando duas e três vezes ao mesmo tempo, uma só descrição e um só aviso; três adendos simultâneos, os três gravados. Revisão: "Minhas cirurgias" só lista a concluída enquanto o adendo é possível (habilitação vigente); quem pediu não é avisado da própria assinatura; a visão restrita não mostra a indicação; testes de cirurgião retirado da equipe, habilitação vencida e limite de adendos. Depois da revisão (branch `fix/surgery-completion-guard`): um gatilho em `surgeries` só aceita "concluída" com a descrição assinada e recusa alterar ou apagar uma cirurgia concluída; o prontuário busca os nomes dos autores das descrições numa única consulta; teste de um profissional de outro cliente (a cirurgia não é encontrada).

### 5b.6 Materiais no livro de estoque
- Catálogo de materiais da rede e importação CSV.
- `stock_lots` com medicamento ou material; entradas, descarte, inventário, avisos e relatório da farmácia passam a incluir materiais.
- Consumo na cirurgia pelo centro cirúrgico (movimento `surgery`) e estorno (`surgery_return`) até o consumido.
- **Saída:** saldo nunca negativo sob corrida real; consumo só de lote válido da unidade da cirurgia.
- Divisão (25/10/2026): 5b.6a catálogo de materiais e importação CSV; 5b.6b lotes de material na farmácia (entradas, descarte, inventário, avisos, relatório e importação de saldo); 5b.6c consumo e estorno na cirurgia.
- **Feito na 5b.6a:** na tela "Procedimentos, salas e materiais", o administrador da rede cadastra materiais (nome único na rede sem distinção de maiúsculas, unidade de estoque de uma lista fixa, código opcional), com busca própria; desativados, nunca apagados; o banco confere os valores. Importação CSV pelo mecanismo existente (`material_name;stock_unit;code`), só pelo administrador da rede: prévia sem gravar, confirmação, aplicação retomável; material existente nunca muda (idêntico ou conflito). Auditoria `material.*` só com ids, unidade de estoque e campos alterados.
- **Feito na 5b.6b:** o livro de estoque passa a guardar materiais: lote, mínimo e linha de inventário são de um medicamento ou de um material, nunca dos dois (CHECK no banco). A farmácia registra entrada, descarte e mínimo de material na tela "Estoque" (medicamentos primeiro, depois materiais), conta materiais no inventário (inclusive lote encontrado sem cadastro), e o relatório de movimentos, os indicadores e o aviso diário incluem materiais. A dispensação continua só de lote do medicamento (o gatilho da 4c compara o medicamento). Corrida real: dois descartes de todo o saldo de um lote de material ao mesmo tempo (um vence, saldo 0) e duas entradas do mesmo lote novo (um lote só, as duas quantidades). Fica para a 5b.6b2: importação de saldo inicial de materiais por CSV (o arquivo atual identifica o medicamento por princípio ativo, forma e concentração).
- **Feito na 5b.6b2:** importação do saldo inicial de materiais por CSV (`source_id;unit;material_name;lot_code;expires_on;quantity;manufacturer`), só pela farmácia da unidade, pelo mesmo fluxo e regras do saldo de medicamentos (prévia, confirmação só por quem enviou, papel conferido de novo na aplicação, lote vencido recusado, reprocessar não entra duas vezes, fabricante diferente é conflito). O material é achado pelo nome do catálogo; os ids de origem ficam separados dos de medicamentos.
- **Feito na 5b.6c:** na tela da cirurgia do centro cirúrgico, a seção "Materiais": o centro cirúrgico da unidade registra o consumo de material (movimento `surgery`) só de lote de material válido da unidade da cirurgia, com a cirurgia em andamento ou até 30 dias depois da conclusão (o consumo costuma ser lançado depois), e corrige pelo estorno (`surgery_return`) até o que a cirurgia tirou daquele lote; o que volta para lote vencido é descartado na hora. O banco garante o mesmo (gatilho) e o saldo nunca negativo. O movimento guarda só o id da cirurgia; o relatório de movimentos mostra "Consumo em cirurgia" e "Estorno de cirurgia" sem nada que leve ao paciente. Corrida real: dois consumos de todo o saldo ao mesmo tempo (um vence, saldo 0) e dois estornos de tudo ao mesmo tempo (um vence, nada volta duas vezes).

### 5b.7 Revisão integrada
- **Jornada num cliente novo:** pedido eletivo na internação → agenda e equipe → termo → checklist → descrição → materiais; urgência sem termo, com motivo; acesso encerrado depois da conclusão.
- Verificação das telas, revisão de segurança independente e limites de produção no README.

## Fora deste plano

- Ficha anestésica completa e sinais vitais intraoperatórios.
- Medicamentos consumidos na cirurgia pelo estoque (seguem pela prescrição da 4b).
- Faturamento, OPME e autorização de convênio (entrega 6).
- Integração com equipamentos e mapa cirúrgico em painel de TV.

## Pendências resolvidas (24/10/2026, decisões do usuário)

1. **Quem lê o prontuário:** o centro cirúrgico da unidade e a equipe escalada, enquanto a cirurgia estiver pedida, agendada ou em andamento.
2. **Quem confirma o checklist:** quem tem o papel Centro cirúrgico na unidade ou está na equipe escalada daquela cirurgia.
3. **Estoque de materiais:** mantido pela farmácia da unidade (entradas, descartes e inventário); o centro cirúrgico registra o consumo e o estorno.
