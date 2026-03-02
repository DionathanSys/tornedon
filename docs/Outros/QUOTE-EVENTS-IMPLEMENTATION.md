# Implementação de Eventos e Listeners para Orçamentos Aprovados

## Resumo

Este documento descreve a implementação completa do sistema de eventos e listeners que é acionado quando um orçamento sofre alterações de status, especialmente quando é **aprovado**.

## Arquitetura

### 1. Evento Principal

**Arquivo:** `app/Events/Quote/QuoteApproved.php`

O evento `QuoteApproved` é disparado sempre que um orçamento é aprovado. Ele carrega:
- A instância completa do Quote
- O ID do usuário que aprovou

```php
new QuoteApproved($quote, $approvedBy)
```

### 2. Listeners Registrados

Todos os listeners abaixo escutam o evento `QuoteApproved` e são executados em sequência:

#### a) `UpdateQuoteItemsStatusListener`
**Arquivo:** `app/Listeners/Quote/UpdateQuoteItemsStatusListener.php`

- **Função:** Atualizar o status de todos os itens do orçamento para "aprovado"
- **Quando executa:** Sempre, logo após a aprovação
- **Ações:**
  - Atualiza `status` de todos os `QuoteItem` para `Status::APPROVED`
  - Registra logs detalhados

#### b) `CreateRequisitionFromApprovedQuoteListener`
**Arquivo:** `app/Listeners/Quote/CreateRequisitionFromApprovedQuoteListener.php`

- **Função:** Criar uma Requisição para itens com destinação "REQUISIÇÃO"
- **Quando executa:** Se existirem items com `destination = REQUISITION`
- **Campos Obrigatórios Atendidos:**
  - ✅ `company_id` → do Quote
  - ✅ `customer_id` → do Quote.partner_id
  - ✅ `sale_date` → now()
  - ✅ `status` → OPEN (padrão)
  - ✅ `number` → gerado automaticamente pelo service
- **Usa:** `RequisitionService::create()`
- **Cria Items:** Através de `RequisitionItem::create()`

**Dados herdados do Quote:**
- `payment_method`
- `payment_condition`
- `discount_amount` (soma dos items)
- `observations`

#### c) `CreateProductionOrderFromApprovedQuoteListener`
**Arquivo:** `app/Listeners/Quote/CreateProductionOrderFromApprovedQuoteListener.php`

- **Função:** Criar Ordem de Produção para itens com destinação "PRODUÇÃO"
- **Quando executa:** Se existirem items com `destination = ORDER_PRODUCTION`
- **Campos Obrigatórios Atendidos:**
  - ✅ `partner_id` → do Quote
  - ✅ `company_id` → do Quote
  - ✅ `priority` → NORMAL (padrão)
  - ✅ `destination_type` → STOCK (padrão)
  - ✅ `items` → do Quote items
- **Usa:** `ProductionOrderService::create()`
- **Cria Items:** Através de `ProductionOrderItem::create()`

**Dados Especiais:**
- Mantém referência `quote_item_id` para rastreabilidade
- Calcula ordens de produção com base em especificações técnicas

#### d) `CreateServiceOrderFromApprovedQuoteListener`
**Arquivo:** `app/Listeners/Quote/CreateServiceOrderFromApprovedQuoteListener.php`

- **Função:** Criar Ordem de Serviço para itens com destinação "SERVIÇO"
- **Quando executa:** Se existirem items com `destination = ORDER_SERVICE`
- **Campos Obrigatórios Atendidos:**
  - ✅ `customer_id` → do Quote.partner_id
  - ✅ `company_id` → do Quote
  - ✅ `order_date` → now()
  - ✅ `status` → OPEN (padrão)
  - ✅ `priority` → NORMAL (padrão)
  - ✅ `type` → MAINTENANCE (padrão)
  - ✅ `number` → gerado automaticamente pelo service
- **Usa:** `ServiceOrderService::create()`
- **Cria Items:** Através de `ServiceOrderItem::create()`

**Dados herdados do Quote:**
- `payment_method`
- `payment_condition`
- `scheduled_date` → now() + 7 dias (padrão)

## Registros

Os listeners foram registrados no **`app/Providers/AppServiceProvider.php`**:

```php
Event::listen(QuoteApproved::class, UpdateQuoteItemsStatusListener::class);
Event::listen(QuoteApproved::class, CreateRequisitionFromApprovedQuoteListener::class);
Event::listen(QuoteApproved::class, CreateProductionOrderFromApprovedQuoteListener::class);
Event::listen(QuoteApproved::class, CreateServiceOrderFromApprovedQuoteListener::class);
```

## Fluxo de Execução

```
1. Action ApproveQuote é executado
   ↓
2. Quote.state()->approve() executa transição
   ↓
3. Quote::refresh()
   ↓
4. QuoteApproved::dispatch($quote, $approvedBy)
   ↓
5. Listeners executam em sequência (transação):
   a) UpdateQuoteItemsStatusListener
   b) CreateRequisitionFromApprovedQuoteListener (se items com REQUISITION)
   c) CreateProductionOrderFromApprovedQuoteListener (se items com PRODUCTION)
   d) CreateServiceOrderFromApprovedQuoteListener (se items com SERVICE)
```

