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

### 6b.2 Diagnóstico no atendimento e na alta
- CID principal (obrigatório no SUS; nas outras modalidades, decidido na etapa) e secundários ao encerrar a consulta e a urgência e na alta; correção por novo registro, anterior preservado.
- Tabela clínica só de inserção, RLS; a conta mostra ao faturamento só o código e o nome do CID.

### 6b.3 SIGTAP por competência
- Importação do ZIP oficial pelo administrador de rede (procedimentos, valores SA/SH/SP, instrumento de registro, compatibilidades de CID e CBO), por competência, com prévia e aplicação, como os CSVs existentes.
- Código SIGTAP no item cobrável.

### 6b.4 Conta SUS
- Itens gerados dos mesmos registros de origem, com o código SIGTAP e o valor da competência do atendimento; o que não é registrado no instrumento da conta (pacote da AIH) não entra.
- Pendências antes do fechamento: item sem código SIGTAP, incompatibilidade de CID ou CBO, CNS ou CPF do paciente, CNS do profissional.

### 6b.5 AIH: laudo, número e procedimento principal
- Laudo de solicitação pelo médico (procedimento solicitado, CID, justificativa clínica), assinado com a senha, em PDF para o gestor.
- Número da AIH autorizada registrado pelo faturamento (13 dígitos, único); procedimento principal com troca motivada; a conta da internação não fecha sem o número.

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
