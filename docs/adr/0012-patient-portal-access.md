# ADR-0012: Portal do paciente em área separada, com contas de paciente próprias

Data: 12/11/2026
Estado: aceita (12/11/2026)

## Contexto

A entrega 7 abre o sistema a pessoas de fora da equipe: o paciente e o responsável legal de menores. Até aqui toda conta é de equipe, ligada a uma rede por um vínculo (`memberships`) com papéis por unidade, e todo acesso ao prontuário tem um motivo de atendimento registrado em `ClinicalAccess::recordRead`. O paciente lê os próprios dados por direito (LGPD, art. 18), não por atender alguém, e um erro de permissão que o deixe alcançar uma rota da equipe exporia dados de outros pacientes.

## Decisão

- **Área separada na mesma aplicação:** rotas em `/portal`, layout próprio e um guard de autenticação `patient` com a tabela `patient_accounts` (e-mail, senha, segundo fator opcional). Conta de paciente nunca tem vínculo de equipe; o middleware das rotas da equipe recusa a sessão de paciente e o portal recusa a sessão de equipe.
- **Conta por rede:** cada conta pertence a um cliente (tenant) e liga-se a um paciente dele; quem é atendido em duas redes tem dois acessos (cada hospital é o controlador dos próprios dados). Unificação de cadastros move a ligação para o cadastro mantido.
- **Responsável legal:** vínculo conferido pela recepção entre a conta do responsável e o paciente menor ou tutelado (`patient_account_links`), encerrado aos 18 anos ou pela recepção; a conta vê os vínculados numa aba própria.
- **Leitura auditada:** toda leitura do portal registra a trilha de acesso ao prontuário com o motivo novo `patient` (ou `guardian`) e só ids, visível em Acessos ao prontuário.
- **Escopo no banco:** as consultas do portal filtram pelo paciente da sessão e pelos vínculados; RLS e `BelongsToTenant` continuam valendo, com o tenant vindo da conta.
- **Acesso por QR da visita:** token de uso na visita, sem conta, que mostra só a jornada daquela visita até 12 horas depois da alta.
- **Ao vivo por consulta periódica** (`usePoll` do Inertia, 20 s); sem servidor WebSocket nesta entrega.

## Alternativas

- **Papel "Paciente" na tabela de usuários:** reaproveita login e segundo fator, mas mistura equipe e pacientes; uma checagem de permissão esquecida abre rota interna, e o vínculo com a rede não descreve o paciente.
- **Aplicação separada (subdomínio):** isolamento máximo, ao custo de duplicar autenticação, deploy e CI.

## Consequências

- Um segundo guard e uma segunda tabela de contas, com redefinição de senha e limite de tentativas próprios.
- Toda tela do portal precisa de teste de acesso: o próprio paciente, o responsável, um estranho (404) e a equipe (recusada).
- Notificações ao paciente reaproveitam o mecanismo da equipe com outro destinatário; push no celular e SMS/WhatsApp dependem de biblioteca e fornecedor ainda não aprovados.
