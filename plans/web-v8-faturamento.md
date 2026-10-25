# Plano — entrega 6: faturamento (particular e convênio)

Data: 25/10/2026. Estado: **aceito; implementação autorizada pelo usuário em 25/10/2026, uma etapa por vez.** Uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização do usuário.
Contrato: ../PRODUCT.md, seção "Contrato da entrega 6" (todas as propostas aceitas pelo usuário em 25/10/2026). Arquitetura: ../docs/adr/0010-billing-access-and-item-origin.md. Regras de trabalho: ../AGENTS.md. O SUS (BPA, AIH, APAC) fica na entrega 6b, com contrato próprio.

## Princípios herdados

- Conta e tudo o que liga paciente a procedimento são clínicos: RLS forçado, leitura na trilha, nada clínico em logs, avisos e auditoria (só ids e valores).
- Dinheiro em centavos inteiros; preço congelado no item.
- Um registro assistencial gera no máximo um item (índice único); conta fechada nunca muda (gatilho).
- Locks e corrida real em toda disputa (gerar itens duas vezes, fechar duas vezes, pôr a conta em dois lotes).
- Testes negativos de acesso: outra unidade, outro cliente, papel revogado, sem papel Faturamento.
- Telas verificadas em 360 e 1440 px; revisão de segurança independente em toda etapa.

## Etapas

### 6.1 Tabelas de preço, convênios e papel
- **Papel Faturamento**, por unidade, na tela de pessoas.
- **Itens cobráveis da rede:** consulta, diária por tipo de leito, exames, procedimentos, materiais e medicamentos (ligados aos catálogos existentes), com código (TUSS ou próprio).
- **Tabelas de preço** com vigência e preço em centavos; importação CSV pelo mecanismo existente.
- **Contratos:** cada convênio da rede aponta para uma tabela, com ajuste percentual opcional, e marca os itens que exigem autorização; o particular usa a tabela particular.
- **Saída:** preço vigente resolvido por data e modalidade; vigências que se sobrepõem recusadas no banco.
- Divisão (25/10/2026): 6.1a papel, itens cobráveis, tabelas e preços; 6.1b contratos dos convênios e tabela particular; 6.1c importação CSV de preços.
- **Feito na 6.1a:** papel Faturamento por unidade; tela "Preços" do administrador da rede: itens cobráveis (consulta, consulta de urgência, diária por tipo de leito, exame, procedimento, material, medicamento, ligados ao catálogo; um item por coisa cobrada), tabelas de preço e preços em centavos com vigência ("1.234,56"); um preço novo encerra o anterior no dia anterior; o banco recusa sobreposição (exclusão), alteração e exclusão de preço (só a data de fim, uma vez). Auditoria só com ids e valores. Corrida real: dois preços do mesmo item ao mesmo tempo, um só registrado, vigências contíguas.

### 6.2 Conta e itens automáticos
- Conta por atendimento (consulta, urgência, internação), aberta na chegada ou admissão, com a modalidade e a carteira do atendimento.
- Geração idempotente dos itens a partir de: atendimento encerrado, dias de internação por tipo de leito, item de exame liberado, cirurgia concluída, consumo e estorno de material, dispensação e devolução de medicamento.
- Tela "Contas" do faturamento da unidade: lista por estado e a conta com os itens, a origem pelo tipo e as pendências (item sem preço, autorização faltando).
- **Saída:** gerar duas vezes ou ao mesmo tempo não duplica item (corrida real); o faturamento não vê conteúdo clínico.

### 6.3 Revisão, item manual e autorização
- Item manual com motivo e autor; item automático não se apaga, é compensado por item negativo com motivo.
- Autorização por item ou guia: número, validade, negada com motivo; item que exige autorização bloqueia o fechamento, salvo urgência com motivo.
- **Saída:** todas as mudanças na trilha e na auditoria só com ids e valores.

### 6.4 Fechamento, conta complementar e particular
- Revisão → fechada (imutável no banco); o que chega depois vai para conta complementar automática.
- Particular: recebimento registrado (forma informativa) e recibo em PDF.
- **Saída:** fechar duas vezes ao mesmo tempo resulta num só fechamento (corrida real); conta fechada recusa qualquer alteração no banco.

### 6.5 Lote TISS e retorno
- Lote por convênio e competência; XML no padrão TISS da ANS (versão registrada no lote), validado contra o XSD oficial no teste; arquivo guardado com hash e baixado para envio no portal.
- Retorno por item (pago, glosado, código e motivo da glosa) e recurso de glosa, que gera novo envio.
- **Saída:** uma conta em no máximo um lote ativo (corrida real); valores conciliados (cobrado = pago + glosado + em recurso).

### 6.6 Relatórios
- Gestor da unidade: produção por período e modalidade, contas pendentes, glosas por motivo e recebido; só números, CSV com proteção contra fórmula.

### 6.7 Revisão integrada
- **Jornada num cliente novo:** atendimento por convênio com exame, internação com cirurgia e materiais, alta, conta gerada, autorização, fechamento, lote, retorno com glosa e recurso; um particular com recibo.
- Verificação das telas, revisão de segurança independente e limites de produção no README.

## Fora deste plano

- SUS: BPA, AIH, APAC e o SIHD/SIA (entrega 6b).
- Integração online com operadoras, NFS-e, gateway de pagamento, tabelas licenciadas (Brasíndice, Simpro), contabilidade e repasse médico.

## Pendências (para decidir na etapa)

1. **Versão do TISS:** a vigente no site da ANS quando a etapa 6.5 começar (o XSD precisa ser baixado e guardado no repositório).
2. **Consulta no pronto-socorro:** cobrada como consulta de urgência, com código próprio na tabela (proposta), ou só pelos itens do atendimento.
