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

Método: ECC Product Capability. Data: 18/10/2026. Estado: **aprovado; implementação autorizada pelo usuário em 18/10/2026, uma etapa por vez.**

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
| Recepção da urgência (papel Recepção existente) | Chegada do paciente, cadastro provisório ou "não identificado"; evasão de quem ainda aguarda | Não classifica nem vê o prontuário; vê o nível e o nome de quem atende (decisão do usuário, 18/10/2026) |
| Classificação de risco (profissional habilitado na unidade) | Sinais vitais, queixa principal e nível de risco; reclassificação | Não abre o prontuário: vê só a classificação do episódio (decisão do usuário, 18/10/2026); o prontuário abre pelo atendimento ou pelo acesso de emergência |
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
| Acesso ao prontuário na internação | Médico responsável e equipe assistencial do setor onde o paciente está internado (habilitação aprovada no setor), enquanto ele estiver lá; cada leitura registrada ([ADR-0004](docs/adr/0004-ward-team-record-access.md), aceita) |
| Paciente não identificado | Cadastro "Não identificado" com sexo, idade aparente e descrição; a identidade vem depois pela unificação manual existente |
| Compatibilidade do leito | Restrição de sexo ou isolamento avisa e pede justificativa registrada; a gestão de leitos decide, sem bloqueio |
| Sumário de alta | Obrigatório e assinado para alta por melhora, a pedido e transferência; óbito e evasão com registro de horário e autor (declaração de óbito fora do sistema) |
| Tempo-alvo estourado | Destaque visual na fila da urgência, sem notificação |
| Indicadores | Censo diário de leitos por unidade e setor (ocupados, livres, bloqueados, em higienização, taxa de ocupação, tempo médio de internação), sem conteúdo clínico |

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões complementares acima).
- **Arquitetura:** reaproveita as ADRs 0001 e 0003; o acesso pela equipe do setor é a [ADR-0004](docs/adr/0004-ward-team-record-access.md), aceita pelo usuário.
- **Plano em etapas:** `plans/web-v3-internacao.md`. **Implementação autorizada pelo usuário em 18/10/2026**, começando pela 3.8.

## Contrato da entrega 4b — prescrição e farmácia

Método: ECC Product Capability. Data: 19/10/2026. Estado: **aprovado; ADR-0005, ADR-0006 e plano aceitos e implementação autorizada pelo usuário em 19/10/2026, uma etapa por vez.**

### Capacidade

O médico prescreve para o paciente internado ou em atendimento de urgência usando o catálogo de medicamentos da rede, e assina a prescrição. O farmacêutico da unidade avalia a prescrição e a farmácia dispensa os itens. A enfermagem do setor registra cada administração no horário previsto. Resultado esperado: o ciclo prescrição → validação → dispensação → administração fica rastreável por item e por horário, e as alergias do paciente são conferidas na prescrição. Essa base serve depois a exames e cirurgia (entrega 5) e ao faturamento (entrega 6).

### Restrições e invariantes (fixas, herdadas das entregas 3 e 4)

- **Tabelas clínicas:** prescrição, itens, validação, dispensação, administração e alergias ficam em tabelas clínicas com RLS forçado ([ADR-0003](docs/adr/0003-rls-on-clinical-tables.md)).
- **Acesso:** continua centralizado em `ClinicalAccess`, com cada leitura gravada na trilha. Nada clínico vai para logs, notificações, URLs ou contexto de auditoria.
- **Assinatura:** a prescrição assinada não muda. Corrigir significa suspender o item e prescrever outro. A prescrição é assinada com **certificado ICP-Brasil em nuvem** ([ADR-0006](docs/adr/0006-icp-brasil-cloud-signature.md)); até as credenciais do fornecedor existirem, vale a assinatura com senha, identificada como tal.
- **Quem prescreve:** só o médico com acesso ao paciente pela internação ativa ou pelo atendimento aberto. Por proposta, só funções que "atendem pacientes"; se a prescrição deve exigir também o conselho CRM, é a questão 7.
- **Administração:** cada registro é uma linha nova, feita, recusada ou não realizada com motivo, nunca reescrita. Administrar exige item validado e dispensado, salvo exceção registrada com motivo (questão 1).
- **Concorrência:** duas pessoas dispensando ou administrando o mesmo horário ao mesmo tempo produzem um único registro. Isso será verificado com corrida real.
- **Fim do acesso:** na alta, a prescrição ativa é encerrada e os horários pendentes deixam de ser administráveis.

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Médico (função que atende, com acesso ao paciente) | Prescrever, copiar a prescrição anterior, suspender item, ver alergias e o histórico de administração | Só pacientes com acesso pela `ClinicalAccess` |
| Farmacêutico (papel novo **Farmácia**, por unidade) | Fila de prescrições a avaliar; aprovar, aprovar com observação ou recusar item com motivo; dispensar | Vê a prescrição, as alergias, a idade, o peso (se houver) e o setor, não a evolução; cada leitura vai para a trilha (precisa de ADR nova) |
| Enfermagem do setor (ADR-0004) | Aprazamento e checagem: administrar, recusar ou não realizar com motivo | Pacientes do setor do leito |
| Administrador de rede | Catálogo de medicamentos (princípio ativo, forma, concentração, via, unidade, marca de controlado ou alto alerta) | Não prescreve por ser administrador |
| Gestor da unidade | Indicadores sem conteúdo clínico (prescrições pendentes de validação, atrasos de administração) | Não lê prescrição |

