# Contrato de capacidades — plataforma hospitalar

Método: ECC Product Capability. Data: 17/10/2026.
Estado: decisões de escopo e fluxo da primeira entrega web fechadas; detalhamento proposto consolidado para revisão de arquitetura. O contrato V1 abaixo prevalece sobre propostas anteriores quando indicado.
Fontes: BRAINSTORMING.md, PRODUCT-BRIEF.md e decisões do usuário nesta conversa.

## Capacidade

Permitir que hospitais médios e grandes redes substituam seus sistemas por etapas, preservando histórico e continuidade operacional, com acesso por função, gestão por unidade e consolidação da rede. Facilidade de uso, migração confiável e gestão integrada são prioridades confirmadas.

## Restrições

### Confirmadas

- SaaS para múltiplos hospitais clientes, com isolamento entre clientes independentes.
- Cobertura completa pretendida, com entrega gradual de fluxos utilizáveis.
- Particular, convênios e SUS.
- Histórico compartilhável entre unidades autorizadas da mesma rede.
- Cenários fictícios nesta etapa; nenhuma implantação real identificada.
- Não iniciar implementação durante esta etapa de definição.
- Governança aprovada: a rede define regras obrigatórias e cada hospital configura sua operação dentro desses limites, com responsável e data de vigência registrados nas alterações.

### Invariantes propostas para revisão

- Cada registro tem origem, identificador e contexto de acesso verificáveis.
- Um registro importado é contabilizado como aceito ou como exceção; nunca desaparece silenciosamente.
- Repetir uma importação não pode criar duplicidades por mero reprocessamento.
- Cada domínio tem uma fonte oficial definida durante a coexistência com o legado.
- Operações de uma unidade respeitam permissões locais e centrais; consolidação não amplia permissões automaticamente.
- Uma alteração de escala só muda a responsabilidade após a conclusão do fluxo aplicável, ainda a definir.
- Regras publicadas pelo concorrente não se tornam automaticamente regras deste produto.

### Preferências e detalhes ainda sujeitos à revisão

- Mudanças de regras não reinterpretam silenciosamente registros antigos; tratamento de retroatividade ainda precisa ser definido.
- A ordem dos módulos posteriores à V1 permanece aberta; o escopo da primeira entrega está aprovado no contrato V1.

## Contrato voltado à implementação futura

### Atores e superfícies propostas

| Ator | Superfície | Limite |
|---|---|---|
| Operador do SaaS | Contratos, módulos, implantação e suporte | Gestão comercial não concede acesso clínico automático |
| Administrador da rede | Unidades, políticas e indicadores | Apenas seu cliente/rede |
| Gestor da unidade | Operação e configurações locais | Apenas unidades e funções autorizadas |
| Profissional | Agenda, tarefas e registros pertinentes | Vínculo e permissões aplicáveis |
| Equipe de migração | Mapeamento, testes e exceções | Fontes e domínios aprovados |
| Paciente | Portal e documentos liberados | Identidade e autorização verificadas |

Perfis clínicos e administrativos serão detalhados por jornada; estes não constituem matriz final de permissões.

### Governança: catálogo inicial proposto

O modelo geral foi aprovado. A distribuição abaixo é proposta para validação, não decisão já tomada pelo usuário.

| Classe | Exemplos propostos | Quem decide |
|---|---|---|
| Obrigatória | Isolamento entre clientes, rastreabilidade, limites de acesso e preservação da origem de dados | Política da plataforma e responsáveis autorizados da rede, sem permitir que configuração enfraqueça invariantes da plataforma |
| Delegável | Horários de setores, modelos de escala, filas e responsáveis locais | Hospital, apenas nos campos e limites publicados pela rede |
| Exceção autorizável | Alteração pontual de prazo operacional ou aprovação fora da rotina | Autoridade designada pela rede, para escopo e período explícitos |

Exceções não autorizam acesso entre clientes nem remoção de auditoria. Protocolos clínicos e obrigações legais não são tratados como preferências administrativas; sua governança exige especificação própria.

**Experiência proposta:** cada configuração mostra valor efetivo, origem (rede ou hospital), limites, vigência e quem pode alterá-la. O hospital pode simular o impacto de uma mudança antes de publicá-la. Sem configuração local válida, vale a configuração herdada; sem regra aplicável a uma ação que exige autorização, não há autorização implícita.

**Ciclo da política:** rascunho → em revisão → aprovada → agendada ou vigente → substituída ou revogada. Alterações criam novas versões. Rejeições retornam para correção preservando o motivo. Quem pode propor, aprovar e publicar será definido por permissão; a política de autoaprovação permanece aberta.

**Exceção:** solicitada → em análise → aprovada ou recusada → encerrada por expiração ou revogação. A aprovação registra regra, justificativa, hospital/setor, operações abrangidas, aprovador e validade. Renovação é nova decisão. Concessões não são retroativas por padrão proposto.

**Conflitos e transição:** antes de mudar um limite central, listar hospitais e operações afetados. Configurações locais incompatíveis exigem ajuste antes da ativação ou transição explicitamente aprovada. Não cancelar silenciosamente plantões ou atendimentos existentes. Alterações de acesso exigem tratamento próprio de revogação; a versão histórica não concede acesso atual.

**Concorrência e histórico:** duas edições da mesma versão não podem sobrescrever uma à outra silenciosamente. A decisão sobre uma operação registra a regra avaliada; políticas aplicáveis são revalidadas no momento da ação final. Se houve mudança relevante, mostrar o conflito e exigir nova decisão, sem repetir a operação automaticamente.

**Exemplos sintéticos para conferir o desenho:**
- Um gestor altera horário de seu setor dentro da faixa permitida: alteração aceita e rastreada.
- O mesmo gestor tenta alterar hospital sem vínculo: ação negada.
- A rede publica limite incompatível com uma configuração local: impacto identificado antes da ativação.
- Uma exceção expira: não autoriza novas ações, mas seu histórico permanece.
- Dois gestores publicam sobre a mesma versão: o segundo recebe conflito, sem perda silenciosa.
- Um usuário perde permissão: não mantém acesso por ter participado de uma operação antiga.

Esses casos são critérios de aceite propostos; não foram executados, pois não existe aplicação implementada.

### Mapa de capacidades e dependências

| Capacidade | Entradas e resultados | Dependências principais |
|---|---|---|
| Identidade e governança | Clientes, unidades, usuários → acesso contextual | Base comum |
| Migração | Arquivos/exportações → dados reconciliados e exceções | Identidade, proveniência e modelo do domínio |
| Equipes | Vínculos e escalas → cobertura e presença | Governança, elegibilidade e aprovações |
| Atendimento | Chegada/agendamento → episódio e encaminhamento | Identidade do paciente, agenda e unidades |
| Assistência | Episódio → registros, prescrições e continuidade | Atendimento, profissionais, catálogo e permissões |
| Farmácia e materiais | Solicitações/consumo → dispensação e movimentação | Assistência, catálogo, estoque e rastreabilidade |
| Internação e urgência | Demanda → cuidado, leito, transferência e alta | Atendimento, assistência e operação coordenados |
| Diagnóstico e cirurgia | Solicitações/agendas → resultados e procedimentos | Assistência, equipes, materiais e integrações |
| Faturamento | Atendimentos e consumo → contas e recebimentos | Modalidade, contratos, autorizações e dados assistenciais |
| Portal e indicadores | Dados autorizados → acompanhamento | Capacidades anteriores e políticas de publicação |

