# ADR-0011: diagnóstico CID-10 estruturado e faturamento SUS pela SIGTAP

Data: 27/10/2026
Estado: aceita pelo usuário (27/10/2026)

## Contexto

A entrega 6b (contrato da entrega 6b no PRODUCT.md, todas as propostas aceitas pelo usuário em 27/10/2026) traz o faturamento SUS: BPA (C e I) do ambulatório e da urgência e AIH da internação, em arquivos de texto nos layouts oficiais do DATASUS. A AIH exige o diagnóstico principal pelo CID-10, e o BPA-I o exige em parte dos procedimentos; hoje o sistema não registra diagnóstico estruturado, só a evolução em texto. O SUS paga pela tabela SIGTAP, que muda a cada competência (mês) e traz, além do valor, regras: em qual instrumento cada procedimento é registrado (BPA-C, BPA-I, AIH principal ou secundário) e com quais CID e CBO é compatível. O faturamento ([ADR-0010](0010-billing-access-and-item-origin.md)) não lê o prontuário.

## Decisão

- **Diagnóstico estruturado:** quem atende informa o CID-10 principal (e secundários, opcionais) ao encerrar a consulta e o atendimento de urgência, e na alta. O diagnóstico é uma tabela clínica (RLS forçado, [ADR-0003](0003-rls-on-clinical-tables.md)), só de inserção, ligada ao atendimento ou à internação e ao autor; uma correção é um novo registro que substitui o anterior, com o anterior preservado (como a retificação do exame). A tabela CID-10 oficial do DATASUS fica no repositório, com a origem, como o XSD do TISS.
- **O que o faturamento lê (revisão da ADR-0010):** além do que já lia, o **código e o nome do CID** da conta SUS (não a evolução, o resumo da alta nem a justificativa do laudo). A leitura continua na trilha com o motivo "billing" ao abrir a conta.
- **Laudo da AIH:** na internação SUS, quem atende emite o laudo de solicitação (procedimento SIGTAP solicitado, CID e justificativa clínica), assinado com a senha, impresso para o fluxo do gestor. A justificativa é clínica e o faturamento não a lê; ele registra o **número da AIH** autorizada (13 dígitos) e o procedimento principal (que começa como o solicitado e muda só com motivo).
- **Laudo depois da alta e auditoria (decisão do usuário, 28/10/2026):** o laudo continua disponível depois da alta para o médico que o assinou e para o papel **Auditor** da rede, que passa a ler os laudos da AIH (inclusive a justificativa). Cada PDF aberto vai para a trilha de leitura (o do auditor com o motivo "audit" e o laudo); a lista dos laudos não mostra conteúdo clínico (sem procedimento, CID nem justificativa). O faturamento continua sem ler o laudo.
- **SIGTAP por competência:** o administrador de rede importa o ZIP oficial de cada competência. Os dados da rede guardam, por competência, os procedimentos (código de 10 dígitos, nome, valores SA, SH e SP em centavos), o instrumento de registro e as compatibilidades de CID e CBO. É catálogo sem dado de paciente, da rede, sem RLS (como as tabelas de preço).
- **Itens SUS:** o item cobrável ganha um código SIGTAP opcional. A conta SUS gera os itens pelos mesmos registros de origem da entrega 6 (índice único por origem), com o código SIGTAP e o valor da competência do atendimento, congelado; o item que a SIGTAP não registra no instrumento da conta (por exemplo, exame comum de internação, incluído no pacote da AIH) não é gerado. Item sem código SIGTAP ou incompatível é pendência visível antes do arquivo.
- **CNS:** o profissional ganha o campo CNS; o CNS (do paciente e do profissional) é validado pelo algoritmo oficial. BPA-I e AIH exigem CNS ou CPF do paciente e o CNS do profissional; faltas são pendências.
- **Arquivos:** BPA e AIH em texto no layout oficial vigente (guardado no repositório com a origem e a versão), um por unidade e competência, gerados uma vez, com SHA-256, no disco privado, baixados com trilha para importar no BPA Magnético e no SISAIH01. Antes de gerar, a aplicação confere as regras do layout e da SIGTAP; não há validador oficial fora dos programas do DATASUS.
- **Retorno do gestor:** registro novo por AIH e por linha de BPA (aprovado ou rejeitado, com motivo); a rejeitada pode ser reapresentada numa competência seguinte. Nunca altera o item.
- **Auditoria e avisos:** só ids, códigos de procedimento sem ligação ao paciente e valores; nunca o CID, que é diagnóstico.

## Alternativas

### O faturamento codifica o CID lendo o prontuário
- **Prós:** nenhuma mudança na rotina do médico.
- **Contras:** amplia muito quem lê o prontuário; o CID escolhido por quem não atendeu erra mais.
- **Por que não:** questão 2 do contrato.

### Cadastro manual dos procedimentos SUS
- **Prós:** sem importação.
- **Contras:** sem compatibilidades, valor desatualizado a cada mês.
- **Por que não:** questão 3 do contrato.

## Consequências

### Positivas
- A produção SUS sai dos registros assistenciais, sem redigitação, com origem rastreável e sem cobrança em dobro.
- O diagnóstico estruturado fica disponível para indicadores futuros (entrega 7).

### Negativas
- Encerrar o atendimento e dar alta passam a pedir o CID ao médico (um campo a mais).
- O faturamento passa a ler um dado diagnóstico (o código CID) da conta SUS.

### Riscos
- **Layouts mudam:** o DATASUS altera os layouts e a SIGTAP com frequência (avisos do SIHD). Mitigação: layout guardado por versão, conferido na etapa e com teste do arquivo gerado.
- **Sem validador oficial no servidor:** o erro só aparece ao importar no programa do DATASUS. Mitigação: regras do layout e da SIGTAP conferidas antes de gerar; arquivo gerado importado nos programas oficiais na verificação da etapa, quando possível.
- **Autorização da AIH depende do gestor:** sem o número, a conta da internação não fecha; o faturamento vê a pendência.
