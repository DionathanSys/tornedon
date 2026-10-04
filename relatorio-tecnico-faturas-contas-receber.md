# Levantamento tecnico: faturas e contas a receber

**Data da analise:** 2026-10-03  
**Escopo:** implementacao presente no checkout, incluindo as alteracoes PIX nao commitadas existentes no workspace.  
**Metodologia:** leitura estatica de modelos, migrations, services, actions, jobs, paineis Filament, rotas, policies e testes. Nao houve alteracao no codigo nem teste de penetracao.

## Resumo executivo

O dominio esta dividido entre `Invoice`, o agrupador `AccountReceivable`, suas `AccountReceivableInstallment` e os recebimentos `AccountReceivableInstallmentPayment`. A baixa financeira e integrada a `CashMovement`; boleto e PIX usam jobs, locks de cache e providers externos.

Os maiores riscos encontrados sao:

- recebimento manual acima do saldo e concorrencia sem lock da parcela;
- exclusao de conta parcialmente recebida sem desfazer os movimentos de caixa;
- falha ao retornar uma fatura para pendente depois que boleto ou PIX foi emitido;
- validadores que aceitam IDs de cliente, fatura e documento fiscal de outra empresa;
- ausencia de rotina que atualize contas vencidas sem uma nova operacao;
- autorizacao de dominio menos explicita para contas a receber do que para faturas.

Existe uma base razoavel de transacoes, auditoria, escopo de tenant no Filament, idempotencia de eventos de boleto e validacao de assinatura do webhook. Esses controles nao cobrem todos os caminhos de escrita nem substituem a validacao de saldo e de pertencimento no dominio.

## Como o recurso e usado

### Superficies de acesso

- `/admin` usa `AdminPanelProvider`, tenant `Company` e descobre os clusters financeiros. Referencia: `app/Providers/Filament/AdminPanelProvider.php:27-49`.
- `/shop` registra explicitamente `AccountReceivableResource`, caixa e contas a pagar. Referencia: `app/Providers/Filament/ShopPanelProvider.php:33-56`.
- `/mobile` descobre somente `app/Filament/Mobile/Resources`; nao foi encontrado ali um recurso de faturas ou contas a receber. Referencia: `app/Providers/Filament/MobilePanelProvider.php:25-48`.
- O recurso de contas a receber do Shop aplica explicitamente `where('company_id', Filament::getTenant()->id)`: `app/Filament/Shop/Resources/AccountReceivables/AccountReceivableResource.php:56-60`.
- O recurso financeiro do Admin nao repete esse filtro, mas a tenancy do Filament aplica o escopo por `BelongsToTenant`. Isso deve ser tratado como controle de infraestrutura, nao como unica barreira do servico.

### Fatura

1. Faturas podem ser criadas a partir do painel financeiro e tambem por acoes de vendas, producao, requisicoes e ordens de servico. O create fixa `company_id` no tenant em `app/Filament/Clusters/Financial/Resources/Invoices/Pages/CreateInvoice.php:18-23`.
2. O total da fatura e calculado a partir dos itens de requisicoes e ordens de servico em `app/Models/Invoice.php:159-337`.
3. A confirmacao valida estado e itens, grava forma/condicao de pagamento, cria documento fiscal, cria a conta a receber, pode registrar recebimento imediato e marca a fatura como confirmada. Referencia: `app/Services/Invoice/Actions/ConfirmInvoiceAction.php:41-194`.
4. A confirmacao pode enfileirar emissao automatica de boleto ou PIX apos o commit: `app/Services/Invoice/Actions/ConfirmInvoiceAction.php:74-94`.
5. Uma fatura confirmada sem contas vinculadas pode usar a acao "Gerar contas". Referencias: `app/Filament/Clusters/Financial/Resources/Invoices/Pages/Actions/GenerateAccountReceivablesAction.php:27-103` e `app/Services/Invoice/Actions/GenerateInvoiceAccountReceivablesAction.php:32-129`.
6. A fatura pode voltar a pendente, desde que nao tenha recebimentos nem comunicacao fiscal iniciada. A acao remove as contas e documentos antes de atualizar o estado: `app/Services/Invoice/Actions/ReturnInvoiceToPendingAction.php:22-94`.

