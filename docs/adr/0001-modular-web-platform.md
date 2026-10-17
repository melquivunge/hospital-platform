# ADR-0001: aplicação web modular

Data: 17/10/2026. Estado: aceita pelo usuário.

## Contexto

Primeira entrega responsiva para vários hospitais, com regras transacionais de escalas, aprovação e isolamento. Aplicativos mobile virão depois. Não existe stack anterior no projeto.

## Decisão

Laravel 13/PHP 8.5, React/TypeScript/Inertia e PostgreSQL 18, numa aplicação modular. Sessão web e CSRF; ações de domínio reutilizáveis numa futura API mobile. Composer e npm com lockfiles. Docker Compose local com serviços separados e CI no GitHub. Hospedagem/CD serão definidos depois, conforme escolha explícita do usuário.

## Alternativas

Livewire concentra a interface em PHP, mas foi preterido pela direção de interface interativa escolhida. SPA/API separadas permitem clientes independentes desde o início, porém adicionam operação e contratos antes da necessidade mobile. Microserviços não se justificam nesta fase.

## Consequências

Uma aplicação reduz operação inicial e facilita transações atômicas. A equipe precisa manter PHP e TypeScript. As telas web não são automaticamente reutilizáveis em Android/iPhone. Isolamento multi-cliente será implementado na etapa seguinte; autenticação inicial não comprova esse isolamento.

## Origem

Plano aprovado: ../../plans/web-v1.md. Starter oficial laravel/react-starter-kit, commit 717b8f55aefd82d25d4119eaebdc8e3a72b8d7e5. Versões exatas registradas nos lockfiles.
