# Levantamento — Documentos Fiscais

> Situação atual levantada em **05/03/2026**. Documento de referência antes da reestruturação.

---

## 1. Objetivo declarado

Controlar a **emissão** de documentos fiscais referentes a vendas (peças via Requisição e serviços via Ordem de Serviço) e o **recebimento** de documentos fiscais de compras. O fluxo de emissão é integrado com a fatura (`Invoice`) do cliente.

---

## 2. Entidades envolvidas

### 2.1 `Invoice` — Fatura

Representa o **agrupamento financeiro** de um ou mais documentos originados de Requisições ou Ordens de Serviço.

| Campo | Tipo | Descrição |
|---|---|---|
| `customer_id` | FK → partners | Cliente |
| `company_id` | FK → companies | Empresa prestadora |
| `invoice_number` | string unique | Número interno da fatura |
| `invoice_date` | date | Data da fatura |
| `total_amount` | decimal 15,4 | Valor total |
| `discount_amount` | decimal 15,4 | Desconto |
| `status` | enum `Invoice\Status` | PENDING / CONFIRMED / CANCELLED |
| `pending/confirmed/canceled` | bool | Flags de estado |
| `confirmed_by/canceled_by` | FK users | Rastreabilidade |

**Relacionamentos:**
- `hasMany` → `FiscalDocument`
- `hasMany` → `AccountReceivable` (contas a receber)
- `hasMany` → `Requisition` (requisições faturadas)

---

### 2.2 `FiscalDocument` — Documento Fiscal (NF-e)

Principal entidade. Representa **uma NF-e** emitida ou recebida.

| Campo | Tipo | Descrição |
|---|---|---|
| `customer_id` | FK → partners | Destinatário |
| `company_id` | FK → companies | Emitente |
| `invoice_id` | FK → invoices | Fatura vinculada (nullable) |
| `status` | enum `FiscalDocument\Status` | Estado interno do documento |
| `issued_at` | date | Data de emissão |
| `movement_at` | date | Data de entrada/saída |
| `document_type` | string | Tipo (nullable) |
| `document_key` | string | Chave de acesso NF-e (44 dígitos) |
| `document_number` | string | Número da NF-e |
| `document_series` | string | Série |
| `operation_type` | int | 0 = entrada, 1 = saída |
| `operation_nature` | string | Natureza da operação (ex: "VENDA") |
| `issue_purpose` | string | Finalidade de emissão |
| `is_final_consumer` | bool | Consumidor final? |
| `buyer_presence_indicator` | bool | Presença do comprador |
| `freight_data` | json | Dados de frete |
| `payment_data` | json | Formas de pagamento |
| `tax_data` | json | Totais tributários e cobrança |
| `nfe_status` | enum `FiscalDocument\NfeStatus` | Status na SEFAZ |
| `nfe_ambiente` | int | 1 = Produção, 2 = Homologação |
| `nfe_protocolo` | string | Protocolo da SEFAZ |
| `nfe_payload` | json | Payload enviado à API |
| `nfe_sequence_id` | FK → nfe_sequences | Sequência utilizada |
| `errors_messages` | json | Histórico de erros |
| `logs` | json | Logs gerais |
| informações adicionais | string | 4 campos de observações fisco/contribuinte |

**Índices únicos:**
- `(document_number, document_series, company_id)` — impede duplicação por emitente
- `(document_number, document_series, customer_id)` — impede duplicação por destinatário

**Relacionamentos:**
- `belongsTo` → `Partner` (customer), `Company`, `Invoice`, `NfeSequence`
- `hasMany` → `FiscalDocumentItem`, `AccountPayable`

---

### 2.3 `FiscalDocumentItem` — Itens da NF-e

