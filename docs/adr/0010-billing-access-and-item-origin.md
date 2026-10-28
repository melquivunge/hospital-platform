# ADR-0010: acesso do faturamento e origem dos itens da conta

Data: 25/10/2026
Estado: aceita pelo usuário (25/10/2026)

## Contexto

A entrega 6 (contrato da entrega 6 no PRODUCT.md, todas as propostas aceitas pelo usuário em 25/10/2026) traz a conta do paciente para particular e convênio; o SUS fica na entrega 6b. Os itens vêm sozinhos dos registros assistenciais das entregas 3 a 5b, e um papel novo, Faturamento, revisa, fecha e envia. A conta liga o paciente ao que foi feito nele, então é dado clínico. O faturamento precisa do nome do paciente, da carteira, das datas e dos códigos, mas não da evolução, dos resultados nem das descrições.

## Decisão

- **Papel novo, Faturamento**, por unidade. Vê as contas dos atendimentos da própria unidade (paciente, modalidade, carteira, itens com código, quantidade, preço e data, e a origem de cada item pelo tipo), sem acesso ao prontuário: nada de evolução, resultado, laudo, descrição ou indicação. Abrir uma conta grava uma leitura na trilha com o motivo "billing".
- **Conta, item, autorização, lote, retorno e recebimento são tabelas clínicas** com RLS forçado ([ADR-0003](0003-rls-on-clinical-tables.md)). Tabelas de preço, convênios e contratos são da rede, sem dado de paciente, sem RLS.
- **Origem dos itens:** cada item automático guarda o tipo e o id do registro que o gerou (atendimento encerrado, dia de internação, item de exame liberado, cirurgia concluída, movimento de estoque de cirurgia ou de dispensação). Um índice único por origem garante que um registro gera no máximo um item, mesmo com a geração repetida ou concorrente. A geração é idempotente: roda quando o atendimento encerra, na alta e quando o faturamento abre a conta. Estorno de material ou devolução de medicamento geram item negativo com a origem do próprio movimento.
- **Preço congelado:** o item guarda o código e o preço em centavos (inteiro) da tabela vigente na data do registro de origem, para a modalidade e o convênio do atendimento. Mudar a tabela depois não altera conta existente.
- **Conta fechada é imutável no banco** (gatilho), como a descrição assinada: itens, valores e autorizações não mudam nem são apagados depois do fechamento. Correção por conta complementar ou estorno.
- **Lote:** uma conta entra em no máximo um lote ativo (índice único); o arquivo gerado fica guardado com o hash. Retorno e glosa são registros novos ligados ao item, nunca alteração do item.
- **Particular:** recebimento registrado (forma de pagamento informativa, sem integração) e recibo em PDF; sem nota fiscal.
- **Auditoria e avisos:** só ids e valores; nunca nome do paciente nem procedimento ligado a ele.

## Alternativas

### O faturamento lê o prontuário durante a revisão
- **Prós:** confere justificativas clínicas de glosa sem pedir a ninguém.
- **Contras:** amplia muito quem lê o prontuário, sem necessidade na maior parte das contas.
- **Por que não:** o usuário escolheu a proposta (questão 3); a justificativa clínica de um recurso é pedida a quem atendeu.

### Conta montada à mão
- **Prós:** mais simples de construir.
- **Contras:** item esquecido e cobrança em dobro dependem só da atenção do faturista.
- **Por que não:** questão 2 (itens automáticos, com revisão).

## Consequências

### Positivas
- Cada valor cobrado é rastreável até o registro assistencial; cobrar duas vezes o mesmo registro é impossível no banco.
- O faturamento trabalha sem acesso ao conteúdo clínico.

### Negativas
- Itens automáticos dependem de as entregas anteriores registrarem bem (um exame sem resultado liberado não é cobrado).
- O XML TISS segue o esquema da ANS, que muda de versão; a versão usada fica registrada no lote.

### Riscos
- **Versão do TISS:** o esquema oficial precisa ser obtido no site da ANS na etapa do lote; sem ele, o arquivo não é validado. Mitigação: validar o XML contra o XSD oficial no teste.
- **Itens sem preço na tabela:** a conta não fecha com item sem preço; o faturamento vê a pendência.

## Revisão (27/10/2026, decisão do usuário)

O administrador de rede também usa a tela "Contas" de todas as unidades da rede, sem precisar do papel Faturamento. Ele lê o mesmo que o Faturamento (sem prontuário), e cada abertura de conta continua gravada na trilha com o motivo "billing".

## Revisão (27/10/2026, ADR-0011)

Na conta SUS, o faturamento também lê o código e o nome do CID-10 informado por quem atendeu (não a evolução, o resumo da alta nem a justificativa do laudo da AIH). Ver [ADR-0011](0011-sus-diagnosis-and-sigtap.md).
