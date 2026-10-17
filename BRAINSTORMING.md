# Plataforma hospitalar — brainstorming

Data: 17/10/2026. Estado: definição de produto; implementação não autorizada nesta etapa.

## Decisões confirmadas

- SaaS para hospitais médios e grandes redes hospitalares.
- Visão de plataforma hospitalar completa, entregue em partes funcionais.
- Substituição integral dos sistemas anteriores por fases, com coexistência durante a transição.
- Importação e preservação dos dados antigos como parte do produto. Ausência de perdas deve ser verificada; não é uma garantia presumida sobre exportações desconhecidas.
- Atendimento particular, por convênios e pelo SUS.
- Compartilhamento do histórico entre unidades da mesma rede, conforme permissões e vínculo assistencial; isolamento entre clientes independentes.
- Desenvolvimento conceitual com cenários e dados fictícios, sem hospital piloto real identificado.
- Trabalho separado do Obra360, nesta pasta. Nenhuma tecnologia de implementação escolhida.
- Três diferenciais priorizados em conjunto: facilidade de uso, migração confiável e gestão integrada da rede. Critérios propostos registrados em PRODUCT-BRIEF.md.
- Governança aprovada: regras obrigatórias da rede, autonomia dos hospitais dentro desses limites e alterações registradas com responsável e vigência.
- Plantões: havendo vários candidatos a uma vaga, o coordenador escolhe entre os elegíveis e registra justificativa. Na primeira entrega, todas as mudanças de responsável exigem aprovação do coordenador; automações ficam para depois.
- Plantões entre hospitais da mesma rede: permitir somente com vínculo e habilitação aprovados na unidade de destino e no mesmo estado (UF).

## Hipóteses de simulação propostas

| Cenário | Estrutura | Objetivo |
|---|---|---|
| Hospital Horizonte | Uma unidade, 150 leitos | Validar jornadas completas e migração gradual |
| Rede Aurora | Seis hospitais, 1.200 leitos no total | Validar gestão central, autonomia local e transferências |

Nomes, volumes e composição dos serviços são hipóteses, não requisitos aprovados individualmente. Os dois cenários incluem modalidades particular, convênios e SUS.

## Visão do produto

Conectar a jornada do paciente à operação hospitalar, reduzindo registros repetidos e tornando pendências, responsáveis e próximos passos visíveis. Diferenciais ainda propostos: linha do tempo integrada, passagem de responsabilidade entre setores, rastreabilidade dos dados importados e gestão de exceções.

## Escopo completo a detalhar

1. Base: redes, hospitais, setores, usuários, perfis, permissões, auditoria, configuração por unidade e contratação de módulos.
2. Migração e integração: fontes, mapeamentos, importações de teste, conferência, tratamento de duplicidades, atualização final, arquivo histórico e retorno operacional.
3. Atendimento: agenda, recepção, cadastro provisório, urgência, classificação de risco, ambulatório, internação, leitos, transferência e alta.
4. Assistência: prontuário multiprofissional, prescrições, administração de medicamentos, solicitações e resultados de exames, documentos e passagem de cuidado.
5. Equipes: cadastro profissional, vínculos, escalas, vagas, trocas, transferências de plantão, candidaturas e presença.
6. Operação: farmácia, estoque, compras, materiais, centro cirúrgico, serviços de apoio e rastreabilidade.
7. Financeiro: contas, faturamento por modalidade, autorizações, glosas, recebimentos, custos e repasses.
8. Paciente e gestão: portal, comunicação, documentos, indicadores por unidade e consolidação da rede.

Essa lista representa a cobertura pretendida. Regras clínicas, fiscais, regulatórias, integrações e critérios operacionais ainda exigem especificação e validação especializada.

## Alternativas de entrega

- Por módulo isolado: facilita dividir o trabalho, mas pode gerar telas sem uma jornada utilizável.
- Por hospital completo: permite validar a unidade inteira, mas exige grande cobertura antes do primeiro uso.
- Por jornada funcional em hospital piloto, depois expansão para a rede: recomendação. Combina módulos necessários a cada fluxo e testa dependências antes de ampliar.

## Sequência proposta, ainda não aprovada em detalhe

| Entrega | Fluxo verificável | Dependências e limites |
|---|---|---|
| 1. Fundação e migração piloto | Administrar unidades e acessos; importar cadastros sintéticos e conferir resultados | Modelo de identidade, isolamento e rastreabilidade |
| 2. Equipes e plantões | Publicar escala, solicitar mudança, resolver cobertura e registrar presença | Definir elegibilidade, aprovações, prazos e correções |
| 3. Atendimento ambulatorial | Agendar, receber, atender, documentar e encerrar | Prontuário inicial, identificação e integração com cobranças necessárias ao piloto |
| 4. Internação e urgência | Receber, avaliar, internar, transferir e dar alta | Entrega coordenada de leitos, assistência, prescrição e farmácia; sem corte produtivo antes de validar o conjunto |
| 5. Diagnóstico e cirurgia | Solicitar e receber resultados; planejar e acompanhar cirurgia | Integrações laboratoriais/imagem, equipes e materiais |
| 6. Suprimentos e financeiro completos | Comprar, rastrear consumo e concluir ciclos de faturamento e recebimento | Fluxos distintos por modalidade; validar dependências antes de migrar operação real |
| 7. Rede e experiência do paciente | Expandir jornadas validadas, portal e indicadores consolidados | Isolamento e estrutura multiunidade existem desde a fundação |

