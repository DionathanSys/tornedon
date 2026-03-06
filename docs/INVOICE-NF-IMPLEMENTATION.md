# Implementação — Faturamento Completo (OS / Requisição / OP → Invoice → NF-e)

> Implementado em **05/03/2026**

---

## 1. Problema encontrado

O botão "Faturar" nas telas de Ordem de Serviço e Requisição **apenas mudava o status do registro**. Nenhuma fatura, documento fiscal ou NF-e era criada. A cadeia fiscal estava completamente ausente.

**Situação antes:**
```
[Faturar] → ServiceOrderService::invoice()
           → InvoiceServiceOrderAction::execute()
           → ClosedState::invoice()
           → $order->update(['status' => INVOICED])   ← parava aqui
```

**Situação depois:**
```
[Faturar] → CreateInvoiceDocumentForXxxAction
               ├── InvoiceSequence::lockForUpdate() → invoice_number único
               ├── Invoice::create()
               ├── FiscalDocument::create()
               └── FiscalDocumentItem::create() × N itens
           → State::invoice() → status INVOICED
           → NfeDocumentService::emitir() → SendNfeJob (assíncrono)
```

---

## 2. Banco de Dados — Migrations criadas

### `2026_03_05_100000_create_invoice_sequences_table`
Criou tabela `invoice_sequences` para controle atômico de numeração de faturas por empresa.

```
invoice_sequences
  id
  company_id  (unique FK → companies)
  last_number (int, default 0)
```

### `2026_03_05_100001_add_fiscal_fields_to_services_table`
Adicionou campos fiscais na tabela `services`, necessários para mapear itens de OS para itens de NF-e.

| Coluna | Tipo | Descrição |
|---|---|---|
| `ncm_code` | varchar(10) nullable | Código NCM do serviço |
| `cfop_code` | varchar(5) nullable | CFOP de saída (ex: 5933) |
| `origin_code` | varchar(2) default `'07'` | Origem do item |
| `unit_of_measure` | varchar(10) default `'UN'` | Unidade de medida |

### `2026_03_05_100002_update_fiscal_document_items_add_service_support`
- Adicionou coluna `service_id` (nullable FK → services) em `fiscal_document_items`
- Tornou `product_id` nullable para suportar itens de serviço (antes era required implicitamente)

> **Regra:** cada item de NF-e deve ter `product_id` **OU** `service_id` preenchido, nunca ambos.

### `2026_03_05_100003_add_invoice_id_to_production_orders_table`
Adicionou `invoice_id` (nullable FK → invoices) em `production_orders`, vinculando a OP à sua fatura gerada.

---

## 3. Models atualizados

### `InvoiceSequence` *(novo)*
Controla o número sequencial de faturas por empresa. Usado via `lockForUpdate()` para evitar duplicidade em concorrência.

### `Service`
Adicionados ao `$fillable`: `nbs_code`, `cnae_code`, `municipal_tax_code`, `iss_exigibility`, `ncm_code`, `cfop_code`, `origin_code`, `unit_of_measure`.

### `FiscalDocumentItem`
- Adicionado `service_id` ao `$fillable`
- Adicionada relação `service(): BelongsTo`

### `Invoice`
Adicionada relação `serviceOrders(): HasMany`.

### `ProductionOrder`
Adicionado `invoice_id` ao `$fillable`.

---

## 4. Serviços — Classes criadas

### `InvoiceService::generateNumber(int $companyId): string`
Método adicionado ao `InvoiceService`. Usa `InvoiceSequence::lockForUpdate()` para gerar o próximo número de fatura de forma atômica (zero-padding em 6 dígitos).

---

### `CreateInvoiceDocumentForServiceOrderAction`
**Caminho:** `app/Services/ServiceOrder/Actions/`

Orquestra a criação da cadeia fiscal para uma OS:
1. Carrega itens com `service` relacionado
2. Gera `invoice_number` via `InvoiceSequence`
3. Calcula `total_amount` (soma dos itens − desconto da OS)
4. Cria `Invoice`
5. Vincula `invoice_id` na OS
6. Cria `FiscalDocument` (status: PENDING, operation_type: 1 = saída, operation_nature: "PRESTAÇÃO DE SERVIÇOS")
7. Cria `FiscalDocumentItem` por item da OS (mapeando `service.ncm_code`, `cfop_code` etc.)

---

### `CreateInvoiceDocumentForRequisitionAction`
**Caminho:** `app/Services/Requisition/Actions/`

Mesmo padrão da OS, mas para Requisições de venda de peças:
- Itens mapeados via `product_id` (já existente em `RequisitionItem`)
- `operation_nature`: "VENDA DE MERCADORIA"
- `origin_code` derivado de `product.origin_code ?? '0'`

---

### `CreateInvoiceDocumentForProductionOrderAction`
**Caminho:** `app/Services/ProductionOrder/Actions/`

Para Ordens de Produção concluídas:
- Preço unitário obtido de `quoteItem.unit_price` (OP vinculada ao orçamento)
- Quantidade: `quantity_approved` → fallback `quantity_produced` → fallback `quantity`
- `operation_nature`: "VENDA DE PRODUTOS FABRICADOS"
- `origin_code`: `'3'` (fabricação própria) como padrão

