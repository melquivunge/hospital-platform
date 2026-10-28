# Municípios — tabela oficial do IBGE

`municipios.json`: código IBGE do município (7 dígitos, com o dígito verificador) => [nome, UF], dos 5.571 municípios
da API oficial de localidades do IBGE (https://servicodados.ibge.gov.br/api/v1/localidades/municipios), baixada em
28/10/2026 (SHA-256 da resposta: 86ecdccdf97d72e7e5e46f0854cfcea8bacc28154cc1bc4ed0e6110e3a6c9c02), reduzida a código,
nome e sigla da UF. Os arquivos do SUS (BPA, AIH) levam o código sem o dígito verificador (6 primeiros dígitos).
