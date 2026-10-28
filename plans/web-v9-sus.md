# Plano — entrega 6b: faturamento SUS (BPA e AIH)

Estado: aceito pelo usuário em 27/10/2026; implementação autorizada.

Contrato: ../PRODUCT.md, seção "Contrato da entrega 6b" (todas as propostas aceitas pelo usuário em 27/10/2026). Arquitetura: ../docs/adr/0011-sus-diagnosis-and-sigtap.md (revisa a ADR-0010). Regras de trabalho: ../AGENTS.md.

## Princípios herdados

- Tudo da entrega 6: conta e itens com RLS, origem única por registro, conta fechada imutável, centavos e preço congelado, corrida real nas operações concorrentes, auditoria só com ids e valores.
- Cada etapa: skills ECC carregadas antes de codar, testes, CI reproduzido localmente (enquanto o Actions do GitHub estiver bloqueado), navegador quando houver tela e revisão independente.

## Etapas

### 6b.1 CNS e tabela CID-10
- CNS do profissional (cadastro e CSV), validação do CNS pelo algoritmo oficial (paciente e profissional).
- Tabela CID-10 oficial do DATASUS no repositório, com a origem e a versão, e busca por código ou nome.
- **Feito na 6b.1:** o CNS do profissional no cadastro (validado pelo algoritmo oficial já usado no paciente, único na rede, só na ficha completa, como o CPF); a tabela CID-10 V2008 do DATASUS em `resources/cid10/cid10.json` (12.451 códigos sem ponto, com a restrição de sexo; origem em `ORIGEM.md`) e `App\Clinical\Cid10` (normalizar, nome, sexo e busca por código ou palavras sem acento). Fica em aberto: o CNS no CSV de profissionais (o CSV também não traz o CPF).

### 6b.2 Diagnóstico no atendimento e na alta
- CID principal (obrigatório no SUS; nas outras modalidades, decidido na etapa) e secundários ao encerrar a consulta e a urgência e na alta; correção por novo registro, anterior preservado.
- Tabela clínica só de inserção, RLS; a conta mostra ao faturamento só o código e o nome do CID.
- **Feito na 6b.2:** decisão do usuário (27/10/2026): CID obrigatório só no SUS, opcional nas outras modalidades. Quem atende informa o CID principal e até 5 secundários ao encerrar a consulta e o atendimento de urgência e na alta (campo com sugestões da tabela oficial, busca `cid10.search`); o código é conferido na tabela CID-10 e no sexo do paciente. Tabela `diagnoses` (migração 008800, RLS), só de inserção, por versão (correção = nova versão; uma principal por versão no banco). A conta SUS mostra ao faturamento só os códigos e nomes; a auditoria nunca leva o CID. Os testes que fechavam atendimento SUS passaram a informar o CID. Em aberto: tela de correção do CID depois do encerramento (fica para quando a pendência aparecer na conta, 6b.4).

### 6b.3 SIGTAP por competência
- Importação do ZIP oficial pelo administrador de rede (procedimentos, valores SA/SH/SP, instrumento de registro, compatibilidades de CID e CBO), por competência, com prévia e aplicação, como os CSVs existentes.
- Código SIGTAP no item cobrável.
- **Feito na 6b.3:** tela "Tabela SIGTAP" do administrador de rede: importa o ZIP oficial da competência (TabelaUnificada do FTP do DATASUS), lido pelos layouts que vêm no próprio ZIP (posição de byte em ISO-8859-1, depois UTF-8); guarda por rede e competência os procedimentos (valores SH, SA e SP em centavos, sexo, idades em meses, quantidade máxima), os instrumentos de registro e os CIDs e CBOs compatíveis (`sigtap_competences`, `sigtap_procedures`, migração 008900; catálogo sem RLS, como as tabelas de preço). Importar de novo a competência a substitui (trava consultiva contra duas importações simultâneas); as contas não mudam. Recusa com mensagem: não ZIP, arquivo ou coluna ausente, arquivo interno acima de 100 MB, linha longa demais. ZIP real de 10/2026: 5.039 procedimentos lidos em 0,4 s. Item cobrável com o código SIGTAP (10 dígitos). Fixture de teste: recorte byte a byte do ZIP oficial, com origem em `tests/Fixtures/sigtap/ORIGEM.md`.

