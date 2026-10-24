# ADR-0008: acesso das equipes de laboratório e de imagem ao prontuário durante a execução

Data: 23/10/2026
Estado: proposta (aguardando aceite do usuário)

## Contexto

A entrega 5 (contrato no PRODUCT.md) traz pedidos e resultados de exames. Hoje o `ClinicalAccess` abre o prontuário por atendimento aberto, internação ativa (ADR-0004), prescrição para a farmácia (ADR-0005) e acesso de emergência. Quem executa o exame não atende o paciente nem trabalha no setor do leito. Na questão 6 do contrato, o usuário decidiu que **quem executa lê o prontuário do paciente durante a execução, com trilha de leitura**, em vez de ler só o pedido. Na questão 3, decidiu por dois papéis separados, Laboratório e Imagem.

## Decisão

- Dois papéis novos, **Laboratório** e **Imagem**, por unidade. Cada um vê a fila dos pedidos do seu tipo na unidade: identificação do paciente, exames, prioridade e tempo de espera, sem gravar leitura (como a fila da farmácia e a lista "Internados").
- **Base de acesso "execução de exame":** a pessoa com o papel lê o prontuário do paciente, com as mesmas telas de quem atende (evoluções, documentos, alergias, prescrições e resultados anteriores), **enquanto o paciente tiver, na unidade dela, um pedido do tipo dela ainda em aberto** (pedido ou coletado/realizado, antes do resultado liberado ou do cancelamento).
- Depois do resultado liberado ou do pedido cancelado, a base de execução termina. A equipe continua vendo **só o pedido e o resultado que ela registrou** (para retificar), não o prontuário.
- Cada abertura do prontuário por essa base entra na trilha `clinical_reads` com o motivo `exam` e o vínculo com o pedido, uma linha por abertura.
- **A equipe não abre acesso sozinha:** o pedido só é criado pelo profissional que atende, com função que atende e habilitação na unidade (questão 2). Laboratório e Imagem não criam pedidos.
- A equipe escreve a coleta ou a realização e o resultado do pedido; não escreve evolução, prescrição nem outros documentos do prontuário.
- Pedidos, itens, resultados, laudos e anexos de exame são tabelas clínicas com RLS forçado ([ADR-0003](0003-rls-on-clinical-tables.md)); a nova base entra na função de acesso usada pelas políticas, como as anteriores.
- O centro cirúrgico (entrega 5b) segue a mesma regra, em ADR própria daquela entrega.

## Alternativas

### Só o pedido (a proposta original da questão 6)
- **Prós:** menos pessoas leem o prontuário; mínimo necessário.
- **Contras:** a equipe de imagem perde o contexto clínico para o laudo (evolução, exames anteriores), e o laboratório perde informações para interpretar resultados.
- **Por que não:** decisão do usuário de 23/10/2026.

### Pelo acesso de emergência
- **Prós:** nenhuma regra nova.
- **Contras:** cada exame viraria um acesso de emergência, com justificativa e aviso ao gestor; o alerta perderia valor.
- **Por que não:** a execução de exames é rotina, não exceção.

### Acesso permanente da equipe a todos os pacientes da unidade
- **Prós:** mais simples.
- **Contras:** abre o prontuário de quem nunca teve exame pedido.
- **Por que não:** o pedido em aberto é o motivo concreto do acesso e limita no tempo.

## Consequências

### Positivas
- Quem executa e lauda tem o contexto clínico completo enquanto trabalha no pedido.
- O acesso nasce de um ato de quem atende (o pedido) e termina sozinho com o resultado ou o cancelamento; cada abertura fica rastreada.
- Reaproveita o `ClinicalAccess`, a trilha, a assinatura com senha e o RLS.

### Negativas
- Mais leitores do prontuário do que na proposta original: toda a equipe de laboratório ou de imagem da unidade, enquanto houver pedido aberto.
- Mais uma base no `ClinicalAccess`, com testes negativos obrigatórios: outra unidade, outro tipo (Laboratório lendo pedido de imagem), pedido liberado ou cancelado, papel revogado.

### Riscos
- **Pedido esquecido em aberto** mantém o acesso. Mitigação: a fila mostra o tempo de espera com destaque, e um pedido sem coleta em prazo configurável (proposta: 7 dias) aparece para o gestor; prazo e ação a definir no plano.
- **Papel dado por engano** abre os pacientes com pedido em aberto da unidade. Mitigação: concessão auditada, trilha de leitura visível em "Acessos ao prontuário" e revisão de segurança da etapa.
- **Laudo de imagem:** a liberação por profissional com conselho médico (CRM), proposta original da questão 3, continua no plano como regra a confirmar pelo usuário.
