# Blueprint ECC — construção da primeira entrega web

Data: 17/10/2026. Estado: arquitetura aceita pelo usuário em 17/10/2026; etapa 1 em implementação. Infraestrutura GitHub/CI/Docker incluída por solicitação posterior; CD adiado até definição de hospedagem.
Contrato funcional: ../PRODUCT.md, seção Contrato V1. Instruções: ../AGENTS.md.

## Evidência e objetivo

Pasta contém Markdown e referências visuais, sem aplicação ou Git. CLI verificada: PHP 8.5.9, Composer 2.10.2, Node 24.19.0 e Docker 29.8.2. Docker CLI disponível não comprova daemon ativo. GitHub CLI existe, mas a consulta de autenticação falhou; GitHub não é dependência do planejamento nem da primeira execução local. Não foi criado remoto nem branch.

Objetivo: construir o fluxo aprovado de cadastros → escala/importação → publicação → mudanças aprovadas → presença geográfica/ajuste administrativo → relatórios. Preservar todas as regras de PRODUCT.md. Nada neste plano declara capacidade para substituir prontuário ou faturamento.

## Arquitetura recomendada — proposta

**Monólito modular:** uma aplicação Laravel 13/PHP 8.5, interface React com TypeScript e Inertia, PostgreSQL como banco. Composer e npm com lockfiles. Tailwind e componentes do starter oficial como base, com desenho próprio a validar; não reutilizar marca ou dados do concorrente.

O starter oficial possui React/Inertia e autenticação Fortify; confirmar as versões efetivamente instaladas antes de fixar os arquivos de dependências. PostgreSQL 18 é candidato para ambiente local/CI; confirmar disponibilidade e compatibilidade na inicialização. Nenhuma dessas ferramentas está instalada como dependência do projeto ainda.

Web com sessão, cookies e CSRF no mesmo domínio. React chama rotas web; validação e decisões ficam em ações de domínio no servidor. Futura API mobile versionada reutiliza essas ações, com autenticação de dispositivo especificada na etapa mobile. Inertia não é a API dos aplicativos; não prometer reutilização automática das telas em React Native.

Filas e sessões em banco no primeiro ambiente, worker separado e notificações internas persistidas. Sem Redis, Kubernetes, microserviços ou servidor SSR Node por padrão. Arquivos privados locais em desenvolvimento; contrato de armazenamento permite object storage privado numa implantação posterior. Banco e arquivos precisam de backup/restore conjunto antes de operação real.

### Alternativas comparadas

| Alternativa | Benefício | Custo e decisão proposta |
|---|---|---|
| Laravel + React/Inertia | Interface interativa mantendo rotas, autenticação e domínio em um app | Duas linguagens e testes de UI; recomendada pela agenda, filtros e formulários do escopo, sem assumir experiência prévia da equipe |
| Laravel + Livewire | Mais lógica/interface concentrada em PHP | Alternativa válida se a equipe preferir PHP; agenda interativa ainda exige cuidado com estado e JavaScript |
| React separado + API backend | Separação de clientes desde o início | Mais contratos, autenticação e operação para V1; adiar até necessidade concreta |

Não é uma conclusão de superioridade geral nem decisão herdada do Obra360. Experiência da equipe e hospedagem não foram informadas; se houver restrição incompatível, revisar antes do scaffold.

### Limites de domínio

- Identidade/governança: cliente, rede, unidade, setor, contas, memberships e permissões.
- Profissionais: unidade-base, funções, vínculos e habilitações por destino.
- Escalas: plantões, publicação e atribuições; fonte única de alteração de responsável.
- Solicitações: trocas, transferências, vagas e candidaturas; chamam ações de escalas.
- Presença: entrada, saída, ocorrências e ajuste administrativo; preserva origem de cada evento.
- Importação: lotes, itens, mapeamentos e reconciliação; usa os mesmos validadores do cadastro e cria rascunhos.
- Relatórios/notificações: leitura autorizada e efeitos posteriores à confirmação; não mudam decisões de domínio.

Organização sugerida: ações e serviços por domínio dentro de app/, rotas/controllers finos e componentes React por jornada. Não criar um framework interno, sistema de plugins ou pacotes separados para cada módulo.

### Isolamento e consistência

Cada rede cliente é um tenant; hospital independente também pode ser cliente de uma unidade. tenant_id obrigatório nos registros de negócio; relações entre registros do tenant usam chaves compostas quando aplicável. Identidade de login pode ser global, mas memberships concedem acesso por cliente; e-mail não concede acesso e um tenant não enumera contas de outro.

