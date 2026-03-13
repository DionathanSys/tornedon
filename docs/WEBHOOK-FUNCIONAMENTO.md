# Sistema de Webhooks — Funcionamento Completo

## 📋 Visão Geral

O sistema de webhooks do Tornedon recebe notificações da API **IntegraNotas** após o processamento de documentos fiscais (NF-e e NFS-e). Este documento detalha como funciona a integração, o fluxo de dados, validações e tratamento de erros.

---

## 🔄 Ciclo de Vida Completo

### 1️⃣ Envio do Documento Fiscal

```
[Usuário clica "Emitir"]
    ↓
[FiscalDocument criado com status PENDING]
    ↓
[Dispatch SendNfeJob / SendNfseJob]
    ↓
[Ação de envio à API IntegraNotas]
    ↓
[FiscalDocument.nfe_status = IN_PROCESSING]
    ↓
[Dispatch ConsultNfeJob/ConsultNfseJob com delay 15s]
```

**Campos atualizados no envio:**
- `document_key`: Chave de acesso gerada pela SEFAZ
- `nfe_status` / `nfse_status`: Muda para `IN_PROCESSING`
- `nfe_environment`: 1 (produção) ou 2 (homologação)
- `nfe_payload` / `nfse_payload`: Cópia do payload enviado (auditoria)

---

### 2️⃣ Webhook — Notificação da IntegraNotas

#### **Endpoints disponíveis:**
```
POST /webhook/nfe
```

**Nota:** Consolida NF-e e NFS-e. O controller detecta automaticamente o tipo pelo `document_type`.

#### **Estrutura do Payload**

##### ✅ Notificação de Teste
```json
{
    "origem": "TESTE",
    "cnpj_cpf": "28586684000174",
    "signature": "hash_configurado"
}
```

##### ✅ Notificação de Sucesso (Autorizado)
```json
{
    "origem": "RPS",
    "chave": "3534340102086680000131550010000012341234567890",
    "status": "autorizado",
    "protocolo": "134190001234567890",
    "numero": "1",
    "serie": "1",
    "signature": "hash_configurado",
    "cnpj_cpf": "28586684000174"
}
```

##### ❌ Notificação de Rejeição
```json
{
    "origem": "RPS",
    "chave": "3534340102086680000131550010000012341234567890",
    "status": null,
    "codigo": "5002",
    "mensagem": "Validação: Lote com ID duplicado",
    "signature": "hash_configurado",
    "cnpj_cpf": "28586684000174"
}
```

##### 🔄 Notificação de Cancelamento
```json
{
    "origem": "RPS",
    "chave": "3534340102086680000131550010000012341234567890",
    "status": "cancelado",
    "signature": "hash_configurado",
    "cnpj_cpf": "28586684000174"
}
```

---

## 📥 Processamento do Webhook

### **1. Recepção (NfeWebhookController::handle)**

```
POST /webhook/nfe
    ↓
[Log do payload]
    ↓
[Verifica origem = "TESTE"?]
    ├─ Sim: Return 200 (teste de comunicação)
    └─ Não: Continua...
    ↓
[Valida assinatura (signature)]
    ├─ Inválida: Log warning, Return 200
    └─ Válida: Continua...
    ↓
[Localiza FiscalDocument pela chave]
    ├─ Não encontrado: Log warning, Return 200
    └─ Encontrado: Processa retorno...
```

### **2. Validação de Assinatura**

```php
$secret = $configService->resolveWebhookSecret($doc->company_id);
if ($secret && $payload['signature'] !== $secret) {
    Log::warning('Assinatura inválida');
    return 200; // Sempre HTTP 200
}
```

**Configuração:**
- Localização: Configurações → NF-e Settings → Webhook → Assinatura do Webhook
- Armazenado em: `CompanyPreference.integranotas.webhook_secret`
- Se vazio: validação desabilitada

### **3. Processamento de Status**

#### **Status: "autorizado"**

```php
$doc->update([
    'nfe_status'    => NfeStatus::AUTHORIZED,
    'nfse_status'   => NfeStatus::AUTHORIZED,  // ambos suportados
    'nfe_protocol'  => $payload['protocolo'],
    'status'        => Status::CONFIRMED,
    'confirmed_at'  => now(),
    'document_number' => $payload['numero'] ?? existing,
    'document_series' => $payload['serie'] ?? existing,
]);
```

**Ações subsequentes:**
1. Log de sucesso
2. **Gera contas a receber** (AccountReceivableGenerationService)
3. **Envia email** ao cliente (opcional - CustomerDocumentEmailService)

#### **Status: "cancelado"**

```php
$doc->update([
    'nfe_status'  => NfeStatus::CANCELED,
    'status'      => Status::CANCELLED,
    'canceled_at' => now(),
]);
```

#### **Status: null ou inexistente (Rejeição)**

