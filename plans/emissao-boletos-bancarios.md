# Plano Tecnico - Emissao de Boletos Bancarios

## Status da Implementacao

- Status geral: Implementacao parcial em andamento
- Fase atual: Fases 4 e 5, com preparacao para homologacao
- Progresso estimado: 70%
- Ultima atualizacao: 2026-09-28
- Responsavel pela atualizacao: equipe de desenvolvimento

### Checklist de acompanhamento

- [x] Fase 1 - Modelagem e permissoes
- [ ] Fase 2 - Cadastro administrativo de bancos e providers
- [x] Fase 3 - Emissao automatica por parcela
- [ ] Fase 4 - Webhook e baixa de pagamentos (funcional; assinatura de producao pendente)
- [ ] Fase 5 - Cancelamento e sincronizacao (cancelamento entregue; sincronizacao pendente)
- [ ] Fase 6 - Homologacao e liberacao gradual

Estado detalhado: Fase 1 concluida; Fase 2 parcial, sem telas administrativas e fake provider; Fase 3 concluida no fluxo de dominio e adapter; Fase 4 funcional para ingestao, idempotencia e baixa, mas bloqueada pela assinatura de producao; Fase 5 parcial, com cancelamento entregue e consulta/sincronizacao/atualizacao ainda pendentes; Fase 6 nao iniciada.

### Regra de atualizacao

Este bloco deve ser atualizado sempre que uma fase for concluida. Cada fase deve registrar:

- status: `nao_iniciada`, `em_andamento`, `bloqueada` ou `concluida`;
- data da ultima atualizacao;
- percentual aproximado;
- arquivos, migrations e testes entregues;
- pendencias ou riscos restantes.

---

## 1. Objetivo

Implementar a emissao de boletos bancarios vinculados as parcelas de contas a receber, com emissao automatica apos a confirmacao da fatura, processamento assincrono, suporte a provedores substituiveis e baixa automatica quando o pagamento for identificado.

O primeiro provider sera a IntegraBancos, mas a arquitetura deve permitir futura troca por um banco especifico ou por outro agregador sem alterar o dominio financeiro.

---

## 2. Premissas Funcionais Confirmadas

- A emissao sera feita somente quando a forma de pagamento da fatura for boleto.
- Sera emitido um boleto por parcela.
- A emissao automatica ocorrera apos a confirmacao da fatura e a criacao das parcelas.
- O usuario podera desabilitar a emissao automatica na confirmacao da fatura.
- A decisao de emissao automatica sera persistida na fatura.
- A decisao sera copiada para cada parcela como snapshot operacional.
- A conta bancaria utilizada pelo boleto sera definida na parcela.
- O campo canonico sera `account_receivable_installments.financial_account_id`.
- `financial_account_id` referenciara `financial_accounts.id`.
- O provider nao sera escolhido pelo usuario comum.
- O provider sera resolvido pela configuracao administrativa da conta bancaria.
- Pagamentos parciais serao permitidos.
- O valor recebido em cada evento da IntegraBancos e incremental.
- Cada evento incremental de pagamento gerara um recebimento separado.
- Tarifas bancarias ficarao fora da primeira etapa.
- Pagamentos acima do saldo ficarao fora da primeira etapa.
- Estornos e devolucoes financeiras ficarao fora da primeira etapa.
- O status externo `7` sera armazenado, mas nao gerara estorno automatico na primeira etapa.
- O cancelamento automatico do boleto sera configuravel nas preferencias da empresa.
- Alteracoes de valor ou vencimento nao serao bloqueadas pelo sistema.
- O sistema armazenara e disponibilizara o link do PDF, sem baixar o arquivo na primeira etapa.

---

## 3. Situacao Atual do Projeto

### Fluxo financeiro existente

O dominio atual possui a seguinte estrutura:

```text
Invoice
  -> AccountReceivable
      -> AccountReceivableInstallment
          -> AccountReceivableInstallmentPayment
```

