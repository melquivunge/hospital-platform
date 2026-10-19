# ADR-0006: assinatura ICP-Brasil em nuvem para a prescrição

Data: 19/10/2026
Estado: aceita pelo usuário (19/10/2026; escolha "certificado em nuvem"). O fornecedor ainda não foi definido.

## Contexto

Desde a entrega 3, a evolução e o sumário são assinados com a senha do sistema. Essa assinatura não tem a validade jurídica da assinatura digital qualificada. Na prescrição de medicamentos, o usuário decidiu (19/10/2026) usar certificado ICP-Brasil. Os médicos hoje usam certificados em nuvem, guardados por um prestador de serviço de confiança (PSC) credenciado pelo ITI e liberados no celular. Integrar um PSC exige contrato e credenciais do fornecedor, que ainda não existem.

## Decisão

- A prescrição assinada gera um **documento canônico em PDF**, assinado no formato **PAdES** pelo certificado em nuvem do médico, via API do PSC. O médico autoriza a assinatura no próprio PSC; o sistema não guarda chave privada nem senha do certificado.
- O médico vincula a conta do PSC uma vez. O sistema guarda o mínimo que o fornecedor exigir (identificador e token de acesso cifrados) e nunca grava o token em log.
- O PDF assinado é guardado como documento clínico, com RLS e trilha. O resultado da validação (cadeia ICP-Brasil, data, CPF do titular igual ao do profissional) fica registrado com a prescrição.
- A integração fica atrás de uma interface (`PrescriptionSigner`). Há uma implementação de teste para desenvolvimento e CI, e uma por PSC quando houver contrato.
- Sem PSC configurado, a prescrição continua assinada com senha e é identificada na tela e no documento como "assinatura eletrônica simples". A evolução e o sumário seguem com senha até nova decisão.

## Alternativas

### Certificado A1 enviado ao servidor
- **Prós:** nenhum fornecedor intermediário.
- **Contras:** o servidor guardaria arquivos de certificado e as senhas deles. Um vazamento permitiria assinar em nome de qualquer médico.
- **Por que não:** o risco é alto demais para a plataforma.

### Manter só a senha
- **Prós:** nada a integrar.
- **Contras:** a prescrição não teria validade jurídica de assinatura qualificada.
- **Por que não:** decisão do usuário.

## Consequências

### Positivas
- A prescrição passa a ter assinatura qualificada sem que a plataforma guarde chaves privadas.
- A interface permite trocar de PSC ou usar mais de um.

### Negativas
- Assinar depende do PSC estar no ar e do médico autorizar no celular, o que acrescenta um passo na rotina.
- O fornecedor e as credenciais são um bloqueio externo: a validação real só acontece depois do contrato.

### Riscos
- **API do PSC:** cada fornecedor tem sua API. O primeiro adaptador será escrito pela documentação oficial dele, nunca por suposição.
- **Token do PSC:** deve ser cifrado em repouso e excluído na revogação do acesso do profissional.
