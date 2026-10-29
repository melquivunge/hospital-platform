# Configuração dos agentes

Adoção inicial e limitada do [vibe-coding-toolkit](https://github.com/soumatheusgomes/vibe-coding-toolkit) (referência: `docs/prompts/12-existing-project-setup.md`, branch `main`, lida em 28/10/2026). As regras existentes do usuário têm prioridade sobre o toolkit.

## O que foi feito

- `AGENTS.md`: bloco curto com a stack, os comandos reais, as restrições específicas do projeto e o mapa da documentação. As duas regras do usuário (main e ECC) ficaram sem alteração de texto.
- Economia de tokens (autorizada pelo usuário em 28/10/2026): o ciclo de engenharia universal foi para `docs/prompts/universal-engineering-loop.md` sem alteração de texto, e o `AGENTS.md` traz um resumo que remete a ele (de ~30 KB para ~7 KB). O `docs/ESTADO-ATUAL.md` ficou só com o estado e o ponto de retomada (de ~118 KB para ~7 KB); o histórico foi, sem alteração, para `docs/HISTORICO.md`.
- `CLAUDE.md` local com `@AGENTS.md`, para o Claude Code carregar as mesmas instruções. O arquivo continua fora do Git, como o `.gitignore` já define.

## Verificado em 28/10/2026 (branch `docs/agents-setup`)

| Comando | Resultado |
|---|---|
| `composer lint:check` | passou |
| `composer types:check` | passou, 0 erros |
| `npm run types:check` | passou |
| `npm run check` | passou (formatação e lint) |
| `DB_HOST=127.0.0.1 npm run build` | passou |
| `sh scripts/test-branch-guards.sh` | passou |
| `docker compose config --quiet` | passou |

Não executado nesta etapa: a suíte de testes (`php artisan test` no contêiner) e o `composer test` completo. A mudança é só de documentação; o último CI verde da `main` foi no commit `a77ab03`.

## Recomendações do toolkit não aplicadas

| Recomendação | Motivo |
|---|---|
| Versionar o `CLAUDE.md` | O `.gitignore` do projeto exclui `/CLAUDE.md`; a escolha foi mantida. |
| Instalar e configurar componentes (Superpowers, RTK, Context7, Graphify, memória, ferramentas de navegador) | Fora do escopo desta etapa: nenhuma instalação nem integração global. |
| Matriz de cobertura de todos os componentes | Adoção limitada; fica para uma etapa autorizada. |
| Backup antes de editar | O Git cumpre esse papel: tudo está numa branch, sem commit. |
| Política própria de Git e delegação do template | O projeto já tem regras próprias (main e ECC, hooks locais); não foram duplicadas. |

## Pendente

- Rodar a suíte completa antes de abrir o PR, se o usuário quiser mesclar esta configuração.
- Atualizar esta página quando um comando, a stack ou o CI mudarem.