### Estados e transições (proposta)

- **Prescrição:** rascunho → assinada → (validada por item) → encerrada (vence, é substituída pela do dia seguinte ou termina com a alta). Item suspenso com motivo e autor.
- **Item:** pendente de validação → aprovado, aprovado com observação ou recusado (com motivo) → dispensado (por horário ou por período, questão 2) → horários aprazados.
- **Horário de administração:** previsto → administrado (horário real e autor), recusado pelo paciente, não realizado (com motivo) ou atrasado (atraso calculado, sem estado gravado).
- **Alergia:** registrada no paciente (substância, reação, gravidade, autor). Prescrever o medicamento correspondente exige justificativa registrada (questão 5).

### Interface e dados (proposta)

- Novas entidades: catálogo de medicamentos da rede (não clínico), alergia, prescrição, item de prescrição, validação farmacêutica, dispensação e administração (clínicas). Aproveitam atendimento, internação, setor do leito, habilitações, assinatura com senha, trilha de leitura, notificações e auditoria.
- A farmácia ler a prescrição é uma base nova de acesso clínico, que precisa de uma **ADR-0005** antes da implementação.

### Não objetivos desta entrega (proposta)

- Controle de estoque com lote, validade, inventário e compras (proposta para uma entrega 4c; ver questão 3).
- Receita de controle especial para uso fora do hospital (Portaria 344/98, notificação de receita) e livro de registro de controlados.
- Integração com base licenciada de interações ou bulário (as regras vêm de uma tabela da rede, decisão de 19/10/2026) e cálculo de dose por peso.
- Bombas de infusão, código de barras ou pulseira na beira do leito.
- Prescrição ambulatorial (receita para casa) e faturamento de materiais e medicamentos.

### Decisões do usuário (19/10/2026)

Todas as propostas abaixo foram aceitas: ciclo completo (1), dispensação por 24 horas com devolução (2), estoque fora, numa entrega 4c (3), prescrição válida por 24 horas e renovada por cópia revisada (4), alergia com aviso e justificativa (5), dupla checagem para controlados e alto alerta (6), prescrição só por função que atende e com CRM (7).

### Decisões complementares do usuário (19/10/2026, riscos do plano)

- **Interações e dose máxima:** a rede mantém uma tabela, cadastrada pela farmácia ou pela administração, com importação CSV. Cada medicamento tem dose máxima por administração e por dia, e cada par de interação tem gravidade (leve, moderada, grave, contraindicada). Na prescrição, dose acima do máximo ou interação com outro item ativo gera um alerta, que exige justificativa registrada. A farmácia vê os alertas e as justificativas na avaliação. A cobertura depende do cadastro; uma base licenciada fica fora por ora.
- **ICP-Brasil:** a prescrição é assinada com certificado digital ICP-Brasil em nuvem, por um prestador de serviço de confiança (PSC) credenciado, via API. O fornecedor e as credenciais dependem de contrato do usuário (bloqueio externo). A integração é construída e testada com um assinador de teste, e a evolução e o sumário continuam com senha até nova decisão.

### Questões (respondidas)

