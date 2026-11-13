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
- Dividida em **7a.1a** (contas, convite, login, isolamento) e **7a.1b** (autocadastro com código e segundo fator).
- **Feito na 7a.1a:** tabela `patient_accounts` (migração 010200; por rede e paciente, e-mail único por rede sem distinção de maiúsculas, link de uso único guardado só como hash SHA-256, 72 h). Guard `patient` registrado em `AppServiceProvider` (não em `config/auth.php`, para o código da equipe continuar tipado como `User`). Portal em `/portal/{rede}` (slug da rede): `ResolvePortalTenant` define a rede pelo endereço antes do guard ler a conta, encerra a sessão de outra rede ou após 30 min sem uso e devolve o guard padrão a `web` ao fim (processo reaproveitado e testes). Telas: entrar, esqueci a senha (mesma resposta exista ou não a conta), criar senha (convite e nova senha pelo mesmo link), início com o nome (social, se houver). A recepção (quem edita o cadastro) envia o convite na ficha do paciente depois de marcar "Conferi o documento"; exige cadastro completo, e-mail e maioridade (menor: pelo responsável, 7a.2); reenviar renova o link; conta ativa não recebe convite. Depois da unificação, a conta mostra o cadastro mantido (lido pelo `merged_into_id`, sem mover a conta). Auditoria só com ids: `patient_portal.invited`, `.password_set`, `.login`, `.reset_requested`. As páginas do portal não recebem nenhum dado compartilhado da equipe. Testes: `tests/Feature/Portal/PatientPortalAccountTest.php` (11).
- **Revisão da 7a.1a (12/11/2026):** corrigido o envenenamento do link pelo cabeçalho `Host` (alto: o link sai sempre do `APP_URL`); RLS também em `patient_accounts`; limite por conta (20 em 15 min, qualquer IP) além do por IP; mesmo custo de hash com e sem conta; link gasto sob trava; senha nova encerra as outras sessões (a sessão guarda uma impressão da senha) e troca o remember token; auditoria sem autor da equipe nos passos do paciente e com `document_checked` no convite; cadastro unificado recusado com mensagem própria. **Riscos aceitos / em aberto:** quem edita o cadastro pode trocar o e-mail e convidar (a conferência do documento é declarada, não provada; a mudança do e-mail do cadastro ainda não é auditada); o "esqueci a senha" ainda faz mais trabalho quando a conta existe (diferença de tempo pequena); o token aparece no log de acesso do nginx e no job da fila (restringir acesso a ambos); antes de mostrar conteúdo clínico (7a.3), conferir que uma unificação errada não exponha o cadastro mantido ao dono da conta do duplicado.

### 7a.1b Autocadastro com código
- **Feito na 7a.1b:** "Primeiro acesso" no portal: CPF + data de nascimento → código de 6 dígitos (HMAC na conta, 10 min, 5 tentativas na conta e na sessão) enviado só ao e-mail do cadastro de paciente maior, completo e não unificado, sem senha ainda; o código certo leva à mesma tela de criar senha do convite (link de uso único). Migração 010300 (código na conta; `invited_by_user_id` opcional, pois não há funcionário). Limite de 3 pedidos por CPF por hora, de qualquer IP, além de 5/min por IP. **Mudança no contrato (decisão 4):** o e-mail **não** aparece na tela, nem mascarado — mostrá-lo só quando os dados conferem revelaria que aquele CPF + nascimento é paciente; a resposta e a tela seguinte são iguais existindo ou não o cadastro. Quem já tem senha não recebe código ("Esqueci minha senha"). Testes: `tests/Feature/Portal/PatientPortalSignupTest.php` (9).
- **Revisão da 7a.1b:** um código ainda válido é mantido ao pedir de novo (quem sabe o CPF e o nascimento não substitui o código do paciente); o e-mail do código sai na hora, fora da fila (o payload guardaria o código legível); o código confere de novo, na verificação, que o cadastro segue completo, maior, não unificado e com o mesmo e-mail; "tentativas esgotadas" aparece na primeira tela. Em aberto: dois pacientes com o mesmo e-mail na rede (o segundo não recebe código; a recepção resolve); pequena diferença de tempo entre dados que conferem ou não (limitada a 3 pedidos por CPF por hora).