Dependências não implicam que o módulo inteiro precisa ser entregue de uma vez. Cada jornada deve incluir o subconjunto necessário de seus módulos dependentes.

### Estados e transições propostos

**Importação:** recebido → mapeado → validado → simulado → em conferência → aprovado para aplicação → aplicado → reconciliado. Erros geram pendências corrigíveis e nova validação. Aplicado não significa liberado para uso operacional.

**Migração de processo:** planejado → teste → coexistência → corte aprovado → monitoramento → concluído. Retorno operacional deve ser planejado por domínio, incluindo dados produzidos após o corte; não equivale a apagar alterações.

**Transferência de paciente:** solicitada → aceita pelo destino → saída registrada → recebimento registrado → concluída. Cancelamento e recusa preservam motivo e autoria. Conteúdo clínico do resumo e condições de emergência permanecem abertos.

**Mudança de plantão:** rascunho → solicitada → decisão pendente → concluída, recusada, cancelada ou expirada. A escolha entre vários candidatos elegíveis pelo coordenador, com justificativa registrada, foi confirmada. Na V1, todas as mudanças de responsável exigem aprovação do coordenador; automações ficam para entregas posteriores.

### Plantões: seleção e responsabilidade

**Decisão confirmada:** quando houver vários candidatos a uma vaga, o coordenador escolhe entre os elegíveis e registra a justificativa. A candidatura, por si só, não é uma escolha automática pelo critério de ordem de chegada.

**Elegibilidade entre hospitais confirmada:** permitir assumir plantões em outro hospital da mesma rede somente com vínculo e habilitação aprovados para a unidade de destino e entre hospitais do mesmo estado (UF). Aprovação em uma unidade não implica aprovação nas demais. Esta é uma regra de produto definida pelo usuário, não uma conclusão sobre exigências legais.

**Detalhamento proposto:** considerar a UF da unidade de origem e da unidade de destino, não o endereço residencial do profissional. Para profissionais com vários vínculos, o contexto da unidade de origem deve ser explícito; sua resolução nas candidaturas sem plantão de origem ainda precisa ser definida. Na troca, cada participante deve cumprir os requisitos da respectiva unidade de destino.

**Validação proposta:** conferir os requisitos na candidatura e novamente na conclusão da atribuição. UF ausente, vínculo não aprovado ou habilitação não aprovada impedem a conclusão e geram motivo compreensível. Não presumir aprovação a partir de um cadastro importado incompleto. Política de validade, suspensão e expiração das aprovações ainda a detalhar.

**Decisão atualizada para a V1:** aprovação do coordenador em todas as mudanças de responsável. Automação de aprovações não faz parte desta entrega. Isso resolve a ambiguidade anterior.

**Fluxo proposto para vaga anunciada:** anúncio aberto → candidaturas recebidas → escolha justificada → validação final → substituição concluída. Retirada, recusa, cancelamento e expiração preservam histórico. Anunciar não libera o responsável atual; sua substituição só ocorre quando o processo termina com sucesso. Essa regra de responsabilidade ainda é proposta.

**Regras propostas para tornar a escolha verificável:**
- Exibir função, vínculo com unidade/setor e disponibilidade pertinentes à vaga. Critérios completos de elegibilidade ainda serão definidos.
- Aplicar o limite confirmado de mesma rede e mesma UF, com vínculo e habilitação aprovados no destino; critérios adicionais de função, setor e disponibilidade continuam a definir.
- Na conclusão, revalidar elegibilidade, candidatura ativa, vaga aberta e ausência de conflito conforme critérios aprovados.
- Impedir que dois coordenadores atribuam a mesma vaga a pessoas diferentes; a segunda tentativa recebe o estado atualizado, sem sobrescrever a primeira.
- Se o candidato desistir ou perder elegibilidade antes da conclusão, retornar a seleção para análise; não escolher automaticamente outro candidato.
- Registrar autor, justificativa, data, versão das regras e resultado da decisão. Visibilidade da justificativa ainda precisa ser definida; destinatários das notificações: envolvidos e coordenadores da unidade (etapa 9a).
- Não aplicar uma exceção automática à escolha entre múltiplos candidatos sem uma nova decisão explícita do produto.

**Casos de aceite propostos:** escolha válida e justificada; tentativa sem justificativa; candidatura retirada; conflito surgido após a escolha; duas escolhas concorrentes; acesso de coordenador de outra unidade. São cenários documentados, não testes executados.

### Interface e dados

- Entradas de migração: fonte, versão do mapeamento, lote e identificação original de cada item.
- Saídas: itens aceitos, exceções por motivo, vínculos, totais aplicáveis e relatório de reconciliação.
- Recuperação: retomada por lote, registro do que foi efetivamente aplicado e reprocessamento idempotente. Transações e concorrência serão definidos na arquitetura.
- Entidades conceituais comuns: cliente, rede, unidade, setor, usuário, profissional, vínculo, paciente, atendimento, documento, evento de auditoria, lote e item importado.
- Cadastros de pacientes exigem política de deduplicação; coincidência de nome não autoriza fusão automática.
- Dados clínicos importados preservam autor e origem disponíveis; ausência de informação não deve ser preenchida como fato inventado.
- Exportação, retenção, exclusão, consentimentos aplicáveis e acesso excepcional precisam de definição específica antes da implementação.

### Segurança, cobrança e operação

- Separar administração do SaaS, operação da instituição e acesso assistencial.
- Testar isolamento entre clientes, unidades e perfis com casos de acesso negado, não apenas caminhos de sucesso.
- Permissões são aplicadas no serviço que fornece o dado, além de controles de interface.
- Registrar mudanças de permissão, decisões de fluxo e resultado de importações, evitando dados clínicos e credenciais em logs técnicos desnecessários.
- Observabilidade proposta: pendências de importação, falhas de integração, tempo de resolução e atrasos por processo.
- Preço, métrica de cobrança, módulos contratáveis e comportamento em inadimplência estão em aberto. Não propor bloqueio automático de cuidados ou histórico como regra implícita.
- Disponibilidade, recuperação, operação sem conexão e metas de desempenho precisam de requisitos antes da arquitetura.

### Critérios propostos para uma simulação integrada

1. Importar escala sintética com duplicidades e erros conhecidos, contabilizando todos os itens.
2. Corrigir pendências e repetir o lote sem duplicar registros já aplicados.
3. Resolver uma vaga conforme regras explícitas e preservar a sequência de decisões.
4. Refletir o resultado nas visões da unidade e da rede autorizadas.
5. Negar consulta de outro cliente e de usuário sem permissão.
6. Medir passos, tempo e erros nas tarefas, sem assumir melhoria antes da comparação.