Contexto resolvido a partir da sessão e membership ativa, jamais confiado a campo do formulário. Policies por unidade e ação; rotas, jobs, imports, exports, caches e downloads propagam e validam contexto. UUIDs ou IDs opacos não substituem autorização.

PostgreSQL RLS é opção de defesa adicional, não proteção implementada por este documento. Se adotada, testar com usuário de banco não proprietário, sem BYPASSRLS, incluindo workers e pooling; caso contrário, não alegar que o banco bloqueia consultas cross-tenant por política de linha. Proteção V1 obrigatória: escopo explícito, relações válidas e testes negativos de todos os caminhos de acesso. A decisão sobre RLS deve ser fechada na revisão técnica da etapa 2.

Transação da mudança trava profissionais afetados e plantões em ordem estável, revalida permissão, versões, elegibilidade, UF, tempo e conflitos na escala resultante e aplica tudo ou nada. Criação/publicação/importação de atribuições deve usar a mesma disciplina de locks; travar só os plantões não impede outro plantão concorrente para o mesmo profissional. Check-in e mudança de responsável compartilham lock do plantão e revalidam dentro da transação. Revogar/suspender vínculo ou habilitação sincroniza com o mesmo lock do profissional e incrementa a versão de elegibilidade; uma aprovação concorrente não pode confirmar com estado revogado já efetivado.

Idempotência por tenant, ator, operação e chave; repetir a mesma chave com conteúdo diferente é conflito. Índices únicos garantem identidades e eventos exclusivos. Notificação é derivada de evento persistido na mesma transação (outbox pequena ou mecanismo equivalente testado); entrega é reexecutável com deduplicação. Não prometer exactly-once; falha do worker não perde o resultado de negócio.

Instantes persistidos em UTC e fuso IANA da unidade para exibição e janelas; UF e fuso são campos distintos. Localização é dado do dispositivo validado pelo servidor, não prova antifraude. Nenhum rastreamento contínuo.

## Estratégia de execução e verificação

Modo inicial: local/direto. Inicializar Git somente quando for iniciada a construção autorizada; não criar GitHub ou publicar. Sem remoto, os passos equivalem a mudanças pequenas locais. Quando GitHub for autorizado, conservar os mesmos limites em PRs.