```php
$doc->update([
    'nfe_status' => NfeStatus::REJECTED,
    'status'     => Status::CANCELLED,
]);

// Adiciona erro ao histórico
$errors = $doc->errors_messages ?? [];
$errors[] = [
    'at'       => now(),
    'origem'   => 'webhook',
    'codigo'   => $payload['codigo'],
    'mensagem' => $payload['mensagem'],
];
$doc->errors_messages = $errors;
```

---

## 🔁 Fallback: Polling (ConsultNfeJob / ConsultNfseJob)

Se o webhook não chegar em tempo real, existe um sistema de polling como **fallback**:

### **Fluxo de Polling**

```
[SendNfseAction/SendNfeAction envia e guarda chave]
    ↓
[Status = IN_PROCESSING]
    ↓
[Dispatch ConsultNfseJob/ConsultNfeJob com delay 15s]
    ↓
[Job tenta MAX_POLLING_ATTEMPTS = 5 vezes]
    ├─ Tentativa 1: Delay 15s
    ├─ Tentativa 2: Delay 30s
    ├─ Tentativa 3: Delay 45s
    ├─ Tentativa 4: Delay 60s
    └─ Tentativa 5: Delay final
    ↓
[Se webhook chegou antes, job cancela (status ≠ IN_PROCESSING)]
[Se webhook não chegou e tem retorno, atualiza]
[Se esgota tentativas, aguarda webhook]
```

**Código do job:**
```php
// Se webhook já atualizou, não consultar
if ($doc->nfe_status !== NfeStatus::IN_PROCESSING) {
    Log::info('Status já atualizado via webhook');
    return;
}

$action->execute($doc); // Consulta API

// Se ainda em processamento e não atingiu limite, reagendar
if ($doc->nfse_status === NfeStatus::IN_PROCESSING && $tentativa < MAX_POLLING_ATTEMPTS) {
    dispatch(new self(...))
        ->delay(now()->addSeconds($delay));
}
```

---

## ⚙️ Configurações Necessárias

### **No Painel Tornedon**

#### Configurações → NF-e Settings

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| **Ambiente Ativo** | 1 = Produção, 2 = Homologação | 2 (desenvolvimento) |
| **Série Padrão NF-e** | Série utilizada | 1 |
| **Série Padrão NFS-e (RPS)** | Série do RPS | 1 |
| **Token — Homologação** | JWT da IntegraNotas (hom) | `eyJ0eXA...` |
| **Token — Produção** | JWT da IntegraNotas (prod) | `eyJ0eXA...` |
| **Assinatura do Webhook** | Hash para validação | `minha_chave_secreta` |
| **URL do Webhook** | Endpoint (leitura) | `https://seu-dominio.com/webhook/nfe` |

### **No Painel da IntegraNotas**

1. Acessar **gestao.integranotas.com.br** (produção) ou **hom-gestao.integranotas.com.br** (homologação)
2. Menu: **Emitente → Webhook**
3. Configurar:
   - **URL:** `https://seu-dominio.com/webhook/nfe`
   - **Assinatura:** Mesmo valor configurado em Tornedon
   - **Teste de comunicação:** Clica para enviar notificação de teste

---

## 📊 Estrutura de Banco de Dados

### **Tabela: fiscal_documents**

```sql
CREATE TABLE fiscal_documents (
    id BIGINT PRIMARY KEY,
    
    -- Identificação
    company_id BIGINT,
    customer_id BIGINT,
    invoice_id BIGINT,
    
    -- Status NF-e
    nfe_status ENUM('rascunho', 'processando', 'autorizado', 'cancelado', 'rejeitado'),
    nfe_ambiente TINYINT,           -- 1 ou 2
    nfe_protocolo VARCHAR(255),     -- Protocolo da SEFAZ
    nfe_payload JSON,               -- Payload enviado (auditoria)
    nfe_sequence_id BIGINT FK,      -- Referência de numeração
    
    -- Status NFS-e
    nfse_status ENUM(...),
    nfse_protocol VARCHAR(255),
    nfse_payload JSON,
    nfse_sequence_id BIGINT FK,
    
    -- Identificação do documento
    document_key VARCHAR(255),      -- Chave de acesso (procurado no webhook)
    document_number VARCHAR(20),    -- Número da NF/RPS
    document_series VARCHAR(5),     -- Série
    
    -- Rastreamento
    document_type ENUM('nfe', 'nfse'),
    status ENUM('pending', 'confirmed', 'cancelled'),
    confirmed_at TIMESTAMP NULL,
    canceled_at TIMESTAMP NULL,
    
    -- Erros
    errors_messages JSON,           -- [{ at, origem, codigo, mensagem }]
    
    UNIQUE KEY uix_document_key (document_key),
    INDEX idx_company (company_id),
    INDEX idx_invoice (invoice_id),
);
```

---

## 🔐 Fluxo de Segurança

### **1. Validação de Origem**

- **Teste:** `origem === "TESTE"` → Log + Return 200 imediatamente
- **Produção:** `origem === "RPS"` (NFS-e) ou outro → Processa normalmente

### **2. Validação de Assinatura**