## Não objetivos desta etapa

Não produzir código, selecionar tecnologia, prometer compatibilidade com fornecedores desconhecidos nem declarar prontidão clínica ou comercial. Não alterar os documentos existentes para transformar propostas em decisões aprovadas.

## Questões abertas

| Decisão | Impacto | Situação |
|---|---|---|
| Regras centrais versus autonomia local | Configuração e permissões em todos os módulos | Modelo aprovado; catálogo de regras delegáveis ainda a detalhar |
| Aprovações de plantão | Estados, responsabilidade e notificações | V1: coordenador aprova todas as mudanças; seleção entre vários candidatos exige justificativa |
| Primeira jornada e sequência | Prioridade e limites da entrega | V1 aprovada: cadastros, permissões, importação de escalas e plantões; demais entregas a ordenar |
| Identidade e deduplicação do paciente | Segurança e continuidade do histórico | Decidido em 18/10/2026: ver Contrato da entrega 3 |
| Fontes legadas | Adaptadores e limites de preservação | Nenhum fornecedor identificado |
| Metas operacionais e equipe | Arquitetura, custo e cronograma | Em aberto |

## Encaminhamento ECC

- Pronto para implementação: não.
- Precisa de esclarecimento de produto: revisar o contrato V1 detalhado; unidade-base e dupla aprovação nas trocas já confirmadas. Ver fechamento V1 para decisões mais recentes.
- Precisa de revisão de arquitetura: após resolver regras estruturais e requisitos operacionais.
- Próxima etapa: continuar ECC Product Capability neste mesmo documento, detalhando uma jornada por vez. ECC Blueprint poderá organizar execução quando houver desenho validado e autorização para implementar.

## Contrato V1 — web responsivo

### Status e promessa

**Aprovado pelo usuário em 17/10/2026:** web responsivo antes de Android/iPhone; primeira entrega com cadastros, permissões, importação de escalas e plantões completos; coordenador aprova todas as mudanças de responsável; registro de presença bloqueado fora do raio permitido pelo hospital.

**Promessa da entrega:** uma rede fictícia consegue cadastrar sua estrutura e profissionais, importar e publicar escalas, resolver vagas e mudanças de plantão, registrar presença e acompanhar resultados por unidade e rede em computador e celular.

Os requisitos detalhados abaixo são a proposta concreta para revisão final. Não representam testes executados nem aprovação implícita de cada escolha operacional. Não existe código ou stack detectada nesta pasta; há apenas documentos e nenhum repositório Git inicializado.

### Limites da entrega

Inclui aplicação web online para desktop, tablet e celular; autenticação, escopo por cliente/unidade, notificações internas e relatórios operacionais. Não inclui prontuário, pacientes reais, faturamento SUS/convênios, integração legada específica, pagamentos, decisão clínica, mensageria externa, aplicativos nativos nem aprovação automática. Importação clínica permanece em entrega futura, embora a migração de escalas já prove a rastreabilidade inicial.

Web responsivo não implica PWA, operação offline, publicação em lojas ou push. Não haverá simulação silenciosa de envio real de mensagens nem confirmação de presença sem resposta do servidor. O contrato de negócio deverá ser reutilizável pelos futuros aplicativos; arquitetura e tecnologia ainda serão selecionadas.

### Papéis e autorização mínima proposta

| Papel | Pode fazer | Não recebe automaticamente |
|---|---|---|
| Operador SaaS | Provisionar cliente e administrador inicial | Consulta de escalas, localização ou dados pessoais do cliente |
| Administrador de rede | Administrar unidades, usuários, permissões e políticas; visão consolidada autorizada | Permissão implícita para aprovar o próprio pedido |
| Gestor de hospital | Administrar setores e profissionais das unidades atribuídas | Acesso às demais unidades |
| Coordenador de plantões | Criar/publicar escalas, decidir mudanças e selecionar candidatos no escopo atribuído | Poder de ignorar vínculo, habilitação ou UF |
| Profissional | Consultar seus plantões, candidatar-se, solicitar mudanças e registrar a própria presença | Escolher outro responsável ou aprovar mudanças |
| Importador | Preparar lotes e corrigir exceções no escopo atribuído | Publicar escala ou aprovar habilitação por importar dados |
| Auditor | Consultar trilha e relatórios autorizados | Alterar registros |

Uma pessoa pode acumular papéis, mas não aprova uma mudança em que seja participante. Deve haver outro coordenador autorizado; na ausência, a pendência fica visível para a rede designar um aprovador. Operação com identificador de outro cliente ou unidade não autorizada é negada também no servidor.

### Requisitos funcionais e aceite

| ID | Requisito proposto | Evidência de aceite necessária |
|---|---|---|
| V1-01 | Login, logout, recuperação de senha e desativação de acesso; sem cadastro público irrestrito | Conta desativada perde acesso e sessão não autoriza novas ações; recuperação não revela existência de conta |
| V1-02 | Cadastrar rede, hospitais, UF, fuso horário, setores e locais de presença | Hospital sem UF/fuso não publica escala; nenhuma configuração concede acesso a outro cliente |
| V1-03 | Cadastrar profissional, função, vínculos e habilitações por destino, com status e validade | Aprovação em A não habilita em B; pendência, suspensão e expiração impedem nova atribuição |
| V1-04 | Criar e editar escala em rascunho; publicar com responsável ou vaga aberta | Datas completas, início anterior ao fim e vínculos válidos; rascunho não aparece como escala confirmada |
| V1-05 | Agenda diária/semanal/mensal, lista e detalhes por perfil | Plantão atravessando meia-noite permanece consultável até o fim previsto (decisão do usuário, 18/10/2026); data e fuso ficam claros |
| V1-06 | Solicitar troca entre dois plantões e transferência direta | Resumo distingue as operações; destinatário aceita; coordenadores exigidos aprovam antes de mudar a escala |
| V1-07 | Anunciar plantão e candidatar-se a vaga, com retirada antes da conclusão | Anúncio não remove responsável; candidatura não equivale a alocação; coordenador escolhe e justifica |
| V1-08 | Consultar solicitações com status, histórico e motivo compreensível | Cancelamento pendente preserva escala original; recusa e expiração têm resultado explícito |
| V1-09 | Registrar presença dentro do raio e tratar ocorrências administrativas separadas | Fora do raio não há checkout geográfico; ajuste autorizado exige horário informado, justificativa e trilha, sem alterar o original; repetição não duplica evento |
| V1-10 | Importar cadastros de profissionais/vínculos e escalas via CSV UTF-8 | Prévia e relatório contabilizam todas as linhas; vínculos importados não aprovam habilitações automaticamente |
| V1-11 | Consultar indicadores e exportar escala/presença em CSV e PDF | Contagens reconciliam com registros; filtros e permissões iguais aos da tela; dados precisos de localização ausentes dos relatórios gerais |
| V1-12 | Notificações internas de pedidos, decisões e alterações | Apenas envolvidos e coordenadores autorizados recebem; falha de notificação não desfaz operação concluída |
| V1-13 | Ajustes de conta, senha, preferências e unidades autorizadas | Alterar unidade selecionada não altera vínculos, UF-base ou permissões |
| V1-14 | Auditar decisões, publicações, importações e alterações de acesso | Autor, escopo, instante e resultado recuperáveis; nenhum segredo em logs |

