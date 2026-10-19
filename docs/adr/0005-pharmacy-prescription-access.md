# ADR-0005: acesso da farmácia e da enfermagem à prescrição

Data: 19/10/2026
Estado: aceita pelo usuário (19/10/2026)

## Contexto

A entrega 4b (contrato no PRODUCT.md) traz a prescrição médica, a avaliação e a dispensação pela farmácia e a administração pela enfermagem. Hoje o `ClinicalAccess` tem três bases de acesso: atendimento aberto, internação ativa (ADR-0004) e acesso de emergência. O farmacêutico não atende o paciente nem trabalha no setor do leito, mas precisa avaliar a prescrição de toda a unidade. Já a enfermagem do setor tem acesso ao prontuário inteiro pela ADR-0004.

## Decisão

- Papel novo **Farmácia**, por unidade. O farmacêutico com esse papel lê, por item, **só a prescrição** dos pacientes daquela unidade que têm uma prescrição ativa. Junto vêm as alergias, a idade, o sexo, o peso quando houver, o setor e o leito. A evolução, os documentos e o restante do prontuário ficam de fora.
- Essa leitura entra na trilha `clinical_reads` com o motivo `pharmacy` e o vínculo com a prescrição, uma linha a cada abertura de prescrição. A fila mostra só a identificação e a contagem de itens pendentes, sem gravar leitura, como a lista "Internados".
- A farmácia escreve a avaliação (aprovado, aprovado com observação ou recusado) e a dispensação. Não escreve na prescrição.
- A enfermagem do setor (ADR-0004) registra a administração. A dupla checagem de controlados e alto alerta exige uma segunda pessoa com a mesma base de acesso, que confirma com a própria senha.
- Quem prescreve continua sendo decidido pelo `ClinicalAccess` (atendimento aberto ou internação ativa), com duas exigências a mais: a função tem que atender pacientes e o conselho do profissional tem que ser CRM.
- A prescrição, os itens, a avaliação, a dispensação, a administração e as alergias são tabelas clínicas com RLS forçado ([ADR-0003](0003-rls-on-clinical-tables.md)).

## Alternativas

### Farmácia com acesso ao prontuário inteiro
- **Prós:** a avaliação usa o contexto clínico completo (evolução, exames).
- **Contras:** amplia muito quem lê o prontuário, numa equipe que avalia todos os pacientes da unidade.
- **Por que não:** a avaliação da 4b se apoia na prescrição, nas alergias e nos dados do paciente; o restante pode ser pedido depois, se a prática mostrar necessidade.

### Farmácia pelo acesso de emergência
- **Prós:** nenhuma regra nova.
- **Contras:** cada avaliação viraria um acesso de emergência, com justificativa e aviso ao gestor. É uso indevido do mecanismo, que perderia o valor de alerta.
- **Por que não:** a avaliação é rotina, não exceção.

## Consequências

### Positivas
- A farmácia trabalha com o mínimo necessário, e cada abertura fica rastreada.
- Reaproveita o `ClinicalAccess`, a trilha, a assinatura com senha e o RLS.

### Negativas
- Mais uma base de acesso no `ClinicalAccess`, que precisa de testes negativos (farmácia de outra unidade, prescrição encerrada, papel revogado).
- A avaliação farmacêutica fica sem a evolução e os exames.

### Riscos
- Um papel Farmácia dado por engano abre as prescrições da unidade inteira. A concessão do papel é auditada, e a revisão de segurança da etapa cobre esse caminho.
