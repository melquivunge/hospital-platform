# Plano — entrega 3: atendimento ambulatorial

Data: 18/10/2026. Estado: aprovado pelo usuário para implementação, uma etapa por vez (uma etapa por PR, CI verde, revisão; merge só com autorização).
Contrato: ../PRODUCT.md, seção "Contrato da entrega 3". Arquitetura: ../docs/adr/0003-rls-on-clinical-tables.md. Regras de trabalho: ../AGENTS.md.

## Princípios herdados da V1

Mesmo monólito Laravel 13 + React/Inertia + PostgreSQL. Auditoria só de inserção, escopo fail-closed, FKs compostas `(tenant_id, id)`, locks em ordem fixa, idempotência e testes negativos de acesso. Nenhum dado clínico em logs, notificações, URLs ou relatórios gerais. Telas no padrão visual atual (docs/design/direction.md e o padrão Apple pedido pelo usuário) e verificadas em 360, 768 e 1440 px.

## Etapas

### 3.1 Fundação clínica
- Dois usuários de banco (dono para migrations e usuário da aplicação sem `BYPASSRLS`) no Docker, no CI e no `.env.example`. Migrations rodam como dono e o resto como usuário da aplicação.
- `CurrentTenant::set()` define `app.tenant_id` na conexão.
- Um helper de migration cria tabela clínica já com RLS forçado e a política padrão.
- Teste de guarda: toda tabela clínica tem RLS forçado. Teste negativo: com `withoutTenantScope()`, outro cliente continua sem ver as linhas.
- Papel **Recepção** por unidade.
- **Saída:** uma tabela clínica de prova bloqueada pelo banco entre clientes; recepção atribuível em Pessoas. (Convênios foram para a 3.5, onde são usados.)

### 3.2 Pacientes
- Cadastro provisório ou completo, com nome, data de nascimento, sexo e contato.
- CPF validado pelo dígito e CNS validado. Cada um é único na rede quando informado.
- Responsável legal opcional.
- Busca por nome, nascimento, CPF ou CNS, mostrando o mínimo para identificar.
- Aviso de possível duplicado no cadastro.
- Recepção e profissional assistente cadastram; auditoria de criação e alteração.
- **Saída:** paciente sem documento cadastrável; CPF ou CNS repetido recusado; aviso de duplicado visível.

### 3.3 Unificação
- Fila de suspeitas.
- O gestor da unidade ou o administrador de rede unifica, com motivo, sem ler conteúdo clínico. O cadastro duplicado aponta para o principal. Desfazer é uma nova decisão auditada.
- **Saída:** histórico do duplicado visível no principal, com origem preservada; desfazer restaura a separação.

### 3.4 Agenda
- Grade por profissional e unidade, com duração fixa da consulta, e bloqueios.
- Agendamento pela recepção. Encaixe explícito e marcado como tal.
- Cancelar com motivo. Remarcar liga o novo agendamento ao anterior. Faltas.
- Restrição no banco contra dois agendamentos no mesmo horário sem encaixe.
- **Saída:** corrida real entre duas recepções pelo mesmo horário produz um único agendamento.

### 3.5 Recepção
- Confirmação de chegada e fila do dia por unidade e profissional.
- **Convênios da rede**, mantidos pelo administrador (não clínico, fora do RLS).
- Modalidade (particular, convênio ou SUS) e, para convênio, carteira e validade.
- **Saída:** a fila reflete chegadas, faltas e cancelamentos.

### 3.6 Atendimento e evolução
- O profissional abre o atendimento a partir da chegada ou de um encaixe e o encerra.
- Evolução em rascunho, visível só ao autor; assinatura com confirmação de senha; adendo assinado.
- **Acesso clínico centralizado:** atendimento ativo em qualquer unidade da rede.
- **Emergência:** justificativa obrigatória, validade de 4 horas, notificação ao gestor da unidade.
- **Trilha de leitura:** só inserção, consultável pela auditoria.
- **Saída:**
  - Assinada não muda.
  - Profissional sem vínculo de atendimento não lê.
  - Emergência expira.
  - Cada leitura registrada.

### 3.7 Documentos
- Anexar PDF, JPG ou PNG de até 10 MB.
- Disco privado com prefixo por cliente.
- Download só com autorização clínica, e cada download auditado.
- Retirar exige motivo e mantém o arquivo.
- **Saída:** arquivo de outro cliente inacessível mesmo com o caminho; tipo falso recusado.

### 3.8 Revisão integrada
- Jornada completa num cliente novo: cadastro → agenda → chegada → atendimento → evolução → documento → encerramento.
- Verificação de telas e revisão de segurança independente.
- Limites de produção atualizados no README.

## Pendências da revisão de segurança da 3.1 (para a 3.2 em diante)

- Tabelas só de inserção (trilha de leitura): revogar de `app_runtime` a alteração e a remoção de linhas, além do trigger.
- Toda tabela clínica com `tenant_id NOT NULL`; nenhuma view ou função `SECURITY DEFINER` sobre tabela clínica sem `security_invoker`.
- Em produção: login da aplicação membro só de `app_runtime` (não dono, não superusuário). Com o login comum, `RESET ROLE` e `set_config` continuam ao alcance de uma injeção de SQL: o RLS protege contra erro de escopo, não contra injeção.

## Riscos

- Dados de saúde: qualquer vazamento é grave. Por isso o RLS (ADR-0003) e a revisão de segurança em toda etapa clínica (3.2, 3.3, 3.6 e 3.7).
- Validade legal: assinatura sem ICP-Brasil e retenção sem prazo validado são limites conhecidos; não declarar prontidão clínica.
- Pooling de conexões na produção (ADR-0003).