Campos de valor do plantão serão opcionais, com moeda explícita, e não representam pagamento ou cálculo de folha. Visibilidade proposta: próprio profissional e gestores autorizados; não exibir valores individuais de colegas na escolha de troca.

### Estados, responsabilidade e concorrência

- **Plantão:** rascunho → publicado → encerrado; cancelamento explícito preserva histórico. Responsável, anúncio e presença são dimensões separadas. Anunciar não altera o vínculo atual.
- **Troca:** solicitada → aceita pelo destinatário → aguardando coordenação → concluída. Recusa, cancelamento antes da conclusão e expiração são terminais. Alterações nos plantões envolvidos invalidam a decisão anterior e exigem reavaliação.
- **Transferência:** mesma sequência da troca, mas apenas um plantão muda de responsável.
- **Entre unidades:** aprovação pelos coordenadores autorizados das duas unidades confirmada pelo usuário para trocas. Uma pessoa com os dois escopos pode registrar ambas as decisões, se não for participante. A mudança só se efetiva após todas as aprovações.
- **Vaga:** aberta → preenchida, cancelada ou expirada. Candidatura: ativa → retirada ou seleção pendente; seleção pendente → atribuída quando a operação inteira é confirmada. Se a revalidação falha, a seleção volta para análise e a candidatura retorna a ativa somente se continuar elegível; caso contrário fica inelegível com motivo. Ao preencher a vaga, demais candidaturas ativas tornam-se não selecionadas. Ao cancelar ou expirar a vaga, candidaturas pendentes são encerradas com o motivo correspondente. Nenhum desses estados altera silenciosamente uma atribuição já concluída.
- **Presença:** sem entrada → entrada registrada → saída registrada por localização ou encerramento administrativo justificado. Checkout geográfico exige entrada anterior. Ocorrência: aberta → em análise → deferida ou indeferida. Só o deferimento autorizado da ocorrência de saída encerra administrativamente a presença; tentativa negada e ocorrência apenas aberta não são presença confirmada.

Na conclusão de mudança, conferir simultaneamente os plantões e participantes afetados. Apenas um resultado pode vencer a disputa por uma vaga; a segunda ação informa o conflito. Uma troca aplica os dois lados ou nenhum. Reenvio da mesma intenção não gera nova mudança.

**Limite temporal confirmado:** bloquear troca e transferência ao atingir o início previsto ou registrar check-in, o que ocorrer primeiro. Revalidar na aprovação final; pedidos pendentes passam a expirados quando perdem essa condição. Proposta coerente para vaga/cessão: não anunciar nem atribuir após início ou presença; substituições durante plantão ficam fora da V1. Não adotar automaticamente o prazo de 12 horas descrito pelo concorrente.

Conflito de horário proposto: intervalos [início, fim) se sobrepõem; fim exatamente igual ao início seguinte não é sobreposição. Na troca, avaliar a escala resultante, excluindo os plantões que cada um deixará. Deslocamento, intervalos mínimos e carga máxima precisam de regras próprias; não são considerados atendidos só por não haver sobreposição.

Após conclusão, retirada de candidatura não desfaz alocação; o profissional inicia nova solicitação. Cancelamento de plantão publicado exige coordenador, motivo e aviso aos envolvidos. Com presença iniciada, não apagar nem cancelar retroativamente: registrar ocorrência para tratamento específico.

### Elegibilidade por unidade e estado

Aplicar as condições confirmadas: mesma rede, mesma UF e vínculo/habilitação aprovados no destino. Proposta adicional: função exigida compatível e autorização para o setor, vigentes por todo o período do plantão.

**Decisão confirmada:** para candidatura sem plantão de origem, usar uma unidade-base administrativa do profissional na rede para determinar a UF. Essa base só pode ser alterada por gestor autorizado, com histórico; trocar a unidade exibida na interface não muda a base. Na troca, comparar as UFs dos dois plantões. Para transferência direta, há apenas um plantão: propõe-se comparar sua UF com a unidade-base do destinatário, assim como na candidatura a vaga; não comparar o plantão consigo mesmo. A seleção de uma unidade na interface não altera nenhuma dessas referências. Na transferência, a aprovação é do coordenador da unidade do plantão; a regra de duas unidades aplica-se à troca de dois plantões em hospitais distintos.

Suspensão ou revogação posterior à publicação impede novas atribuições e sinaliza plantões futuros afetados ao coordenador, sem deixar a escala silenciosamente descoberta.

### Presença com limite geográfico

O hospital configura pontos autorizados, raio em metros e limite aceitável de incerteza. Parâmetros são obrigatórios antes de ativar presença geográfica; números não serão inventados como requisitos aprovados.

Proposta conservadora: aceitar uma medição recente apenas se distância ao ponto mais a precisão informada couber no raio; precisão inadequada, localização ausente/negada, configuração incompleta e falha de conexão não geram confirmação. O servidor calcula a distância e valida o vínculo, plantão e sequência; a UI explica o impedimento e permite nova tentativa.

A localização fornecida pelo navegador não comprova presença física nem elimina falsificação. Não haverá rastreamento contínuo. Coordenadas são coletadas na ação solicitada, para validação e comprovante restrito; a política de retenção será definida antes de dados reais.

**Falhas e ajuste administrativo — confirmado:** permitir relatar uma ocorrência, preservando tentativa e explicação. O coordenador pode encerrar administrativamente a ocorrência de saída com horário informado e justificativa, sem fabricar um checkout validado por localização. A V1 inclui esse fluxo separado. O registro original permanece inalterado; autor, horário informado, instante da decisão e motivo são preservados. Relatórios distinguem saída geográfica de saída ajustada manualmente. Não há criação automática de check-in e o raio continua obrigatório para o registro geográfico. Proposta de controle: coordenador participante não aprova a própria ocorrência; outro coordenador autorizado decide. A presença pode terminar como encerrada administrativamente, com o checkout geográfico ausente e identificado como tal. Janelas permitidas de horário devem ser explicitamente configuradas pela rede/hospital antes de uso; testes usam valores sintéticos identificados como tais.

### Importação inicial e reconciliação

Formatos propostos: CSV versionados para profissionais, vínculos e plantões. Campos mínimos de escala: sistema de origem, identificador original, unidade, setor, função, início, fim e identificador de profissional ou indicação explícita de vaga. Datas incluem fuso ou offset; cabeçalhos e códigos têm mapeamento visível. Nome sozinho não identifica uma pessoa de forma segura.

Fluxo: enviar → validar estrutura → mapear códigos → prévia sem escrita operacional → corrigir exceções → aplicar linhas válidas por confirmação explícita → reconciliar. Importações de escala criam rascunhos; publicação é ação separada do coordenador.