1. **Recorte do ciclo.** Proposta: prescrição, validação farmacêutica, dispensação e administração pela enfermagem, tudo nesta entrega. Alternativa: só prescrição e administração, com a farmácia numa entrega seguinte.
2. **Dispensação.** Proposta: dispensa por período de 24 horas por item, com devolução registrada. Alternativa: dose unitária por horário.
3. **Estoque.** Proposta: fica fora desta entrega; a dispensação é registrada sem baixa de estoque. Alternativa: estoque simples, com saldo por unidade, já agora.
4. **Validade da prescrição.** Proposta: 24 horas, renovada todo dia por cópia da anterior revisada; vencida, deixa de gerar horários. Alternativa: válida até ser suspensa.
5. **Alergia.** Proposta: aviso que exige justificativa registrada para prescrever (sem bloqueio). Alternativa: bloqueio, liberado só pela farmácia.
6. **Medicamentos controlados e de alto alerta.** Proposta: marcação no catálogo, destaque na tela e dupla checagem na administração (segunda pessoa confirma com a própria senha). Alternativa: só a marcação.
7. **Quem prescreve.** Proposta: profissional cuja função "atende pacientes" e com conselho CRM no cadastro. Alternativa: qualquer função que atenda pacientes (permitiria a prescrição de enfermagem, que tem regras próprias).

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões acima).
- **Arquitetura:** [ADR-0005](docs/adr/0005-pharmacy-prescription-access.md) e [ADR-0006](docs/adr/0006-icp-brasil-cloud-signature.md), aceitas.
- **Plano em etapas:** `plans/web-v4-prescricao.md`, aceito. **Implementação autorizada pelo usuário em 19/10/2026**, começando pela 4b.1.

## Contrato da entrega 4c — estoque da farmácia

Método: ECC Product Capability. Data: 22/10/2026. Estado: **aprovado; ADR-0007 e plano aceitos e implementação autorizada pelo usuário em 22/10/2026, uma etapa por vez.** Origem: decisão do usuário de 19/10/2026 (questão 3 da entrega 4b: estoque fora da 4b, numa entrega 4c).

### Capacidade

A farmácia de cada unidade sabe quanto tem de cada medicamento do catálogo, em que lote e até quando vale. Ela registra as entradas, e a dispensação da entrega 4b passa a baixar o estoque do lote certo; a devolução repõe o mesmo lote. A farmácia descarta o que venceu ou se perdeu, com motivo, e faz inventário com ajuste justificado. Resultado esperado: saldo por lote confiável e rastreável de ponta a ponta, sem dispensar lote vencido. Essa base serve depois a materiais e cirurgia (entrega 5) e ao custo e faturamento de medicamentos (entrega 6).

### Restrições e invariantes (propostas)

- **Saldo derivado de movimentos:** o saldo nunca é editado. Cada mudança é um movimento só de inserção (entrada, dispensação, devolução, descarte, ajuste de inventário), com autor, data e motivo quando houver. O saldo é a soma dos movimentos, e uma tabela de saldo por lote, se existir, só muda junto com um movimento, na mesma transação.
- **Nunca negativo:** um lote não fica com saldo negativo. Isso é garantido no banco (restrição), e não só na tela.
- **Lote vencido não sai:** a dispensação nunca usa lote vencido.
- **Concorrência:** duas dispensações simultâneas do último saldo de um lote resultam numa única baixa. O lock segue a ordem existente (internação → prescrição → item) e, por último, o lote. Isso será verificado com corrida real.
- **Sem dado de paciente no estoque:** o movimento de dispensação aponta só para o id da dispensação. Estoque e movimentos não são tabelas clínicas, e nome de paciente ou de medicamento ligado a paciente não vai para logs, notificações nem auditoria (só ids). Ligar um movimento ao paciente continua exigindo o acesso clínico da 4b (ADR-0005).
- **Por unidade:** cada unidade tem o seu estoque, e a farmácia de uma unidade não move o estoque de outra.
- **Histórico preservado:** nada é apagado. Lote errado é corrigido por ajuste com motivo, e as dispensações já feitas não são reinterpretadas.

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Farmácia (papel da 4b, por unidade) | Entrada de lote, saldo por medicamento e lote, descarte, inventário, alertas de vencimento e de estoque mínimo | Só a própria unidade |
| Gestor da unidade | Saldo, itens a vencer e abaixo do mínimo, perdas por motivo; aprova o ajuste de inventário (questão 5) | Sem dado de paciente |
| Administrador de rede | Unidade de estoque de cada medicamento do catálogo; visão consolidada da rede | Não movimenta estoque |
| Médico e enfermagem | Nada novo | — |

### Estados e transições (proposta)

