# ADR-0003: RLS do PostgreSQL nas tabelas clínicas

Data: 18/10/2026. Estado: aceita pelo usuário. Revisita a [ADR-0002](0002-no-postgres-rls-in-v1.md) para os dados da entrega 3.

## Contexto

A entrega 3 (PRODUCT.md, "Contrato da entrega 3") traz dados pessoais sensíveis de saúde: pacientes, atendimentos, evoluções e documentos. Hoje o isolamento entre clientes depende só da aplicação (escopo fail-closed, FKs compostas e testes). Um erro de escopo, como um `withoutTenantScope()` indevido, exporia o prontuário de um cliente a outro. O usuário decidiu reavaliar a ADR-0002 antes de criar tabelas clínicas.

## Decisão

- **RLS forçado (`ENABLE` + `FORCE ROW LEVEL SECURITY`) em toda tabela clínica**, com a política `tenant_id = current_setting('app.tenant_id', true)::bigint`. Sem cliente definido, nenhuma linha é visível ou gravável (fail-closed também no banco).
- **Dois usuários de banco:** o dono das tabelas, usado só pelas migrations, e o usuário da aplicação e dos workers, sem `BYPASSRLS`, com permissões concedidas por padrão.
- **O cliente vai ao banco junto com o contexto:** `CurrentTenant::set()` também define `app.tenant_id` na conexão; limpar o contexto zera a variável. Requisições, jobs e comandos já passam por esse ponto.
- **Acesso clínico centralizado:** uma única classe de autorização decide se alguém lê o prontuário (atendimento ativo na rede ou emergência válida). Toda leitura grava um registro numa tabela só de inserção.
- **Documentos clínicos** ficam no disco privado, sob um prefixo por cliente, e são servidos só depois da autorização clínica.
- As tabelas da V1 seguem a ADR-0002 até a definição da hospedagem.

## Alternativas consideradas

### RLS em todas as tabelas agora
- **Prós:** defesa uniforme.
- **Contras:** mexe em toda a V1 já validada, com risco de regressão sem dado sensível novo que o justifique.
- **Por que não:** custo e risco desproporcionais; fica para a hospedagem.

### Manter só a aplicação (ADR-0002)
- **Prós:** mais rápido e sem mudança de infraestrutura.
- **Contras:** um erro de código vira vazamento de dados de saúde entre hospitais.
- **Por que não:** o risco é maior com prontuário, e ativar o RLS antes de existir dado clínico é mais barato do que depois.

## Consequências

### Positivas
- O banco barra o acesso clínico entre clientes mesmo se a aplicação errar.
- A trilha de leitura atende à exigência do contrato de registrar quem viu cada prontuário.

### Negativas
- Docker, CI e migrations passam a usar dois usuários de banco; os testes rodam como usuário da aplicação.
- Todo acesso a tabela clínica precisa de cliente definido, inclusive em jobs e comandos.

### Riscos
- **Pooling de conexões na produção:** uma variável de sessão pode vazar entre requisições. Mitigação: definir a variável a cada requisição e job, zerar ao limpar o contexto, e revisar o pooler na hospedagem.
- **Migration que esquece o RLS:** um teste percorre as tabelas clínicas e falha se alguma estiver sem RLS forçado.