### Conta a receber e baixa

- O cadastro manual aceita parceiro cadastrado ou contraparte avulsa, gera de 1 a 24 parcelas, aplica classificacao financeira e sincroniza o cabecalho: `app/Services/AccountReceivable/AccountReceivableService.php:43-121`.
- A baixa e feita na parcela por acao Filament, com data, valor, juros, multa, desconto e conta financeira: `app/Filament/Clusters/Financial/Resources/AccountReceivables/RelationManagers/Actions/RegisterInstallmentPaymentAction.php:24-119`.
- Ao baixar com conta financeira, `CashMovementService` cria ou atualiza um movimento de entrada. Ao editar ou excluir a baixa, o movimento e sincronizado ou removido/estornado: `app/Services/Financial/CashMovementService.php:43-71` e `app/Services/Financial/CashMovementService.php:671-755`.
- O status do agrupador e derivado das parcelas. O status `overdue` depende de uma sincronizacao ocorrer: `app/Services/AccountReceivable/Actions/Installment/SyncAccountReceivableStatusFromInstallmentsAction.php:18-48`.

### Boleto e PIX

- Boleto e PIX sao preparados por parcela, verificando forma de pagamento, saldo, entitlement, conta financeira, conexao bancaria e dados do pagador. Referencias: `app/Services/Financial/Banking/BankSlipEligibilityService.php:33-99` e `app/Services/Financial/Pix/PixEligibilityService.php:35-106`.
- O registro no provider ocorre em jobs `banking` com `ShouldBeUnique` e lock de cache. Referencias: `app/Jobs/RegisterBankSlipJob.php:17-109` e `app/Jobs/RegisterPixChargeJob.php:18-111`.
- O webhook de boleto e publico por necessidade de integracao, mas valida CNPJ, assinatura HMAC, timestamp de cinco minutos e deduplica evento. Referencias: `routes/web.php:33-35`, `app/Services/Financial/Banking/Providers/IntegraBancosProvider.php:61-107` e `app/Services/Financial/Banking/BankSlipWebhookService.php:19-57`.
- O webhook de liquidacao aceita pagamentos incrementais e rejeita valor acima do saldo antes da baixa: `app/Jobs/ProcessBankSlipWebhookJob.php:117-171`.
- O PIX e consultado a cada cinco minutos por `pix:dispatch-queries`: `routes/console.php:29-32` e `app/Console/Commands/DispatchPixChargeQueriesCommand.php:16-40`.

## Controles positivos observados

- Transacoes envolvendo criacao, confirmacao, baixa, edicao e exclusao de registros financeiros.
- Auditoria para criacao/alteracao/exclusao de faturas e contas, alem de registro de baixa.
- Escopo explicito por empresa em opcoes de contas financeiras, categorias, conexoes bancarias, boleto e PIX.
- `company_id` da conta e das parcelas e derivado do tenant/agrupador nos fluxos Filament.
- Fatura impede exclusao apos documento fiscal ou conta a receber; acoes de retorno verificam recebimentos e estados fiscais.
- Eventos de boleto possuem chave unica por provider/evento; pagamentos de boleto possuem chave unica por evento, reduzindo duplicidade.
- Webhook de boleto nao confia somente no endpoint publico: a assinatura e vinculada ao CNPJ e a chave da conexao.
- Testes cobrem integracao financeira, movimento de caixa, regras de cartao, geracao de parcelas, webhook de boleto e caminho feliz de PIX.

## Achados priorizados

### Alto

#### AR-01 - Recebimento manual pode exceder o saldo da parcela

