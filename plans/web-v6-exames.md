# Plano — entrega 5: exames (laboratório e imagem)

Data: 23/10/2026. Estado: **aceito pelo usuário e implementação autorizada em 24/10/2026.** Uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização do usuário.
Contrato: ../PRODUCT.md, seção "Contrato da entrega 5" (decisões do usuário de 23/10/2026). Arquitetura: ../docs/adr/0008-exam-team-record-access.md, ../docs/adr/0003-rls-on-clinical-tables.md e ../docs/adr/0004-ward-team-record-access.md. Regras de trabalho: ../AGENTS.md. A cirurgia é a entrega 5b, em plano próprio.

## Princípios herdados

- Pedidos, resultados e laudos são clínicos: RLS forçado, leitura na trilha, nada clínico em logs, notificações e auditoria (só ids e marcadores).
- Assinatura eletrônica simples (senha) no pedido e na liberação do resultado, até o ICP-Brasil real.
- Resultado liberado nunca muda: retificação cria nova versão, com a anterior visível.
- Locks e corrida real em toda disputa (liberar o mesmo resultado, coletar o mesmo pedido).
- Testes negativos de acesso: outra unidade, outro tipo de exame, pedido encerrado, papel revogado.
- Telas verificadas em 360 e 1440 px; revisão de segurança independente em toda etapa.

## Etapas

### 5.1 Catálogo de exames e papéis
- **Catálogo da rede** (administrador): nome, tipo (laboratório ou imagem), material ou região, preparo, código TUSS/SIGTAP opcional e, para laboratório, analitos com unidade e faixa de referência (texto e mínimo/máximo opcionais).
- Importação CSV do catálogo pelo mecanismo existente.
- **Papéis novos Laboratório e Imagem**, por unidade, concedidos na tela de pessoas.
- **Saída:** catálogo com testes de unicidade e de validação; papéis com escopo de unidade (CHECK existente).
- Divisão (24/10/2026): 5.1a catálogo, analitos e papéis; 5.1b importação CSV do catálogo.

### 5.2 Pedido de exame
- O profissional que atende (função que atende e habilitação na unidade) pede exames no atendimento ambulatorial, na urgência ou na internação: itens do catálogo, indicação clínica, prioridade (rotina ou urgente) e assinatura com senha.
- Cancelamento por quem pediu, com motivo, antes da coleta.
- O pedido aparece no prontuário do paciente.
- **Saída:** pedido só com acesso clínico válido (testes negativos); assinatura conferida; nada clínico na auditoria.
- **Feito (24/10/2026):** o prontuário do atendimento e da internação ganha o botão "Exames", que abre os pedidos daquele encontro. O pedido trava o atendimento ou a internação, para não nascer num encontro que acabou de fechar. O autor pode cancelar depois do fechamento, mas por ora a tela só abre com o encontro ativo; a 5.3 mostra os pedidos abertos na fila.

### 5.3 Fila e execução (ADR-0008)
- Filas de Laboratório e de Imagem por unidade, ordenadas por prioridade e espera, com tempo-alvo em destaque (como a classificação de risco); a fila não grava leitura.
- Enquanto o pedido está aberto, a equipe abre o prontuário com a base "execução de exame", gravando cada abertura na trilha com o motivo `exam`.
- Registro da coleta (laboratório) ou da realização (imagem), com horário e quem fez.
- Pedido sem coleta há 7 dias aparece para o gestor da unidade (risco da ADR-0008, decisão de 24/10/2026).
- **Saída:** acesso termina com o resultado ou o cancelamento (teste); corrida real em coletar o mesmo pedido.
- Divisão (24/10/2026): 5.3a filas, execução e leitura do prontuário; 5.3b pedido sem coleta há 7 dias ao gestor.
- **Feito na 5.3b:** o comando `exams:overdue` roda de hora em hora no `scheduler`. Uma vez por dia de cada unidade, a partir das 7 h, ele avisa os gestores (ou, se a unidade não tiver gestor, os administradores da rede) quantos pedidos estão abertos com exame sem coleta há mais de 7 dias. A tela "Exames atrasados" mostra data, prioridade, local, quem pediu e quantos itens faltam, sem o nome dos exames nem o prontuário. Três execuções simultâneas geraram um único aviso.
- **Feito na 5.3a:** um pedido pode misturar laboratório e imagem, então a coleta ou realização é por item, e cada item entra na fila do seu tipo. A coleta impede o cancelamento. A trilha ganha o motivo `exam`, com o pedido. Corrida real de coleta contra coleta e de coleta contra cancelamento: todas as rodadas consistentes. Tempos-alvo: o administrador da rede define na tela do catálogo de exames (padrão: urgente 1 hora, rotina 24 horas). A tela de execução mostra também as prescrições do atendimento ou da internação do pedido, só para leitura. Até a 5.4, o acesso dura enquanto o pedido estiver aberto, mesmo com todos os itens já coletados; a 5.3b avisa o gestor, e o resultado da 5.4 encerra o acesso.