```php
$secret = CompanyPreference::get('integranotas.webhook_secret', $companyId);

if ($secret && $payload['signature'] !== $secret) {
    // Rejeita silenciosamente para não alertar API
    return response()->json(['ok' => true]);
}
```

### **3. Lookup Seguro**

```php
$doc = FiscalDocument::where('document_key', $chave)->first();

if (!$doc) {
    Log::warning('Documento não encontrado');
    return 200; // Sempre 200 para não reagendar na API
}
```

---

## 🚨 Tratamento de Erros

### **Sempre HTTP 200**

```php
// Mesmo em erros, sempre retorna 200
try {
    // Processa webhook
} catch (Exception $e) {
    Log::error('Erro ao processar webhook', [...]);
}
return response()->json(['ok' => true]); // HTTP 200
```

**Por quê?**
- A IntegraNotas só reagenda em respostas 2xx (sucesso)
- Erros HTTP 4xx/5xx causam reenvios desnecessários
- Todos os erros são logados para análise

### **Retry da API**

- Máximo: 3 tentativas
- Intervalo: Automático pela IntegraNotas
- Logs: Disponíveis em "Gestão" na IntegraNotas

---

## 📋 Logs Importantes

### **Locais de Log**

```
storage/logs/laravel-YYYY-MM-DD.log
```

### **Estrutura de Log**

```
[2026-03-13 14:32:15] INFO NfeWebhookController: payload recebido
[chave] => 3534340102086680000131550010000012341234567890
[origem] => RPS

[2026-03-13 14:32:15] INFO NfeWebhookController: NF-e autorizada via webhook
[fiscal_document_id] => 42
[protocolo] => 134190001234567890

[2026-03-13 14:32:15] INFO AccountReceivableGenerationService: contas a receber geradas
[invoice_id] => 10
[fiscal_document_id] => 42
[installments] => 3
```

---

## 🔍 Checklist de Configuração

- [ ] Token JWT configurado em Tornedon (ambiente ativo)
- [ ] Assinatura do webhook configurada e documentada
- [ ] URL do webhook registrada na IntegraNotas
- [ ] Teste de comunicação ("TESTE") funcionando
- [ ] Logs sendo gerados corretamente
- [ ] Email de confirmação enviado após autorização (opcional)
- [ ] Contas a receber geradas automaticamente
- [ ] Polling funcionando como fallback

---

## 📞 Suporte IntegraNotas

- **API Status:** https://integranotas.com.br/doc/sefaz/monitor
- **Documentação:** https://integranotas.com.br/doc/webhook
- **WhatsApp:** https://api.whatsapp.com/send?phone=5546999243891

---

## 🔄 Fluxo Completo — Diagrama

```
┌─────────────────────────────────────────────────────────────────┐
│                   USUÁRIO EMITE DOCUMENTO                        │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
        ┌─────────────────────────────────────┐
        │  1. SendNfseJob / SendNfeJob        │
        │     - Reserva número                │
        │     - Monta payload                 │
        │     - Envia à API                   │
        └────────────┬────────────────────────┘
                     │
                     ▼
        ┌─────────────────────────────────────┐
        │  FiscalDocument.status              │
        │  = IN_PROCESSING                    │
        │  + Salva document_key               │
        └──────────┬──────────────────────────┘
                   │
           ┌───────┴────────┐
           │                │
           ▼ (Primário)     ▼ (Fallback)
       ┌─────────────┐  ┌──────────────────┐
       │   WEBHOOK   │  │ ConsultNfseJob   │
       │ Notification│  │ (Polling 15s)    │
       │   arrives   │  │ Max 5 tentativas │
       └────┬────────┘  └────┬─────────────┘
           │                 │
           └─────────┬───────┘
                     │
                     ▼
        ┌─────────────────────────────────────┐
        │  NfeWebhookController::handle       │
        │  - Valida assinatura                │
        │  - Localiza documento               │
        │  - Processa status                  │
        └────────────┬────────────────────────┘
                     │
           ┌─────────┼─────────┐
           │         │         │
    ┌──────▼───┐ ┌───▼──────┐ ┌──▼────────┐
    │Autorizado│ │Cancelado │ │Rejeitado  │
    └──────┬───┘ └───┬──────┘ └──┬───────┘
           │         │           │
           ▼         ▼           ▼
    ┌─────────────────────────────────────┐
    │ FiscalDocument.status = CONFIRMED   │
    │                    ou CANCELLED     │
    │ (+ protocol, number, series, etc)   │
    └────────┬────────────────────────────┘
             │
             ▼
    ┌─────────────────────────────────────┐
    │  AccountReceivableGeneration        │
    │  (se autorizado)                    │
    │  - Cria parcelas                    │
    │  - Linka com Invoice                │
    └────────┬────────────────────────────┘
             │
             ▼
    ┌─────────────────────────────────────┐
    │  Email enviado ao cliente           │
    │  (Notificação de autorização)       │
    └─────────────────────────────────────┘
```

---

**Versão do Documento:** 1.0  
**Data:** 13 de março de 2026  
**Status:** ✅ Atual