### Pendências da 7a.1 fechadas (12/11/2026)
- "Esqueci minha senha" e "Primeiro acesso" respondem no mesmo tempo existindo ou não o paciente: a busca e o envio rodam depois da resposta (`SendPortalAccessMail`), sem fila; link e código saem na hora, nunca num payload guardado. O CPF digitado fica só na sessão (cifrada) até o código.
- O nginx grava `/portal/{rede}/criar-senha/[omitido]` no log de acesso.
- A troca do e-mail do cadastro já é auditada (`patient.updated` com os nomes dos campos).
- Dois pacientes com o mesmo e-mail na rede: o convite mostra o erro à recepção; o autocadastro não envia código (resposta igual).
- Competência do BPA e da AIH com mês e ano em português (`MonthField`), no lugar do campo de mês do navegador.
- Os gatilhos de estoque leem `app_now()` (migração 010400): `now()`, ou o relógio fixo que só a suíte de testes define; os lotes de teste deixam de vencer com o calendário (conferido simulando o banco em 2027).
- **Para a 7a.3:** antes de mostrar conteúdo clínico, conferir a unificação: a conta do cadastro duplicado só lê o cadastro mantido se a unificação não foi desfeita e o e-mail continua o mesmo.

### 7a.1c Segundo fator opcional
- TOTP opcional na conta do paciente (configurar, códigos de recuperação, desafio no login), separado da 7a.1b por tamanho.
- **Feito:** página "Segurança da conta" (ligar com senha + código do app, 8 códigos de recuperação mostrados uma vez, novos códigos e desligar com senha); desafio no login e também depois de nova senha pelo link do e-mail; 5 códigos errados por conta em 15 min; código só de passo de tempo posterior ao último usado (sem reuso); segredo e códigos cifrados; auditoria só com ids.

### 7a.2 Responsável legal
- Vínculo conta do responsável ↔ paciente menor ou tutelado, conferido e encerrado pela recepção (`patient_account_links`, histórico só de inserção).
- Aba "Filhos menores" com o mesmo conteúdo de cada vinculado; aos 18 anos o vínculo deixa de valer (verificado na leitura) e a recepção vê o convite pendente do paciente.
- **Feito:** seção "Responsáveis no portal" no cadastro do menor (e-mail do acesso ativo do responsável + documento da guarda conferido; encerrar com confirmação); `patient_account_links` só de inserção com RLS; aba "Filhos menores" no início do portal e página de cada vinculado (404 para quem não está vinculado; sessão da equipe não entra); aos 18 anos o vínculo deixa de valer na leitura e o cadastro volta a mostrar o convite do próprio paciente. Pendente de decisão: tutelado adulto (hoje só menores).

### 7a.3 Resultados, consultas e meus dados
- Resultados de exame assinados e não retificados, com valores, referências e PDF; crítico com a orientação "procure seu médico".
- Próximas consultas (data, hora, unidade, profissional; telefone da unidade).
- Meus dados (leitura) e pedido de correção, que vira pendência para a recepção resolver.
- Leituras na trilha de acesso ao prontuário.
- **Feito (7a.3a):** resultados assinados (só a versão atual de cada exame; corrigido indicado), valores com referência no formato brasileiro, aviso "procure seu médico" no crítico e PDF (só da versão atual); próximas consultas (data, hora, unidade, profissional); o mesmo conteúdo no início do paciente e na página de cada filho vinculado; depois da unificação, o cadastro mantido mostra o que foi unificado nele; cada visualização e download na trilha de acesso (motivo "portal", conta do portal no lugar do usuário da equipe), visível na tela de auditoria. Pendente: telefone da unidade (a unidade ainda não tem esse campo); Meus dados e pedido de correção ficam na 7a.3b.

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
