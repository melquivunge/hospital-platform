# Plano — entrega 5b: cirurgia

Data: 24/10/2026. Estado: **proposta, aguardando aprovação do usuário.** Uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização do usuário.
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

### 5b.2 Pedido de cirurgia
- O profissional que atende pede no atendimento, na urgência ou na internação: procedimento, lateralidade quando houver, indicação, caráter (eletiva ou urgência), data desejada e assinatura com senha.
- Cancelamento por quem pediu, com motivo, antes do início.
- O pedido aparece no prontuário.
- **Saída:** pedido só com acesso clínico válido; nada clínico na auditoria.

### 5b.3 Agenda, equipe e acesso (ADR-0009)
- Agenda da unidade por sala e dia; o centro cirúrgico agenda sala, início e duração e escala a equipe (cirurgião obrigatório).
- Restrição de exclusão contra sobreposição na sala; reagendamento e cancelamento com motivo.
- Urgência: agendada na hora, com motivo, numa sala livre (a eletiva que ocupa a sala é reagendada antes).
- Base "cirurgia" no `ClinicalAccess`, com o motivo `surgery` na trilha; a agenda não grava leitura.
- Cirurgia agendada com horário vencido há mais de 24 horas aparece para o gestor (risco da ADR-0009).
- **Saída:** corrida real em agendar a mesma sala e horário; acesso termina com a conclusão ou o cancelamento (teste).

### 5b.4 Checklist de cirurgia segura e termo
- As três etapas da OMS (antes da anestesia, antes da incisão, antes da saída da sala), com os itens fixos da lista da OMS; cada etapa grava quem confirmou e quando.
- Termo de consentimento assinado anexado (PDF, JPG ou PNG, conferido pelo conteúdo) antes de confirmar a primeira etapa; urgência sem termo com motivo.
- Confirmar a primeira etapa põe a cirurgia em andamento.
- **Saída:** etapa confirmada imutável no banco; ordem das etapas garantida; corrida real em confirmar a mesma etapa.

### 5b.5 Descrição cirúrgica
- O cirurgião da equipe registra a descrição (achados, técnica, intercorrências, peças enviadas) e assina com senha; com as três etapas confirmadas, a cirurgia fica concluída.
- Adendo assinado depois, com o original visível.
- Quem pediu é avisado da conclusão, sem dado clínico no texto.
- **Saída:** descrição imutável no banco; só o cirurgião escalado assina.

### 5b.6 Materiais no livro de estoque
- Catálogo de materiais da rede e importação CSV.
- `stock_lots` com medicamento ou material; entradas, descarte, inventário, avisos e relatório da farmácia passam a incluir materiais.
- Consumo na cirurgia pelo centro cirúrgico (movimento `surgery`) e estorno (`surgery_return`) até o consumido.
- **Saída:** saldo nunca negativo sob corrida real; consumo só de lote válido da unidade da cirurgia.

### 5b.7 Revisão integrada
- **Jornada num cliente novo:** pedido eletivo na internação → agenda e equipe → termo → checklist → descrição → materiais; urgência sem termo, com motivo; acesso encerrado depois da conclusão.
- Verificação das telas, revisão de segurança independente e limites de produção no README.

## Fora deste plano

- Ficha anestésica completa e sinais vitais intraoperatórios.
- Medicamentos consumidos na cirurgia pelo estoque (seguem pela prescrição da 4b).
- Faturamento, OPME e autorização de convênio (entrega 6).
- Integração com equipamentos e mapa cirúrgico em painel de TV.

## Pendências para o usuário

1. **Quem lê o prontuário:** o centro cirúrgico da unidade **e a equipe escalada** (proposta da ADR-0009), ou só o centro cirúrgico.
2. **Quem confirma o checklist:** quem tem o papel Centro cirúrgico ou é da equipe escalada (proposta), ou só a enfermagem circulante.
3. **Estoque de materiais:** mantido pela farmácia da unidade, com consumo pelo centro cirúrgico (proposta), ou mantido pelo próprio centro cirúrgico.