**Evidencia:** `AccountReceivableInstallmentValidator::paymentRules()` exige apenas `amount > 0` e nao compara o valor com o saldo: `app/Services/AccountReceivable/Validators/AccountReceivableInstallmentValidator.php:88-104`. A action cria o pagamento e recalcula o saldo com `max(..., 0)`, permitindo que o recebido acumulado ultrapasse o devido: `app/Services/AccountReceivable/Actions/Installment/RegisterAccountReceivableInstallmentPaymentAction.php:41-81` e `:147-168`. A tela ainda sugere `due_amount`, e nao o saldo atual: `app/Filament/Clusters/Financial/Resources/AccountReceivables/RelationManagers/Actions/RegisterInstallmentPaymentAction.php:45-49`.

**Impacto:** sobre-recebimento, `paid_amount` maior que o valor devido, classificacao indevida como recebido e movimento de caixa inflado. Juros/multa/desconto tambem aceitam valores positivos sem limite de coerencia, inclusive desconto maior que o total recalculado.

**Recomendacao:** dentro de uma transacao, bloquear a parcela com `lockForUpdate()`, recalcular o saldo com os pagamentos persistidos e rejeitar valor que exceda o saldo, salvo regra explicita de overpayment. Validar tambem desconto, juros e multa contra limites de negocio. Cobrir cadastro, edicao, boleto e PIX.

#### AR-02 - Baixas concorrentes podem gerar duplicidade

**Evidencia:** `registerInstallmentPayment()` abre transacao, mas nao bloqueia a parcela antes de criar o pagamento: `app/Services/AccountReceivable/AccountReceivableService.php:239-341`. O calculo le os pagamentos atuais e atualiza a parcela sem lock: `app/Services/AccountReceivable/Actions/Installment/RegisterAccountReceivableInstallmentPaymentAction.php:147-168`. O job PIX bloqueia a cobranca PIX, mas nao a parcela antes de chamar o mesmo servico: `app/Jobs/QueryPixChargeJob.php:114-165`.

**Impacto:** duas requisicoes podem ler o mesmo saldo e registrar pagamentos que, somados, ultrapassam a parcela. Dois charges diferentes da mesma parcela tambem nao sao serializados pelo lock individual de cada charge.

**Recomendacao:** usar uma unica rotina de baixa que bloqueie a parcela e valide saldo dentro da mesma transacao; garantir idempotencia por origem (`pix_charge_id`, `bank_slip_event_id` ou chave manual) e testar concorrencia em banco compativel com producao.

#### AR-03 - Exclusao de conta parcialmente recebida pode deixar caixa orfao

**Evidencia:** `DeleteAccountReceivableAction` impede somente quando o cabecalho esta com `paid=true`: `app/Services/AccountReceivable/Actions/DeleteAccountReceivableAction.php:67-82`. Uma conta parcialmente recebida continua com `paid=false`. O servico de exclusao remove o agrupador sem chamar a rotina de reversao dos pagamentos: `app/Services/AccountReceivable/AccountReceivableService.php:695-741`. Parcelas e pagamentos possuem cascata de exclusao, enquanto o movimento usa apenas `origin_type/origin_id`, sem FK para o pagamento: `database/migrations/2026_04_06_000001_create_account_receivable_installments_table.php:14-18`, `database/migrations/2026_04_06_000002_create_account_receivable_installment_payments_table.php:13-18` e `database/migrations/2026_04_08_000003_create_cash_movements_table.php:23-49`.

**Impacto:** o contas a receber desaparece, mas o movimento de entrada pode continuar no caixa, quebrando saldo, conciliacao e auditoria.

**Recomendacao:** bloquear exclusao se existir qualquer pagamento ou boleto/PIX relacionado; exigir estorno/exclusao segura de cada pagamento antes da exclusao, ou executar essa reversao atomica no servico de exclusao. Adicionar teste para conta parcialmente recebida com movimento conciliado e nao conciliado.

#### AR-04 - Retorno de fatura para pendente conflita com cobrancas emitidas

