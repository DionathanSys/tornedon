# Documentação da Camada Fiscal

> Última atualização: Março 2026

---

## Sumário

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura Atual](#2-arquitetura-atual)
3. [Fluxo de Criação de Documento Fiscal](#3-fluxo-de-criação-de-documento-fiscal)
4. [Fluxo de Emissão de NF-e](#4-fluxo-de-emissão-de-nf-e)
5. [Fluxo de Resposta (Webhook + Polling)](#5-fluxo-de-resposta-webhook--polling)
6. [Mapa de Chamadores (Quem chama Quem)](#6-mapa-de-chamadores-quem-chama-quem)
7. [Estrutura de Arquivos Atual](#7-estrutura-de-arquivos-atual)
8. [Modelos e Relacionamentos](#8-modelos-e-relacionamentos)
9. [Validators Atuais](#9-validators-atuais)
10. [Integração IntegraNotas (API)](#10-integração-integranotas-api)
11. [Vínculo Fatura (Invoice) → Documento Fiscal](#11-vínculo-fatura-invoice--documento-fiscal)
12. [Plano de Reestruturação](#12-plano-de-reestruturação)

---

## 1. Visão Geral

O sistema fiscal é responsável por criar, validar, emitir e gerenciar documentos fiscais eletrônicos (NF-e) através da API **IntegraNotas** (SDK `cloud-dfe/sdk-php`).

O ciclo completo de uma NF-e:

```
Criação → Preenchimento de Itens → Preview (opcional) → Emissão → Processamento SEFAZ → Autorização/Rejeição
```

**API IntegraNotas:**
- NF-e: https://integranotas.com.br/doc/nfe
- NFS-e: https://integranotas.com.br/doc/nfse (planejado para etapa futura)

---

## 2. Arquitetura Atual

Segue o padrão do projeto:

```
Filament (UI)
    ↓
Service (orquestra fluxo)
    ↓
Action (regra de negócio isolada)
    ↓
Model
```

### Services da camada fiscal:

| Service | Responsabilidade |
|---------|-----------------|
| `FiscalDocumentService` | CRUD do documento fiscal (create, update, delete) com DB::transaction |
| `NfeDocumentService` | Orquestra ciclo NF-e: emitir (assíncrono), consultar, DANFE, preview |
| `NfeConfigService` | Resolve configurações da API IntegraNotas por empresa (token, ambiente, série) |

### Actions da camada fiscal:

| Action | Responsabilidade |
|--------|-----------------|
| `CreateFiscalDocumentAction` | Cria `FiscalDocument` com validação via `FiscalDocumentValidator` |
| `UpdateFiscalDocumentAction` | Atualiza `FiscalDocument` com validação |
| `DeleteFiscalDocumentAction` | Exclui `FiscalDocument` com verificação de vínculos |
| `SendNfeAction` | Orquestra envio: reservar número → montar payload → enviar via SDK |
| `BuildNfePayloadAction` | Monta payload completo conforme API IntegraNotas |
| `ReserveNfeNumberAction` | Reserva número/série atomicamente via `NfeSequence` |
| `ConsultNfeAction` | Consulta status da NF-e na SEFAZ via API |
| `PrintNfeDanfeAction` | Gera PDF/DANFE de NF-e autorizada via API |
| `PrintNfePreviewAction` | Gera pré-visualização (PDF+XML) sem enviar à SEFAZ |

---

## 3. Fluxo de Criação de Documento Fiscal

### Fluxo via Filament (manual):

```
Filament CreateFiscalDocument Page
    ↓ (formulário FiscalDocumentForm)
    ↓ (preenchimento dos dados: empresa, cliente, fatura, itens, frete, etc.)
    ↓
FiscalDocumentResource (Filament internamente chama mutateFormDataBeforeCreate)
    ↓
FiscalDocumentService::create($data, $createdBy)
    ↓ DB::transaction
    ↓
CreateFiscalDocumentAction::execute($data)
    ↓
FiscalDocumentValidator::validateCreate($data)
    ↓ (regras: customer_id obrigatório, company_id obrigatório, status enum)
    ↓
FiscalDocument::create($validatedData)
    ↓
Retorna FiscalDocument (status: PENDING, nfe_status: null)
```

### Dados preenchidos na criação:

**Seção Identificação:**
- `company_id` — Empresa emitente (obrigatório)
- `customer_id` — Cliente/Destinatário (obrigatório)
- `invoice_id` — Fatura vinculada (opcional)

**Seção NF-e:**
- `operation_nature` — Ex: "VENDA DENTRO DO ESTADO" (padrão)
- `issued_at`, `movement_at` — Datas (padrão: now())
- `operation_type` — 1=Saída, 0=Entrada
- `issue_purpose` — Finalidade (1=Normal, 2=Complementar, 3=Ajuste, 4=Devolução)
- `is_final_consumer` — Toggle (padrão: true)
- `buyer_presence_indicator` — Presença do comprador (padrão: 9=não presencial)

**Seção Itens (Repeater):**
- `product_id`, `ncm_code`, `cfop_code`, `origin_code`
- `quantity`, `unit_price`, `total_price`
- `unit_of_measure`, `included_in_total`
- `tax_data` — JSON com impostos (ICMS, PIS, COFINS) e informações adicionais

**Seção Frete:**
- `freight_data.modalidade_frete` — 0=CIF, 1=FOB, 9=sem frete (padrão)

**Seção Informações Adicionais:**
- `additional_taxpayer_information`, `additional_tax_information`

---

## 4. Fluxo de Emissão de NF-e

O envio é **sempre assíncrono** (via job/fila):

```
┌─────────────────────────────────────────────────────────────┐
│ FILAMENT TABLE — Botão "Emitir NF-e"                        │
│ (visível se: !nfeSent() || isRejected())                    │
│                                                             │
│ Chama: NfeDocumentService->emitir($record, auth()->id())    │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
            ┌──────────────────────────────────┐
            │ NfeDocumentService::emitir()      │
            │                                  │
            │ 1. Valida se NF-e já foi enviada │
            │    (permite reenvio se REJECTED)  │
            │                                  │
            │ 2. dispatch(SendNfeJob)           │
            │    → Assíncrono via fila          │
            │    → 3 tentativas, backoff        │
            │      [30s, 60s, 120s]             │
            └──────────────────────────────────┘
                           │
                           ▼
            ┌──────────────────────────────────┐
            │ SendNfeJob::handle()              │
            │                                  │
            │ Localiza FiscalDocument           │
            │ Executa SendNfeAction             │
            │                                  │
            │ Se erro de validação (5001/5002): │
            │   → $this->fail() (sem retry)    │
            │ Caso contrário:                   │
            │   → retry com backoff             │
            └──────────────────────────────────┘
                           │
                           ▼
    ┌──────────────────────────────────────────────────────┐
    │ SendNfeAction::execute($doc, $serie, $opNature)      │
    │                                                      │
    │ ETAPA 1 — Reservar Número                            │
    │   ReserveNfeNumberAction::execute()                   │
    │     → NfeSequence::nextNumber(company, serie, nature) │
    │     → Salva document_number, document_series no doc   │
    │                                                      │
    │ ETAPA 2 — Montar Payload                             │
    │   BuildNfePayloadAction::execute($doc)                │
    │     → Carrega: company, customer.address, items       │
    │     → Monta: destinatario, itens[], frete, pagamento  │
    │     → Retorna array conforme API IntegraNotas         │
    │                                                      │
    │ ETAPA 3 — Enviar via SDK                             │
    │   NfeConfigService::buildSdkParams($companyId)        │
    │     → Resolve token + ambiente                        │
    │   $nfe = new CloudDfe\SdkPHP\Nfe($params)             │
    │   $resp = $nfe->cria($payload)                        │
    └──────────────────────┬───────────────────────────────┘
                           │
              ┌────────────┴────────────────┐
              │                             │
              ▼                             ▼
    ┌──────────────────┐         ┌──────────────────────┐
    │ Código 5023       │         │ Erro (5001/5002/etc) │
    │ (Em processamento)│         │                      │
    │                   │         │ Salva erros em       │
    │ Salva no doc:     │         │ errors_messages[]    │
    │ - document_key    │         │                      │
    │ - nfe_status =    │         │ Retorna false        │
    │   IN_PROCESSING   │         └──────────────────────┘
    │ - nfe_payload     │
    │ - nfe_ambiente    │
    │                   │
    │ Dispara           │
    │ ConsultNfeJob     │
    │ (delay 15s)       │
    └──────────────────┘
```

---

## 5. Fluxo de Resposta (Webhook + Polling)

A API IntegraNotas opera de forma **assíncrona**. A resposta chega por dois canais:

### Canal Primário: Webhook

```
IntegraNotas SEFAZ → POST /webhook/nfe → NfeWebhookController::handle()
```

| Campo Recebido | Uso |
|----------------|-----|
| `chave` | Localiza `FiscalDocument` por `document_key` |
| `status` | `autorizado`, `cancelado`, ou outro (rejeitado) |
| `protocolo` | Salvo em `nfe_protocolo` |
| `signature` | Validada contra `CompanyPreference integranotas.webhook_secret` |
| `numero`, `serie` | Salvos em `document_number`, `document_series` |
| `origem` | Se `TESTE`, apenas confirma recebimento (ping) |

**Processamento:**

| Status Recebido | nfe_status | status | Campos extras |
|-----------------|------------|--------|---------------|
| `autorizado` | `AUTHORIZED` | `CONFIRMED` | `nfe_protocolo`, `confirmed_at` |
| `cancelado` | `CANCELED` | `CANCELLED` | `canceled_at` |
| `outro/null` | `REJECTED` | `CANCELLED` | `errors_messages[]` |

> O webhook **sempre retorna HTTP 200**, conforme exigência da IntegraNotas.

### Canal Secundário: Polling (fallback)

```
ConsultNfeJob (dispatched por SendNfeAction após envio com sucesso)
    ↓
ConsultNfeAction::execute($doc)
    ↓
$nfe->consulta(['chave' => $doc->document_key])
```

- Máximo de **5 tentativas** com delay incremental (15s, 30s, 45s, 60s, 75s)
- Se webhook já atualizou o status → job sai sem fazer nada
- Se status ainda `IN_PROCESSING` e tentativas < 5 → reagenda com delay maior

### Consulta Manual (síncrono via Filament):

```
Filament Table — Botão "Consultar SEFAZ"
    ↓ (visível se: isInProcessing())
NfeDocumentService::consultar($doc, $userId)
    ↓
ConsultNfeAction::execute($doc)
    ↓ síncrono
Atualiza FiscalDocument conforme resposta
```

---

## 6. Mapa de Chamadores (Quem chama Quem)

```
┌──────────────────────────────────────────────────────────────────────┐
│                           FILAMENT (UI)                              │
│                                                                      │
│  FiscalDocumentForm ─────────► Filament Resource (Create/Edit)       │
│  FiscalDocumentsTable ──┬───► NfeDocumentService::emitir()           │
│                         ├───► NfeDocumentService::consultar()        │
│                         ├───► NfeDocumentService::preview()          │
│                         └───► NfeDocumentService::danfe()            │
│  NfeSettingsPage ───────────► CompanyPreference (config tokens)      │
└──────────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                          SERVICES                                    │
│                                                                      │
│  FiscalDocumentService                                               │
│    ::create()     ──────────► CreateFiscalDocumentAction              │
│    ::update()     ──────────► UpdateFiscalDocumentAction              │
│    ::delete()     ──────────► DeleteFiscalDocumentAction              │
│                                                                      │
│  NfeDocumentService                                                  │
│    ::emitir()     ──────────► dispatch(SendNfeJob)                    │
│    ::consultar()  ──────────► ConsultNfeAction                       │
│    ::danfe()      ──────────► PrintNfeDanfeAction                    │
│    ::preview()    ──────────► PrintNfePreviewAction                  │
│                                                                      │
│  NfeConfigService                                                    │
│    ::resolveAmbiente()                                               │
│    ::resolveToken()     ◄──── SendNfeAction, NfeWebhookController    │
│    ::resolveSerie()                                                  │
│    ::buildSdkParams()                                                │
│    ::resolveWebhookSecret() ◄── NfeWebhookController                 │
└──────────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                           JOBS (FILAS)                                │
│                                                                      │
│  SendNfeJob                                                          │
│    ::handle()    ──────────► SendNfeAction::execute()                 │
│    ::failed()    ──────────► FiscalDocument->update(errors_messages)  │
│                                                                      │
│  ConsultNfeJob                                                       │
│    ::handle()    ──────────► ConsultNfeAction::execute()              │
│    (auto-reagenda até 5x)                                            │
└──────────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                          ACTIONS                                     │
│                                                                      │
│  CreateFiscalDocumentAction                                          │
│    ::execute() ─────► FiscalDocumentValidator::validateCreate()       │
│                 ─────► FiscalDocument::create()                       │
│                                                                      │
│  UpdateFiscalDocumentAction                                          │
│    ::execute() ─────► FiscalDocumentValidator::validateUpdate()       │
│                 ─────► FiscalDocument->update()                       │
│                                                                      │
│  DeleteFiscalDocumentAction                                          │
│    ::execute() ─────► Verifica vínculos → FiscalDocument->delete()    │
│                                                                      │
│  SendNfeAction                                                       │
│    ::execute() ─────► ReserveNfeNumberAction                         │
│                 ─────► BuildNfePayloadAction                         │
│                 ─────► CloudDfe\SdkPHP\Nfe->cria()                   │
│                 ─────► dispatch(ConsultNfeJob) [fallback polling]     │
│                                                                      │
│  BuildNfePayloadAction                                               │
│    ::execute() ─────► Lê FiscalDocument + company + customer + items │
│                 ─────► Monta array conforme API IntegraNotas          │
│                                                                      │
│  ReserveNfeNumberAction                                              │
│    ::execute() ─────► NfeSequence::nextNumber()                      │
│                 ─────► FiscalDocument->update(number, series)         │
│                                                                      │
│  ConsultNfeAction                                                    │
│    ::execute() ─────► CloudDfe\SdkPHP\Nfe->consulta()                │
│                 ─────► FiscalDocument->update(status, protocolo)      │
│                                                                      │
│  PrintNfeDanfeAction                                                 │
│    ::execute() ─────► CloudDfe\SdkPHP\Nfe->pdf()                     │
│                                                                      │
│  PrintNfePreviewAction                                               │
│    ::execute() ─────► BuildNfePayloadAction (monta payload)          │
│                 ─────► CloudDfe\SdkPHP\Nfe->preview()                │
└──────────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                       WEBHOOK (HTTP)                                 │
│                                                                      │
│  NfeWebhookController::handle()                                      │
│    ← POST /webhook/nfe (sem auth middleware)                         │
│    → NfeConfigService::resolveWebhookSecret()                        │
│    → FiscalDocument (localiza por document_key)                      │
│    → Atualiza: nfe_status, status, protocolo, confirmed/canceled_at  │
│    → Sempre retorna HTTP 200                                         │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 7. Estrutura de Arquivos Atual

```
app/
├── Services/
│   ├── Fiscal/
│   │   └── NfeConfigService.php              ← Configuração IntegraNotas
│   │
│   └── FiscalDocument/
│       ├── FiscalDocumentService.php          ← CRUD (create, update, delete)
│       ├── NfeDocumentService.php             ← Ciclo NF-e (emitir, consultar, danfe, preview)
│       │
│       ├── Actions/
│       │   ├── CreateFiscalDocumentAction.php
│       │   ├── UpdateFiscalDocumentAction.php
│       │   ├── DeleteFiscalDocumentAction.php
│       │   ├── SendNfeAction.php              ← Orquestra envio NF-e
│       │   ├── BuildNfePayloadAction.php      ← Monta payload API
│       │   ├── ReserveNfeNumberAction.php     ← Reserva número atômico
│       │   ├── ConsultNfeAction.php           ← Consulta SEFAZ
│       │   ├── PrintNfeDanfeAction.php        ← PDF DANFE
│       │   └── PrintNfePreviewAction.php      ← Preview (PDF+XML)
│       │
│       └── Validators/
│           └── FiscalDocumentValidator.php    ← Validação genérica (sem distinção por modelo)
│
├── Models/
│   ├── FiscalDocument.php
│   ├── FiscalDocumentItem.php
│   └── NfeSequence.php
│
├── Jobs/
│   ├── SendNfeJob.php                         ← Job assíncrono de envio
│   └── ConsultNfeJob.php                      ← Job de polling (fallback)
│
├── Http/Controllers/
│   └── NfeWebhookController.php               ← Webhook IntegraNotas
│
├── Enum/FiscalDocument/
│   ├── Status.php                             ← PENDING, CONFIRMED, CANCELLED
│   └── NfeStatus.php                          ← PENDING, IN_PROCESSING, AUTHORIZED, REJECTED, CANCELED
│
└── Filament/Clusters/Sales/Resources/FiscalDocuments/
    ├── FiscalDocumentResource.php
    ├── Pages/
    │   ├── CreateFiscalDocument.php
    │   ├── EditFiscalDocument.php
    │   └── ListFiscalDocuments.php
    ├── Schemas/
    │   └── FiscalDocumentForm.php             ← Formulário completo (seções: identificação, NF-e, itens, frete)
    └── Tables/
        └── FiscalDocumentsTable.php           ← Tabela + ações (emitir, consultar, preview, DANFE)
```

---

## 8. Modelos e Relacionamentos

### FiscalDocument

```
FiscalDocument
    ├── belongsTo: Company (company_id)          — empresa emitente
    ├── belongsTo: Partner (customer_id)         — cliente/destinatário
    ├── belongsTo: Invoice (invoice_id)          — fatura vinculada (nullable)
    ├── belongsTo: NfeSequence (nfe_sequence_id) — sequência de numeração
    ├── belongsTo: User (created_by, updated_by, confirmed_by, canceled_by)
    ├── hasMany:   FiscalDocumentItem            — itens da nota
    └── hasMany:   AccountPayable                — contas a pagar vinculadas
```

**Campos JSON armazenados:**
- `freight_data` — dados de frete (modalidade, volumes, peso)
- `payment_data` — formas de pagamento
- `tax_data` — totais tributários e cobrança
- `errors_messages` — array de erros acumulados
- `nfe_payload` — payload completo enviado à API (para debug/reenvio)
- `logs` — logs de operações

### FiscalDocumentItem

```
FiscalDocumentItem
    ├── belongsTo: FiscalDocument (fiscal_document_id)
    ├── belongsTo: Product (product_id)  — nullable
    ├── belongsTo: Service (service_id)  — nullable (preparado para NFS-e)
    └── belongsTo: User (created_by, updated_by)
```

**Campos do item:**
- `item_number` — número sequencial
- `origin_code` — origem da mercadoria (0=Nacional, etc.)
- `ncm_code` — classificação fiscal NCM
- `cfop_code` — código fiscal de operação
- `quantity`, `unit_price`, `total_price`
- `unit_of_measure` — unidade (UN, KG, etc.)
- `included_in_total` — inclui no total da nota
- `tax_data` — JSON com impostos (ICMS, PIS, COFINS) e informações adicionais

---

## 9. Validators Atuais

O `FiscalDocumentValidator` atual é **genérico** — não distingue por modelo de documento (NF-e vs NFS-e):

```php
// Regras de criação:
'customer_id'  => 'required|integer|exists:partners,id'
'company_id'   => 'required|integer|exists:companies,id'
'status'       => 'required|in:pending,confirmed,cancelled'
'issued_at'    => 'required|date'
'movement_at'  => 'required|date'
// ... demais campos nullable
```

**Problemas identificados:**
1. Não valida campos obrigatórios para NF-e (ex: `operation_nature`, `cfop_code` dos itens)
2. Não valida campos obrigatórios para NFS-e (ex: `service_id`, código do serviço)
3. Não valida estrutura do `tax_data` dos itens (ICMS, PIS, COFINS)
4. Não valida itens (apenas o cabeçalho do documento é validado)
5. Não distingue regras entre tipos/modelos de documento

---

## 10. Integração IntegraNotas (API)

### Payload NF-e — Estrutura enviada:

```json
{
    "natureza_operacao": "VENDA DENTRO DO ESTADO",
    "serie": "1",
    "numero": "1035",
    "data_emissao": "2020-10-15T03:00:00-03:00",
    "data_entrada_saida": "2020-10-15T03:00:00-03:00",
    "tipo_operacao": "1",
    "finalidade_emissao": "1",
    "consumidor_final": "0",
    "presenca_comprador": "9",
    "destinatario": {
        "cnpj": "15493535500128",
        "nome": "EMPRESA MODELO",
        "indicador_inscricao_estadual": "1",
        "inscricao_estadual": "212055510",
        "endereco": { "..." }
    },
    "itens": [
        {
            "numero_item": "1",
            "codigo_produto": "000297",
            "descricao": "SAL GROSSO 50KGS",
            "codigo_ncm": "55110011",
            "cfop": "5102",
            "unidade_comercial": "SC",
            "quantidade_comercial": 10,
            "valor_unitario_comercial": "22.45",
            "valor_bruto": "224.50",
            "origem": "0",
            "inclui_no_total": "1",
            "imposto": {
                "icms": { "situacao_tributaria": "101", "..." },
                "pis":  { "situacao_tributaria": "01", "..." },
                "cofins": { "situacao_tributaria": "01", "..." }
            }
        }
    ],
    "frete": { "modalidade_frete": "0" },
    "pagamento": { "formas_pagamento": [{ "meio_pagamento": "01", "valor": "224.50" }] }
}
```

### Respostas da API:

| Código | Significado | Ação no Sistema |
|--------|-------------|-----------------|
| `5023` | Lote em processamento | Salva `document_key`, status `IN_PROCESSING`, dispara polling |
| `5001` | Erro de validação (emitente) | Salva erros, job falha sem retry |
| `5002` | Erro de validação (dados) | Salva erros, job falha sem retry |
| Webhook `autorizado` | NF-e autorizada pela SEFAZ | Status → `AUTHORIZED` / `CONFIRMED` |
| Webhook `cancelado` | NF-e cancelada | Status → `CANCELED` / `CANCELLED` |

### SDK utilizado:

```php
$sdk = new CloudDfe\SdkPHP\Nfe($params);

$sdk->cria($payload);             // Envio NF-e
$sdk->consulta(['chave' => '']); // Consulta status
$sdk->pdf(['chave' => '']);      // Gera DANFE (PDF base64)
$sdk->preview($payload);         // Preview (PDF + XML base64)
```

---

## 11. Vínculo Fatura (Invoice) → Documento Fiscal

### Estado atual:

- `FiscalDocument` possui `invoice_id` (nullable FK)
- `Invoice hasMany FiscalDocuments`
- O vínculo é opcional — o documento fiscal pode ser criado manualmente sem fatura
- **Não existe** importação automática de itens da fatura para o documento fiscal
- Os itens da nota são preenchidos manualmente no Repeater do Filament

### Origem dos itens da fatura:

A `Invoice` agrega itens de múltiplas origens:

```
Invoice
    ├── hasMany: ServiceOrder (OS)        → ServiceOrderItem (serviços + produtos)
    ├── hasMany: Requisition              → RequisitionItem (produtos/materiais)
    └── hasMany: ProductionOrder (OP)     → ProductionOrderItem (produtos acabados)
```

O `total_amount` da Invoice é calculado como atributo computado (soma dos totais de OS + Requisição + OP).

### Necessidade:

90% das vezes os itens do documento fiscal serão provenientes da fatura. É necessário:
1. Importar automaticamente itens da fatura para o documento fiscal
2. Respeitar dados fiscais de cada item (NCM, CFOP, impostos)
3. Garantir rastreabilidade (de qual OS/Requisição/OP veio cada item)

---

## 12. Plano de Reestruturação

### 12.1. Problema Central

A camada fiscal atual foi construída exclusivamente para NF-e. Para suportar NFS-e (e futuramente outros modelos), precisa:

1. **Validators por modelo** — campos obrigatórios diferem entre NF-e e NFS-e
2. **Payload builders por modelo** — estrutura de envio à API é completamente diferente
3. **Importação de itens da fatura** — automatizar o preenchimento dos itens fiscais
4. **Validação de itens** — regras específicas por tipo de item (produto vs serviço)

### 12.2. Diferenças NF-e vs NFS-e

| Aspecto                 | NF-e                                      | NFS-e                                       |
| ----------------------- | ----------------------------------------- | ------------------------------------------- |
| **Órgão**               | SEFAZ (estadual)                          | Prefeitura (municipal)                      |
| **Itens**               | Produtos (obrigatório: NCM, CFOP, origem) | Serviços (obrigatório: código serviço, ISS) |
| **Impostos item**       | ICMS, PIS, COFINS                         | ISS, PIS, COFINS                            |
| **Campos obrigatórios** | natureza_operacao, serie, numero, frete   | regime_tributario, codigo_servico           |
| **Destinatário**        | IE obrigatória para contribuinte          | Dispensa IE                                 |
| **Processamento**       | Assíncrono (5023 → webhook)               | Pode ser síncrono (depende da prefeitura)   |
| **SDK**                 | `CloudDfe\SdkPHP\Nfe`                     | `CloudDfe\SdkPHP\Nfse`                      |

### 12.3. Estrutura Proposta

#### Enum `DocumentModel` (novo):

```php
enum DocumentModel: string
{
    case NFE  = 'nfe';   // NF-e — Nota Fiscal Eletrônica (produtos)
    case NFSE = 'nfse';  // NFS-e — Nota Fiscal de Serviço Eletrônica
}
```

> O campo `document_type` do `FiscalDocument` passa a armazenar o modelo (nfe/nfse).

#### Validators por Modelo:

```
app/Services/FiscalDocument/Validators/
    ├── FiscalDocumentValidator.php          ← Regras comuns (cabeçalho)
    ├── NfeDocumentValidator.php             ← Regras específicas para NF-e
    ├── NfseDocumentValidator.php            ← Regras específicas para NFS-e (futuro)
    └── Items/
        ├── NfeItemValidator.php             ← Valida item de NF-e (NCM, CFOP, impostos)
        └── NfseItemValidator.php            ← Valida item de NFS-e (código serviço, ISS) (futuro)
```

**`FiscalDocumentValidator` (regras comuns):**
```
- customer_id: required
- company_id: required
- document_type: required|in:nfe,nfse
- issued_at: required|date
- movement_at: required|date
- items: required|array|min:1
```

**`NfeDocumentValidator` (regras específicas NF-e):**
```
CABEÇALHO:
- operation_nature: required|string|max:60
- operation_type: required|in:0,1
- issue_purpose: required|in:1,2,3,4
- is_final_consumer: required|boolean
- buyer_presence_indicator: required|in:0,1,2,3,4,5,9
- freight_data: required|array
- freight_data.modalidade_frete: required|in:0,1,2,3,4,9

DESTINATÁRIO (via customer):
- customer.document_number: required (CPF ou CNPJ válido)
- customer.address: required (logradouro, bairro, cidade, UF, CEP)
- customer.state_tax_indicator: required|in:1,2,9
```

**`NfeItemValidator` (regras por item NF-e):**
```
- product_id: required|exists:products,id
- ncm_code: required|string|size:8
- cfop_code: required|string|size:4
- origin_code: required|in:0,1,2,3,4,5,6,7,8
- quantity: required|numeric|min:0.0001
- unit_price: required|numeric|min:0
- total_price: required|numeric|min:0
- unit_of_measure: required|string|max:6
- tax_data: required|array
- tax_data.imposto: required|array
- tax_data.imposto.icms: required|array
- tax_data.imposto.icms.situacao_tributaria: required
- tax_data.imposto.pis: required|array
- tax_data.imposto.pis.situacao_tributaria: required
- tax_data.imposto.cofins: required|array
- tax_data.imposto.cofins.situacao_tributaria: required
```

**`NfseDocumentValidator` (futuro — regras NFS-e):**
```
CABEÇALHO:
- regime_tributario: required
- (campos específicos do provedor municipal)

ITEM:
- service_id: required|exists:services,id
- codigo_servico: required
- aliquota_iss: required|numeric
```

#### Resolução do Validator:

Adicionar um `ValidatorResolver` que seleciona os validators corretos com base no `document_type`:

```php
class FiscalDocumentValidatorResolver
{
    public static function resolve(string $documentType): array
    {
        return match ($documentType) {
            'nfe'  => [
                'document' => new NfeDocumentValidator(),
                'item'     => new NfeItemValidator(),
            ],
            'nfse' => [
                'document' => new NfseDocumentValidator(),
                'item'     => new NfseItemValidator(),
            ],
        };
    }
}
```

### 12.4. Importação de Itens da Fatura

#### Action proposta: `ImportInvoiceItemsAction`

Responsável por converter itens da fatura (OS, Requisições, OPs) em `FiscalDocumentItem`:

```
Invoice
    ├── ServiceOrders → ServiceOrderItems ─────┐
    ├── Requisitions  → RequisitionItems  ─────┤──► ImportInvoiceItemsAction
    └── ProductionOrders → POItems        ─────┘        │
                                                         ▼
                                               FiscalDocumentItem[]
                                               (com product_id, quantity, unit_price,
                                                ncm_code, cfop_code, tax_data preenchidos)
```

**Regras da importação:**
1. Cada item da fatura gera um `FiscalDocumentItem`
2. NCM e CFOP vêm do cadastro do `Product` (a ser adicionado se não existir)
3. Dados tributários (ICMS, PIS, COFINS) podem ser resolvidos via regra fiscal por produto/operação
4. Itens de serviço (de OS) vão para NFS-e; itens de produto (de Requisição/OP) vão para NF-e
5. Registrar rastreabilidade (qual item da fatura originou qual item fiscal)

**Fluxo proposto com fatura:**

```
Filament Table Invoice — Botão "Gerar Nota Fiscal"
    ↓
Seleciona modelo (NF-e ou NFS-e) ← futuro: ambas se fatura mista
    ↓
FiscalDocumentService::createFromInvoice($invoice, $documentType, $userId)
    ↓
CreateFiscalDocumentAction (cabeçalho — customer e company da fatura)
    ↓
ImportInvoiceItemsAction (itens da fatura → itens fiscais)
    ↓
ValidatorResolver::resolve($documentType)
    ↓ valida documento + itens conforme modelo
FiscalDocument (status: PENDING, pronto para emissão)
```

### 12.5. Estrutura de Arquivos Proposta

```
app/
├── Enum/FiscalDocument/
│   ├── Status.php                 ← existente
│   ├── NfeStatus.php              ← existente
│   └── DocumentModel.php          ← NOVO (nfe, nfse)
│
├── Services/
│   ├── Fiscal/
│   │   └── NfeConfigService.php   ← existente
│   │
│   └── FiscalDocument/
│       ├── FiscalDocumentService.php          ← existente (adicionar createFromInvoice)
│       ├── NfeDocumentService.php             ← existente
│       │
│       ├── Actions/
│       │   ├── CreateFiscalDocumentAction.php ← existente (usar ValidatorResolver)
│       │   ├── UpdateFiscalDocumentAction.php ← existente (usar ValidatorResolver)
│       │   ├── DeleteFiscalDocumentAction.php ← existente
│       │   ├── ImportInvoiceItemsAction.php   ← NOVO — importa itens da fatura
│       │   │
│       │   ├── Nfe/                           ← NOVO — actions específicas NF-e
│       │   │   ├── SendNfeAction.php          ← mover de Actions/
│       │   │   ├── BuildNfePayloadAction.php  ← mover de Actions/
│       │   │   ├── ReserveNfeNumberAction.php ← mover de Actions/
│       │   │   ├── ConsultNfeAction.php       ← mover de Actions/
│       │   │   ├── PrintNfeDanfeAction.php    ← mover de Actions/
│       │   │   └── PrintNfePreviewAction.php  ← mover de Actions/
│       │   │
│       │   └── Nfse/                          ← FUTURO — actions específicas NFS-e
│       │       ├── SendNfseAction.php
│       │       ├── BuildNfsePayloadAction.php
│       │       └── ...
│       │
│       └── Validators/
│           ├── FiscalDocumentValidatorResolver.php  ← NOVO — resolve validator por modelo
│           ├── FiscalDocumentValidator.php   ← existente (refatorar p/ regras comuns)
│           ├── NfeDocumentValidator.php      ← NOVO — regras cabeçalho NF-e
│           ├── NfseDocumentValidator.php     ← FUTURO
│           └── Items/
│               ├── NfeItemValidator.php      ← NOVO — regras item NF-e
│               └── NfseItemValidator.php     ← FUTURO
│
├── Models/
│   ├── FiscalDocument.php         ← existente (adicionar campo document_type como enum)
│   └── FiscalDocumentItem.php     ← existente
│
└── Jobs/
    ├── SendNfeJob.php             ← existente
    └── ConsultNfeJob.php          ← existente
```

### 12.6. Resumo das Etapas de Implementação

| #   | Etapa                                         | Descrição                                                        | Prioridade |
| --- | --------------------------------------------- | ---------------------------------------------------------------- | ---------- |
| 1   | Enum `DocumentModel`                          | Criar enum nfe/nfse + migration para `document_type`             | Alta       |
| 2   | `NfeDocumentValidator`                        | Extrair regras específicas de NF-e do validator atual            | Alta       |
| 3   | `NfeItemValidator`                            | Criar validação obrigatória dos itens NF-e (NCM, CFOP, impostos) | Alta       |
| 4   | `FiscalDocumentValidatorResolver`             | Resolver o validator correto por `document_type`                 | Alta       |
| 5   | Refatorar `CreateFiscalDocumentAction`        | Usar `ValidatorResolver` em vez do validator genérico            | Alta       |
| 6   | Refatorar `UpdateFiscalDocumentAction`        | Idem                                                             | Alta       |
| 7   | Mover actions NF-e para `Actions/Nfe/`        | Organizar por subdiretório                                       | Média      |
| 8   | `ImportInvoiceItemsAction`                    | Importar itens da fatura para o documento fiscal                 | Alta       |
| 9   | `FiscalDocumentService::createFromInvoice()`  | Novo método para criar doc fiscal a partir de fatura             | Alta       |
| 10  | Adaptar Filament Form                         | Adicionar select de `document_type`, condicionar campos          | Média      |
| 11  | `NfseDocumentValidator` + `NfseItemValidator` | Regras específicas NFS-e                                         | Futuro     |
| 12  | `NfseDocumentService` + Actions NFS-e         | Serviço e actions para NFS-e                                     | Futuro     |

---

### 12.7. Regras de Negócio dos Itens

#### Para itens de NF-e (produto):

1. `product_id` obrigatório — todo item NF-e deve ter um produto vinculado
2. `ncm_code` obrigatório (8 dígitos) — classificação fiscal
3. `cfop_code` obrigatório (4 dígitos) — código fiscal de operação
4. `origin_code` obrigatório — origem da mercadoria (tabela SEFAZ)
5. `tax_data.imposto.icms` obrigatório — ao menos `situacao_tributaria`
6. `tax_data.imposto.pis` obrigatório — ao menos `situacao_tributaria`
7. `tax_data.imposto.cofins` obrigatório — ao menos `situacao_tributaria`
8. `quantity > 0` e `unit_price >= 0`
9. `total_price = quantity * unit_price` (consistência)

#### Para itens de NFS-e (serviço) — futuro:

1. `service_id` obrigatório — todo item NFS-e deve ter um serviço vinculado
2. `codigo_servico` obrigatório — código do serviço municipal (LC 116)
3. `aliquota_iss` obrigatória
4. Não exige NCM, CFOP, ICMS
5. Exige PIS e COFINS

#### Importação da fatura — regras:

1. Itens de **Requisição** → geram itens NF-e (são produtos)
2. Itens de **Ordem de Produção** → geram itens NF-e (são produtos acabados)
3. Itens de **Ordem de Serviço** → podem gerar itens NF-e (produtos/peças) e NFS-e (mão de obra/serviço)
4. Se fatura tem produtos E serviços → gerar NF-e + NFS-e separadamente
5. NCM e CFOP devem vir do cadastro do produto (campos a serem adicionados ao model `Product` se não existirem)
6. Dados tributários podem ter defaults por produto/operação (simplifica preenchimento)
