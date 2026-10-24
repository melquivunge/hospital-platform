# ADR-0009: acesso do centro cirúrgico ao prontuário e materiais no livro de estoque

Data: 24/10/2026
Estado: proposta; decisões do usuário de 24/10/2026 incorporadas (equipe escalada lê o prontuário; centro cirúrgico ou equipe confirma o checklist; farmácia mantém o estoque de materiais)

## Contexto

A entrega 5b (contrato da entrega 5 no PRODUCT.md, decisões do usuário de 23/10/2026) traz a cirurgia: pedido, agenda de salas, checklist de cirurgia segura, termo obrigatório, descrição assinada e materiais. Na questão 6, o usuário decidiu que quem executa lê o prontuário durante a execução, com trilha, e que o centro cirúrgico segue a regra da [ADR-0008](0008-exam-team-record-access.md). Na questão 8, decidiu que os materiais usam o livro de estoque da [ADR-0007](0007-pharmacy-stock-ledger.md). O cirurgião, o anestesista e a instrumentação muitas vezes não atendem o paciente no ambulatório nem trabalham no setor do leito.

## Decisão

- **Papel novo, Centro cirúrgico**, por unidade: agenda salas e equipe, percorre o checklist e registra o consumo de materiais. Vê a agenda da unidade (identificação do paciente, procedimento, sala, horário e equipe) sem gravar leitura, como as filas de exames.
- **Base de acesso "cirurgia":** lê o prontuário, com as mesmas telas de quem atende, enquanto o paciente tiver na unidade uma cirurgia **pedida, agendada ou em andamento**:
  - quem tem o papel Centro cirúrgico na unidade;
  - os profissionais escalados na equipe daquela cirurgia (cirurgião, auxiliares, anestesista, instrumentação e circulante), só enquanto estiverem na equipe.
- **Checklist:** cada etapa é confirmada por quem tem o papel Centro cirúrgico na unidade ou está na equipe escalada daquela cirurgia.
- Cada abertura entra em `clinical_reads` com o motivo `surgery` e o vínculo com a cirurgia, uma linha por abertura.
- **Depois de concluída ou cancelada**, a base termina. Por 30 dias, o cirurgião vê só a cirurgia, o checklist e a descrição (para o adendo), e o centro cirúrgico vê só a cirurgia e o consumo de materiais (para corrigir com movimento de estorno). Nada do resto do prontuário.
- **O centro cirúrgico não abre acesso sozinho:** a cirurgia só nasce do pedido do profissional que atende (função que atende e habilitação na unidade, dentro do atendimento, da urgência ou da internação). A exceção é a cirurgia de urgência, que o médico que atende pede e o centro cirúrgico agenda na hora, com motivo.
- **Assinatura com senha** no pedido, na descrição cirúrgica e no adendo; o checklist grava quem confirmou cada etapa e quando, sem senha.
- **Imutável no banco (gatilho):** etapa do checklist confirmada, descrição assinada e adendo nunca mudam nem são apagados.
- **Sala sem conflito no banco:** uma restrição de exclusão (`btree_gist`) recusa duas cirurgias ativas na mesma sala com horários que se sobrepõem. Duas pessoas agendando a mesma sala e horário resultam em um só agendamento (corrida real).
- **Materiais:**
  - o catálogo de materiais é da rede, separado do de medicamentos;
  - `stock_lots` ganha `material_id`, e uma restrição exige medicamento **ou** material, nunca os dois;
  - o saldo, o `CHECK (balance >= 0)`, os gatilhos, o descarte, o inventário e os avisos da ADR-0007 valem igual;
  - a farmácia da unidade registra entradas, descartes e inventário de materiais, como faz com medicamentos;
  - o consumo na cirurgia é um movimento novo, `surgery`, com o id da cirurgia, registrado pelo centro cirúrgico, só da unidade da cirurgia e de lote válido;
  - a correção é um movimento `surgery_return` até o que a cirurgia consumiu daquele lote, nunca uma alteração do movimento;
  - o movimento guarda só o id da cirurgia; ligá-lo ao paciente exige o acesso clínico.
- Cirurgia, equipe, checklist, termo, descrição e adendo são tabelas clínicas com RLS forçado ([ADR-0003](0003-rls-on-clinical-tables.md)). Salas e catálogos não são clínicos.

## Alternativas

### Só o centro cirúrgico, sem a equipe escalada
- **Prós:** menos leitores.
- **Contras:** o cirurgião e o anestesista chamados de fora do setor não leriam o prontuário que precisam para operar e anestesiar.
- **Por que não:** a equipe escalada é quem mais precisa do contexto clínico.

### Estoque de materiais separado do da farmácia
- **Prós:** o centro cirúrgico controla o próprio estoque.
- **Contras:** duplica entradas, inventário e avisos, e contraria a decisão da questão 8.
- **Por que não:** o livro da ADR-0007 já garante saldo e histórico; só muda quem consome.

### Conflito de sala verificado só na aplicação
- **Prós:** sem extensão nova.
- **Contras:** depende de lock correto em todo caminho.
- **Por que não:** a restrição de exclusão deixa o banco garantir a regra.

## Consequências

### Positivas
- Mesmo desenho dos exames: o acesso nasce do pedido, termina com a conclusão e fica rastreado.
- O banco garante sala sem sobreposição, histórico imutável e saldo de material nunca negativo.

### Negativas
- Mais leitores do prontuário: o centro cirúrgico da unidade e a equipe escalada.
- `stock_lots` passa a ter duas origens; telas, relatório e importação de estoque precisam filtrar por tipo.
- A extensão `btree_gist` entra no banco.

### Riscos
- **Equipe escalada por engano** abre o prontuário. Mitigação: a escala da equipe é auditada e a trilha mostra cada abertura.
- **Cirurgia esquecida como agendada** mantém o acesso. Mitigação: cirurgia agendada com horário vencido há mais de 24 horas aparece para o gestor da unidade, como os exames sem coleta.
- **Material sem saldo** recusa o consumo. Mitigação: o consumo é registrado depois e não bloqueia o checklist nem a descrição; a farmácia regulariza a entrada.
