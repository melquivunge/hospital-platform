# BPA — layout oficial de exportação

`Layout_Exportacao_BPA.pdf`: layout da interface texto do BPA e do SIA (cabeçalho, BPA-C e BPA-I), copiado sem
alteração de `ftp://arpoador.datasus.gov.br/siasus/BPA/Layout_Exportacao_BPA.pdf` (o mesmo arquivo está em
`siasus/Documentos/BPA/`), página "Versão do BPA" de http://sia.datasus.gov.br, baixado em 28/10/2026.
SHA-256: f318bc0359b378e8455280f87dec0ad8c016bcf853d640f2f83353e5648f9f1f.

Versão do BPA Magnético vigente na data (`BPA_LEIAME.txt` do mesmo diretório): 05.00 de 07/07/2026, obrigatória, que
cria o campo "Pessoa sem CPF/Registro Civil" (BPA-I, posição 351, válido a partir da competência 07/2026).

Pontos do layout usados na etapa 6b.6a2:

- Linhas de 132 (cabeçalho), 50 (BPA-C) e 353 (BPA-I) posições, com CR+LF no fim.
- Campo de controle do cabeçalho: soma dos códigos de procedimento e das quantidades, resto por 1111, mais 1111.
- O paciente é identificado por um só documento no BPA-I: o CPF ou o CNS.
- Raça/cor é obrigatória no BPA-I (01 a 05; "99 sem informação" está riscado na versão vigente); etnia só para 05.
