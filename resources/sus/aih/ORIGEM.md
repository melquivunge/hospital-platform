# AIH (SISAIH01) — layout e tabelas oficiais

- `layout_sisaih01_2026_07.pdf`: layout da interface texto do SISAIH01, competência 07/2026, extraído sem alteração de
  `ftp://ftp2.datasus.gov.br/public/sistemas/dsweb/SIHD/Manuais/Layout_do_SISAIH01_para_CMPT_202607.zip` (página
  "Documentos sobre o SISAIH01" de http://sihd.datasus.gov.br), baixado em 28/10/2026. SHA-256 do zip:
  73a24c52e3d1b57c29c629de1d8e3dd62f7832ebe05208b4d2b550bf135e4dc7; do PDF:
  73d39ceb418b75d1847da8ff2ef3b7a17aa38ca8f9251247c2cf5212c2d9ff9f.
- `nacionalidades.json` (329 códigos) e `tipos-logradouro.json` (129 códigos): código de 3 dígitos => nome, das tabelas
  TB_NACIONALIDADE e TB_LOGRADOURO do banco `SISAIH99.GDB` do programa oficial SISAIH01, versão 01.15
  (`ftp://ftp2.datasus.gov.br/public/sistemas/dsweb/SIHD/Programas/SISAIH01_VER0115.exe`, SHA-256
  2ac1daa42ebdffd4683dfa1570b3f4475da800cccf7f2f73047d2dd7f43dd9ee), baixado em 28/10/2026, extraído com `innoextract`.
  Os registros foram lidos diretamente das páginas do banco Firebird (nome seguido do código), sem o servidor Firebird.
  Tipos de logradouro: todos os 129 registros da tabela conferidos. Nacionalidades: 329 registros lidos. Conferido na
  etapa 6b.6b2 (28/10/2026): o bloco de 64 KiB da tabela tem 330 marcas de código; a que sobra é de um registro da
  tabela vizinha de municípios (PILOES/RN), não uma nacionalidade. Os códigos "RESERVADO" (020, 048, 049, 050) não são
  oferecidos no cadastro. Exemplos: 010 BRASIL, 045 PORTUGAL; 081 RUA, 008 AVENIDA.
- `carater-internacao.json` (6 códigos, CAR_INTERN) e `motivos-saida.json` (21 códigos, MOT_SAÍDA): tabelas
  TB_CARAC_INTERN e TB_MOT_COBR do banco `SISAIH00.GDB` do mesmo instalador (SHA-256 do arquivo extraído:
  8fb69714599613471efbf5a036b237cef2e74159fecff20520763b2e88e54ca4), lidas das páginas do banco (código, tamanho,
  nome), texto sem alteração. Limite: o programa 01.15 é de 2008; códigos criados depois nessas tabelas não estão
  aqui (conferir na importação real no SISAIH01).
- `especialidades-leito.json` (32 códigos, ESPEC_AIH): a lista do próprio layout 07/2026 (página 2).