Pontos relevantes:

- `App\Models\Invoice` possui relacao com contas a receber.
- `App\Models\AccountReceivable` possui relacao com parcelas e pagamentos.
- `App\Models\AccountReceivableInstallment` possui os valores, vencimento, saldo e status da parcela.
- `App\Models\AccountReceivableInstallmentPayment` representa cada recebimento.
- `AccountReceivableService::registerInstallmentPayment()` ja recalcula a parcela e a conta a receber.
- O mesmo fluxo pode criar o movimento de caixa por meio do `CashMovementService`.
- `PaymentMethod::BANK_SLIP` ja existe.

### Ponto de integracao da confirmacao

`InvoiceService::confirm()` executa a confirmacao dentro de uma transacao. O job de emissao nao deve chamar a API externa dentro dessa transacao.

O disparo deve ocorrer somente depois do commit, preferencialmente por evento ou job com `afterCommit`.

### Lacuna de conta bancaria

Atualmente `AccountReceivableInstallment` possui `bank_account_id`, mas nao existe um model `BankAccount` no projeto. A primeira etapa deve migrar esse conceito para:

```text
account_receivable_installments.financial_account_id
```

A coluna deve possuir foreign key para `financial_accounts.id`, relacao Eloquent `financialAccount()` e validacao de pertencimento a mesma empresa.

Nao sera criado `financial_account_id` em `Invoice` nem em `AccountReceivable` para esta funcionalidade. A conta usada pelo boleto sera exclusivamente a conta definida na parcela.

---

## 4. Direito de Emissao por Empresa

O direito de emitir boletos deve ser separado da configuracao de emissao automatica.

### Direito comercial

Criar uma estrutura de entitlement ou feature por empresa, por exemplo:

```text
company_entitlements
- id
- company_id
- feature
- enabled
- starts_at nullable
- ends_at nullable
- metadata nullable
- timestamps
```

Valor inicial da feature:

```text
bank_slip_issuance
```

Esse cadastro deve ser controlado por super admin ou pelo modulo responsavel por planos e contratos. Nao deve ser uma opcao livre do usuario comum em `CompanyPreference`.

### Configuracao operacional

As preferencias da empresa podem guardar:

```text
bank_slip_auto_issuance_default
cancel_bank_slips_when_invoice_cancelled
```

Essas preferencias definem o comportamento padrao, mas nao concedem o direito comercial.

### Servico de elegibilidade

Criar um servico central, por exemplo:

```text
BankSlipEligibilityService
```

Ele deve validar:

- empresa com entitlement ativo;
- forma de pagamento igual a boleto;
- parcela ainda nao quitada;
- parcela com `financial_account_id` valido;
- conta financeira pertencente a empresa;
- conexao bancaria ativa para a conta;
- provider habilitado;
- dados minimos do pagador disponiveis.

Nenhuma tela ou job deve duplicar essas regras.

---

## 5. Persistencia da Decisao de Emissao

A decisao deve existir em dois niveis com responsabilidades diferentes.

### Fatura

Adicionar em `invoices`:

```text
auto_bank_slip_issuance nullable boolean
```

Esse campo representa a escolha feita pelo usuario durante a confirmacao da fatura.

Ele serve para:

- auditoria;
- exibicao da configuracao escolhida;
- propagacao para parcelas criadas posteriormente;
- evitar que uma alteracao da preferencia da empresa mude uma fatura ja confirmada.

### Parcela

Adicionar em `account_receivable_installments`:

```text
auto_bank_slip_issuance nullable boolean
```

Esse campo representa a decisao efetiva da parcela e sera lido pelo job de emissao.

Regras:

- `null` para parcelas que nao usam boleto;
- `true` para emissao automatica habilitada;
- `false` para emissao automatica desabilitada, permitindo eventual emissao manual se a empresa possuir direito.

Ao gerar as parcelas, copiar o valor da fatura para cada parcela.