Nenhum comando abaixo foi executado como teste de aplicação: não há aplicação. Etapa 1 deve criar/confirmar o contrato de scripts e documentação. Comandos planejados: `composer test`, `composer lint`, `composer analyse`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run test:e2e`. Não alegar que já existem. Scripts PHP devem usar PHPUnit, Pint e análise estática compatível; selecionar dependência de análise apenas após verificar versões. Testes transacionais e de isolamento usam PostgreSQL real descartável, não SQLite como prova equivalente.

Cada passo tem: arquivos exclusivos quando possível, teste relevante, critério de saída e retorno seguro. Dados de desenvolvimento são sintéticos. Não executar migração destrutiva em dados de origem desconhecida. Metas de performance comercial e políticas de retenção/recuperação são gates de produção, não números inventados neste plano.

## Passos executáveis após aprovação

### 1. Ambiente e esqueleto autenticado
**Contexto:** projeto novo; web online primeiro; V1-01 e V1-13. Depende do aceite de arquitetura.
**Tarefas/arquivos:** scaffold oficial em diretório temporário e integração preservando Markdown e app/ com imagens; mover referências só com caminho atualizado, pois app/ conflita com a pasta PHP. Criar manifests/locks, ambiente PostgreSQL descartável, instruções README, scripts de verificação. Desabilitar cadastro público e configurar transporte local de recuperação. Fortify sessão/CSRF, logout e testes iniciais. Não copiar .env de outro projeto.
**Verificação:** confirmar scripts; testes de login/logout/recuperação e build. Scripts npm consultados no manifest antes de executar.
**Saída:** login local funciona; nenhuma rota pública de criação de usuário; ambiente reproduzível documentado.
**Retorno:** reverter apenas arquivos do scaffold rastreados; preservar documentos/referências e volumes não descartáveis.

### 2. Isolamento e administração da estrutura
**Contexto:** multi-cliente, rede com autonomia local; V1-01/02/14. Depende de 1.
**Tarefas/arquivos:** migrations/models de tenants, memberships, unidades/setores e permissões; políticas e middleware; telas de administração; revogação de acesso; auditoria mínima. Implementar catálogo pequeno de regras V1 de presença com versão, vigência, origem, valor herdado, campos delegáveis e limites obrigatórios da rede; hospital só altera o permitido e ações revalidam a versão efetiva. Fechar RLS com teste ou decisão explícita de não adoção. Provisionamento local pelo operador sem acesso operacional implícito.
**Verificação:** `composer test` focado em isolamento/autorização; `composer lint`; testes PostgreSQL para relações e conta desativada; UI e typecheck.
**Saída:** dois clientes e duas unidades não compartilham dados por URL, formulário ou sessão. Acesso de usuário global depende de membership; teste comprova que override local não enfraquece limite obrigatório da rede.
**Retorno:** desativar funcionalidade; migrações aditivas e rollback somente em base de teste sem dados a preservar.

### 3. Profissionais e elegibilidade
**Contexto:** mesma rede/UF, unidade-base, aprovação no destino; V1-03. Depende de 2.
**Tarefas/arquivos:** domínio Professionals, vínculos, habilitações, função e validade; CRUD e serviço de elegibilidade com motivos; política de atualização de base.
**Verificação:** `composer test` para múltiplos vínculos, UF, suspensão/expiração e duração integral do plantão; typecheck/build da interface.
**Saída:** não há herança implícita de aprovação entre hospitais; troca de filtro UI não troca unidade-base.
**Retorno:** suspender edição/novas atribuições, preservando histórico.

### 4. Plantões e publicação segura
**Contexto:** rascunho/publicado, UTC/fuso e calendário; V1-04/05. Depende de 3.
**Tarefas/arquivos:** domínio Scheduling, criar/editar/publicar/cancelar, atribuições, locks ordenados e validação temporal; lista acessível primeiro e calendário diário/semanal/mensal sem drag-and-drop obrigatório; outbox/eventos base. Definir contrato de presença e lock compartilhado agora, para integração com Requests e Attendance. CRUD/importação não alteram diretamente o responsável de plantão publicado: usam o fluxo aprovado. Mudanças relevantes de horário ou participantes invalidam aprovações pendentes.
**Verificação:** `composer test` com duas conexões PostgreSQL para publicação concorrente; madrugada, intervalos, papel errado e idempotência; `npm run typecheck` e build.
**Saída:** conflito concorrente não duplica atribuição; fluxo publicação→consulta funciona em desktop/celular.
**Retorno:** desativar publicação; não apagar plantões publicados ou seus eventos.

### 5. Importação rastreável de CSV
**Contexto:** identidade tenant+origem+entidade+ID; rascunho e reconciliação; V1-10. Depende de 4.
**Tarefas/arquivos:** domínio Imports, templates, prévia, mapeamento, resolução de referências, estados por item, worker retomável e tela de exceções. Originais privados; limites de upload configurados. Reusar Professionals/Scheduling sem duplicar regras.
**Verificação:** `composer test` com reimportação, conflito, erro parcial/retry, referência estrangeira e fórmulas; UI de confirmação e totais.
**Saída:** cada linha contabilizada; retry não duplica; importação não publica nem aprova habilitação.
**Retorno:** pausar worker/lote; itens aplicados permanecem rastreados, sem desfazer dados por exclusão cega.

### 6. Vagas e candidaturas
**Contexto:** anúncio não libera responsável, escolha pelo coordenador; V1-07/08. Depende de 4.
**Tarefas/arquivos:** domínio Requests/Vacancies, anúncio, candidatura/retirada, seleção justificada e confirmação; UI e eventos; reutilizar locks/eligibilidade. Aprovação não pode ser da própria pessoa envolvida.
**Verificação:** `composer test` para dois coordenadores, candidatura retirada, UF-base, atribuição atômica e estados demais candidatos; build/typecheck.
**Saída:** um vencedor por vaga; nenhuma candidatura é tratada como alocação antes da conclusão.
**Retorno:** bloquear novas decisões mantendo alocações e histórico existentes.

### 7. Trocas e transferências
**Contexto:** duas unidades aprovam troca; transferência tem um só plantão; V1-06/08. Depende de 6.
**Tarefas/arquivos:** Requests/Swaps e Transfers, aceite destinatário, decisões por escopo, finalização atômica, recusa/cancelamento/expiração; interface de resumo e histórico. Bloquear após início ou check-in. Não reutilizar aprovação após alteração relevante da proposta.
**Verificação:** `composer test` de corrida com mudança de escala, revogação e início; aprovação incompleta não altera ninguém; `npm run typecheck` e build.
**Saída:** troca muda ambos ou nenhum; duplo aceite não equivale a dupla atribuição; autofavorecimento negado. Aceite integrado depende da etapa 8: não aceitar stub permanente que sempre indique ausência de presença.
**Retorno:** pausar novas solicitações; alterações concluídas exigem nova operação autorizada, não rollback silencioso.

### 8. Presença e ajuste administrativo
**Contexto:** geofence, medição recente, precisão, saída ajustada distinta; V1-09. Depende de 4; integração obrigatória com 7 antes do aceite final.
**Tarefas/arquivos:** Attendance, pontos/raios/janelas de teste explicitados, validação servidor, comprovante textual, ocorrência/deferimento e permissões; reusar lock do plantão; coleta pontual. Versionar configuração geográfica e guardar a versão usada na decisão; distinguir instante da medição do dispositivo e instante de recebimento/decisão do servidor. Sem mapa externo obrigatório para concluir fluxo.
**Verificação:** `composer test` dentro/fora/borda, precisão inválida, pedido duplicado, saída sem entrada, ajuste pelo próprio coordenador, corrida check-in/troca; teste de UI com geolocalização simulada e posterior verificação em dispositivo físico via HTTPS antes de operação real. Prova de concorrência check-in/troca somente após integração das etapas 7 e 8.
**Saída:** localização negada não vira presença; ajuste identifica horário informado e aprovação sem alterar original.
**Retorno:** desativar novas capturas se necessário e encaminhar ocorrências; preservar eventos existentes.

### 9. Notificações, relatórios e revisão integrada
**Contexto:** V1-11/12/13/14; depende de 5,7,8.
**Tarefas/arquivos:** leitores de outbox, inbox, relatórios/paginação/filtros, exportação CSV/PDF privada e ajustes; deduplicar notificações e revalidar destinatário. Escolher biblioteca PDF compatível após avaliar dependências, não presumir instalação. Testar worker com contextos distintos sequenciais.
**Verificação:** `composer test`, `composer lint`, `composer analyse`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run test:e2e`, todos depois de confirmados em manifests; audits de dependências com resultados registrados. Testar export/job/download e revogação cross-tenant; responsividade 360/768/1440, teclado, foco e zoom.
**Saída:** jornada inteira sintética demonstrável; registros e totais reconciliados; revisão independente sem bloqueadores. Documentar limites de produção e teste físico pendente quando houver.
**Retorno:** pausar exports/notificações sem desfazer operações; remover artefatos expirados por política aprovada, não durante teste cego.

