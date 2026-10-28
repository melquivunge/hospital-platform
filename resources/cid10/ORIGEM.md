# CID-10 — tabela oficial do DATASUS

Fonte: DATASUS, "CID-10 — Versão 2008", arquivo CSV,
http://www2.datasus.gov.br/cid10/V2008/downloads/CID10CSV.zip
(SHA-256 do zip: 84f23809275575f751255048064bbb244b0de33fd5987ab98df0f98e5f5d2c95), baixado em 27/10/2026.

`cid10.json` é gerado de `CID-10-SUBCATEGORIAS.CSV` (ISO-8859-1, separado por ponto e vírgula): cada código, sem o
ponto (como na SIGTAP e nos arquivos do SUS: `A000`, ou a categoria de 3 caracteres quando ela não tem subcategoria,
como `A33`), aponta para `[descrição, restrição de sexo]`, em que a restrição é `F`, `M` ou vazia (coluna
`RESTRSEXO`). São 12.451 códigos. Usado em `app/Clinical/Cid10.php` (etapa 6b.1, ADR-0011). Ao trocar de versão,
gerar de novo pelo mesmo critério e atualizar esta nota.
