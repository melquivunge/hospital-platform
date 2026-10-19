# ADR-0004: acesso ao prontuário pela equipe do setor de internação

Data: 18/10/2026
Estado: aceita pelo usuário (18/10/2026)

## Contexto

A [ADR-0003](0003-rls-on-clinical-tables.md) centralizou o acesso clínico em `App\Clinical\ClinicalAccess`, com duas bases: atendimento aberto do profissional com o paciente (entrega 3) e acesso de emergência justificado, só leitura, por 4 horas (etapa 3.6b). Na internação (entrega 4), o paciente é cuidado por uma equipe que troca a cada plantão. O usuário decidiu em 18/10/2026 que leem o prontuário de um paciente internado o **médico responsável** e a **equipe assistencial do setor onde ele está internado**. Exigir que cada profissional de cada plantão abra um atendimento não condiz com a rotina da enfermagem.

## Decisão

- `ClinicalAccess` ganha uma terceira base: **internação ativa**. Lê o prontuário quem for o médico responsável da internação ou tiver habilitação aprovada no setor do leito atual, enquanto o paciente estiver lá.
- A base é avaliada no momento da leitura, a partir de dados do banco: a movimentação do paciente e as habilitações. Quando o paciente muda de setor, a equipe do setor anterior perde o acesso na hora. Na alta, a equipe também perde.
- Cada leitura continua gravada na trilha só de inserção, com o motivo `internação` e o vínculo com a internação.
- Escrever (evolução, adendo, documento) também passa a valer para quem tem essa base. A regra da entrega 3 continua: a evolução assinada não muda.
- A gestão de leitos, a recepção, a gestão e a administração continuam sem leitura clínica por causa do papel.

### Esclarecimento da implementação (etapa 4.4b, 18/10/2026)

- A lista "Internados" mostra só identificação (nome, leito, unidade e responsável), como a fila da urgência e a tela da gestão de leitos, e não grava leitura. Entra na trilha toda abertura do prontuário (evoluções, documentos e download) pela internação.
- Antes da internação ter leito (pedido aguardando), só o médico responsável lê; a equipe do setor passa a ler quando o paciente ocupa o leito.
- A alta (etapa 4.7) precisa decidir o destino dos rascunhos de evolução ainda abertos, que deixam de poder ser assinados quando o acesso termina.

## Alternativas

### Só quem abre atendimento (regra do ambulatório)
- **Prós:** um modelo só; a mais restrita.
- **Contras:** cada profissional de cada plantão precisaria abrir um atendimento por paciente, o que é atrito diário e tende a virar contorno pelo acesso de emergência.
- **Por que não:** decisão do usuário a favor da equipe do setor.

### Lista de equipe mantida à mão por internação
- **Prós:** controle fino.
- **Contras:** depende de manutenção constante e falha justamente na troca de plantão.
- **Por que não:** a habilitação por setor já existe (etapa 3b da V1) e é a fonte de verdade de quem trabalha onde.

## Consequências

### Positivas
- O acesso acompanha o paciente: sai do setor, sai a equipe.
- Reaproveita habilitações e a trilha de leitura existentes.

### Negativas
- Um setor grande dá acesso a muita gente. Isso é mitigado pela trilha de leitura, consultável pela auditoria (3.6b).
- A regra de acesso fica mais complexa e precisa de testes negativos para cada mudança de setor e para a alta.

### Riscos
- Uma habilitação aprovada por engano num setor dá acesso clínico a esse setor. A aprovação já é auditada; a revisão de segurança da etapa deve cobrir esse caminho.
