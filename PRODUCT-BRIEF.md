# Diagnóstico de produto — ECC Product Lens

Data: 17/10/2026. Etapa: brainstorming. Método: ECC Product Lens, modo Product Diagnostic.
Base: decisões e evidências registradas em BRAINSTORMING.md. Não constitui especificação de implementação.

## 1. Para quem

Mercado confirmado: hospitais médios e grandes redes, com particular, convênios e SUS.

Personas propostas para validação:
- Comprador: direção hospitalar ou da rede, com participação de TI e administração.
- Responsável pela implantação: equipe de TI e líderes dos processos migrados.
- Usuários: coordenação de escalas, recepção, equipes assistenciais, farmácia, suprimentos e faturamento.
- Beneficiário: paciente, cuja jornada deve manter continuidade durante a mudança de sistema.

Não há hospital comprador, patrocinador ou piloto real identificado.

## 2. Dor e evidência

Hipóteses: duplicação de registros, fragmentação de histórico, pendências entre setores, dificuldade de cobertura de escalas e receio de perder dados numa substituição.

A necessidade de preservar dados durante migração foi explicitada pelo idealizador. Frequência, custo e gravidade dessas dores em compradores reais não foram medidos. As telas e notas de versão do concorrente demonstram funcionalidades, não insatisfação do mercado.

Próxima validação proposta: entrevistar responsáveis de operação, assistência e TI; observar um fluxo e medir retrabalho, tempo e exceções antes de prometer melhorias.

## 3. Por que agora

Existe uma iniciativa de criar produto próprio e referências para começar a descoberta. Não há evidência de urgência de compra, orçamento disponível ou mudança externa que justifique o investimento comercial. Próximo passo: investigar eventos que levariam um hospital a substituir seu sistema.

## 4. Visão completa

SaaS hospitalar capaz de substituir o legado gradualmente, com identidade e histórico contínuos, isolamento entre clientes, gestão por rede/unidade e cobertura assistencial, operacional e financeira.

“Melhor” será tratado como hipótese verificável: menos registros repetidos, menos pendências sem responsável, menor tempo para concluir tarefas e maior rastreabilidade das migrações. Não há comparação de desempenho comprovada com o InsightCare.

## 5. Primeira prova de valor proposta

Separar duas hipóteses:

1. Produto: gestão de equipes e plantões resolve uma necessidade operacional. Simular cadastro de unidades e profissionais → importação da escala → publicação → solicitação de troca/cessão → decisão → presença → relatório.
2. Substituição: migração preserva dados e vínculos. Simular uma importação de pacientes, atendimentos e documentos com duplicidades e exceções conhecidas, preservando os originais e conferindo cada resultado.

Uma prova de plantões não demonstra capacidade de substituir prontuário ou faturamento. Uma importação sintética não demonstra compatibilidade com sistemas reais. A primeira entrega comercial permanece em aberto até identificar comprador e processo prioritário.

## 6. Limites desta etapa

- Planejar a cobertura completa e entregas por jornada; não implementar software neste brainstorming.
- Não prometer ausência de perdas sem inventário e reconciliação das fontes.
- Não tratar hipóteses, dados fictícios ou notas de versão como validação de mercado.
- Não selecionar tecnologias, prazos ou preço sem requisitos e restrições suficientes.
- Não acrescentar automação de decisões clínicas como diferencial presumido.

## Diferenciais priorizados pelo usuário

Os três pilares foram escolhidos em conjunto: facilidade de uso, migração confiável e gestão integrada da rede. Nenhum fica reservado apenas para uma fase final.

Critérios propostos para cada entrega:
- Facilidade de uso: testar tarefas por perfil, medir tempo, passos, erros e necessidade de ajuda. A comparação exige uma linha de base; metas ainda não definidas.
- Migração confiável: preservar origem, contabilizar cada registro importado ou pendente e conferir vínculos, documentos e totais aplicáveis. Não confundir importação técnica com validação operacional.
- Gestão integrada: validar permissões locais e centrais, origem por unidade e consolidação dos dados do fluxo entregue, sem permitir acesso entre clientes independentes.

Exemplo de primeira jornada de plantões: importar uma escala sintética com relatório de exceções; permitir que o coordenador resolva uma vaga; refletir o resultado nas visões da unidade e da rede. Isso testa os três pilares em conjunto, sem alegar validação de módulos ainda não construídos.

## 7. Como medir sucesso

| Hipótese | Medida proposta | Como validar |
|---|---|---|
| Tarefas mais simples | Tempo, passos e erros por tarefa | Comparar o fluxo atual com o protótipo usando os mesmos casos |
| Melhor cobertura | Tempo para resolver vaga e número de vagas pendentes | Acompanhar solicitações e resultado final |
| Migração rastreável | Registros aceitos, rejeitados e pendentes; vínculos e totais conferidos | Comparar com conjunto de origem e exceções conhecidas |
| Continuidade de histórico | Documentos e eventos encontrados nos casos de teste | Conferência por paciente e origem |
| Interesse comercial | Disposição de responsáveis em avaliar piloto e discutir contratação | Conversas com compradores identificados |

Metas numéricas dependem da linha de base; não foram inventadas. Para a simulação, cada item de origem deve ter destino ou exceção explícita, sem descarte silencioso.

## Riscos e ações

| Risco | Ação seguinte |
|---|---|
| Escopo amplo sem comprador validado | Priorizar a jornada pelo problema do comprador, mantendo mapa completo |
| Sequência técnica confundida com valor comercial | Tratar fundação como habilitadora; demonstrar um fluxo concluído |
| Regras de contratação e assistência desconhecidas | Registrar decisões pendentes e validar com especialistas antes de uso real |
| Migração subestimada | Separar teste sintético, adaptador real e corte operacional |
| Confusão entre cópia visual e diferenciação | Medir melhoria de tarefas, não quantidade de telas |

## Recomendação go/no-go

**GO para descoberta e desenho com cenários fictícios.** Há objetivo e escopo suficientes para organizar jornadas e construir hipóteses testáveis.

**Ainda sem base para autorizar um lançamento ou assumir investimento na substituição completa.** Faltam comprador, validação das dores, orçamento, integrações reais e critérios operacionais. Isto é uma avaliação de evidência, não um cancelamento da visão do produto.

Próximo passo recomendado: detalhar uma primeira jornada com atores, estados, exceções e critérios de aceite que exercite os três pilares escolhidos. A ordem de entregas continua proposta, sem aprovação detalhada. Quando o diagnóstico for validado, a skill ECC product-capability é a continuação apropriada para especificar capacidades, ainda respeitando o escopo autorizado.

## Escopo aprovado da primeira entrega — 17/10/2026

Web responsivo primeiro, Android/iPhone depois. Cadastros, permissões, importação de escalas e plantões completos, com coordenador aprovando todas as mudanças de responsável. Check-in/check-out bloqueados fora do raio permitido pelo hospital. Requisitos detalhados, propostas ainda não aprovadas e critérios de aceite estão no contrato V1 de PRODUCT.md. Isso fecha a entrega de construção inicial, mas não comprova prontidão comercial ou substituição integral de um hospital real.