---

### `InvoiceProductionOrderAction`
**Caminho:** `app/Services/ProductionOrder/Actions/`

Domain action que orquestra:
1. `CreateInvoiceDocumentForProductionOrderAction`
2. `CompletedState::invoice()` → status INVOICED
3. `NfeDocumentService::emitir()` (fora da transaction — side-effect externo)

---

## 5. Domain Actions atualizadas

### `InvoiceServiceOrderAction` (domain)
**Antes:** apenas chamava `ClosedState::invoice()`.

**Depois:**
1. Abre `DB::transaction`
2. Chama `CreateInvoiceDocumentForServiceOrderAction`
3. Chama `ClosedState::invoice()` (muda status)
4. Chama `NfeDocumentService::emitir()` (fora da transaction)

### `InvoiceRequisitionAction` (domain)
**Antes:** chamava `state()->invoice()` + processava saída de estoque.

**Depois:**
1. Abre `DB::transaction`
2. Chama `CreateInvoiceDocumentForRequisitionAction`
3. Chama `state()->invoice()` (muda status)
4. Processa saída de estoque (lógica original mantida)
5. Chama `NfeDocumentService::emitir()` **fora** da transaction

> A emissão da NF-e é feita fora da transaction para evitar que uma falha no job cause rollback dos dados já persistidos. Se a NF-e não for enfileirada, o log registra o aviso e o reenvio pode ser feito manualmente.

---

## 6. ProductionOrder — Suporte a faturamento

### `ProductionOrder\Status` enum
Adicionado case `INVOICED = 'invoiced'` com description "Faturada", color "warning" e transição permitida a partir de `COMPLETED`.

### `ProductionOrderState` (abstract)
Adicionado método `invoice(): void` que lança `InvalidStateTransitionException` por padrão.

### `CompletedState`
Implementado `invoice()`: muda status para `INVOICED`.

### `InvoicedState` *(novo)*
Estado terminal. Não permite nenhuma transição. Registrado no `StateResolver`.

### `ProductionOrderService`
Adicionado método `invoice(ProductionOrder $productionOrder, int $userId)` seguindo o padrão existente dos outros métodos de transição.

### Filament — `InvoiceProductionOrderAction` *(novo)*
**Caminho:** `app/Filament/Clusters/Manufacturing/Resources/ProductionOrders/Pages/Actions/`

Botão "Faturar" adicionado nas páginas `ViewProductionOrder` e `EditProductionOrder`:
- Visível apenas quando `status === COMPLETED`
- Requer confirmação
- Chama `ProductionOrderService::invoice()`

---

## 7. Mapeamento de dados OS/Requisição/OP → NF-e

### Origem dos preços por tipo

| Origem | Campo de preço nos itens | Campo de quantidade |
|---|---|---|
| OS | `service_order_items.unit_price` | `quantity` |
| Requisição | `requisition_items.unit_price` | `quantity` |
| OP | `quote_items.unit_price` (via quoteItem) | `quantity_approved` |

### Campos fiscais por tipo de item

| Campo NF-e | Origem OS | Origem Requisição | Origem OP |
|---|---|---|---|
| `ncm_code` | `service.ncm_code` | `product.ncm_code` | `product.ncm_code` |
| `cfop_code` | `service.cfop_code` | `product.cfop_code` | `product.cfop_code` |
| `origin_code` | `service.origin_code ?? '07'` | `product.origin_code ?? '0'` | `product.origin_code ?? '3'` |
| `unit_of_measure` | `service.unit_of_measure ?? 'UN'` | `item.unit_of_measure` | `item.unit_of_measure ?? 'UN'` |

---

## 8. Pontos de atenção pendentes

1. **Campos fiscais dos serviços em branco** — Os campos `ncm_code`, `cfop_code` criados nos serviços estarão `null` para registros existentes. O `BuildNfePayloadAction` precisará lidar com isso ou o cadastro de serviços deve ser enriquecido antes de emitir NF-e de OS.

2. **Preço dos itens de OP** — OPs criadas manualmente (sem vínculo com orçamento) terão `quoteItem = null` e portanto `unit_price = 0`. Esses casos precisam de tratamento antes do faturamento.

3. **Campos fiscais dos produtos** — Similar aos serviços: `ncm_code`, `cfop_code`, `origin_code` já existem no model `Product` (verificar se estão populados na base).

4. **`FiscalDocumentValidator`** — O campo `product_id` foi tornado nullable no banco mas o validator pode precisar de atualização para aceitar `service_id` como alternativa.

5. **NF-e de serviços vs. produtos** — NF-e de prestação de serviços pode exigir campos específicos (ISS) que `BuildNfePayloadAction` ainda não cobre para serviços de OS.

6. **Invoice Model — `serviceOrders` relation sem FK** — A tabela `service_orders` possui `invoice_id`, mas a relation `Invoice::serviceOrders()` adicionada assume essa FK. Confirmar que a coluna `invoice_id` existe na tabela `service_orders` (verificar migrations existentes ou adicionar se necessário).
