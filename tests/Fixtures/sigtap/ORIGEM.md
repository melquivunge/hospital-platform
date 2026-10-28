# SIGTAP — recorte para testes

Recorte da tabela oficial da SIGTAP, competência 10/2026, arquivo
`TabelaUnificada_202610_v2610271043.zip` do FTP do DATASUS
(ftp://ftp2.datasus.gov.br/pub/sistemas/tup/downloads/, SHA-256 do zip:
525e5ef197da5133ed3177f42b56cb341057668af8da2fdc7f6674bcc25af5cd), baixado em 27/10/2026.

Os arquivos `*_layout.txt` são cópias sem alteração; os `.txt` de dados trazem só as linhas originais (ISO-8859-1) dos
procedimentos 0301010072 (consulta médica em atenção especializada), 0303140151 (tratamento de pneumonias ou
influenza) e 0407040129 (hernioplastia umbilical), com as primeiras 40 compatibilidades de CID e as ocupações 225125,
225225 e 225250. Usados em `tests/Feature/Clinical/SigtapImportTest.php` (etapa 6b.3).
