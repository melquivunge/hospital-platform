# Plano — entrega 4: urgência, internação e leitos

Data: 18/10/2026. Estado: **aprovado pelo usuário para implementação em 18/10/2026**, uma etapa por vez (uma etapa por PR, CI verde, revisão; merge só com autorização).
Contrato: ../PRODUCT.md, seção "Contrato da entrega 4". Arquitetura: ../docs/adr/0003-rls-on-clinical-tables.md e ../docs/adr/0004-ward-team-record-access.md. Regras de trabalho: ../AGENTS.md.

## Princípios herdados

Os mesmos da entrega 3 (plans/web-v2-ambulatorio.md):
- monólito Laravel e React;
- RLS forçado nas tabelas com dado de paciente;
- acesso clínico só pelo `ClinicalAccess`, com cada leitura registrada;
- gatilhos no banco para o que não pode mudar;
- locks em ordem fixa e corrida real em toda disputa por recurso (leito, paciente, atendimento);
- testes negativos de acesso;
- telas verificadas no navegador em 360, 768 e 1440 px, com as referências visuais;
- revisão de segurança independente em toda etapa clínica.

## Antes da entrega 4: pendência da entrega 3

### 3.8 Revisão integrada (prevista no plano da entrega 3 e ainda não feita)
- Jornada completa num cliente novo, só pelas rotas: cadastro → agenda → chegada → atendimento → evolução assinada → documento → encerramento, e também acesso de emergência e trilha.
- Telas das etapas 3.1 a 3.7 nos três perfis (recepção, profissional, gestão e auditoria) em 360, 768 e 1440 px, sem erro de JavaScript ou 5xx, sem rolagem horizontal, com nomes acessíveis.
- Revisão de segurança independente da entrega inteira. Limites de produção da entrega 3 no README.
- **Saída:** a jornada ambulatorial ponta a ponta num cliente sintético novo, com relatório.

## Etapas da entrega 4

### 4.1 Leitos
- Setor de internação (setor existente marcado como de internação), quartos e leitos com atributos: tipo (enfermaria, apartamento, UTI), restrição de sexo e isolamento.
- Estados do leito com histórico: livre, reservado, ocupado, em higienização, bloqueado (com motivo).
- Papéis novos por unidade: **Gestão de leitos** (reserva e bloqueia) e **Higienização** (marca o leito como pronto).
- Mapa de leitos por setor para a gestão de leitos e o gestor da unidade.
- **Saída:** mapa confiável; dois pedidos simultâneos pelo mesmo leito resultam numa reserva só (corrida real).

### 4.2 Chegada e classificação de risco
- Escala de 5 níveis da rede (cor, nome, tempo-alvo), mantida pelo administrador de rede.
- Recepção registra a chegada à urgência: paciente existente, cadastro provisório ou "Não identificado" (sexo, idade aparente, descrição).
- Classificação pela enfermagem: queixa principal, sinais vitais e nível. A reclassificação gera um registro novo ligado ao anterior.
- Fila por nível e tempo de espera, com destaque visual para o tempo-alvo estourado.
- **Saída:** fila ordenada por risco e espera; a reclassificação preserva o histórico; o "Não identificado" é unificável depois.

### 4.3 Atendimento de urgência
- O profissional chama da fila e abre o atendimento, que passa a nascer também de um episódio de urgência, além do agendamento. A evolução, o adendo, os documentos e a trilha são os da entrega 3.
- Desfechos: alta da urgência, evasão (horário e autor) ou pedido de internação.
- **Saída:** episódio de urgência completo; duas chamadas do mesmo paciente resultam num atendimento só.

### 4.4 Internação
- Pedido de internação com setor pedido e médico responsável.
- A gestão de leitos aloca o leito. Fora da regra de sexo ou isolamento: aviso e justificativa registrada.
- **Acesso pela equipe do setor** (ADR-0004): o médico responsável e quem tem habilitação aprovada no setor do leito leem e escrevem enquanto o paciente estiver lá; motivo `internação` na trilha.
- Evolução de internação por vários autores.
- **Saída:**
  - o banco recusa dois pacientes no mesmo leito e duas internações ativas do mesmo paciente (corrida real);
  - a equipe de outro setor não lê;
  - a gestão de leitos não lê conteúdo clínico.

### 4.5 Movimentação interna e higienização
- Troca de leito, de setor e de médico responsável, cada uma registrada sem reescrever o histórico.
- O leito anterior vai para higienização, e a higienização o devolve a livre. Bloqueio e desbloqueio com motivo.
- **Saída:** ao mudar de setor, o acesso troca na hora (o setor anterior perde, o novo ganha); o histórico de leitos fica completo.

### 4.6 Transferência entre unidades da rede
- Solicitada → aceita pela gestão de leitos do destino (leito reservado) → saída registrada → recebimento registrado → concluída. Recusa e cancelamento com motivo.
- Avisos à gestão de leitos das duas unidades, sem dado clínico.
- **Saída:** paciente internado no destino com o histórico; o leito de origem vai para higienização; recusa e cancelamento preservam o motivo.

### 4.7 Alta e censo
- Tipos de alta: melhora, a pedido, transferência, óbito, evasão. Sumário assinado obrigatório, exceto óbito e evasão.
- O leito vai para higienização e o acesso da equipe termina na alta.
- Censo diário por unidade e setor (ocupados, livres, bloqueados, em higienização, taxa de ocupação, tempo médio de internação), sem conteúdo clínico.
- **Saída:** a alta sem sumário é recusada quando ele é obrigatório; o censo bate com o mapa de leitos.

### 4.8 Revisão integrada
- Jornada: chegada → classificação → atendimento de urgência → internação → troca de setor → transferência entre unidades → alta, num cliente novo.
- Verificação de telas, revisão de segurança independente e limites de produção no README.

## Depois da entrega 4

- **Entrega 4b:** prescrição e farmácia, também antes de qualquer uso real.
- Hospedagem, quando o usuário decidir.

## Riscos

- **Superlotação real:** o modelo de leito único com bloqueio no banco precisa conviver com a improvisação (macas no corredor). Proposta para a 4.1: o leito "extra" pode ser criado pela gestão de leitos, identificado como tal.
- **Acesso amplo pela equipe do setor (ADR-0004):** mitigado pela trilha de leitura e pela auditoria; testes negativos em cada mudança de setor.
- **Escala de risco própria:** não é Manchester; validar com especialista antes de uso real e não declarar equivalência.
- **Validade legal:** sem ICP-Brasil, e declaração de óbito fora do sistema; limites conhecidos.
