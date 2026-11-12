# Plano — entrega 7: portal do paciente (7a portal base, 7b jornada no hospital)

Estado: aceito pelo usuário em 12/11/2026; implementação autorizada.

Contrato: ../PRODUCT.md, seção "Contrato da entrega 7". Arquitetura: ../docs/adr/0012-patient-portal-access.md. Regras de trabalho: ../AGENTS.md. A 7c (indicadores da rede) terá contrato e plano próprios.

## Princípios herdados

- Dados clínicos com RLS e `BelongsToTenant`; leitura registrada por `ClinicalAccess::recordRead` (motivos novos `patient` e `guardian`); históricos só de inserção; auditoria só com ids.
- Cada etapa: skills ECC carregadas antes de codar, branch e PR próprios, testes (inclusive de acesso: próprio paciente, responsável, estranho = 404, equipe = recusada), CI verde, navegador a 360 px e 1440 px quando houver tela, revisão independente (`ecc:security-reviewer` e `ecc:code-reviewer`).

## Etapas

### 7a.1 Contas de paciente e login
- Guard `patient`, tabela `patient_accounts` (por rede, ligada a um paciente), login, saída, redefinição de senha, segundo fator opcional, sessão de 30 min sem uso, limite de tentativas.
- Convite pela recepção (documento conferido, e-mail do cadastro, link de 72 h de uso único).
- Autocadastro: CPF + data de nascimento → código de 6 dígitos ao e-mail já cadastrado (mascarado), 10 min, 5 tentativas; resposta igual exista ou não o paciente; paciente sem e-mail: "procure a recepção".
- Middleware: rotas da equipe recusam sessão de paciente e o portal recusa sessão de equipe. Unificação de cadastros move a conta.
- Layout do portal (celular primeiro) e página inicial vazia com o nome do paciente.

### 7a.2 Responsável legal
- Vínculo conta do responsável ↔ paciente menor ou tutelado, conferido e encerrado pela recepção (`patient_account_links`, histórico só de inserção).
- Aba "Filhos menores" com o mesmo conteúdo de cada vinculado; aos 18 anos o vínculo deixa de valer (verificado na leitura) e a recepção vê o convite pendente do paciente.

### 7a.3 Resultados, consultas e meus dados
- Resultados de exame assinados e não retificados, com valores, referências e PDF; crítico com a orientação "procure seu médico".
- Próximas consultas (data, hora, unidade, profissional; telefone da unidade).
- Meus dados (leitura) e pedido de correção, que vira pendência para a recepção resolver.
- Leituras na trilha de acesso ao prontuário.

### 7a.4 Documentos liberados
- O profissional marca um anexo do próprio atendimento como "liberar ao paciente" e pode revogar (histórico só de inserção, auditado).
- O portal lista e baixa só os liberados e não revogados.

### 7b.1 QR da visita
- Na confirmação da chegada (consulta ou urgência) a recepção gera o QR; token aleatório, ligado à visita, válido até 12 h depois da alta, revogável.
- Abre só a jornada da visita, sem conta e sem resultados.

### 7b.2 Jornada ao vivo
- Etapas, posição e tempo estimado das quatro filas pelas regras do contrato; atualização a cada 20 s (`usePoll`); estado "atualizado há X min".
- Mesma tela no portal (conta) e no QR (visita).

### 7b.3 Notificações no portal e por e-mail
- Eventos: "você é o próximo", início do atendimento, resultado liberado, consulta marcada, alta. Guardados como as notificações da equipe, com outro destinatário; e-mail para resultado, consulta e alta.

### 7b.4 Push no celular e canais externos
- Push do navegador (portal adicionado à tela inicial): exige biblioteca nova e chaves VAPID — **pedir aprovação do usuário antes de instalar**.
- SMS e WhatsApp: interface do canal pronta, sem envio real (BLOCKED_EXTERNAL até fornecedor e contrato).

### 7.5 Revisão integrada
- Teste da jornada completa: convite → login → resultado → menor → QR → fila → alta; atualização do `docs/ESTADO-ATUAL.md` e do README (limites conhecidos).