### 5.4 Resultado e laudo
- Laboratório: valores por analito, com a faixa de referência e marcação fora da faixa; texto livre; anexo PDF.
- Imagem: laudo em texto e anexo PDF; liberação só por profissional com conselho médico (CRM) (decisão de 24/10/2026).
- Liberação assinada com senha; retificação como nova versão, com a anterior visível e motivo.
- Aviso a quem pediu, sem dado clínico no texto.
- **Saída:** resultado imutável no banco depois de liberado (gatilho); corrida real em liberar o mesmo resultado.
- **Feito na 5.4:** o resultado é por item: depois da coleta ou realização, a equipe do tipo do item na unidade libera valores por analito (laboratório, com a referência do catálogo gravada e marcação abaixo/acima calculada no servidor), observações ou laudo e um PDF, assinados com a senha. Laudo de imagem só por profissional com CRM. O banco recusa alterar ou apagar resultado e valores, e recusa resultado de item não coletado (gatilho). A retificação é a versão seguinte, com motivo; a anterior continua visível. Quem pediu recebe aviso sem nome do exame, paciente ou valor, com link para o prontuário, onde os resultados aparecem (atendimento, internação, acesso de emergência e execução). Com todos os itens do seu tipo liberados, a equipe perde o prontuário e vê só o pedido e os resultados, para retificar (lista "Liberados nos últimos 30 dias" na fila). Corrida real de duas pessoas liberando ou retificando a mesma versão: uma vence, a outra recebe aviso claro (6 rodadas consistentes).

### 5.5 Resultado crítico
- Quem libera marca "crítico"; quem pediu recebe aviso destacado e registra ciência na tela do resultado.
- Sem ciência em 1 hora, o gestor da unidade é avisado (comando no serviço `scheduler`, idempotente).
- **Saída:** escalonamento uma vez por resultado (teste); ciência só por quem pediu ou quem atende o paciente.
- **Feito na 5.5:** quem libera (ou retifica) marca o resultado como crítico. Quem pediu recebe aviso urgente, sem dado clínico, destacado em vermelho na lista e na barra superior até ser lido. A ciência é registrada na tela do resultado, uma vez, só por quem pediu ou por quem atende o paciente (atendimento aberto ou internação), e apaga os avisos urgentes daquele resultado. O comando `exams:critical`, a cada 5 minutos, avisa o gestor da unidade (sem gestor, o administrador da rede) quando passa 1 hora sem ciência, uma vez por resultado; a tela "Exames atrasados" lista os críticos sem ciência (local, quando, quem pediu), sem exame nem valor. O banco impede desfazer a ciência ou o escalonamento. Corrida real: três escalonamentos simultâneos geraram um aviso; duas ciências simultâneas, uma; ciência contra escalonamento, sempre consistente.

### 5.6 Revisão integrada
- **Jornada num cliente novo:** pedido urgente na urgência → fila do laboratório → coleta → resultado crítico → aviso e ciência; pedido de imagem na internação → laudo → retificação; acesso da equipe encerrado depois do resultado.
- Verificação das telas, revisão de segurança independente e limites de produção no README.
- **Feito na 5.6:** jornada num cliente novo, só pelas rotas (`ExamJourneyTest`): pedido urgente na urgência, fila do laboratório fora do tempo-alvo, coleta, resultado crítico, aviso urgente, escalonamento ao gestor e ciência; internação, tomografia pedida pelo leito, laudo com PDF pela radiologista com CRM e retificação; acesso da equipe encerrado a cada resultado; trilha, auditoria e avisos conferidos sem dado clínico. A jornada achou o catálogo recusando "3,5" (corrigido). Revisão de segurança independente, sem achado crítico ou alto: a tela restrita depois do resultado vale por 30 dias; o laudo de imagem exige também habilitação vigente na unidade; referência ambígua ("450,000") recusada no formulário. Limites de produção no README.

## Fora deste plano

- Cirurgia, agenda de salas, checklist, termo e materiais (entrega 5b).
- Integração com equipamentos e sistemas (LIS, PACS, DICOM, HL7/FHIR), visualizador de imagens e laboratório externo.
- Faturamento e autorização de convênio (entrega 6); portal do paciente (entrega 7).

## Pendências resolvidas (24/10/2026)

- Laudo de imagem liberado só por profissional com CRM.
- Pedido sem coleta aparece ao gestor depois de 7 dias.