Cada linha termina como criada, já existente idêntica, conflitante ou rejeitada. Uma identidade é cliente + sistema de origem + tipo de entidade + identificador original. Referências a profissional, unidade e setor são resolvidas no mesmo cliente e tipo correto; referências ausentes ou ambíguas tornam a linha pendente, sem associação por nome presumida. Reimportação idêntica não duplica; mudança de conteúdo produz conflito para resolução, sem sobrescrever presença ou decisões existentes. Falha parcial mostra exatamente as linhas aplicadas e permite retomar as restantes. Todas as contagens devem fechar com o total recebido.

Guardar origem, versão de mapeamento e resultado por item. Não executar fórmulas importadas; exportações devem neutralizar conteúdo interpretável como fórmula. Arquivos permanecem privados no escopo do cliente. PDF clínico e importação de banco legado não fazem parte desta primeira entrega.

### Experiência responsiva

- Gestor no desktop: calendário/lista, filtros, pendências e visão por unidade. Profissional no celular: próximos plantões, solicitações, vagas e presença com ações fáceis de alcançar.
- Referências visuais observadas nas 24 imagens: detalhes de plantão/vaga; calendário; menus; confirmação de entrada/saída; comprovante textual com mapa; lista/detalhes de solicitações; seleção de profissional e plantão; candidatura/retirada; ajustes.
- Preservar estados de carregamento, vazio, erro, sucesso e ação indisponível com motivo. Bloquear envio duplicado sem perder informações ao falhar.
- Testar como critérios propostos: larguras de 360, 768 e 1440 px; zoom de 200%; teclado, foco de modais, rótulos e leitura de mensagens; status não depende só de cor. Tabelas podem ter rolagem interna, sem esconder ações essenciais.
- Oferecer lista equivalente ao calendário e texto equivalente ao mapa. Idioma inicial português do Brasil; datas completas nos resumos de confirmação.

### Observabilidade e prontidão

Registrar decisões e falhas com identificadores de correlação, sem senhas, tokens ou coordenadas em logs gerais. Relatórios diferenciam vagas abertas, anunciadas e solicitações pendentes; métricas possuem definição e escopo. Tempo de resposta, volume, disponibilidade e recuperação exigem metas de engenharia antes da produção; nenhuma garantia de escala comercial foi validada.

Para aceite de construção local, exigir testes de isolamento, elegibilidade, estados, concorrência, reenvio, importação parcial e presença dentro/fora do raio, além de revisão do fluxo completo nos três tamanhos de tela. Simulações de localização não equivalem a teste em dispositivo físico. E-mail de recuperação em ambiente local pode ser capturado em transporte de teste identificado; entrega real depende de provedor configurado e verificado.

### Cenários fictícios

Hospital Horizonte e Rede Aurora continuam hipóteses de demonstração. A Aurora deve conter duas unidades na mesma UF e uma em outra UF para provar o bloqueio entre estados; um segundo cliente independente prova isolamento. Criar profissionais com múltiplos vínculos, habilitação pendente/expirada, plantão atravessando meia-noite, duas candidaturas concorrentes, sobreposição de horário e arquivos com erros conhecidos. Usar nomes e contatos sintéticos; não copiar dados pessoais das capturas.

### Fechamento e encaminhamento

1. Confirmado: plataforma, escopo, aprovação pelo coordenador, seleção justificada e limite geográfico.
2. Confirmados adicionalmente: unidade-base para candidaturas, aprovação de trocas entre hospitais pelas duas unidades, ajuste administrativo de saída com histórico e bloqueio de trocas/transferências após início ou check-in. Detalhes operacionais propostos estão identificados para revisão de arquitetura e aceite antes do código.
3. Depois do aceite do contrato: escolher arquitetura e stack com ECC, detalhar testes e plano de construção. Não iniciar código automaticamente por existir este documento.
4. Antes de operação real: metas de disponibilidade/recuperação, retenção, parâmetros de presença, credenciais de serviços e validação com uma instituição real.

**Estado ECC:** requisitos de escopo e decisões solicitadas fechados; encaminhar para revisão de arquitetura e plano de construção. Não pronto para implementação direta sem escolher stack, ambiente e validar o desenho técnico. Detalhes marcados como propostas não são fatos do concorrente nem decisões individuais já aprovadas.

### Registro de revisão documental — 17/10/2026

ECC Product Capability aplicado. Agente de pesquisa UX examinou as 18 imagens restantes (24 no total com a leitura inicial). Agente Software Architect realizou revisão independente e confirmou correções de identidade de importação, referências e estados de candidatura. As duas decisões então pendentes foram respondidas pelo usuário e incorporadas: ajuste administrativo e limite temporal. Verificações locais conferiram requisitos V1-01 a V1-14 únicos e decisões presentes. Nenhum teste de aplicação, migração real ou validação de produção foi executado; não existe aplicação implementada.

### Plano técnico proposto — 17/10/2026

[Blueprint ECC da primeira entrega web](plans/web-v1.md): arquitetura recomendada, alternativas, nove etapas, dependências, testes e critérios de saída. Revisão independente realizada e achados incorporados. Arquitetura aceita pelo usuário. Base Laravel/React, autenticação e infraestrutura local implementadas na branch chore/bootstrap-infrastructure; módulos hospitalares ainda não implementados. 17/10/2026: CI (quality, containers) aprovado; revisão independente sem bloqueadores, achados S1/S2/S4 corrigidos; PR #1 pronto para revisão, aguardando merge autorizado. Branch fix/auth-hardening (17/10/2026): recuperação de senha sem enumeração e com limite por IP, guarda de produção e checklist no README, traduções pt_BR, cabeçalhos de segurança/CSP no Nginx; revisão de segurança sem bloqueadores. Pendentes: trustProxies ao definir hospedagem, envio de e-mail de recuperação em fila (diferença de tempo), proteção remota da main (exige GitHub Pro ou repositório público). Hospedagem/CD adiado pelo usuário. Etapa 2 dividida em 2a (fundação do isolamento), 2b (telas de administração e seletor de instituição) e 2c (catálogo de regras e decisão sobre RLS). 2a na branch feat/tenant-isolation (17/10/2026): clientes, unidades com UF/fuso, setores, vínculos e papéis com chaves compostas e CHECK no PostgreSQL, contexto pela sessão com escopo fail-closed, desativação de conta e revogação de vínculo, provisionamento pelo operador sem acesso operacional, auditoria append-only também no banco; revisões de segurança e de banco sem bloqueadores. Pendentes para 2b: seletor de instituição, reativação de vínculo, proteção do último administrador, auditoria da troca de instituição, validação de fuso IANA, unicidade de e-mail sem distinção de maiúsculas. 2b dividida em 2b-1 e 2b-2. 2b-1 na branch feat/tenant-admin (17/10/2026): seletor de instituição com auditoria (inclusive negativas) e retorno à página pedida; unidades (UF válida, fuso IANA do Brasil, nome único sem distinção de maiúsculas, aviso de cadastro incompleto) e setores por unidade, sem exclusão; telas acessíveis verificadas em navegador a 360 e 1440 px; revisões de segurança e de React/acessibilidade sem bloqueadores. Pendentes para 2b-2: usuários e papéis (convite, concessão e retirada, revogação e reativação, último administrador), e-mail sem distinção de maiúsculas. Ambiente local: ao reconstruir só o serviço app, o Nginx mantém o IP antigo (502) até reiniciar o web.