A sequência é conceitual, sem estimativas de prazo. Funções de uma entrega posterior podem precisar ser antecipadas para fechar um fluxo anterior. O sistema antigo permanece responsável pelos processos ainda não migrados; a fonte oficial de cada dado deve ser definida por etapa.

## Migração sem descarte silencioso

Inventariar fontes → mapear → importar em ambiente de teste → conferir vínculos e totais → resolver exceções → validar com responsáveis → atualizar diferenças → realizar corte controlado → monitorar e manter retorno previsto.

Preservar arquivos originais e proveniência. Quando não houver conversão segura, manter arquivo histórico consultável e registrar a limitação. Simulações com CSV e PDF não demonstram compatibilidade com fornecedores reais.

## Referências e evidências

- Imagens fornecidas: `/Users/melquiantonio/Desktop/app/`, 24 arquivos. Todas examinadas visualmente: seis inicialmente e as 18 restantes pelo agente de pesquisa UX em 17/10/2026. Não houve teste de interação. Campos e ações observados estão sintetizados em PRODUCT.md, contrato V1.
- Relato do usuário: plantões do dia, detalhes, troca, cessão/anúncio, candidaturas, check-in/check-out e ajustes.
- Site institucional: https://www.invisual.com.br/#solucoes — apresenta portfólio hospitalar; não comprova regras internas do app.
- Google Play: https://play.google.com/store/apps/details?hl=pt&id=br.com.insightcare.plantao.app — identificação do aplicativo e desenvolvedora.
- App Store: https://apps.apple.com/br/app/insightcare-plant%C3%A3o/id6745890569 — notas da desenvolvedora descrevem transferência direta, elegibilidade por função, restrições temporais, relatórios de gerente e tratamento de plantões atravessando a meia-noite. Não testado em sessão autenticada.
- Login fornecido: https://gruposanta.insightcare.com.br/index.php?r=site%2Flogin — sem investigação autenticada realizada.
- REA 6.5.0: conexão MCP confirmada. Nenhuma conclusão sobre funcionamento interno do produto foi produzida pelo REA até agora.

## Decisões em aberto

- Nome e posicionamento da marca própria.
- Momento de transferência de responsabilidade e contexto de origem para profissionais com múltiplos vínculos: propostas detalhadas no contrato V1 para revisão. Aprovação pelo coordenador e limites de elegibilidade já confirmados.
- Validação e correção de presença, falhas de localização e operação sem conexão.
- Regras de identidade do paciente, duplicidades e acesso excepcional ao histórico.
- Inventário detalhado de serviços e perfis dos cenários fictícios.
- Fronteiras entre módulos, prioridade das jornadas e critérios de aceite de cada entrega.
- Disponibilidade, recuperação de desastre, desempenho, suporte e implantação.
- Hospedagem, arquitetura, tecnologias, equipe, orçamento e modelo de cobrança.
- Fornecedores legados, formatos disponíveis, integrações e critérios de reconciliação.

## Próximo passo

Revisar a sequência proposta e detalhar a primeira jornada, preservando a visão completa. Este documento é um registro de brainstorming, não uma especificação final nem autorização para implementação.

## Atualização de escopo — 17/10/2026

Web responsivo primeiro; Android/iPhone depois. V1 aprovada: cadastros, permissões, importação de escalas e plantões completos. Todas as mudanças de responsável passam pelo coordenador. Check-in/check-out bloqueados fora do raio permitido pelo hospital. Contrato detalhado e propostas de funcionamento em PRODUCT.md.

## Elegibilidade e coordenação — decisões adicionais

Para candidaturas sem plantão de origem, usar unidade-base administrativa do profissional para comparar a UF. Trocas entre hospitais exigem aprovação dos coordenadores das duas unidades. Ambas confirmadas pelo usuário em 17/10/2026.

## Evidência complementar das imagens

- IMG_0912: detalhes do plantão, cobertura, local, tipo, turno, valor e colegas.
- IMG_0913/0915/0916: menu de ações, troca e seleção de plantão, incluindo seleção desabilitada e estado vazio.
- IMG_0917: solicitações abertas/canceladas. IMG_0919: agenda, imagem parcialmente borrada.
- IMG_0920/0925: transições e carregamento; não constituem telas adicionais.
- IMG_0921/0922/0924/0926: confirmações e comprovantes de entrada/saída, horários e mapa.
- IMG_0928/0929/0930: detalhes de vaga, candidatura e retirada.
- IMG_0932/0933: sucesso e processamento da retirada. IMG_0934: ajustes, conta, escala, senha e saída.

As capturas não comprovam regras de elegibilidade, aprovação, raio geográfico ou acessibilidade. Dados pessoais e controles externos de vídeo não foram incorporados ao produto.

## Fechamento dos fluxos V1

Confirmado em 17/10/2026: coordenador pode encerrar ocorrência administrativa de saída, com horário informado, justificativa, histórico e identificação de ajuste manual, separado do checkout geográfico. Trocas e transferências bloqueadas após início previsto ou check-in, com revalidação na conclusão. Próxima etapa: arquitetura e plano de construção web com ECC.