| Campo | Descrição |
|---|---|
| `fiscal_document_id` | FK → fiscal_documents |
| `product_id` | FK → products |
| `item_number` | Número do item na NF-e |
| `origin_code` | Origem (0–8) |
| `ncm_code` | Código NCM |
| `cfop_code` | CFOP |
| `quantity` | Qtd (decimal 4 casas) |
| `unit_of_measure` | Unidade |
| `unit_price` | Preço unitário (MoneyCast) |
| `total_price` | Total do item (MoneyCast) |
| `included_in_total` | Inclui no total da NF-e? |
| `tax_data` | json — ICMS, PIS, COFINS por item |

---

### 2.4 `NfeSequence` — Controle de Numeração

Garante a **unicidade e sequencialidade** do número da NF-e por empresa/série/natureza de operação.

| Campo | Descrição |
|---|---|
| `company_id` | FK → companies |
| `serie` | Série (max 3 chars, padrão "1") |
| `operation_nature` | Natureza da operação |
| `last_number` | Último número emitido |

O método `NfeSequence::nextNumber()` usa `SELECT ... FOR UPDATE` (lock de linha) dentro de uma transaction, garantindo que dois jobs simultâneos nunca reservem o mesmo número.

---

## 3. Status e Ciclo de Vida

### 3.1 `FiscalDocument\Status` — Estado interno

| Valor | Descrição |
|---|---|
| `pending` | Pendente — documento criado, aguardando confirmação/emissão |
| `confirmed` | Confirmada — NF-e autorizada pela SEFAZ |
| `cancelled` | Cancelada — rejeitada ou cancelada |

### 3.2 `FiscalDocument\NfeStatus` — Estado na SEFAZ

| Valor | Descrição |
|---|---|
| `pendente` | Ainda não enviada à API |
| `em_processamento` | Enviada, aguardando resposta da SEFAZ |
| `autorizado` | Autorizada pela SEFAZ |
| `rejeitado` | Rejeitada — pode ser reenviada |
| `cancelado` | Cancelada na SEFAZ |

### 3.3 `Invoice\Status`

| Valor | Descrição |
|---|---|
| `pending` | Pendente |
| `confirmed` | Confirmada |
| `cancelled` | Cancelada |

---

## 4. Onde aparece na interface (Filament)

### Cluster **Sales** (Vendas)
- **`FiscalDocuments/FiscalDocumentResource`** — listado como "Notas Fiscais (NF-e)". Foco na **emissão** de NF-e de saída.

### Cluster **Financial** (Financeiro)
- **`FiscalDocuments/FiscalDocumentResource`** — listado como "Documentos Fiscais". Foco no **recebimento/controle** de documentos de compras.
- **`Invoices/InvoiceResource`** — gestão das faturas.

### Cluster **Settings** (Configurações)
- **`NfeSettingsPage`** — configuração dos tokens IntegraNotas, ambiente, série padrão e webhook secret. Visível apenas para `super_admin`.

---

## 5. Fluxo de Emissão de NF-e

### 5.1 Gatilho

A emissão é disparada a partir de dois pontos de origem:

| Origem | Action Filament | Condição |
|---|---|---|
| Ordem de Serviço | `InvoiceServiceOrderAction` | `status === State::CLOSED` |
| Requisição (venda de peças) | `InvoiceRequisitionAction` | `status === Status::CLOSED` |

Ambas chamam o respectivo Service (`ServiceOrderService::invoice()` / `RequisitionService::invoice()`) com o `Auth::id()` do usuário logado.

### 5.2 Sequência completa

