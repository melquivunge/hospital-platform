# ADR-0007: estoque da farmácia como livro de movimentos

Data: 22/10/2026
Estado: aceita pelo usuário (22/10/2026)

## Contexto

A entrega 4c (contrato no PRODUCT.md, decisões do usuário de 22/10/2026) traz o estoque da farmácia de cada unidade, por lote e validade. A dispensação da 4b passa a baixar o estoque e a devolução a repô-lo. Há três exigências fortes:
- o saldo de um lote nunca pode ficar negativo;
- duas dispensações simultâneas do último saldo de um lote resultam numa única baixa;
- o histórico nunca é apagado.

A dispensação é uma tabela clínica (RLS, ADR-0003). O estoque não tem dado de paciente.

## Decisão

- **Lote** (`stock_lots`, não clínica):
  - campos: cliente, unidade, medicamento do catálogo, código do lote, validade (data), fabricante opcional e `balance`;
  - único por unidade, medicamento, código e validade.
- **Movimento** (`stock_movements`, não clínica, só de inserção, com gatilho que recusa UPDATE, DELETE e TRUNCATE):
  - campos: lote, tipo (`entry`, `dispensing`, `return`, `discard`, `adjustment`), quantidade com sinal, autor, data, motivo quando o tipo exige, e o id da dispensação ou do inventário quando houver;
  - uma restrição no banco amarra o sinal e o motivo a cada tipo.
- **Saldo mantido pelo banco:**
  - um gatilho `AFTER INSERT` em `stock_movements` soma a quantidade ao `balance` do lote;
  - `stock_lots` tem `CHECK (balance >= 0)`, então um movimento que deixaria o lote negativo falha e desfaz a transação inteira;
  - o saldo nunca é escrito pela aplicação, e um teste confere que ele é sempre a soma dos movimentos.
- **Locks:** a ordem existente (internação → prescrição → item) ganha os lotes no fim, sempre em ordem crescente de id.
  - Entrada, descarte e ajuste travam só os lotes envolvidos.
  - A corrida real é verificada como nas entregas anteriores.
- **Dispensação:**
  - a aplicação sugere os lotes válidos que vencem primeiro e aceita a escolha da farmácia, podendo dividir a quantidade entre lotes;
  - grava a dispensação da 4b e um movimento `dispensing` por lote, na mesma transação;
  - lote vencido (validade antes de hoje no fuso da unidade) é recusado no servidor.
- **Devolução:** volta para os lotes que a dispensação usou, até o que cada um deu, começando pelo de validade mais longa. Dispensações anteriores à 4c não têm movimento e a devolução delas também não gera. Se o lote venceu nesse meio-tempo, a devolução entra e sai na mesma transação como descarte com motivo `expired`, e a farmácia é avisada para separar as unidades (decisão de 23/10/2026, a pedido do usuário). O banco também recusa movimento de dispensação de lote vencido ou de outra unidade ou medicamento, e devolução acima do que o lote deu.
- **Descarte (4c.3a):** motivo codificado (`expired`, `damage`, `loss`, `other`); só "outro" leva um texto curto (`note`, até 200 caracteres), e o banco amarra código e texto. "Vencido" só vale para lote vencido no fuso da unidade, e um lote vencido pode ser descartado inteiro. A quantidade vai até o saldo, lido com o lote travado. A auditoria guarda o código, nunca o texto.
- **Entrada esquecida (decisão da questão 4):** a tela da dispensação permite à farmácia registrar uma entrada de lote. A entrada é uma ação separada, um movimento `entry` comum e auditado, e não um atalho que dispensa sem saldo.
- **Inventário:**
  - a contagem de cada lote grava o horário;
  - o ajuste é a diferença entre o contado e o saldo calculado pelos movimentos até aquele horário, para que o que saiu depois não seja ajustado de novo. Na 4c.3b esse saldo é lido com o lote travado no momento da contagem e guardado na linha (a precisão de segundos dos movimentos deixaria ambíguo o mesmo segundo);
  - contagem cega: ninguém vê o saldo do sistema enquanto conta; o gestor vê saldo e diferença depois do envio;
  - lote encontrado sem cadastro entra com código e validade e o lote é criado na aprovação; se ele foi cadastrado nesse meio-tempo, a aprovação é recusada e pede nova contagem;
  - um inventário em andamento por unidade; um ajuste por lote e inventário (índice único); a aprovação recusa ajuste que deixaria lote negativo (saiu mais depois da contagem);
  - o vínculo do ajuste à unidade do inventário é garantido pela aplicação, não pelo banco;
  - o ajuste só é aplicado com a aprovação do gestor da unidade, que tem que ser outra pessoa: quem abriu, contou (mesmo se a contagem foi refeita por outro) ou enviou não decide;
  - a contagem não trava a dispensação.