**Evidencia:** o retorno exclui as contas a receber, mas valida apenas a existencia de pagamentos e estados fiscais: `app/Services/Invoice/Actions/ReturnInvoiceToPendingAction.php:24-62` e `:125-132`. Boleto e PIX referenciam a parcela com `restrictOnDelete`, impedindo a cascata: `database/migrations/2026_09_27_000001_create_bank_slip_domain_tables.php:198-229` e `database/migrations/2026_10_03_000001_create_pix_charge_domain.php:24-51`. O observer agenda cancelamento de boleto quando a fatura e cancelada, mas nao trata o retorno para pendente nem o cancelamento de PIX: `app/Observers/InvoiceObserver.php:14-23`.

**Impacto:** a acao pode falhar depois de uma cobranca ter sido emitida, deixando a fatura confirmada quando o operador esperava desfaze-la, ou exigindo intervencao manual no banco/provider.

**Recomendacao:** definir uma maquina de estados para cobrancas. Antes de remover a conta, cancelar/revogar boleto e PIX no provider, aguardar/registrar o resultado e somente entao remover ou manter os registros. Se a politica for preservar historico, retornar a fatura sem apagar o contas a receber e marcar as cobrancas como canceladas.

### Medio

#### AR-05 - Validacao de IDs financeiros nao garante pertencimento a empresa

**Evidencia:** o validador de conta aceita `customer_id`, `invoice_id` e `fiscal_document_id` com `exists` global: `app/Services/AccountReceivable/Validators/AccountReceivableValidator.php:70-96`. O validador de parcelas valida existencia global de `account_receivable_id` e `company_id`: `app/Services/AccountReceivable/Validators/AccountReceivableInstallmentValidator.php:44-70`. Em contraste, categoria, centro de custo e conta financeira usam validacao por `company_id`.

**Impacto:** qualquer consumidor que alcance esses services/actions com payload controlado pode associar registros de outra empresa, expondo dados ou contaminando relatorios. O fluxo Filament atual reduz a superficie, mas nao corrige a regra de dominio.

**Recomendacao:** trocar `exists` por regras com `where company_id` e validar relacoes cruzadas (`invoice.company_id`, `fiscal_document.company_id`, `account_receivable.company_id`, cliente vinculado a empresa) antes de persistir. Testar sempre com duas empresas.

#### AR-06 - Autorizacao de contas a receber depende excessivamente do contexto Filament

**Evidencia:** existe `InvoicePolicy`, mas nao foi encontrada policy equivalente para `AccountReceivable`, parcela ou recebimento. `DeleteAccountReceivableAction` nao recebe usuario nem confere `belongsToCompany`, ao contrario de `DeleteInvoiceAction`: `app/Services/AccountReceivable/Actions/DeleteAccountReceivableAction.php:18-38` e `app/Services/Invoice/Actions/DeleteInvoiceAction.php:88-105`. As acoes Filament recebem records que normalmente ja estao no tenant.

**Impacto:** uma nova rota, comando, listener ou uso de service com model carregado por ID pode permitir IDOR ou alteracao entre empresas. Trata-se de risco condicional no checkout atual, nao de acesso publico comprovado.

**Recomendacao:** centralizar autorizacao de tenant nas services/actions, exigir usuario/tenant ou um contexto de sistema explicitamente auditado e criar policies para as tres entidades financeiras.

#### AR-07 - Status vencido fica obsoleto e cancelado nao e preservado

**Evidencia:** `SyncAccountReceivableStatusFromInstallmentsAction` calcula `overdue` somente durante sua execucao: `app/Services/AccountReceivable/Actions/Installment/SyncAccountReceivableStatusFromInstallmentsAction.php:21-44`. Nao ha comando agendado de contas a receber vencidas em `routes/console.php`; os agendamentos financeiros presentes sao contas a pagar, PIX e tarefas fiscais. A sincronizacao produz apenas recebido, parcialmente recebido, vencido ou pendente, apesar do enum conter `cancelled`: `app/Enum/AccountReceivable/Status.php:5-11` e `app/Services/AccountReceivable/Actions/Installment/SyncAccountReceivableStatusFromInstallmentsAction.php:28-33`.