### Direção visual — 17/10/2026

Usuário exige interface elegante, bonita e profissional. Direção em [docs/design/direction.md](docs/design/direction.md); acabamento deve ser verificado em navegador e não apenas por inspeção do código. 2c na branch feat/policy-catalog (17/10/2026): catálogo de regras versionado e só de inserção (precisão máxima da localização), herança rede → unidade com limites, valor efetivo resolvido na leitura (valor local nunca enfraquece o limite da rede), publicação direta com autor, motivo e vigência, conflito de versão; RLS não adotado na V1 (docs/adr/0002-no-postgres-rls-in-v1.md). Etapa 3 dividida em 3a e 3b. Decisões do usuário (17/10/2026): profissional independente da conta de acesso; habilitação por profissional + unidade + função, com status, validade e setores autorizados; cadastro com nome, registro no conselho, identificador externo e contato. 3a na branch feat/professionals: cadastro de profissionais com unidade-base obrigatória, troca de base com motivo e histórico na auditoria, gestor limitado às próprias unidades. 3b na branch feat/eligibility: funções da rede, habilitações por profissional + unidade + função com setores e validade local, aprovação na unidade de destino, versão de elegibilidade sob lock e serviço de elegibilidade com motivos (aprovação em A não habilita em B; pendência, suspensão, revogação e expiração bloqueiam). Etapa 4 dividida em 4a (plantões, publicação, cancelamento) e 4b (agenda e eventos). Decisões do usuário (17/10/2026): conflito conta rascunho e publicado; publicado só cancela; coordenador da unidade e administrador de rede montam a escala, gestor e auditor consultam. 4a na branch feat/shifts: restrição de exclusão contra sobreposição no banco, elegibilidade revalidada sob lock na criação e na publicação, horários no fuso da unidade. 4b na branch feat/schedule-calendar: agenda diária, semanal e mensal no fuso da unidade, com plantão que atravessa a meia-noite visível no dia em que termina; eventos para notificação ficam em audit_events (mesma transação), e o consumo fica para a etapa 9. Etapa 5 dividida em 5a (profissionais) e 5b (plantões). 5a na branch feat/import-professionals: importação CSV com prévia sem escrita operacional, resultado por linha (criada, idêntica, conflitante, rejeitada), identidade por cliente + origem + tipo + identificador, aplicação confirmada em segundo plano, retomável e sem duplicar, e arquivo original privado. 5b na branch feat/import-shifts: importação CSV de plantões como rascunho, com datas e fuso explícitos, referências resolvidas no mesmo cliente (profissional só pela identidade da mesma origem), as mesmas regras da escala e reimportação idempotente. Etapa 6 dividida em 6a (acesso do profissional), 6b (vagas abertas) e 6c (anúncio do próprio plantão). 6a na branch feat/professional-access: convite pelo e-mail do cadastro (decisão do usuário), papel Profissional na unidade-base, ligação única entre conta e profissional, e tela "Meus plantões" só com plantões publicados. 6b na branch feat/open-shift-applications: candidatura e retirada em vagas abertas, elegibilidade conferida na candidatura e na escolha, escolha pelo coordenador com justificativa obrigatória e atribuição atômica sob lock, um único escolhido por vaga, sem autoescolha nem substituição automática; demais candidatos encerrados. 6c na branch feat/shift-announcement: o responsável anuncia o próprio plantão e continua responsável até a escolha justificada do substituto pelo coordenador, que troca o responsável de forma atômica; quem anuncia não decide a própria substituição. Etapa 7 dividida em 7a (transferência direta) e 7b (troca). 7a na branch feat/shift-transfer: pedido do responsável a um colega habilitado, aceite do colega e aprovação do coordenador da unidade do plantão, sem decisão por participante; um pedido pendente por plantão; expiração ao atingir o início; troca de responsável atômica e revalidada. 7b na branch feat/shift-swap: troca entre dois plantões com aceite do colega, elegibilidade de cada lado comparada à UF do outro plantão, conflito avaliado na escala resultante, aprovação das duas unidades quando os hospitais são diferentes e aplicação dos dois lados ou nenhum. Etapa 8 dividida em 8a (presença por localização) e 8b (ocorrências e ajuste administrativo). Decisões do usuário (18/10/2026): duas janelas de horário configuradas pela rede/unidade (entrada a partir de X minutos antes do início; saída até Y minutos depois do fim) e pontos cadastrados pelo gestor da unidade e pelo administrador de rede. 8a na branch feat/attendance: pontos imutáveis por unidade, regras de precisão e janelas no catálogo sem valor padrão, decisão no servidor (distância + precisão no raio), tentativas recusadas preservadas, eventos só de inserção, e bloqueio de anúncio, transferência, troca e cancelamento após a entrada. 8b na branch feat/attendance-occurrences: ocorrências de entrada e saída relatadas pelo profissional e decididas com justificativa por coordenador não participante; saída deferida com horário informado encerra a presença administrativamente, identificada como ajustada, sem alterar os registros originais; entrada deferida não cria check-in; estado "em análise" omitido.

## Contrato da entrega 3 — atendimento ambulatorial

Método: ECC Product Capability. Data: 18/10/2026. Estado: **definição, sem autorização de implementação.** As decisões marcadas como "do usuário" foram tomadas nesta data; o restante é proposta para revisão.

### Capacidade

A recepção de uma unidade cadastra o paciente (mesmo sem documento), agenda e confirma a chegada para uma consulta com um profissional. O profissional abre o atendimento, registra a evolução clínica, anexa documentos e encerra. O prontuário fica disponível, com acesso restrito e auditado, para quem atende o paciente. Resultado esperado: um episódio ambulatorial completo e rastreável, base para internação e leitos (entrega 4), exames (entrega 5), faturamento (entrega 6) e portal do paciente (entrega 7).

### Decisões do usuário (18/10/2026)

- **Identidade:** CPF e Cartão Nacional de Saúde (CNS) são opcionais, mas únicos na rede quando informados. Quem chega sem documento recebe **cadastro provisório**, que é completado depois.
- **Duplicidade:** na hora do cadastro, o sistema avisa sobre um possível duplicado. A **unificação é manual**, feita por perfil autorizado, com motivo, e preserva o histórico dos dois cadastros. Coincidência de nome nunca unifica sozinha (regra já existente).
- **Acesso ao prontuário:** veem o prontuário os profissionais com **atendimento ativo do paciente na unidade**. Fora disso, existe **acesso de emergência** com justificativa obrigatória, auditado.
- **Recorte:** agenda e recepção; atendimento e evolução; documentos anexos; modalidade do atendimento (particular, convênio ou SUS), **sem faturamento**.