## Mudanças no Banco de Dados

### Tabela: `quotes`

**Campos Adicionados (Migração):**
- `payment_method` (string, nullable) - Método de pagamento
- `payment_condition` (string, nullable) - Condição de pagamento

**Migração:** `2026_02_27_103028_add_payment_fields_to_quotes_table.php`

```bash
php artisan migrate
```

## Interface Filament

### Modal de Aprovação

**Arquivo:** `app/Filament/Clusters/Sales/Resources/Quotes/Pages/Actions/ApproveQuoteAction.php`

O modal de aprovação agora apresenta dois campos obrigatórios:
1. **Método de Pagamento** - select com valores do enum PaymentMethod
2. **Condição de Pagamento** - select com valores do enum PaymentCondition

Esses valores são:
- + Preenchidos no modal ANTES da aprovação
- Salvos no Quote
- Herdados pelos documentos criados (Requisição, Ordem de Serviço)

## Validações e Tratamento de Erros

### Por Listener

1. **UpdateQuoteItemsStatusListener**
   - ✅ Sempre executa sem dependências

2. **CreateRequisitionFromApprovedQuoteListener**
   - ✅ Valida através de `RequisitionValidator::validateCreate()`
   - ✅ Retorna erro se campos obrigatórios faltarem
   - ✅ Usa transação DB

3. **CreateProductionOrderFromApprovedQuoteListener**
   - ✅ Valida através de `ProductionOrderValidator::validate()`
   - ✅ Retorna erro se campos obrigatórios faltarem
   - ✅ Usa transação DB

4. **CreateServiceOrderFromApprovedQuoteListener**
   - ✅ Valida através de `ServiceOrderValidator::validateCreate()`
   - ✅ Retorna erro se campos obrigatórios faltarem
   - ✅ Usa transação DB

### Logs

Todos os listeners mantêm logs detalhados em diferentes níveis:
- `Log::debug()` - Início de operação
- `Log::info()` - Sucesso
- `Log::error()` - Falha com trace

## Exemplo de Uso

```php
// A aprovação é feita através do ApproveQuote action
$approveQuote = new ApproveQuote($userId);
$quote = $approveQuote->execute($quoteModel);

// O evento é disparado automaticamente na ação
// QuoteApproved::dispatch($quote, $userId)

// Os listeners são acionados automaticamente pelo Laravel
```

## Tratamento de Items por Destinação

Conforme a `destination` de cada item do orçamento:

| Item Destination | Listener | Documento Criado | Status Inicial |
|---|---|---|---|
| `REQUISITION` | CreateRequisitionFromApprovedQuoteListener | Requisição | OPEN |
| `ORDER_PRODUCTION` | CreateProductionOrderFromApprovedQuoteListener | Ordem de Produção | QUEUED |
| `ORDER_SERVICE` | CreateServiceOrderFromApprovedQuoteListener | Ordem de Serviço | OPEN |

Os items com uma determinada destinação geram **apenas** o documento correspondente.

## Campos Padrão Utilizados

### ProductionOrder
- `priority`: NORMAL
- `destination_type`: STOCK

### ServiceOrder
- `priority`: NORMAL
- `type`: MAINTENANCE
- `scheduled_date`: now() + 7 dias

## Future Enhancements

1. Adicionar campo `service_type` ao Quote/QuoteItem para customizar tipo de serviço
2. Adicionar campo `production_priority` ao Quote/QuoteItem para customizar prioridade
3. Criar modal dinâmico baseado em campos faltantes ao invés de campos fixos
4. Adicionar webhook/event após criação de cada documento
5. Permitir desabilitar criação automática por destination via configuração

## Testes

Para testar a implementação:

```bash
# 1. Executar migrações
php artisan migrate

# 2. Criar um orçamento de teste via UI Filament
# 3. Adicionar items com diferentes destinações
# 4. Clicar em "Aprovar"
# 5. Preencher dados de pagamento no modal
# 6. Verificar se foram criados:
#    - Requisição (se houver items REQUISITION)
#    - Ordem de Produção (se houver items PRODUCTION)
#    - Ordem de Serviço (se houver items SERVICE)

# 7. Verificar logs
tail -f storage/logs/laravel.log
```

## Troubleshooting

**Problema:** Os items não estão sendo criados com `quote_item_id`
- **Solução:** O listener tenta atualizar após criação. Verifique se a query está encontrando os items corretamente.

**Problema:** ServiceOrder criada sem items
- **Solução:** Certifique-se que QuoteItem possui `service_id` preenchido quando destination é SERVICE.

**Problema:** ProductionOrder com status errado
- **Solução:** O status é definido como QUEUED pela ação. A migração pode ter nomes diferentes.