**Impacto:** uma parcela pode continuar pendente depois do vencimento e uma sincronizacao posterior pode substituir cancelado por pendente/recebido.

**Recomendacao:** criar comando idempotente para atualizar vencidos e preservar estados terminais; definir se cancelamento ocorre no cabecalho, parcela e cobranca externa.

#### AR-08 - Geracao isolada de contas nao configura cobranca bancaria

**Evidencia:** a acao "Gerar contas" solicita forma, condicao, perfil de cartao e categoria, mas nao solicita conta financeira nem flags de emissao: `app/Filament/Clusters/Financial/Resources/Invoices/Pages/Actions/GenerateAccountReceivablesAction.php:35-77`. A action de dominio tambem nao encaminha `financial_account_id`, `auto_bank_slip_issuance` ou `auto_pix_charge_issuance` para `AccountReceivableService::create()`: `app/Services/Invoice/Actions/GenerateInvoiceAccountReceivablesAction.php:63-90`.

**Impacto:** uma fatura confirmada que use o fluxo isolado gera parcelas sem conta financeira e sem emissao automatica. O operador precisa editar a parcela e emitir manualmente, e pode interpretar a geracao como equivalente a confirmacao.

**Recomendacao:** alinhar os dois fluxos: reutilizar um DTO de condicoes financeiras, exigir conta quando boleto/PIX forem escolhidos e persistir as flags de emissao somente depois de validar entitlement/conexao.

#### AR-09 - Mass assignment global enfraquece as barreiras de dominio

**Evidencia:** `Model::unguard()` e executado globalmente no bootstrap: `app/Providers/AppServiceProvider.php:82-85`. Os models ainda declaram `fillable`, mas essa protecao deixa de existir. Os validadores de fatura aceitam flags de estado (`pending`, `confirmed`, `canceled`) e status vindos do payload: `app/Services/Invoice/Validators/InvoiceValidator.php:15-24` e `:92-108`.

**Impacto:** um novo caminho que reutilize `Model::create()` com dados de request pode gravar `company_id`, estado ou metadados que deveriam ser definidos por servico. O risco atual e maior para manutencao futura e integracoes do que para uma rota publica identificada.

**Recomendacao:** remover o `unguard` global ou restringi-lo a seeds; usar DTOs/allowlists por caso de uso; impedir que estados de ciclo de vida sejam aceitos como campos livres em create/update.

#### AR-10 - Logs e payloads persistidos podem conter dados financeiros e pessoais

**Evidencia:** o registro de pagamento grava o payload completo e snapshots monetarios em logs: `app/Services/AccountReceivable/Actions/Installment/RegisterAccountReceivableInstallmentPaymentAction.php:22-46` e `:107-141`. Services de fatura/conta tambem registram `data` completo em erros, e boleto/PIX armazenam `provider_payload` bruto, que pode incluir CPF, identificacao de cobranca, QR Code ou URL de PDF.

**Impacto:** aumento da superficie de vazamento em logs, ferramentas de observabilidade, backups e tabelas de auditoria; possivel exposicao de PII e artefatos de cobranca.

**Recomendacao:** aplicar redacao por campo, nunca registrar credenciais/assinaturas/payload bruto por padrao, limitar retencao e controlar acesso a logs e `provider_payload`. Manter apenas campos necessarios para suporte e auditoria.

### Baixo ou melhoria de controle

#### AR-11 - Cobertura nao exercita os principais cenarios de abuso

Os testes existentes sao bons para caminhos felizes e integracao financeira, mas nao foram encontrados testes para:

- dois recebimentos simultaneos na mesma parcela;
- recebimento maior que o saldo e desconto maior que o devido;
- duas empresas usando IDs cruzados;
- exclusao de conta parcialmente recebida;
- retorno de fatura com boleto/PIX existente;
- rotina de vencimento e preservacao de `cancelled`;
- rollback completo quando provider, caixa ou emissao falha no meio da transacao.