- **Lote:** com saldo → zerado, ou vencido (calculado pela data, nunca gravado). Um lote zerado ou vencido continua no histórico.
- **Movimento:** só existe gravado; não muda nem sai.
- **Inventário:** aberto → contado (por lote) → ajustes aplicados ou cancelado. Durante a contagem, as dispensações continuam (questão 6).

### Interface e dados (proposta)

- **Entidades novas (não clínicas):**
  - lote: medicamento, unidade, código do lote, validade, fabricante opcional;
  - movimento de estoque;
  - inventário e as contagens de cada lote.
- **Catálogo:** ganha a unidade de estoque e o estoque mínimo por unidade (questão 2).
- **Dispensação da 4b:**
  - escolhe o lote e grava o movimento na mesma transação;
  - a devolução gera o movimento de volta para o mesmo lote.
- **Importação:** saldo inicial por CSV (lote, validade e quantidade), usando o mecanismo de importação existente, com relatório de exceções.

### Não objetivos desta entrega (proposta)

- Compras: pedido, cotação, fornecedor, nota fiscal eletrônica e contas a pagar.
- Custo, preço e faturamento de medicamentos (entrega 6).
- Farmácias satélites e estoque por setor (questão 1).
- Livro de controlados e escrituração na Anvisa (SNGPC) (questão 7).
- Materiais e insumos que não estão no catálogo de medicamentos (entrega 5).
- Código de barras e leitura de DataMatrix ou GTIN.

### Decisões do usuário (22/10/2026)

Propostas aceitas nas questões 1, 2, 3, 5, 6, 7, 8 e 9. Na questão 4, o usuário escolheu o meio-termo: **dispensar sem saldo continua recusado, mas a farmácia pode registrar a entrada esquecida (lote, validade e quantidade) na própria tela da dispensação e dispensar em seguida.** A invariante "nunca negativo" fica mantida.

### Questões (respondidas)

1. **Onde fica o estoque.** Proposta: um estoque por unidade (a farmácia da unidade). Alternativa: também farmácias satélites e estoque por setor, com transferência interna.
2. **Unidade de controle.** Proposta: o estoque conta na mesma unidade que a dispensação da 4b já usa ("unidades", como comprimido, ampola ou frasco). Cada medicamento ganha um rótulo de unidade de estoque e um estoque mínimo por unidade de hospital. Alternativa: embalagem com fator de conversão (caixa com 20 comprimidos).
3. **Escolha do lote na dispensação.** Proposta: o sistema sugere o lote válido que vence primeiro, e a farmácia pode trocar por outro lote válido. Se a quantidade não couber num lote só, divide entre lotes. Alternativa: sempre automático, sem escolha.
4. **Dispensar sem saldo.** Proposta: recusar, com a mensagem de que falta estoque, e a prescrição segue para outra solução fora do sistema. Alternativa: permitir como exceção com motivo, com o saldo ficando pendente. Isso violaria a invariante "nunca negativo", que teria de mudar.
5. **Ajuste de inventário.** Proposta: a farmácia conta, e o ajuste só é aplicado com a aprovação do gestor da unidade (duas pessoas, motivo obrigatório). Alternativa: a própria farmácia aplica, com motivo e auditoria.
6. **Inventário com farmácia funcionando.** Proposta: a contagem não bloqueia a dispensação. O ajuste compara a contagem com o saldo do momento da contagem e considera o que saiu depois. Alternativa: bloquear a dispensação do medicamento enquanto ele é contado.
7. **Controlados.** Proposta: nesta entrega, o controlado tem o mesmo controle por lote, mais o relatório de movimentos por período para a escrituração manual. O livro e o SNGPC ficam fora. Alternativa: incluir já o livro de registro.
8. **Alertas.** Proposta: aviso interno (as notificações existentes) para a farmácia e o gestor quando um lote vence em 30 dias e quando o saldo fica abaixo do mínimo, uma vez por dia. Alternativa: só a tela, sem aviso.
9. **Prescrições abertas no início.** Proposta: as dispensações feitas antes da 4c ficam sem movimento, e a devolução delas também não gera movimento. O saldo começa pela importação ou pelo primeiro inventário. Alternativa: criar movimentos retroativos estimados (não recomendado, porque inventa dados).

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões acima).
- **Arquitetura:** [ADR-0007](docs/adr/0007-pharmacy-stock-ledger.md), aceita.
- **Plano em etapas:** `plans/web-v5-estoque.md`, aceito. **Implementação autorizada pelo usuário em 22/10/2026**, começando pela 4c.1.
- **Implementação:** só depois do contrato, da ADR e do plano aprovados pelo usuário.

