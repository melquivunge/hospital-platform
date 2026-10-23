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
- **Devolução:** volta para os lotes que a dispensação usou, até o que cada um deu, começando pelo de validade mais longa. Dispensações anteriores à 4c não têm movimento e a devolução delas também não gera.
- **Entrada esquecida (decisão da questão 4):** a tela da dispensação permite à farmácia registrar uma entrada de lote. A entrada é uma ação separada, um movimento `entry` comum e auditado, e não um atalho que dispensa sem saldo.
- **Inventário:**
  - a contagem de cada lote grava o horário;
  - o ajuste é a diferença entre o contado e o saldo calculado pelos movimentos até aquele horário, para que o que saiu depois não seja ajustado de novo;
  - o ajuste só é aplicado com a aprovação do gestor da unidade, que tem que ser outra pessoa;
  - a contagem não trava a dispensação.
- **Acesso:**
  - a farmácia (papel da 4b) movimenta só a própria unidade;
  - o gestor da unidade vê saldos e aprova ajustes;
  - o administrador da rede só vê;
  - estoque e movimentos ficam fora do RLS, porque não têm dado de paciente, e a autorização é por unidade nas consultas e policies, como nas tabelas operacionais.
- **Auditoria:** só ids e quantidades. O movimento de dispensação guarda só o id da dispensação; ligá-lo ao paciente continua exigindo o acesso clínico da ADR-0005.
- **Avisos diários:** um comando agendado avisa a farmácia e o gestor de lotes que vencem em 30 dias e de saldo abaixo do mínimo, uma vez por dia, pelas notificações internas. Isso exige um serviço `scheduler` no `compose.yaml`, que hoje não existe.

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
