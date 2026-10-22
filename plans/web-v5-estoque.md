# Plano — entrega 4c: estoque da farmácia

Data: 22/10/2026. Estado: **proposto, aguardando aprovação do usuário.** Uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização do usuário.
Contrato: ../PRODUCT.md, seção "Contrato da entrega 4c" (decisões do usuário de 22/10/2026). Arquitetura: ../docs/adr/0007-pharmacy-stock-ledger.md e ../docs/adr/0005-pharmacy-prescription-access.md. Regras de trabalho: ../AGENTS.md.

## Princípios herdados

- Movimentos só de inserção, com gatilho no banco.
- Saldo mantido pelo banco, nunca negativo.
- Locks em ordem fixa (internação → prescrição → item → lotes por id) e corrida real em toda disputa.
- Testes negativos de acesso (outra unidade, papel revogado, administrador que só vê).
- Nenhum dado de paciente no estoque, nos logs, nas notificações ou na auditoria.
- Telas verificadas em 360, 768 e 1440 px com as referências visuais.
- Revisão de segurança independente em toda etapa.

## Etapas

### 4c.1 Lotes, entradas e saldo
- **Catálogo:** cada medicamento ganha a unidade de estoque (rótulo: comprimido, ampola, frasco), definida pelo administrador. Cada unidade de hospital define o estoque mínimo por medicamento.
- **Entrada de lote pela farmácia:**
  - campos: medicamento, código do lote, validade, fabricante opcional e quantidade;
  - entrada repetida do mesmo lote soma no mesmo lote;
  - não se aceita entrada de lote já vencido.
- **Tela de saldo:** por medicamento e lote, com validade, a vencer em 30 dias, vencido e abaixo do mínimo.
- **Importação CSV do saldo inicial** (mecanismo da V1, com relatório de exceções e sem duplicar no reprocesso).
- **Saída:** saldo por lote igual à soma dos movimentos (teste); `CHECK` e gatilhos provados com teste de banco.

### 4c.2 Dispensação e devolução com estoque
- A dispensação da 4b sugere os lotes válidos que vencem primeiro. A farmácia aceita ou troca e pode dividir a quantidade entre lotes.
- Sem saldo, recusa com mensagem. Na mesma tela, "Registrar entrada" abre a entrada de lote (decisão da questão 4).
- A devolução volta para os lotes da dispensação. Dispensação anterior à 4c devolve sem movimento.
- **Saída:**
  - duas dispensações simultâneas do último saldo de um lote resultam numa baixa só (corrida real);
  - lote vencido nunca é dispensado;
  - a jornada da 4b continua passando.

### 4c.3 Descarte e inventário
- **Descarte com motivo:** vencido, avaria, perda ou outro com texto. Lote vencido pode ser descartado inteiro.
- **Inventário:**
  - a farmácia abre um inventário para a unidade (todos os medicamentos ou alguns);
  - conta por lote (com horário) e informa lote encontrado sem cadastro;
  - o gestor da unidade, que não pode ser quem contou, aprova ou recusa o ajuste;
  - o ajuste usa o saldo no horário da contagem;
  - a dispensação continua durante a contagem.
- **Saída:** o ajuste nunca deixa lote negativo; a corrida aprovar ajuste × dispensar resulta em saldo consistente.

### 4c.4 Avisos, relatório e indicadores
- **Serviço `scheduler`** no `compose.yaml` (`schedule:work`) e comando diário de avisos:
  - lote vence em 30 dias;
  - saldo abaixo do mínimo;
  - para a farmácia e o gestor da unidade;
  - sem repetir o mesmo aviso no mesmo dia.
- **Relatório de movimentos por período e medicamento** (controlados em destaque), para a escrituração manual. Exportação CSV sem dado de paciente.
- **Indicadores do gestor:** itens abaixo do mínimo, a vencer e perdas por motivo; visão consolidada da rede para o administrador.
- **Saída:** comando idempotente no mesmo dia (teste); relatório confere com os movimentos.

### 4c.5 Revisão integrada
- **Jornada num cliente novo:**
  1. importação do saldo inicial;
  2. entrada de lote;
  3. prescrição → avaliação → dispensação dividida entre dois lotes → devolução;
  4. tentativa com lote vencido recusada;
  5. sem saldo → entrada rápida → dispensação;
  6. descarte;
  7. inventário com ajuste aprovado;
  8. avisos e relatório.
- Verificação de telas, revisão de segurança independente e limites de produção no README.

## Depois da entrega 4c

- Entrega 5 (exames e cirurgia), conforme o roteiro.
- Ficam para depois:
  - farmácias satélites e estoque por setor;
  - embalagem com fator de conversão e compras;
  - livro de controlados e SNGPC;
  - custo e faturamento (entrega 6).

## Riscos

- **Saldo divergente da prateleira** no começo: o saldo só vale a partir da importação ou do primeiro inventário. Uma entrada esquecida trava a dispensação até ser registrada (mitigado pela entrada rápida).
- **Desempenho da dispensação:** mais locks e linhas na mesma transação. Medir na corrida e no navegador.
- **Migração:** o gatilho de saldo e o `down` recusam se houver movimentos. Sempre aplicar no banco local, além do CI com banco vazio.
- **Escrituração de controlados:** continua manual; o relatório só apoia.