## Contrato da entrega 5 — exames e cirurgia

Método: ECC Product Capability. Data: 23/10/2026. Estado: **aprovado; [ADR-0008](docs/adr/0008-exam-team-record-access.md) e plano `plans/web-v6-exames.md` aceitos e implementação autorizada pelo usuário em 24/10/2026, uma etapa por vez.** Pendências do plano resolvidas com as propostas: laudo de imagem liberado só por profissional com CRM; pedido sem coleta aparece ao gestor depois de 7 dias. Origem: roteiro ("Diagnóstico e cirurgia: solicitações e agendas → resultados e procedimentos", mapa de capacidades) e as entregas 3, 4, 4b e 4c, que deixaram exames, cirurgia e materiais para esta entrega.

### Capacidade

O médico que atende o paciente (ambulatório, urgência ou internação) pede exames de laboratório e de imagem a partir do catálogo de exames da rede. A equipe de exames da unidade recebe o pedido, registra a coleta ou a realização e libera o resultado com laudo assinado; quem pediu é avisado, e um resultado crítico chega com destaque. Para cirurgia, o médico pede o procedimento, o centro cirúrgico agenda sala e equipe, a equipe percorre o checklist de cirurgia segura e o cirurgião registra a descrição cirúrgica assinada. Resultado esperado: pedido → realização → resultado, e pedido de cirurgia → agenda → checklist → descrição, rastreáveis de ponta a ponta no prontuário do paciente, base para o faturamento (entrega 6).

### Restrições e invariantes (fixas, herdadas das entregas 3 a 4c)

- **Dado clínico protegido:** pedidos, resultados, laudos, agenda cirúrgica com paciente e descrições são clínicos, com RLS forçado (ADR-0003) e leitura gravada na trilha. Notificações, logs e auditoria levam só ids e marcadores, nunca nome de paciente, exame ligado a paciente ou resultado.
- **Quem lê:** quem atende o paciente (ADR-0003 e ADR-0004). Quem executa o exame ou a cirurgia lê só o necessário para executar, por papel e unidade, com trilha (como a farmácia na ADR-0005). Isso exige uma ADR nova.
- **Assinatura:** pedido, laudo e descrição cirúrgica com a assinatura eletrônica simples (senha), como a prescrição até o ICP-Brasil real (ADR-0006).
- **Histórico preservado:** resultado liberado e descrição assinada não mudam; correção por adendo ou por resultado retificado, com o anterior visível.
- **Por unidade e por rede:** a equipe de exames e o centro cirúrgico de uma unidade só trabalham os pedidos da própria unidade; o catálogo é da rede.
- **Concorrência:** duas pessoas liberando o mesmo resultado ou agendando a mesma sala no mesmo horário resultam num só registro (verificado com corrida real).

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Médico que atende | Pedir exames e cirurgia no atendimento ou na internação; ver resultados; assinar a descrição cirúrgica | Só pacientes que atende |
| Equipe de exames (papel novo, por unidade) | Fila de pedidos, coleta ou realização, resultado e laudo | Só a própria unidade; lê o pedido, não o prontuário inteiro |
| Centro cirúrgico (papel novo, por unidade) | Agenda de salas, equipe, checklist de cirurgia segura | Só a própria unidade |
| Enfermagem do setor | Ver pedidos e resultados dos internados do setor; checklist no centro cirúrgico | Como na ADR-0004 |
| Administrador de rede | Catálogo de exames e procedimentos; salas do centro cirúrgico por unidade | Sem dado clínico |

### Estados e transições (proposta)

- **Pedido de exame:** pedido → coletado ou realizado → resultado liberado; ou cancelado (com motivo, antes do resultado). Resultado liberado pode ser retificado, nunca apagado.
- **Pedido de cirurgia:** pedido → agendado (sala, data e equipe) → em andamento (checklist de entrada) → concluído (descrição assinada); ou cancelado ou reagendado, com motivo.
- **Checklist de cirurgia segura (OMS):** antes da anestesia, antes da incisão e antes da saída da sala; cada etapa com quem confirmou e quando.

### Interface e dados (proposta)

- **Catálogo da rede:** exames (nome, tipo laboratório ou imagem, material, preparo, valores de referência opcionais) e procedimentos cirúrgicos; código TUSS/SIGTAP opcional, sem faturar.
- **Entidades clínicas novas:** pedido de exame e seus itens, resultado e laudo, pedido de cirurgia, agenda de sala, equipe cirúrgica, checklist, descrição cirúrgica.
- **Resultado:** texto e valores com referência, e anexo em PDF pelo mecanismo de documentos existente (sem visualizador de imagem médica).
- **Avisos:** resultado liberado e resultado crítico para quem pediu, pelas notificações existentes, sem dado clínico no texto.