O job nao deve depender da leitura da fatura para decidir se emite o boleto. Ele deve usar o snapshot da parcela.

---

## 6. Cadastro de Bancos e Providers

O provider deve ser uma decisao administrativa, nao uma escolha operacional do usuario comum.

### Cadastro global de bancos

Criar ou avaliar um cadastro global, por exemplo:

```text
banks
- id
- code
- name
- is_active
- metadata nullable
- timestamps
```

Esse cadastro deve ser mantido por super admin.

### Cadastro de providers

Criar um catalogo tecnico, por exemplo:

```text
billing_providers
- id
- key
- name
- adapter_class
- is_active
- capabilities json
- timestamps
```

Provider inicial:

```text
integrabancos
```

### Conexao da conta bancaria

Criar uma entidade por empresa e conta financeira, por exemplo:

```text
bank_account_connections
- id
- company_id
- financial_account_id
- bank_id
- billing_provider_id
- environment
- credentials json criptografado
- settings json
- status
- last_success_at nullable
- last_error_at nullable
- last_error nullable
- timestamps
```

As credenciais devem ser criptografadas. A conexao deve ser configurada por super admin ou usuario administrativo autorizado.

O usuario comum visualiza apenas a conta financeira e seu status operacional. Nao deve escolher diretamente `billing_provider_id`.

### Resolucao do provider

Ao processar uma parcela:

```text
parcela.financial_account_id
  -> FinancialAccount
  -> BankAccountConnection
  -> BillingProvider
  -> Adapter
```

Se houver mais de uma conexao aplicavel, a selecao deve ser definida administrativamente por prioridade ou por uma conexao marcada como principal.

O boleto deve armazenar a conexao utilizada na emissao para preservar o historico mesmo que a conta troque de provider no futuro.

---

## 7. Modelagem dos Boletos

Criar uma entidade, por exemplo `BankSlip`, vinculada obrigatoriamente a uma parcela.

### Campos sugeridos

```text
bank_slips
- id
- company_id
- account_receivable_installment_id
- bank_account_connection_id
- status
- provider_identification
- provider_charge_id nullable
- amount
- due_date
- pdf_url nullable
- digitable_line nullable
- barcode nullable
- provider_status_code nullable
- provider_status_message nullable
- registered_at nullable
- paid_at nullable
- cancel_requested_at nullable
- canceled_at nullable
- last_synchronized_at nullable
- last_error nullable
- provider_payload json nullable
- timestamps
```

O boleto nao deve ser identificado apenas pelo numero da fatura. A `provider_identification` deve ser estavel, unica e adequada ao limite aceito pelo provider.

### Status local sugerido

```text
pending_registration
registered
registration_failed
update_pending
cancel_pending
canceled
partially_paid
paid
payment_returned
needs_review
```

O status local nao deve reutilizar o status da conta a receber.

### Um boleto por parcela

Deve existir no maximo um boleto ativo por parcela. Boletos cancelados ou substituidos devem permanecer no historico.

Essa regra deve ser protegida por transacao, lock e validacao de dominio.

---

## 8. Contrato de Provider

Criar um contrato independente do provider, por exemplo:

```php
interface BankSlipProviderInterface
{
    public function register(BankSlipRegistrationInput $input): BankSlipProviderResult;

    public function update(BankSlipUpdateInput $input): BankSlipProviderResult;

    public function cancel(BankSlipCancellationInput $input): BankSlipProviderResult;

    public function query(BankSlipQueryInput $input): BankSlipProviderResult;

    public function validateWebhook(array $payload, string $rawBody): bool;

    public function normalizeWebhook(array $payload): BankSlipWebhookEvent;
}
```

Implementacao inicial:

```text
IntegraBancosBankSlipProvider
```

Implementacoes futuras podem ser:

```text
BancoDoBrasilBankSlipProvider
SicoobBankSlipProvider
OutroBankSlipProvider
```

O restante da aplicacao deve depender de DTOs internos e nao dos campos especificos da IntegraBancos.