### Restrições e invariantes

- Isolamento entre clientes e escopo por unidade, como na V1 (permissão aplicada no servidor; operação com identificador de outro cliente é negada).
- Dado de saúde é dado pessoal sensível (LGPD): coleta mínima; nenhum dado clínico em logs técnicos, notificações, assuntos de e-mail ou URLs; exportações e relatórios gerais sem conteúdo clínico.
- **Toda leitura de prontuário fica registrada** (quem, quando, qual paciente, por qual motivo: atendimento ou emergência). Hoje a auditoria registra só escritas; leitura auditada é requisito novo.
- Evolução **assinada é imutável**: correção é um adendo assinado que referencia a original. Nada clínico é apagado; retirar um documento anexado exige motivo e mantém o arquivo e o registro.
- Unificação de pacientes não apaga cadastros: o duplicado fica marcado como unificado e aponta para o principal; atendimentos e documentos passam a aparecer no principal, com a origem preservada. Desfazer a unificação é proposta em aberto.
- Arquivos: privados, nome aleatório, servidos só a quem pode ver o prontuário, com tipo e tamanho validados e conteúdo nunca interpretado (mesmos cuidados da foto de perfil e da importação).
- Concorrência: um horário de agenda não recebe dois agendamentos sem encaixe explícito; duas pessoas abrindo o mesmo atendimento recebem estado atualizado, sem duplicar.

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Recepção (papel novo, por unidade) | Cadastro de paciente, agenda, confirmação de chegada, faltas, modalidade | Não vê evolução nem documentos clínicos |
| Profissional assistente (profissional já cadastrado, com função assistencial na unidade) | Agenda própria, abrir e encerrar atendimento, evolução, adendo, documentos | Prontuário só com atendimento ativo ou emergência justificada |
| Gestor da unidade | Agendas da unidade (grade, bloqueios), indicadores sem conteúdo clínico | Não vê prontuário por ser gestor |
| Unificador (perfil a definir; proposta: gestor da unidade ou administrador de rede) | Revisar suspeitas e unificar cadastros | Não lê conteúdo clínico para unificar |
| Administrador de rede | Unidades, papéis, tabela de convênios da rede | Não vê prontuário por ser administrador |
| Auditor | Trilha de acessos e decisões, incluindo leituras de prontuário | Não vê conteúdo clínico |
| Paciente | Fora desta entrega (portal na entrega 7) | — |

### Estados e transições (proposta)

- **Paciente:** provisório → completo (CPF ou CNS e dados mínimos informados); unificado (terminal, aponta para o principal).
- **Agendamento:** agendado → chegou (recepção confirma) → em atendimento → concluído. Saídas: cancelado (com motivo, antes da chegada) e faltou (após o horário, sem chegada). Remarcar = cancelar + novo agendamento ligado ao anterior.
- **Atendimento:** aberto (pelo profissional, a partir de uma chegada ou de um encaixe) → encerrado. Reabrir não existe: o que vier depois é adendo.
- **Evolução:** rascunho (só o autor vê) → assinada (imutável). Adendo: rascunho → assinado, ligado à evolução.
- **Documento:** anexado → retirado (com motivo; o arquivo permanece para a auditoria).
- **Acesso de emergência:** solicitado com justificativa → concedido na hora por tempo limitado → expirado. Cada concessão gera evento e aparece para a gestão e a auditoria.

### Interface e dados (proposta)

- Novas entidades: paciente, identificador do paciente (CPF, CNS, origem), suspeita/unificação, grade e bloqueio de agenda por profissional e unidade, agendamento, atendimento, evolução e adendo, documento anexado, convênio da rede, acesso de emergência e registro de leitura do prontuário.
- Paciente pertence ao **cliente (rede)**; atendimento pertence à **unidade**. Contrato vigente: "histórico compartilhável entre unidades autorizadas da mesma rede". A decisão de acesso deste contrato restringe a leitura a quem atende, em qualquer unidade da rede; a visão por unidade é tratada nas questões abertas.
- Busca de paciente por nome, data de nascimento, CPF ou CNS, mostrando o mínimo necessário para identificar (sem conteúdo clínico).
- Reaproveitar: auditoria só de inserção, notificações (sem conteúdo clínico), armazenamento privado, padrões de lock e idempotência da V1.

### Não objetivos desta entrega

Prescrição e administração de medicamentos, pedidos e resultados de exames, internação e leitos, urgência e classificação de risco, faturamento e autorização de convênio, assinatura digital ICP-Brasil, portal do paciente, integração com sistemas legados, importação de pacientes e documentos antigos, teleconsulta e aplicativos nativos.

### Decisões complementares do usuário (18/10/2026)

As questões abertas deste contrato foram respondidas; todas as propostas foram aceitas.

| Tema | Decisão |
|---|---|
| Prontuário entre unidades | Quem tem atendimento ativo do paciente em qualquer unidade da rede vê o histórico dele em toda a rede |
| Unificação | Gestor da unidade ou administrador de rede; desfazer é nova decisão, também auditada |
| Acesso de emergência | Dura 4 horas; o gestor da unidade é notificado e a auditoria registra; renovar exige nova justificativa |
| Assinatura da evolução | Confirmação com a senha no ato da assinatura, sem ICP-Brasil (limite conhecido) |
| Retenção e exclusão | Nada clínico é excluído nesta entrega; prazo legal e base de tratamento validados com especialista antes de operação real |
| Paciente menor ou tutelado | Campo de responsável legal no cadastro; o responsável não acessa nada até o portal (entrega 7) |
| Agenda | Grade por profissional e unidade com duração fixa por consulta; encaixe permitido pela recepção e marcado como tal |
| Convênios | Tabela da rede mantida pelo administrador; número da carteira e validade registrados no atendimento |
| Isolamento no banco (RLS) | Reavaliar a ADR-0002 antes de criar tabelas clínicas |
| Documentos | PDF, JPG e PNG, até 10 MB por arquivo |

### Encaminhamento ECC

- **Implementação autorizada pelo usuário em 18/10/2026**, uma etapa por vez: plano em `plans/web-v2-ambulatorio.md`.
- **Esclarecimento de produto:** concluído (decisões complementares acima).
- **Arquitetura:** RLS nas tabelas clínicas, leitura auditada e documentos por cliente ([ADR-0003](docs/adr/0003-rls-on-clinical-tables.md), aceita pelo usuário).

## Contrato da entrega 4 — urgência, internação e leitos

Método: ECC Product Capability. Data: 18/10/2026. Estado: **definição, sem autorização de implementação.** As decisões marcadas como "do usuário" foram tomadas nesta data; o restante é proposta para revisão.

### Capacidade