Referencias principais: `tests/Feature/Services/AccountReceivable/AccountReceivableFinancialIntegrationTest.php`, `tests/Feature/Services/Financial/BankSlipWebhookProcessingTest.php` e `tests/Feature/Services/Financial/PixChargeProcessingTest.php`.

O `phpunit.xml` usa SQLite em memoria, fila sincrona e mail array: `phpunit.xml:20-32`. Isso nao simula integralmente locks, isolamento e constraints de MySQL/PostgreSQL usados em producao; testes de concorrencia e migrations devem rodar tambem em um banco representativo.

### Verificacao executada

Comando executado:

```text
php artisan test tests/Feature/Services/AccountReceivable tests/Feature/Services/Financial/BankSlipWebhookProcessingTest.php tests/Feature/Services/Financial/PixChargeProcessingTest.php
```

Resultado: **27 testes passaram, 150 assercoes, 7,52 s**. A aprovacao confirma os caminhos cobertos, mas nao elimina os cenarios de abuso listados acima.

## Fluxos que precisam de decisao de negocio

- Overpayment: deve ser rejeitado, virar credito do cliente ou ser permitido como recebimento excedente separado?
- Cancelamento: a conta a receber deve ser mantida para historico ou removida ao retornar a fatura para pendente?
- Cobranca externa: cancelar boleto/PIX automaticamente ao cancelar ou retornar fatura?
- Vencimento: o status deve ser atualizado por scheduler, por consulta sob demanda ou por ambos?
- Permissoes: quais perfis podem criar, editar, baixar, excluir e estornar recebimentos?
- Recebimento parcial com exclusao: exigir estorno formal sempre que houver movimento conciliado?

## Plano recomendado

### Imediato

1. Corrigir baixa com `lockForUpdate`, limite de saldo e idempotencia por origem.
2. Bloquear exclusao de qualquer conta/parcela com pagamento ou cobranca relacionada; implementar estorno atomico de caixa.
3. Corrigir retorno para pendente considerando boleto e PIX, com cancelamento externo ou politica de preservacao.
4. Adicionar testes de duas empresas, excesso de pagamento e concorrencia.

### Curto prazo

1. Aplicar regras de pertencimento por empresa em todos os FKs recebidos por service/action.
2. Criar policies e verificacoes de tenant para conta, parcela e pagamento.
3. Alinhar os fluxos de confirmacao e geracao isolada de contas, incluindo conta financeira e emissao.
4. Criar comando agendado de vencimento e preservar estados terminais.

### Medio prazo

1. Remover `Model::unguard()` global e substituir payloads livres por DTOs.
2. Reduzir logs sensiveis e definir retencao/controle para payloads de providers.
3. Usar uma maquina de estados explicita para fatura, conta, parcela, boleto e PIX.
4. Executar suite financeira em MySQL/PostgreSQL, incluindo testes de isolamento e concorrencia.

## Arquivos centrais consultados

- `app/Models/Invoice.php`
- `app/Models/AccountReceivable.php`
- `app/Models/AccountReceivableInstallment.php`
- `app/Models/AccountReceivableInstallmentPayment.php`
- `app/Services/Invoice/InvoiceService.php`
- `app/Services/Invoice/Actions/ConfirmInvoiceAction.php`
- `app/Services/Invoice/Actions/GenerateInvoiceAccountReceivablesAction.php`
- `app/Services/AccountReceivable/AccountReceivableService.php`
- `app/Services/AccountReceivable/Validators/AccountReceivableValidator.php`
- `app/Services/AccountReceivable/Validators/AccountReceivableInstallmentValidator.php`
- `app/Jobs/ProcessBankSlipWebhookJob.php`
- `app/Jobs/QueryPixChargeJob.php`
- `routes/web.php`
- `routes/console.php`
- migrations de contas, parcelas, pagamentos, boleto, PIX e movimentos de caixa
- testes Feature de AccountReceivable, BankSlip e PixCharge