---

## 9. Contrato Conhecido da IntegraBancos

Informacoes identificadas na documentacao atual:

O pacote oficial `integrabancos/sdk-php` foi adicionado na versao `1.0.1`. O adapter utiliza o SDK para autenticacao OAuth, geracao, alteracao, cancelamento e consulta de cobrancas. O SDK tambem aplica a criptografia AES-256-GCM e o hash SHA-256 exigidos pela API nos payloads de cobranca.

A validacao da assinatura do webhook continua pendente para producao: a documentacao informa o campo `assinatura` e a chave de encriptacao do contrato, mas nao publica o algoritmo de derivacao. O codigo aceita comparacao literal somente com valor configurado em ambiente de debug, nunca como validacao de producao.

### Autenticacao

- OAuth2 para obtencao e renovacao do token;
- header `Authorization: Bearer {token}`;
- header `x-api-key` para identificacao do emitente;
- credenciais de integracao armazenadas de forma criptografada.

### Operacoes

```text
POST   /api/v1/charge
PUT    /api/v1/charge
DELETE /api/v1/charge
GET    /api/v1/charge/{identificacao}
GET    /api/v1/charge/{identificacao}/{evento_id}
POST   /api/v1/oauth/token
```

### Eventos

O exemplo real de webhook confirmou que o provider nao envia `evento_id`. A normalizacao usa um fingerprint deterministico do payload sem `assinatura`, mantendo a idempotencia mesmo quando a assinatura muda. O campo `cnpj_cpf` e validado contra o documento da empresa da conexao. Os campos `pdf`, `linha_digitavel`, `qrcode` e `pix_copia_cola` ficam preservados no payload do boleto; PDF e linha digitavel tambem sao promovidos aos campos locais quando o evento de geracao e processado.

Eventos identificados:

```text
GERACAO
ALTERACAO
CANCELAMENTO
ATUALIZACAO
```

Status identificados:

```text
2 - Gerado
3 - Pago/Liquidado
4 - Cancelado por acao feita dentro do banco
5 - Evento processado
7 - Devolvido ou pagamento estornado ao pagador
```

O status `7` deve ser armazenado como `payment_returned` ou `needs_review`, sem estorno financeiro automatico na primeira etapa.

### Retorno da cobranca

Campos relevantes identificados:

- `identificacao`;
- `valor`;
- `status.codigo`;
- `status.mensagem`;
- `detalhes`;
- `pdf`;
- `linha_digitavel`;
- `qrcode`;
- `pix_copia_cola`.

O escopo inicial utilizara principalmente `pdf`, mas os demais identificadores devem ser persistidos quando retornados.

Campos de juros, multa, desconto, tarifa e regras completas de alteracao ainda devem ser confirmados antes da implementacao definitiva do payload.

---

## 10. Fluxo de Confirmacao e Emissao

### Confirmacao da fatura

Na acao de confirmacao:

1. Validar a forma de pagamento.
2. Exibir a opcao de emissao somente para boleto.
3. Carregar a preferencia padrao da empresa.
4. Permitir que o usuario desabilite a emissao para aquela fatura.
5. Persistir `Invoice.auto_bank_slip_issuance`.
6. Gerar as contas a receber e parcelas.
7. Copiar `auto_bank_slip_issuance` para cada parcela.
8. Confirmar a fatura.
9. Finalizar a transacao.
10. Disparar o processo de emissao somente apos o commit.

### Orquestracao

Criar um servico, por exemplo:

```text
BankSlipIssuanceOrchestrator
```

Responsabilidades:

- selecionar parcelas elegiveis;
- resolver a conta financeira da parcela;
- resolver a conexao bancaria;
- verificar entitlement e configuracoes;
- criar registros locais de boleto;
- disparar jobs por parcela;
- evitar duplicidade de emissao.

### Jobs

Jobs sugeridos:

```text
RegisterBankSlipJob
ProcessBankSlipWebhookJob
SyncBankSlipStatusJob
UpdateBankSlipJob
CancelBankSlipJob
```

Cada job deve utilizar:

- `ShouldBeUnique` quando aplicavel;
- lock por parcela ou boleto;
- backoff progressivo;
- `afterCommit`;
- tratamento separado para erro transitorio, autenticacao, validacao e rejeicao funcional.

Erro de API nao deve desfazer a confirmacao da fatura.

---

## 11. Webhook e Pagamentos

Criar endpoint publico separado do webhook fiscal, por exemplo:

```text
POST /webhook/bank-slips
```

### Recepcao

1. Ler o corpo bruto da requisicao.
2. Validar assinatura conforme o contrato da IntegraBancos.
3. Validar evento de teste quando aplicavel.
4. Persistir o evento recebido.
5. Garantir unicidade por provider e identificador do evento.
6. Retornar o codigo HTTP esperado pelo provider.
7. Disparar processamento assincrono.

### Tabela de eventos

Criar `bank_slip_events` com:

```text
- company_id nullable
- bank_slip_id nullable
- billing_provider_id
- provider_event_id
- event_type
- provider_status_code nullable
- amount nullable
- payload
- received_at
- processed_at nullable
- failed_at nullable
- error nullable
```

Restricao recomendada:

```text
unique(billing_provider_id, provider_event_id)
```

### Baixa incremental

Como o valor do evento e incremental, cada evento de pagamento gera um pagamento interno separado.

Exemplo:

```text
Parcela: R$ 1.000,00
Evento 1: R$ 400,00
Evento 2: R$ 600,00
```

O processamento deve:

1. localizar o boleto;
2. validar empresa e parcela;
3. verificar se o evento ja foi processado;
4. registrar o pagamento com o valor incremental;
5. informar a conta financeira da parcela;
6. utilizar `AccountReceivableService::registerInstallmentPayment()`;
7. recalcular parcela e conta a receber;
8. criar ou sincronizar movimento de caixa;
9. atualizar o status local do boleto.

O evento nao deve atualizar diretamente `paid`, `paid_amount` ou `status` da conta a receber.

---

## 12. Cancelamento

Adicionar preferencia da empresa:

```text
cancel_bank_slips_when_invoice_cancelled
```

Quando habilitada:

1. A fatura pode ser cancelada normalmente.
2. Boletos ativos relacionados ficam `cancel_pending`.
3. Um `CancelBankSlipJob` e despachado apos o commit.
4. O provider e chamado usando a conexao original do boleto.
5. O boleto passa para `canceled` apos confirmacao.
6. Falhas ficam disponiveis para retry e acompanhamento.

O cancelamento da fatura nao deve depender de uma resposta sincrona da API bancaria.

---

## 13. Alteracoes de Valor e Vencimento

Alteracoes nao serao bloqueadas pelo sistema.

Quando uma parcela com boleto ativo for alterada:

- registrar a alteracao local;
- marcar o boleto como `update_pending` ou `needs_review`;
- tentar utilizar `PUT /api/v1/charge` quando o provider suportar;
- registrar a resposta externa;
- informar eventual divergencia entre parcela e boleto.

O sistema nao deve apresentar o boleto como sincronizado se o valor ou vencimento externo estiver diferente.

---

## 14. Fora do Escopo Inicial

- Tarifas bancarias e sua apropriacao contabil.
- Pagamento acima do saldo.
- Estorno automatico para status `7`.
- Regras avancadas de juros, multa e desconto.
- Download e armazenamento local do PDF.
- Envio automatico do boleto por email.
- Integracao direta com banco especifico.
- Conciliacao avancada com OFX por nosso numero.
- Reemissao com versionamento completo.

Esses itens devem ser preservados na modelagem para permitir evolucao posterior, mas nao devem bloquear a primeira entrega.

---

## 15. Interface do Usuario

### Confirmacao da fatura

Adicionar:

- checkbox de emissao automatica;
- descricao da elegibilidade da empresa;
- alerta quando faltar conexao bancaria;
- mensagem de que a emissao ocorrera em segundo plano.

### Fatura

Exibir relacao de boletos por parcela contendo:

- parcela;
- vencimento;
- valor;
- status do boleto;
- status da conta a receber;
- link do PDF;
- data da ultima sincronizacao;
- erro mais recente, quando houver.

Acoes futuras ou iniciais conforme necessidade:

- abrir boleto;
- emitir manualmente;
- consultar status;
- solicitar cancelamento;
- reprocessar falha.

### Configuracao administrativa

O super admin deve controlar:

- cadastro de bancos;
- providers compatíveis;
- conexoes bancarias;
- credenciais;
- ambiente;
- habilitacao da conta;
- direito de emissao por empresa.

O usuario comum nao deve informar credenciais nem escolher o adapter.

---

## 16. Fases de Implementacao

### Fase 1 - Modelagem e permissoes

- [x] Criar migration para `account_receivable_installments.financial_account_id`.
- [x] Migrar dados atuais de `bank_account_id`, se aplicavel.
- [x] Criar foreign key para `financial_accounts`.
- [x] Criar relacao `financialAccount()` na parcela.
- [x] Criar campo de emissao automatica na fatura.
- [x] Criar campo snapshot de emissao automatica na parcela.
- [x] Criar estrutura de entitlement por empresa.
- [x] Adicionar preferencias de emissao e cancelamento.
- [x] Criar validacoes de empresa e conta financeira.

### Fase 2 - Bancos e providers

- [x] Criar cadastro global de bancos.
- [x] Criar catalogo de providers.
- [x] Criar conexoes bancarias por empresa e conta financeira.
- [x] Implementar armazenamento criptografado de credenciais.
- [x] Implementar resolucao administrativa do provider.
- [x] Criar contrato e DTOs do provider.
- [ ] Criar fake provider para testes.

### Fase 3 - Emissao automatica

- [x] Adicionar checkbox na confirmacao da fatura.
- [x] Persistir decisao na fatura.
- [x] Copiar decisao para as parcelas.
- [x] Criar tabela e model `BankSlip`.
- [x] Criar orquestrador de emissao.
- [x] Criar `RegisterBankSlipJob`.
- [x] Implementar adapter da IntegraBancos usando o SDK oficial.
- [x] Persistir retorno e link do PDF.
- [x] Exibir boletos por parcela.

### Fase 4 - Webhook e baixa

- [x] Criar endpoint de webhook.
- [x] Criar inbox de eventos.
- [ ] Implementar validacao de assinatura real em producao.
- [x] Implementar idempotencia por provider e evento.
- [x] Criar `ProcessBankSlipWebhookJob`.
- [x] Processar eventos incrementais.
- [x] Registrar pagamentos pelo servico existente.
- [x] Atualizar status do boleto.
- [x] Criar testes de eventos duplicados entre conexoes e pagamentos parciais.

### Fase 5 - Cancelamento e sincronizacao

- [x] Implementar preferencia de cancelamento.
- [x] Criar `CancelBankSlipJob`.
- [ ] Criar consulta manual de status.
- [ ] Criar sincronizacao periodica de boletos pendentes.
- [ ] Implementar atualizacao de valor e vencimento.
- [x] Registrar divergencias como `needs_review`.

### Fase 6 - Homologacao

- [ ] Validar credenciais em homologacao.
- [ ] Validar criacao de boleto.
- [ ] Validar alteracao.
- [ ] Validar cancelamento.
- [ ] Validar webhook de teste.
- [ ] Validar pagamento integral.
- [ ] Validar pagamentos parciais incrementais.
- [ ] Validar falha e retry.
- [ ] Validar troca de provider sem alterar boletos antigos.
- [ ] Liberar gradualmente por empresa.

---

## 17. Testes Obrigatorios

### Elegibilidade

