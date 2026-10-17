# ADR-0002: sem RLS do PostgreSQL na V1

Data: 17/10/2026. Estado: aceita pelo usuário.

## Contexto

O plano (plans/web-v1.md, etapa 2) pede fechar a decisão sobre Row-Level Security com teste ou com uma decisão explícita de não adoção. Hoje a aplicação, os workers e as migrations usam um único usuário de banco, que é dono das tabelas. O dono ignora as políticas de linha, a menos que se use `FORCE ROW LEVEL SECURITY`.

## Decisão

Não adotar RLS na V1. O isolamento entre clientes continua garantido por:

- escopo fail-closed (`App\Tenancy\BelongsToTenant`): consulta sem cliente definido lança exceção;
- cliente resolvido pela sessão e pelo vínculo ativo, nunca por campo do formulário;
- chaves estrangeiras compostas `(tenant_id, id)`, que impedem no banco referências entre clientes;
- testes negativos de cada caminho de acesso (tests/Feature/Tenancy, tests/Feature/Governance).

O projeto não alega que o banco bloqueia consultas entre clientes por política de linha.

## Alternativa considerada

Adotar RLS exigiria: um usuário de banco separado (não dono, sem BYPASSRLS) para a aplicação e os workers, outro para as migrations, políticas em todas as tabelas com `tenant_id` e `SET LOCAL` do cliente em cada transação e job, com cuidado especial em pooling. Além disso, precisaria de testes executados com esse usuário. É uma defesa adicional real, mas muda Docker, CI, migrations e workers antes de existir hospedagem.

## Consequências

Um erro de escopo na aplicação (por exemplo, `withoutTenantScope()` usado indevidamente) não é barrado pelo banco. Para mitigar: uso de `withoutTenantScope()` restrito a provisionamento e testes, e revisão de segurança por PR.

Reavaliar antes da produção, junto com a definição de hospedagem e de usuários de banco por papel.