```
[Filament - Botão "Faturar"]
        │
        ▼
[ServiceOrderService / RequisitionService]
  → Cria Invoice
  → Cria FiscalDocument (status: pending, nfe_status: null)
  → Cria FiscalDocumentItems
        │
        ▼
[NfeDocumentService::emitir()]
  → Verifica se já enviada (impede reenvio exceto se REJEITADO)
  → dispatch(SendNfeJob)
        │
        ▼
[SendNfeJob] — fila, assíncrono, até 3 tentativas (backoff: 30s/60s/120s)
  │
  ├─ SendNfeAction::execute()
  │     │
  │     ├─ 1. ReserveNfeNumberAction  (SELECT FOR UPDATE — número atômico)
  │     │         → FiscalDocument.document_number, document_series, nfe_sequence_id
  │     │
  │     ├─ 2. BuildNfePayloadAction   (monta JSON para IntegraNotas)
  │     │         → emitente (company), destinatário (customer + address)
  │     │         → itens (product + tax_data per item)
  │     │         → frete, pagamento, totais tributários
  │     │
  │     └─ 3. SDK CloudDfe\SdkPHP\Nfe::cria($payload)
  │               │
  │               ├─ Código 5023 (lote em processamento):
  │               │     → salva document_key, nfe_status = EM_PROCESSAMENTO
  │               │     → salva nfe_ambiente, nfe_payload
  │               │     → dispatch(ConsultNfeJob, delay 15s)  ← polling de fallback
  │               │
  │               ├─ Código 5001/5002 (erro de validação):
  │               │     → salva erro em errors_messages
  │               │     → SendNfeJob::fail() — não retenta
  │               │
  │               └─ Outro erro:
  │                     → salva erro em errors_messages, retenta (até 3x)
  │
  └─ (falha definitiva) → salva em FiscalDocument.errors_messages

        │
        ▼ (concomitante)

[NfeWebhookController POST /nfe/webhook]   ← canal PRIMÁRIO de retorno
  → Valida assinatura opcional (webhook_secret por empresa)
  → Localiza FiscalDocument pela document_key
  │
  ├─ status = 'autorizado':
  │     → nfe_status = AUTORIZADO, status = CONFIRMED
  │     → salva protocolo, número, série definitivos
  │
  ├─ status = 'cancelado':
  │     → nfe_status = CANCELADO, status = CANCELLED
  │
  └─ (sem status / rejeição):
        → nfe_status = REJEITADO, status = CANCELLED
        → salva código e mensagem em errors_messages
        (permite reenvio pois isRejeitado() == true)

        │
        ▼ (polling de fallback)

[ConsultNfeJob] — até 5 tentativas (15s/30s/45s/60s)
  → Se status != EM_PROCESSAMENTO, encerra (webhook já atualizou)
  → ConsultNfeAction consulta a API
  → Se ainda EM_PROCESSAMENTO: reagenda com delay incremental
  → Se status final obtido: encerra
```

---

## 6. Integração com a API IntegraNotas

### 6.1 SDK utilizado
`cloud-dfe/sdk-php` via Composer. Instanciado em `SendNfeAction` e `ConsultNfeAction`.

### 6.2 Configuração por empresa (`CompanyPreference`)

| Chave | Descrição |
|---|---|
| `integranotas.token_producao` | JWT de produção |
| `integranotas.token_homologacao` | JWT de homologação |
| `integranotas.ambiente` | 1 = Produção, 2 = Homologação |
| `integranotas.serie_padrao` | Série padrão (ex: "1") |
| `integranotas.webhook_secret` | Assinatura para validar retorno do webhook |

Resolvida via `NfeConfigService` (singleton), que lê de `CompanyPreference` e abstrai a lógica de qual token usar conforme o ambiente configurado.

### 6.3 Operações implementadas

| Operação | Síncrono/Async | Onde |
|---|---|---|
| Envio (cria NF-e) | **Assíncrono** via job | `SendNfeJob → SendNfeAction` |
| Consulta de status | Síncrono + polling async | `ConsultNfeAction / ConsultNfeJob` |
| Impressão DANFE | Síncrono (retorna base64) | `PrintNfeDanfeAction` |
| Preview (pré-envio) | Síncrono (retorna pdf+xml) | `PrintNfePreviewAction` |
| Webhook (retorno SEFAZ) | Síncrono (POST receptor) | `NfeWebhookController` |

---

## 7. Serviços e Actions — Mapa de classes