O paciente chega à urgência de uma unidade, com ou sem cadastro, e é classificado por risco. É atendido no pronto-socorro e pode receber alta ou ser internado. A gestão de leitos aloca um leito. A equipe assistencial registra a evolução durante a internação, o paciente pode mudar de leito, de setor ou de hospital dentro da rede, e recebe alta com sumário. Resultado esperado: um episódio de urgência e internação rastreável de ponta a ponta e um mapa de leitos confiável, base para prescrição e farmácia (entrega 4b), exames e cirurgia (entrega 5) e faturamento (entrega 6).

### Decisões do usuário (18/10/2026)

- **Recorte (jornada primeiro):** a entrega 4 cobre urgência com classificação de risco, internação, leitos, transferência e alta. **Prescrição e farmácia formam a entrega 4b**, também antes de qualquer uso real (o roteiro exige o conjunto coordenado antes de um corte produtivo).
- **Classificação de risco:** escala própria de **5 níveis** com cores, tempo-alvo de atendimento configurado pela rede e sinais vitais registrados. **Sem fluxogramas licenciados** (o Protocolo de Manchester exige licença).
- **Leitos:** papel novo **Gestão de leitos**, por unidade (como o núcleo interno de regulação): aloca, transfere e bloqueia leitos. A liberação após a higienização é sinalizada no sistema.
- **Transferência entre hospitais da mesma rede:** incluída. Transferência para fora da rede fica fora desta entrega.

### Restrições e invariantes

- Tudo o que vale na entrega 3 continua valendo: isolamento por cliente com RLS forçado nas tabelas clínicas ([ADR-0003](docs/adr/0003-rls-on-clinical-tables.md)), acesso clínico centralizado em `ClinicalAccess`, leitura auditada, evolução assinada imutável, nada clínico apagado e nenhum dado clínico em logs, notificações ou URLs.
- **Um leito, um paciente:** o banco recusa duas ocupações simultâneas do mesmo leito e duas internações ativas do mesmo paciente.
- **Um paciente internado tem sempre leito e médico responsável**; a troca de qualquer um dos dois é registrada com autor e horário, sem reescrever o histórico.
- **Movimentação conserva o passado:** cada troca de leito, de setor ou de unidade é um registro novo; o leito anterior entra em higienização.
- **Classificação não se apaga:** reclassificar gera um novo registro ligado ao anterior; o tempo de espera conta a partir da chegada.
- Concorrência: duas pessoas alocando o mesmo leito ou internando o mesmo paciente ao mesmo tempo resultam em uma única ocupação, com estado atualizado para a outra (verificado com corrida real, como nas entregas anteriores).

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Recepção da urgência (papel Recepção existente) | Chegada do paciente, cadastro provisório ou "não identificado" | Não classifica nem vê o prontuário |
| Classificação de risco (profissional de enfermagem) | Sinais vitais, queixa principal e nível de risco; reclassificação | Vê o prontuário só enquanto classifica (acesso pelo episódio) |
| Profissional assistente da urgência | Fila por risco e espera, atendimento de urgência (reaproveita o atendimento e a evolução da entrega 3), decisão de alta ou internação | Prontuário pelo episódio ativo |
| Gestão de leitos (papel novo, por unidade) | Mapa de leitos, alocação, reserva, bloqueio, transferência interna, aceite de transferência vinda de outra unidade | Não vê conteúdo clínico, só o necessário para alocar (nome, sexo, idade, isolamento, setor pedido) |
| Equipe do setor de internação | Evolução, movimentação solicitada, sumário de alta | Ver questão aberta sobre o acesso na internação |
| Higienização | Marca o leito como pronto | Só o estado do leito |
| Gestor da unidade | Configuração de setores, quartos e leitos; indicadores de ocupação sem conteúdo clínico | Não vê prontuário por ser gestor |
| Administrador de rede | Escala de risco e tempos-alvo da rede | Não vê prontuário por ser administrador |

### Estados e transições (proposta)

- **Episódio de urgência:** chegou → classificado (pode ser reclassificado) → em atendimento → alta da urgência, internação ou transferência. Evasão (saiu sem atendimento) com horário e autor.
- **Leito:** livre → reservado → ocupado → em higienização → livre. Bloqueado (manutenção ou isolamento, com motivo) sai e volta para livre.
- **Internação:** solicitada (pelo médico, com setor pedido) → leito alocado → internado → alta. Durante a internação: troca de leito, de setor ou de médico responsável.
- **Transferência entre unidades:** solicitada → aceita pela gestão de leitos do destino (leito reservado) → saída registrada → recebimento registrado → concluída. Recusa e cancelamento preservam motivo e autoria (máquina já descrita neste documento).
- **Alta:** tipo (melhora, a pedido, transferência, óbito, evasão) e sumário de alta assinado; o leito vai para higienização.

### Interface e dados (proposta)

- Novas entidades: setor de internação, quarto e leito com atributos (tipo, restrição de sexo, isolamento), estado do leito com histórico, episódio de urgência, classificação de risco (escala da rede, sinais vitais), internação, movimentação, transferência entre unidades, alta e sumário.
- Reaproveitar: paciente e unificação (3.2–3.3), atendimento, evolução, adendo, documentos e trilha de leitura (3.6–3.7), modalidade e convênio (3.5), notificações internas e auditoria.
- Tabelas com dados do paciente são clínicas (RLS); a estrutura de leitos (quarto, leito, atributos) não é.

### Não objetivos desta entrega

Prescrição e farmácia (entrega 4b), exames e cirurgia (entrega 5), faturamento e AIH (entrega 6), regulação estadual (CROSS, SISREG), transferência para fora da rede, escores de UTI, integração com monitores e equipamentos, painel de chamada por voz ou TV, assinatura ICP-Brasil, aplicativos nativos.

### Decisões complementares do usuário (18/10/2026)

As questões abertas deste contrato foram respondidas; todas as propostas foram aceitas.

| Tema | Decisão |
|---|---|
| Acesso ao prontuário na internação | Médico responsável e equipe assistencial do setor onde o paciente está internado (habilitação aprovada no setor), enquanto ele estiver lá; cada leitura registrada ([ADR-0004](docs/adr/0004-ward-team-record-access.md), proposta) |
| Paciente não identificado | Cadastro "Não identificado" com sexo, idade aparente e descrição; a identidade vem depois pela unificação manual existente |
| Compatibilidade do leito | Restrição de sexo ou isolamento avisa e pede justificativa registrada; a gestão de leitos decide, sem bloqueio |
| Sumário de alta | Obrigatório e assinado para alta por melhora, a pedido e transferência; óbito e evasão com registro de horário e autor (declaração de óbito fora do sistema) |
| Tempo-alvo estourado | Destaque visual na fila da urgência, sem notificação |
| Indicadores | Censo diário de leitos por unidade e setor (ocupados, livres, bloqueados, em higienização, taxa de ocupação, tempo médio de internação), sem conteúdo clínico |

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões complementares acima).
- **Arquitetura:** reaproveita as ADRs 0001 e 0003; o acesso pela equipe do setor é a [ADR-0004](docs/adr/0004-ward-team-record-access.md), proposta, aguardando aceite.
- **Plano em etapas:** `plans/web-v3-internacao.md`. **Implementação ainda não autorizada.**