- empresa sem direito de emissao;
- empresa com direito, mas sem conexao;
- forma de pagamento diferente de boleto;
- parcela sem conta financeira;
- conta financeira de outra empresa;
- conexao desativada;
- emissao automatica desabilitada.

### Emissao

- um boleto por parcela;
- duas parcelas gerando dois boletos;
- confirmacao nao dispara provider dentro da transacao;
- job duplicado nao gera segundo boleto;
- timeout apos criacao nao gera duplicidade;
- retorno de erro permite retry;
- link do PDF persistido.

### Webhook

- evento valido;
- evento duplicado;
- assinatura invalida;
- evento de teste;
- evento para boleto inexistente;
- evento de outra empresa;
- eventos fora de ordem;
- status `3` com pagamento integral;
- varios eventos incrementais;
- status `7` armazenado sem estorno automatico.

### Financeiro

- baixa integral atualiza parcela;
- baixa parcial mantem saldo;
- dois pagamentos parciais atualizam o saldo corretamente;
- movimento de caixa criado uma unica vez por pagamento;
- nenhuma baixa duplicada por webhook repetido;
- dados da parcela e da conta a receber permanecem no mesmo tenant.

### Cancelamento

- preferencia desabilitada nao cancela boleto;
- preferencia habilitada dispara cancelamento;
- falha de cancelamento permite retry;
- cancelamento da fatura nao e desfeito por falha da API.

---

## 18. Criterios de Aceite

A primeira entrega sera considerada concluida quando:

1. somente empresas autorizadas puderem emitir boletos;
2. somente faturas com forma de pagamento boleto gerarem boletos;
3. o usuario puder desabilitar a emissao automatica;
4. a decisao ficar registrada na fatura e nas parcelas;
5. cada parcela puder usar seu `financial_account_id`;
6. o provider for resolvido sem escolha pelo usuario comum;
7. houver um boleto por parcela;
8. a emissao ocorrer de forma assincrona apos o commit;
9. o link do PDF for disponibilizado;
10. webhooks duplicados nao gerarem pagamentos duplicados;
11. pagamentos incrementais atualizarem corretamente o saldo;
12. o fluxo existente de contas a receber for reutilizado;
13. o cancelamento automatico respeitar a preferencia da empresa;
14. a troca de provider nao alterar boletos historicos;
15. tenant isolation for garantido em todos os fluxos.

---

## 19. Riscos e Mitigacoes

### Duplicidade de boleto

Mitigacao:

- identificador local estavel;
- lock por parcela;
- job unico;
- consulta antes de repetir chamada apos timeout.

### Duplicidade de pagamento

Mitigacao:

- inbox de eventos;
- unique por provider e evento;
- lock na parcela;
- associacao do evento ao pagamento interno.

### Provider trocado

Mitigacao:

- guardar a conexao utilizada no boleto;
- nao resolver provider novamente para boletos antigos;
- manter adapters compativeis com historico.

### API indisponivel

Mitigacao:

- emissao em job;
- retry com backoff;
- status local de falha;
- consulta posterior por identificacao.

### Conta financeira incorreta

Mitigacao:

- conta obrigatoria na parcela;
- validacao de tenant;
- snapshot da conta no boleto;
- provider resolvido pela conexao administrativa.

---

## 20. Resultado Esperado

O fluxo final devera ser:

```text
Confirmacao da fatura
        |
        v
Contas a receber e parcelas criadas
        |
        v
Decisao de emissao copiada para as parcelas
        |
        v
Job assincrono por parcela
        |
        v
Conta financeira da parcela
        |
        v
Conexao bancaria administrativa
        |
        v
Provider resolvido
        |
        v
Boleto emitido e link persistido
        |
        v
Webhook incremental de pagamento
        |
        v
AccountReceivableInstallmentPayment
        |
        v
Parcela, conta a receber, caixa e auditoria atualizados
```

A arquitetura permanecera independente da IntegraBancos e preparada para providers especificos por banco.
