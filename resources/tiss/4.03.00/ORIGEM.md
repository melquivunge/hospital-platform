# Padrão TISS 4.03.00 — esquemas oficiais (componente de comunicação)

Fonte: ANS, "Padrão TISS – Setembro/2026", Componente de Comunicação (versões 04.03.00 e 01.06.00),
https://www.gov.br/ans/pt-br/assuntos/prestadores/padrao-para-troca-de-informacao-de-saude-suplementar-2013-tiss/PadroTISSComunicao_202511.zip
(SHA-256 do zip: db8640e1c3b87085892f54f838bfcea9934439ff365798c8428559f88c13d62d), baixado em 27/10/2026.

Arquivos copiados sem alteração (pasta "Padrão TISS Comunicação 040300" do zip). Usados para validar o XML do lote
no teste (etapa 6.5). Ao mudar de versão, guardar a nova pasta ao lado e registrar a versão no lote.

## Terminologias (códigos usados no XML)

Os significados dos códigos vêm das tabelas de domínio oficiais da TUSS, versão 202609: ANS, "Padrão TISS –
Componente de Representação de Conceitos em Saúde",
https://www.ans.gov.br/arquivos/extras/tiss/Padrao_TISS_Representacao_de_Conceitos_em_Saude_202609.zip
(SHA-256 do zip: 7f3d7a9794a8929fddd6f13af26dd9496087bf8b8670f275bd7ecd9a2f64172d), planilha "TUSS - Demais
terminologias", consultada em 27/10/2026. Usados em `app/Billing/TissLotXml.php`: tabela 23 (caráter: 1 eletivo,
2 urgência), 25 (despesa: 02 medicamentos, 03 materiais, 05 diárias), 26 (conselho: 02 COREN, 06 CRM...), 36 (9 não
acidente), 50 (04 consulta), 60 (036 unidade), 76 (regime: 01 ambulatorial, 04 pronto-socorro) e 87 (tabelas: 00
própria, 18, 19, 20, 22). A UF é o código do IBGE (dm_UF) e o CBO aceito é a lista dm_CBOS do próprio XSD.

`glosas.json` é a tabela 38 (mensagens: glosas, negativas e outras), os 470 termos vigentes da mesma planilha
(código de 4 dígitos e termo, espaços repetidos reduzidos a um), usada no retorno da operadora (etapa 6.5c).

Hash do epílogo: MD5 do texto de cada elemento folha, em ordem, sem tags, atributos nem indentação, em ISO-8859-1
(como o arquivo, segundo o Componente Organizacional). Há implementações que calculam sobre UTF-8: conferir no
validador da primeira operadora.