### Não objetivos desta entrega (proposta)

- Integração com equipamentos e sistemas de laboratório ou imagem (LIS, PACS, DICOM, HL7/FHIR) e visualizador de imagens.
- Laboratório externo ou de apoio com envio eletrônico.
- Ficha anestésica completa e controle intraoperatório de sinais vitais.
- Faturamento, autorização de convênio e AIH (entrega 6).
- Portal do paciente com resultados (entrega 7).
- Hemoterapia e banco de sangue.

### Decisões do usuário (23/10/2026)

Propostas aceitas nas questões 1, 2, 4, 5, 7, 8, 9 e 10. Duas escolhas diferentes da proposta:

- **Questão 3:** **dois papéis separados, Laboratório e Imagem**, por unidade, cada um com a sua fila.
- **Questão 6:** **quem executa lê o prontuário do paciente durante a execução, com trilha de leitura** (em vez de só o pedido). Detalhado na ADR-0008: vale enquanto o paciente tem um pedido em aberto daquele tipo na unidade; cada abertura fica na trilha. O centro cirúrgico segue a mesma regra na entrega 5b, em ADR própria.

Consequência para o recorte (questão 1): **entrega 5 = exames** (laboratório e imagem); **entrega 5b = cirurgia** (agenda de salas, checklist, termo obrigatório, descrição e materiais no livro de estoque).

### Questões (respondidas)

1. **Recorte.** Proposta: dividir como nas entregas 4 e 4b: **entrega 5 = exames** (laboratório e imagem) e **entrega 5b = cirurgia** (agenda, checklist, descrição e materiais). Alternativa: tudo numa entrega só (maior, mais demorada até o primeiro uso).
2. **Quem pede.** Proposta: só profissional com função que atende e habilitação na unidade, dentro do atendimento ou da internação (como a prescrição). Alternativa: também enfermagem, por protocolo.
3. **Equipe de exames.** Proposta: um papel novo "Exames" por unidade, que recebe a fila, registra coleta e libera o resultado; laudo de imagem liberado por profissional com conselho (CRM). Alternativa: papéis separados para laboratório e imagem.
4. **Formato do resultado.** Proposta: texto livre e valores com referência quando o catálogo os tiver, mais anexo PDF. Alternativa: só anexo PDF (mais simples, sem valores pesquisáveis).
5. **Resultado crítico.** Proposta: quem libera marca "crítico"; o médico que pediu recebe aviso destacado e precisa registrar ciência; sem ciência em 1 hora, o gestor da unidade é avisado. Alternativa: só aviso, sem ciência obrigatória.
6. **Acesso de quem executa (ADR nova).** Proposta: a equipe de exames lê só o pedido (exames, indicação clínica, alergias relevantes ao exame, como contraste) e o que ela mesma registrou; o centro cirúrgico lê o pedido de cirurgia, alergias e o checklist. Nada do resto do prontuário. Alternativa: acesso ao prontuário durante a execução, com trilha.
7. **Agenda cirúrgica (se a cirurgia entrar).** Proposta: salas por unidade com horários; o centro cirúrgico agenda; cirurgia de urgência entra sem agendamento prévio, com registro do motivo. Alternativa: só lista de espera, sem salas.
8. **Materiais na cirurgia.** Proposta: na entrega 5b, um catálogo de materiais usando o mesmo livro de estoque da 4c (lote, validade, movimento só de inserção), com consumo registrado na cirurgia. Alternativa: materiais só como texto na descrição, estoque de materiais depois.
9. **Termo de consentimento.** Proposta: anexo obrigatório do termo assinado antes de concluir o checklist de entrada (exceto urgência, com motivo). Alternativa: opcional.
10. **Exames de urgência.** Proposta: marcador de prioridade (rotina, urgente) no pedido, com a fila da equipe de exames ordenada por ele e tempo-alvo com destaque visual (como a classificação de risco). Alternativa: sem prioridade.

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões acima).
- **Arquitetura:** [ADR-0008](docs/adr/0008-exam-team-record-access.md), aceita (acesso das equipes de laboratório e imagem). Materiais no livro de estoque e acesso do centro cirúrgico ficam em ADR da entrega 5b.
- **Plano em etapas:** `plans/web-v6-exames.md` (entrega 5), aceito, implementado e mesclado (5.1 a 5.6, 24/10/2026). `plans/web-v7-cirurgia.md` (entrega 5b) e [ADR-0009](docs/adr/0009-surgical-center-access-and-materials.md): aceitos e implementação autorizada em 24/10/2026, uma etapa por vez.
- **Implementação:** só depois do contrato, da ADR e do plano aprovados pelo usuário.

