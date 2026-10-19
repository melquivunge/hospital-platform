# Plano — entrega 4b: prescrição e farmácia

Data: 19/10/2026. Estado: **proposto, aguarda aceite do usuário**. Depois do aceite: uma etapa por PR, com CI verde e revisão; o merge só acontece com autorização.
Contrato: ../PRODUCT.md, seção "Contrato da entrega 4b" (decisões do usuário de 19/10/2026). Arquitetura: ../docs/adr/0003-rls-on-clinical-tables.md, ../docs/adr/0004-ward-team-record-access.md e ../docs/adr/0005-pharmacy-prescription-access.md (proposta). Regras de trabalho: ../AGENTS.md.

## Princípios herdados

Os mesmos das entregas 3 e 4:
- RLS forçado nas tabelas com dado de paciente;
- acesso clínico só pelo `ClinicalAccess`, com cada leitura registrada;
- gatilhos no banco para o que é assinado ou só de inserção;
- locks em ordem fixa (internação → prescrição → item → horário) e corrida real em toda disputa;
- testes negativos de acesso;
- telas verificadas em 360, 768 e 1440 px com as referências visuais;
- revisão de segurança independente em toda etapa.

## Etapas

### 4b.1 Catálogo de medicamentos e alergias
- Catálogo da rede, mantido pelo administrador: princípio ativo (DCB), forma, concentração, unidade de dose, vias permitidas, marcação de controlado e de alto alerta, desativar em vez de excluir. Importação CSV, aproveitando o mecanismo de importação da V1.
- Alergias no paciente (substância ou medicamento do catálogo, reação, gravidade, autor), registradas por quem tem acesso clínico. Só de inserção, com inativação justificada.
- **Saída:** catálogo utilizável e alergias visíveis no cabeçalho do prontuário.

### 4b.2 Prescrição
- O médico (função que atende, com CRM, com acesso pela internação ou pelo atendimento) monta a prescrição do dia. Cada item tem medicamento, dose, via, frequência ou horários, "se necessário" com condição, início e observação.
- Rascunho, depois assinatura com senha. A prescrição vale 24 horas. A do dia seguinte nasce por cópia da anterior e tem que ser revisada e assinada. Suspender um item exige motivo.
- Alergia correspondente: aviso e justificativa obrigatória no item.
- Aprazamento: os horários previstos são gerados na assinatura, no fuso da unidade.
- **Saída:** prescrição assinada imutável; prescrição vencida não gera horários; a alta encerra a prescrição ativa.

### 4b.3 Avaliação farmacêutica e dispensação
- Papel **Farmácia** por unidade (ADR-0005). Fila de prescrições a avaliar. O farmacêutico aprova, aprova com observação ou recusa cada item com motivo. A recusa avisa o médico prescritor.
- Dispensação por item para 24 horas, e devolução registrada.
- **Saída:** só item aprovado é dispensado; duas dispensações simultâneas do mesmo item resultam numa só (corrida real).

### 4b.4 Administração pela enfermagem
- Tela do setor com os horários previstos de cada paciente, destacando os atrasados. Cada horário pode ser administrado (horário real), recusado pelo paciente ou não realizado com motivo, sempre como registro novo.
- Controlados e alto alerta: segunda pessoa confirma com a própria senha (dupla checagem).
- Administrar item não dispensado só como exceção, com motivo registrado.
- **Saída:** histórico de administração por item; dois registros simultâneos do mesmo horário resultam num só (corrida real).

### 4b.5 Revisão integrada
- Jornada num cliente novo: internação → prescrição com alergia justificada → avaliação → dispensação → administração com dupla checagem → renovação no dia seguinte → alta encerrando a prescrição.
- Verificação de telas, revisão de segurança independente e limites de produção no README.

## Depois da entrega 4b

- **Entrega 4c:** estoque da farmácia (saldo, lote, validade, inventário), decisão do usuário de 19/10/2026.
- Entregas 5 (exames e cirurgia) e 6 (faturamento), conforme o roteiro.

## Riscos

- **Segurança do paciente:** o sistema não verifica interações nem dose máxima (não objetivo). A avaliação farmacêutica é a barreira, e isso precisa estar claro para quem usar.
- **Validade legal:** sem ICP-Brasil, e a receita de controle especial fica fora do sistema.
- **Acesso da farmácia (ADR-0005):** papel concedido por engano; mitigado pela auditoria da concessão e pela trilha de leitura.
- **Fuso e horário de verão:** o aprazamento no relógio da unidade herda o limite conhecido da agenda.