### 6b.4 Conta SUS
- Itens gerados dos mesmos registros de origem, com o código SIGTAP e o valor da competência do atendimento; o que não é registrado no instrumento da conta (pacote da AIH) não entra.
- Pendências antes do fechamento: item sem código SIGTAP, incompatibilidade de CID ou CBO, CNS ou CPF do paciente, CNS do profissional.
- **Feito na 6b.4:** `App\Billing\SusRules`. A conta SUS recebe, dos mesmos registros de origem, o procedimento SIGTAP do item cobrável com o valor da competência do dia (SA no BPA; SH + SP na AIH, quando o episódio virou internação). Decisão de desenho: item sem código SIGTAP não é cobrado no SUS (o administrador escolhe o que o SUS paga dando o código; a diária de enfermaria, por exemplo, está no pacote da AIH), nem o procedimento que a SIGTAP não registra no instrumento da conta. Sem a competência importada, os itens esperam (item não muda depois de gerado) e a conta mostra a pendência. Urgência SUS em andamento só recebe itens depois do desfecho (alta = BPA; pedido de internação = AIH), para nada cobrado no BPA ficar no pacote da AIH (achado alto da revisão). Pendências que impedem o fechamento: competência não importada, sem CID principal, CID incompatível com o procedimento, CBO de quem atendeu incompatível com o item do próprio atendimento, paciente sem CNS ou CPF, profissional sem CNS. Em aberto: consultas da SIGTAP por item a cada abertura da conta (agrupar se pesar); AIH sem procedimento principal fecha com total zero até a 6b.5 (laudo e procedimento principal).

### 6b.5 AIH: laudo, número e procedimento principal
- Laudo de solicitação pelo médico (procedimento solicitado, CID, justificativa clínica), assinado com a senha, em PDF para o gestor.
- Número da AIH autorizada registrado pelo faturamento (13 dígitos, único); procedimento principal com troca motivada; a conta da internação não fecha sem o número.
- Dividida em **6b.5a** (laudo) e **6b.5b** (número da AIH, procedimento principal e trava no fechamento).
- **Feito na 6b.5a:** o médico responsável pela internação SUS (aguardando leito, internado ou em transferência) assina o laudo com a senha na tela da internação: procedimento solicitado (busca por código, com ou sem pontos, ou por palavras sem acento, só procedimentos registrados como principal da AIH, instrumento 03, na última competência da SIGTAP importada até o mês da unidade), CID principal compatível com o procedimento na SIGTAP, um CID secundário e a justificativa clínica. Tabela `aih_requests` (migração 009000, RLS), só de inserção, por versão (novo laudo = nova versão; o anterior fica). Quem atende a internação baixa o PDF da última versão (com a trilha de leitura); o faturamento não lê o laudo; a auditoria leva só ids (nem o procedimento, que ligado à internação é dado clínico). Decisão do usuário (28/10/2026): o laudo continua disponível depois da alta e para a auditoria.
- **Feito na 6b.5a2:** tela "Laudos da AIH": o Auditor vê todos os laudos da rede (todas as versões) e o médico os que assinou, inclusive depois da alta; a lista não traz conteúdo clínico. O PDF passa a ser por laudo (`aih-requests/{id}/pdf`): quem atende a internação ativa, quem assinou aquela versão e o Auditor. Trilha de leitura com o motivo novo "audit" e o laudo (migração 009100, regra recriada `NOT VALID`), visível em Acessos ao prontuário.

### 6b.6 Arquivos
- **6b.6a BPA:** BPA-C (consolidado por procedimento, CBO e idade) e BPA-I (individualizado) da competência, no layout oficial vigente, por unidade; gerado uma vez, SHA-256, baixado com trilha.
- **6b.6b AIH:** arquivo de importação do SISAIH01 da competência (AIHs com alta na competência), no layout da versão vigente.
- Layouts oficiais guardados no repositório com a origem; teste do arquivo gerado contra o layout; quando possível, importação nos programas do DATASUS na verificação.

### 6b.7 Retorno do gestor e relatórios
- Aprovado ou rejeitado por AIH e por linha de BPA, com motivo; reapresentação da rejeitada numa competência seguinte.
- Relatórios do gestor da unidade com as linhas SUS (produção, aprovado, rejeitado).

### 6b.8 Revisão integrada
- Jornada num cliente novo: SIGTAP importada, consulta SUS com CID (BPA), urgência que vira internação com cirurgia, laudo, número da AIH, alta com CID, arquivos BPA e AIH, retorno com uma rejeição reapresentada.
- Verificação das telas, revisão de segurança independente e limites de produção no README.

## Fora deste plano

- APAC, envio direto ao SIA/SIHD, SISREG, CIHA, RAAS, e-SUS e RNDS.
- Teto físico e financeiro da programação com o gestor (só informativo).

## Pendências (para decidir na etapa)

1. **Versões dos layouts:** as vigentes no DATASUS quando cada etapa de arquivo começar (o layout é baixado e guardado no repositório).
2. **CID nas outras modalidades:** obrigatório só no SUS ou em todas (decidir na 6b.2).
