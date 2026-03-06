# Plano de Implementação — Faturamento → Invoice → NF-e

> Data: 05/03/2026

---

## 1. Diagnóstico: O que está quebrado

### Fluxo atual ao clicar "Faturar" (OS ou Requisição)

```
[Botão "Faturar" no Filament]
        │
        ▼
InvoiceServiceOrderAction (Filament)
        │
        ▼
ServiceOrderService::invoice()
        │
        ▼
InvoiceServiceOrderAction (domain) ← PROBLEMA AQUI
        │
        ▼
ClosedState::invoice()
        │
        ▼
$order->update(['status' => INVOICED])   ← PARA AQUI
        ·
        ·  ← NÃO EXISTE (deveria existir)
        ▼
❌ Invoice NÃO é criada
❌ FiscalDocument NÃO é criado
❌ FiscalDocumentItems NÃO são criados
❌ NF-e NÃO é disparada
```

**Conclusão:** o botão "Faturar" apenas muda o status do registro. Nenhuma estrutura fiscal é criada.

---

## 2. Gaps de Infraestrutura

| Componente | Situação | Ação |
|---|---|---|
| `InvoiceSequence` model/migration | ❌ Não existe | Criar |
| `services.ncm_code` | ❌ Não existe | Migration + model |
| `services.cfop_code` | ❌ Não existe | Migration + model |
| `services.origin_code` | ❌ Não existe | Migration + model |
| `services.unit_of_measure` | ❌ Não existe | Migration + model |
| `fiscal_document_items.service_id` | ❌ Não existe | Migration + model |
| `fiscal_document_items.product_id` (nullable) | ❌ required | Alterar para nullable |
| `invoices.service_orders` relation | ❌ Não existe no model | Adicionar |
| `CreateInvoiceDocumentForServiceOrderAction` | ❌ Não existe | Criar |
| `CreateInvoiceDocumentForRequisitionAction` | ❌ Não existe | Criar |
| `ProductionOrder` → invoice flow | ❌ Não existe | Criar |

---

## 3. Fluxo após a correção

```
[Botão "Faturar" no Filament]
        │
        ▼
InvoiceServiceOrderAction (Filament)
        │
        ▼
ServiceOrderService::invoice()
        │
        ▼                            ← NOVO CONTEÚDO
InvoiceServiceOrderAction (domain):
  1. Gera invoice_number via InvoiceSequence (lockForUpdate)
  2. Cria Invoice (status: pending, customer, company, total, data)
  3. Vincula service_order.invoice_id = invoice.id
  4. Cria FiscalDocument (status: pending, invoice_id, operation_type: 1=saída)
  5. Cria FiscalDocumentItems (um por item da OS, usando service_id)
  6. Chama ClosedState::invoice() ← muda status para INVOICED
  7. Despacha NfeDocumentService::emitir() ← dispara job assíncrono

        │
        ▼
[SendNfeJob] → [SendNfeAction] → SDK IntegraNotas
        │
        ├─ Lote em processamento → ConsultNfeJob (fallback)
        │
        ▼
[NfeWebhookController] ← retorno da SEFAZ (canal primário)
  → autorizado: FiscalDocument.status = CONFIRMED, Invoice.status = CONFIRMED
  → rejeitado/cancelado: FiscalDocument.status = CANCELLED
```

---

## 4. Mapeamento de Dados

### OS → Invoice
| Campo Invoice | Fonte |
|---|---|
| `customer_id` | `service_order.customer_id` |
| `company_id` | `service_order.company_id` |
| `invoice_number` | `InvoiceSequence::nextNumber(company_id)` |
| `invoice_date` | `today()` |
| `total_amount` | `service_order.items.sum(total_amount) - discount_amount` |
| `discount_amount` | `service_order.discount_amount` |

### OS → FiscalDocument
| Campo FiscalDocument | Fonte |
|---|---|
| `customer_id` | `service_order.customer_id` |
| `company_id` | `service_order.company_id` |
| `invoice_id` | `invoice.id` |
| `status` | `FiscalDocument\Status::PENDING` |
| `issued_at` | `today()` |
| `movement_at` | `today()` |
| `operation_type` | `1` (saída) |
| `operation_nature` | `'PRESTAÇÃO DE SERVIÇOS'` |
| `payment_data` | derivado de `service_order.payment_method + payment_condition` |

### ServiceOrderItem → FiscalDocumentItem
| Campo FiscalDocumentItem | Fonte |
|---|---|
| `service_id` | `item.service_id` |
| `product_id` | `null` |
| `item_number` | `$index + 1` |
| `ncm_code` | `item.service.ncm_code` |
| `cfop_code` | `item.service.cfop_code` |
| `origin_code` | `item.service.origin_code` ?? `'07'` (serviço) |
| `unit_of_measure` | `item.service.unit_of_measure` ?? `'UN'` |
| `quantity` | `item.quantity` |
| `unit_price` | `item.unit_price` |
| `total_price` | `item.total_amount` |

### Requisição → Invoice / FiscalDocument
Mesmo padrão acima. Items via `product_id` (já existente em `RequisitionItem`).

### RequisitionItem → FiscalDocumentItem
| Campo FiscalDocumentItem | Fonte |
|---|---|
| `product_id` | `item.product_id` |
| `service_id` | `null` |
| `item_number` | `$index + 1` |
| `ncm_code` | `item.product.ncm_code` |
| `cfop_code` | `item.product.cfop_code` |
| `origin_code` | `item.product.origin_code` ?? `'0'` |
| `unit_of_measure` | `item.unit_of_measure` |
| `quantity` | `item.quantity` |
| `unit_price` | `item.unit_price` |
| `total_price` | `item.total_amount` |

---

## 5. Production Order

A Ordem de Produção (OP) não tem fluxo de faturamento direto hoje. O faturamento da OP ocorre em dois cenários:

1. **OP → Estoque → Requisição → Fatura** (já existe via Requisição)
2. **OP → Entrega direta → NF-e** (não existe ainda)

**Plano para OP:**
- Adicionar `invoice_id` na tabela `production_orders`
- Criar `InvoiceProductionOrderAction` (domain)
- Adicionar `invoice()` em `CompletedState`
- Adicionar `ProductionOrderService::invoice()`
- Criar `InvoiceProductionOrderAction` (Filament) na página Edit da OP

---

## 6. Checklist de Implementação

### Banco de Dados
- [x] `invoice_sequences` migration + model
- [x] `services`: adicionar `ncm_code`, `cfop_code`, `origin_code`, `unit_of_measure`
- [x] `fiscal_document_items`: `service_id` nullable, tornar `product_id` nullable
- [ ] `production_orders`: adicionar `invoice_id` (Production Order — fase 2)

### Models
- [x] `InvoiceSequence`
- [x] `Service` — fillable atualizado
- [x] `FiscalDocumentItem` — service_id adicionado, product_id nullable
- [x] `FiscalDocumentValidator` — product_id tornando opcional (either/or)
- [x] `Invoice` — relação `serviceOrders()` adicionada

### Domain Actions
- [x] `CreateInvoiceDocumentForServiceOrderAction` — Cria Invoice + FiscalDoc + Items para OS
- [x] `CreateInvoiceDocumentForRequisitionAction` — Cria Invoice + FiscalDoc + Items para Requisição
- [x] `InvoiceServiceOrderAction` — expandido para orquestrar o fluxo completo
- [x] `InvoiceRequisitionAction` — expandido para orquestrar o fluxo completo
- [ ] `InvoiceProductionOrderAction` — fase 2
- [ ] `CompletedState::invoice()` — fase 2
- [ ] `ProductionOrderService::invoice()` — fase 2