## Dependências, paralelismo e ownership

Sequência: 1 → 2 → 3 → 4. Depois de 4: 5 e 6 podem ser independentes; 7 depende de 6; 8 pode avançar separado de 5/6 após congelar contrato de Scheduling. Passo 9 integra todos.

Cada passo é uma unidade de revisão, podendo ser dividido antes de executar se exceder uma mudança revisável. Responsáveis de Imports, Requests e Attendance não alteram simultaneamente Scheduling, migrations comuns ou rotas compartilhadas. Integrações nesses arquivos são seriadas pelo agente principal.

Perfil de execução: modelo padrão herdado para trabalho; revisão adversarial com agente de arquitetura, sem trocar modelo por preferência. A escolha de execução por agentes não substitui testes e revisão do principal.

## Alterações no plano

Ao dividir, inserir ou reordenar passo, registrar motivo, requisitos afetados, dependências e evidência. Não remover critérios para acomodar teste falhando. Reabrir decisão de produto se mudar comportamento aprovado; atualizar contrato antes de codificar. Não registrar passo como concluído sem comandos e resultado real.

## Rastreabilidade e gates

V1-01: 1/2; V1-02: 2; V1-03: 3; V1-04/05: 4; V1-06: 7; V1-07: 6; V1-08: 6/7; V1-09: 8; V1-10: 5; V1-11/12: 9; V1-13: 1/9; V1-14: 2/4/9.

Antes do código: aceite da arquitetura e desenho, escolha explícita da fronteira de isolamento, parâmetros sintéticos definidos e diretório de referências preservado. Antes de produção: infraestrutura, custos, requisitos de disponibilidade/recuperação, teste de restauração, retenção, análise de segurança e instituição piloto real. Propostas de infraestrutura não criam compromissos comerciais.

## Fontes oficiais consultadas

- https://laravel.com/docs/13.x/releases — requisitos e suporte de versão.
- https://laravel.com/docs/13.x/starter-kits — React/Inertia e Fortify.
- https://laravel.com/docs/13.x/queues — filas e processamento após transação.
- https://laravel.com/docs/13.x/database — transações e bancos suportados.
- https://www.postgresql.org/docs/current/ddl-rowsecurity.html — limites e comportamento de RLS; não é configuração já aplicada.

## Revisão

Revisão independente pelo agente Software Architect concluída: três lacunas identificadas e incorporadas — caminhos alternativos de atribuição, governança herdada/versionada e dependência entre presença e solicitações. Acrescentadas sincronização de revogação e validação física via HTTPS. Revisão documental não comprova comportamento de software. Nenhum teste de aplicação executado nesta etapa documental.