- **Acesso:**
  - a farmácia (papel da 4b) movimenta só a própria unidade;
  - o gestor da unidade vê saldos e aprova ajustes;
  - o administrador da rede só vê;
  - estoque e movimentos ficam fora do RLS, porque não têm dado de paciente, e a autorização é por unidade nas consultas e policies, como nas tabelas operacionais.
- **Auditoria:** só ids e quantidades. O movimento de dispensação guarda só o id da dispensação; ligá-lo ao paciente continua exigindo o acesso clínico da ADR-0005.
- **Avisos diários:** um comando agendado avisa a farmácia e o gestor de lotes que vencem em 30 dias e de saldo abaixo do mínimo, uma vez por dia, pelas notificações internas. Isso exige um serviço `scheduler` no `compose.yaml`, que hoje não existe.
  - 4c.4a: `stock:alerts` roda de hora em hora no serviço `scheduler` e avisa cada unidade uma vez por dia da unidade, a partir das 7 h no fuso dela (uma linha por unidade e dia, com índice único, na mesma transação do evento). Conta também lotes vencidos com saldo; o mínimo considera só lotes válidos. Só contagens, sem dado de paciente.
  - O gestor da unidade também é avisado de inventário enviado e passa a ver a tela de estoque só para consulta.
- **Indicadores (4c.4c):** por unidade, abaixo do mínimo (lotes válidos, a mesma regra do aviso e da tela), lotes a vencer em 30 dias, vencidos com saldo e perdas no período por motivo (descartes e diferença negativa de inventário); a unidade escolhida em detalhe. Farmácia e gestor veem as próprias unidades; o administrador da rede vê todas, só para consulta. No cartão, perdas em número de registros (unidades de medicamentos diferentes não se somam); no detalhe, em unidades de estoque.
- **Relatório de movimentos (4c.4b):** por unidade, período em dias da unidade (até 366) e medicamento, controlados primeiro e em destaque; para a farmácia e o gestor. Dispensação e devolução aparecem só pelo tipo, nunca pelo id que leva ao paciente. CSV auditado com entrada e saída em colunas positivas (a neutralização de fórmula não transforma número em texto), até 20 mil linhas.

## Alternativas

### Saldo editável, sem livro de movimentos
- **Prós:** mais simples de consultar e de escrever.
- **Contras:** não explica como o saldo chegou ali e permite sobrescrever sem rastro.
- **Por que não:** o contrato exige rastreabilidade e histórico preservado.

### Saldo sempre calculado pela soma dos movimentos, sem coluna
- **Prós:** sem duplicação.
- **Contras:** "nunca negativo" sob concorrência exigiria lock e soma a cada dispensação, só na aplicação. As telas de saldo somariam todo o histórico.
- **Por que não:** a coluna mantida por gatilho com `CHECK` deixa o banco garantir a invariante. A soma continua disponível para conferência.

### Estoque como tabela clínica (RLS)
- **Prós:** isolamento extra.
- **Contras:** o estoque não tem dado de paciente, e o RLS clínico exigiria contexto clínico em telas operacionais (entrada, inventário, avisos).
- **Por que não:** basta não guardar nada do paciente no estoque; o vínculo fica do lado clínico.

## Consequências

### Positivas
- O banco garante que o saldo não fica negativo e que o histórico não muda.
- Um inventário mostra exatamente qual movimento explica cada diferença.
- Reaproveita o papel Farmácia, as notificações, a importação CSV e a auditoria.

### Negativas
- A dispensação da 4b fica mais longa: escolha de lotes e mais locks na mesma transação.
- Um serviço novo (`scheduler`) no ambiente.
- Um gatilho de saldo exige migração cuidadosa: o `down` recusa se houver movimentos.

### Riscos
- **Entrada lançada errada:** dá saldo falso. Mitigado pelo ajuste de inventário com duas pessoas e pela auditoria da entrada.
- **Saldo divergente da prateleira:** a recusa da dispensação pode atrasar o cuidado. Mitigado pela entrada rápida na própria tela, decisão da questão 4.