## Contrato da entrega 6 — faturamento

Método: ECC Product Capability. Data: 25/10/2026. Estado: **aprovado; todas as propostas aceitas pelo usuário em 25/10/2026. [ADR-0010](docs/adr/0010-billing-access-and-item-origin.md) e plano `plans/web-v8-faturamento.md` propostos, aguardando aceite.** Nada é implementado antes da ADR e do plano aprovados. Origem: roteiro ("Faturamento: atendimentos e consumo → contas e recebimentos", mapa de capacidades) e as entregas 3 a 5b, que registram modalidade e convênio no atendimento e deixaram custo, preço e faturamento para esta entrega.

### Capacidade

Cada atendimento (consulta, urgência, internação) gera uma conta do paciente com o que foi feito: consulta, diárias, exames liberados, cirurgias concluídas, materiais consumidos e medicamentos dispensados (menos o devolvido), cada item com o código e o preço da tabela que vale para a modalidade do atendimento. O faturamento da unidade revisa a conta, registra autorizações do convênio, fecha a conta e, para convênio, monta o lote de envio da competência; depois registra o que foi pago e o que foi glosado. No particular, registra o recebimento e emite o recibo. Resultado esperado: do atendimento ao recebimento, cada valor cobrado rastreável até o registro assistencial que o originou, sem cobrança em dobro e sem item esquecido.

### Restrições e invariantes (fixas, herdadas das entregas 3 a 5b)

- **Dado protegido:** a conta liga paciente a procedimentos, exames e cirurgias, então é dado clínico (RLS forçado, ADR-0003). Auditoria, notificações e logs só com ids e valores, nunca nome de paciente ou procedimento ligado a ele.
- **Origem rastreável:** todo item automático aponta para o registro assistencial que o gerou (exame liberado, cirurgia concluída, movimento de estoque, dia de internação); um registro gera no máximo um item (unicidade no banco). Item manual exige motivo e autor.
- **Histórico preservado:** conta fechada não muda; correção por conta complementar ou estorno, com o anterior visível (como adendo e retificação).
- **Dinheiro:** valores em centavos inteiros, nunca ponto flutuante; preço do item congelado no momento em que entra na conta (mudar a tabela depois não altera conta existente).
- **Por unidade e por rede:** tabelas de preço e contratos são da rede; contas e lotes, da unidade do atendimento.
- **Concorrência:** fechar a mesma conta duas vezes, incluir a mesma conta em dois lotes ou gerar o mesmo item duas vezes resultam num só registro (verificado com corrida real).

### Atores e superfícies (proposta)

| Ator | Superfície | Limite |
|---|---|---|
| Faturamento (papel novo, por unidade) | Contas da unidade, revisão, autorização, fechamento, lotes, retorno do convênio, recebimento particular | Lê o necessário para a conta (itens, datas, códigos, carteira, nome do paciente); não lê evolução, resultados nem descrições |
| Administrador de rede | Tabelas de preço, convênios e contratos (qual tabela vale para cada convênio), códigos | Sem dado de paciente |
| Gestor da unidade | Relatórios de faturamento (produção, glosas, recebido) | Só números agregados |
| Recepção | Modalidade e carteira no atendimento (já existe, entrega 3) | Sem acesso à conta |

### Estados e transições (proposta)

- **Conta:** aberta (recebe itens enquanto o atendimento corre) → em revisão (atendimento encerrado ou alta) → fechada (imutável) → enviada (no lote, convênio) ou recebida (particular) → paga, paga parcialmente (com glosa) ou glosada; recurso de glosa gera um novo envio. Item que chega depois do fechamento (resultado liberado tarde) vai para uma conta complementar.
- **Lote de convênio:** em montagem → fechado (arquivo gerado) → enviado → retorno registrado.
- **Autorização:** pedida → autorizada (número e validade) ou negada; item que exige autorização não fecha sem ela, salvo urgência com motivo.

### Interface e dados (proposta)