```
app/Services/FiscalDocument/
├── FiscalDocumentService.php       → CRUD (create, update, delete) do FiscalDocument
├── NfeDocumentService.php          → Orquestrador NF-e (emitir, consultar, danfe, preview)
└── Actions/
    ├── CreateFiscalDocumentAction.php    → Cria FiscalDocument (valida e persiste)
    ├── UpdateFiscalDocumentAction.php    → Atualiza
    ├── DeleteFiscalDocumentAction.php    → Exclui (com regras de negócio)
    ├── ReserveNfeNumberAction.php        → Reserva número atômico via NfeSequence
    ├── BuildNfePayloadAction.php         → Monta JSON para IntegraNotas
    ├── SendNfeAction.php                 → Orquestra envio (reserva + payload + SDK)
    ├── ConsultNfeAction.php              → Consulta status na SEFAZ
    ├── PrintNfeDanfeAction.php           → Gera DANFE (base64)
    └── PrintNfePreviewAction.php         → Gera preview PDF+XML

app/Services/Fiscal/
└── NfeConfigService.php            → Resolve token, ambiente, série por empresa

app/Jobs/
├── SendNfeJob.php                  → Dispara SendNfeAction (3 tentativas, backoff 30/60/120s)
└── ConsultNfeJob.php               → Polling de status (5 tentativas, delay incremental 15s)

app/Http/Controllers/
└── NfeWebhookController.php        → Receptor de retorno da IntegraNotas (POST)
```

---

## 8. Regras de negócio identificadas

1. **Emissão só é permitida a partir de documentos FECHADOS** — Requisição ou OS precisam estar no estado `CLOSED` para acionar o faturamento.

2. **Reenvio permitido apenas se REJEITADO** — `SendNfeAction` bloqueia reenvio se `nfe_status != null && !isRejeitado()`. Rejeições da SEFAZ permitem nova tentativa mantendo o mesmo número reservado.

3. **Número da NF-e é reservado atomicamente antes do envio** — `ReserveNfeNumberAction` usa `SELECT FOR UPDATE` dentro de DB transaction, evitando números duplicados em cenário de concorrência.

4. **Erros de validação (código 5001/5002) falham imediatamente** — `SendNfeJob` chama `$this->fail()` nesses casos, sem utilizar as 3 tentativas de backoff.

5. **Webhook é o canal primário; polling é fallback** — `ConsultNfeJob` verifica se o webhook já atualizou o status antes de consultar a API.

6. **Webhook sempre retorna HTTP 200** — `NfeWebhookController` nunca retorna HTTP de erro para não interromper o reenvio da IntegraNotas. Erros internos são apenas logados.

7. **Configuração por empresa** — Token, ambiente e série padrão são isolados por `company_id` via `CompanyPreference`. Uma empresa pode operar em homologação enquanto outra está em produção.

8. **FiscalDocument duplicado é impedido por índice unique** — `(document_number, document_series, company_id)` e `(document_number, document_series, customer_id)` no banco.

9. **Rastreabilidade total** — todos os documentos registram `created_by`, `updated_by`, `confirmed_by`, `canceled_by` com respectivos timestamps.

10. **Configuração da página NF-e restrita a `super_admin`** — `NfeSettingsPage::canAccess()` tem um TODO pendente de ativação do filtro de role.

---

## 9. Pontos de atenção / inconsistências observadas

- O `FiscalDocumentResource` existe em **dois clusters diferentes** (Sales e Financial) usando o mesmo Model. A diferença de contexto não está documentada no código das resources.
- O campo `document_type` existe na tabela mas não tem um Enum associado — fica como string livre.
- A migration `2026_03_04_000002_add_nfe_fields_to_fiscal_documents_table.php` adicionou os campos NF-e depois da criação original, indicando que NF-e foi implementada em uma segunda etapa.
- `NfeSettingsPage::canAccess()` está retornando `true` para todos os usuários (TODO não resolvido).
- `Invoice` e `FiscalDocument` têm `Status` enums com os mesmos valores mas em namespaces separados — podem ser unificados.