- **Tabelas da rede:** itens cobráveis com código (TUSS para convênio, SIGTAP para SUS, código próprio para particular), preço em centavos e vigência; importação por CSV pelo mecanismo existente.
- **Contratos:** cada convênio aponta para uma tabela (com percentual de ajuste opcional) e diz quais itens exigem autorização.
- **Entidades novas:** conta, item da conta (com origem), autorização, lote, retorno (pago e glosa por item com código de motivo), recebimento particular e recibo.
- **Arquivo do convênio:** XML no padrão TISS da ANS, gerado por lote, baixado para envio manual no portal da operadora.

### Não objetivos desta entrega (proposta)

- Integração online com operadoras (WebService TISS, elegibilidade e autorização automáticas).
- Nota fiscal de serviço (NFS-e), que depende do município, e gateway de pagamento.
- Tabelas licenciadas de preço de materiais e medicamentos (Brasíndice, Simpro): o preço é cadastrado pela rede.
- Contabilidade, contas a pagar, repasse médico e custo hospitalar.
- Portal do paciente (entrega 7).

### Decisões do usuário (25/10/2026)

Todas as propostas aceitas: entrega 6 = particular e convênio, SUS na entrega 6b; itens automáticos com revisão; papel Faturamento sem acesso ao conteúdo clínico; tabela por convênio com preço congelado; diária automática por tipo de leito; autorização registrada à mão; lote em XML TISS; glosa por item com recurso; particular com recibo, sem nota fiscal; fechamento no fim do atendimento com conta complementar.

### Questões (respondidas)

1. **Recorte.** Proposta: **entrega 6 = particular e convênio** (conta, tabelas, autorização, lote TISS, retorno e glosa) e **entrega 6b = SUS** (BPA do ambulatório, AIH da internação, APAC), que tem regras e arquivos próprios do DATASUS. Alternativa: tudo numa entrega só (muito maior, mais demorada até o primeiro uso).
2. **Origem dos itens.** Proposta: itens gerados automaticamente dos registros assistenciais (consulta, diária por dia de internação, exame liberado, cirurgia concluída, material consumido, medicamento dispensado menos devolvido); o faturamento revisa e pode incluir item manual com motivo. Alternativa: conta montada à mão pelo faturamento.
3. **Quem fatura e o que lê.** Proposta: papel novo "Faturamento" por unidade, que vê a conta (itens, datas, códigos, carteira e nome do paciente), mas não a evolução, os resultados nem as descrições (exige ADR). Alternativa: o faturamento lê o prontuário durante a revisão da conta, com trilha.
4. **Preços.** Proposta: tabela da rede por item, com vigência; cada convênio usa uma tabela, com ajuste percentual opcional; o preço fica congelado no item. Alternativa: uma tabela única para tudo.
5. **Diária.** Proposta: uma diária por dia de internação, pelo tipo do leito (enfermaria, apartamento, UTI), contada pela permanência registrada nos movimentos da internação. Alternativa: diárias lançadas à mão.
6. **Autorização de convênio.** Proposta: registro manual do número de autorização (e validade) por item ou guia; itens marcados no contrato como "exige autorização" não fecham sem ela, salvo urgência com motivo. Alternativa: sem controle de autorização.
7. **Envio ao convênio.** Proposta: lote por convênio e competência, com o XML TISS gerado pelo sistema para envio no portal da operadora. Alternativa: só relatório (PDF/CSV) para digitação no portal.
8. **Glosa e recurso.** Proposta: registrar o retorno por item (pago, glosado, código e motivo da glosa) e permitir recurso, que gera um novo envio. Alternativa: só o valor total pago por conta.
9. **Particular.** Proposta: conta com recebimento registrado (forma: dinheiro, cartão, PIX, informativa, sem integração) e recibo em PDF; sem nota fiscal nesta entrega. Alternativa: incluir NFS-e (depende do município e de credenciais).
10. **Fechamento.** Proposta: a conta vai para revisão quando o atendimento encerra ou na alta; o que chega depois entra em conta complementar. Alternativa: conta parcial periódica para internações longas (por exemplo, a cada 10 dias), comum em convênios.

### Encaminhamento ECC

- **Esclarecimento de produto:** concluído (decisões acima).
- **Arquitetura:** [ADR-0010](docs/adr/0010-billing-access-and-item-origin.md) (acesso do faturamento e origem dos itens), proposta.
- **Plano em etapas:** `plans/web-v8-faturamento.md` (6.1 a 6.7), proposto.
- **Implementação:** só depois do contrato, da ADR e do plano aprovados pelo usuário.
