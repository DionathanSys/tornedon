# Documentação — Documentos Fiscais

> Última atualização: Março 2026

---

## Sumário

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura de Camadas](#2-arquitetura-de-camadas)
3. [Onde as Informações são Armazenadas](#3-onde-as-informações-são-armazenadas)
4. [Como as Informações são Obtidas](#4-como-as-informações-são-obtidas)
5. [Motor de Decisão Fiscal (Cálculo de Impostos)](#5-motor-de-decisão-fiscal-cálculo-de-impostos)
6. [Fluxo Completo de Emissão](#6-fluxo-completo-de-emissão)
7. [Fluxo de Resposta: Webhook e Polling](#7-fluxo-de-resposta-webhook-e-polling)
8. [Quem Consome Cada Informação](#8-quem-consome-cada-informação)
9. [Integração IntegraNotas (SEFAZ)](#9-integração-integranotas-sefaz)
10. [Vínculo Fatura → Documento Fiscal](#10-vínculo-fatura--documento-fiscal)
11. [Configurações e Parâmetros](#11-configurações-e-parâmetros)
12. [Enums e Domínios Fiscais](#12-enums-e-domínios-fiscais)
13. [NFS-e — Estado Atual e Roadmap](#13-nfs-e--estado-atual-e-roadmap)

---

## 1. Visão Geral

O módulo fiscal é responsável pelo ciclo completo dos documentos eletrônicos emitidos pela empresa: criação, cálculo tributário, envio à SEFAZ, recepção de resposta e armazenamento do resultado.

O ciclo de uma NF-e, do início ao fim:

```
Fatura / Entrada Manual
        ↓
  Criar Documento Fiscal (cabeçalho + itens)
        ↓
  Resolver Contexto Fiscal (por item)
        ↓
  Motor de Decisão: FiscalRule > ProductTax > Regime Padrão
        ↓
  Persistir Snapshot Imutável por Item
        ↓
  Emitir (assíncrono via Job)
        ↓
  Reservar Número → Montar Payload → Enviar via IntegraNotas → SEFAZ
        ↓
  Resposta: Webhook (primário) ou Polling (fallback)
        ↓
  NF-e Autorizada / Rejeitada
```

**Integrações externas:**
- **IntegraNotas** — intermediário entre o sistema e a SEFAZ. SDK: `cloud-dfe/sdk-php`
- **SEFAZ** — Secretaria da Fazenda estadual, autoriza a NF-e

---

## 2. Arquitetura de Camadas

O módulo segue o padrão geral da aplicação:

```
Filament (UI / Gatilho)
    ↓
Service (orquestração do fluxo)
    ↓
Action (regra de negócio isolada e testável)
    ↓
Model (acesso a dados)
```

### Mapa de classes por camada

| Camada | Classe | Responsabilidade |
|--------|--------|-----------------|
| **UI** | `FiscalDocumentResource` (Sales / Financial) | Listagem, criação e edição de documentos |
| **UI** | `GenerateFiscalDocumentAction` | Botão "Gerar NF-e" na página de Invoice |
| **UI** | `NfeSettingsPage` | Configuração do token IntegraNotas |
| **UI** | `FiscalProfileSettingsPage` | Configuração dos defaults tributários |
| **UI** | `FiscalRulesPage` | Gerenciamento de regras de override fiscal |
| **Service** | `FiscalDocumentService` | CRUD do cabeçalho do documento |
| **Service** | `FiscalDocumentItemService` | CRUD + criação em lote dos itens |
| **Service** | `NfeDocumentService` | Ciclo NF-e: emitir, consultar, DANFE, preview |
| **Service** | `FiscalDecisionService` | Motor de decisão: resolve CFOP, CST e alíquotas por item |
| **Service** | `NfeConfigService` | Lê token/ambiente/série por empresa |
| **Action** | `ResolveFiscalContextAction` | Constrói o `FiscalContextDTO` por item e aciona o motor |
| **Action** | `PersistFiscalSnapshotAction` | Salva o resultado da decisão como snapshot imutável |
| **Action** | `SendNfeAction` | Reserva número → monta payload → envia via SDK |
| **Action** | `BuildNfePayloadAction` | Monta o JSON completo para a API IntegraNotas |
| **Action** | `ReserveNfeNumberAction` | Reserva número atômico via `SELECT FOR UPDATE` |
| **Action** | `ConsultNfeAction` | Consulta status da NF-e na SEFAZ |
| **Action** | `PrintNfeDanfeAction` | Solicita DANFE (PDF base64) da API |
| **Action** | `PrintNfePreviewAction` | Gera preview (PDF + XML) sem enviar à SEFAZ |
| **Job** | `SendNfeJob` | Executa o envio de forma assíncrona (3 retries) |
| **Job** | `ConsultNfeJob` | Polling de fallback (5 tentativas com backoff) |
| **Controller** | `NfeWebhookController` | Recebe callback POST da IntegraNotas |

---

## 3. Onde as Informações são Armazenadas

### 3.1. Tabela `fiscal_documents` — Cabeçalho do Documento

Arquivo: `app/Models/FiscalDocument.php`

Armazena os dados de identificação, status e resultado de cada documento fiscal emitido.

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `company_id` | FK | Empresa emitente |
| `customer_id` | FK | Cliente/destinatário |
| `invoice_id` | FK (nullable) | Fatura de origem (opcional) |
| `nfe_sequence_id` | FK | Qual sequenciador foi usado |
| `fiscal_profile_version_id` | FK | Versão do perfil fiscal ativa no momento da emissão |
| `document_type` | enum | `nfe` ou `nfse` |
| `document_number` | string | Número da nota (reservado atomicamente) |
| `document_series` | string | Série da nota |
| `document_key` | string(44) | Chave de acesso SEFAZ (preenchida após envio) |
| `status` | enum | `PENDING` / `CONFIRMED` / `CANCELLED` |
| `nfe_status` | enum | `pending` / `in_processing` / `authorized` / `rejected` / `canceled` |
| `operation_type` | enum | `0` = entrada, `1` = saída |
| `operation_nature` | string | Natureza da operação (ex: "VENDA DENTRO DO ESTADO") |
| `issue_purpose` | enum | Normal, Complementar, Ajuste, Devolução |
| `is_final_consumer` | boolean | Indica consumidor final |
| `buyer_presence_indicator` | enum | Presença do comprador |
| `issued_at` | datetime | Data de emissão |
| `movement_at` | datetime | Data de entrada/saída |
| `confirmed_at` | datetime | Quando a SEFAZ autorizou |
| `canceled_at` | datetime | Quando foi cancelada |
| `nfe_ambiente` | int | `1` = produção, `2` = homologação |
| `nfe_protocolo` | string | Protocolo de autorização SEFAZ |
| `nfe_payload` | JSON | Payload exato enviado à API (para debug/reenvio) |
| `tax_regime_used` | string | Regime tributário vigente na emissão |
| `freight_data` | JSON | Modalidade de frete, volumes, peso, transportadora |
| `payment_data` | JSON | Formas de pagamento e valores |
| `tax_data` | JSON | Totais tributários calculados (soma dos itens) |
| `errors_messages` | JSON array | Erros acumulados de tentativas de envio |
| `logs` | JSON array | Histórico de operações (debug) |

**Índice único:** `(document_number, document_series, company_id)` — impede número duplicado.

---

### 3.2. Tabela `fiscal_document_items` — Itens do Documento

Arquivo: `app/Models/FiscalDocumentItem.php`

Cada item da nota fiscal possui seus próprios dados tributários calculados e congelados no momento da emissão.

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `fiscal_document_id` | FK | Documento pai |
| `product_id` | FK (nullable) | Produto vinculado |
| `service_id` | FK (nullable) | Serviço vinculado (NFS-e) |
| `product_code` | string | Código interno do produto |
| `item_number` | int | Número sequencial do item na nota |
| `product_origin` | int | Origem da mercadoria (0–8, tabela SEFAZ) |
| `ncm_code` | string(8) | Código NCM (Nomenclatura Comum do Mercosul) |
| `cest_code` | string (nullable) | CEST (Substituição Tributária) |
| `cfop_code` | string(4) | CFOP determinado pelo motor de decisão |
| `barcode` | string | Código de barras (EAN) |
| `quantity` | decimal | Quantidade |
| `unit_of_measure` | string | Unidade comercial (UN, KG, m², etc.) |
| `unit_price` | Money | Valor unitário |
| `total_price` | Money | Valor total do item |
| `taxable_unit` | string | Unidade tributável (pode diferir da comercial) |
| `taxable_quantity` | decimal | Quantidade tributável |
| `taxable_unit_price` | Money | Valor unitário tributável |
| `discount_amount` | Money | Desconto |
| `freight_amount` | Money | Frete rateado |
| `insurance_amount` | Money | Seguro |
| `other_expenses_amount` | Money | Outras despesas acessórias |
| `fiscal_rule_id` | FK (nullable) | ID da regra fiscal aplicada (rastreabilidade) |
| `fiscal_rule_version` | int (nullable) | Versão da regra no momento da aplicação |
| `tax_data` | JSON | **Impostos calculados por item** (ver abaixo) |
| `fiscal_snapshot` | JSON | **Snapshot imutável da decisão fiscal** (ver abaixo) |

**Estrutura de `tax_data` (calculado):**

```json
{
  "imposto": {
    "icms": {
      "situacao_tributaria": "101",
      "modalidade_base_calculo": "3",
      "aliquota": 12,
      "base_calculo": 224.50,
      "valor": 26.94,
      "valor_st": 0,
      "base_calculo_st": 0
    },
    "pis": {
      "situacao_tributaria": "01",
      "aliquota": 0.65,
      "base_calculo": 224.50,
      "valor": 1.46
    },
    "cofins": {
      "situacao_tributaria": "01",
      "aliquota": 3.00,
      "base_calculo": 224.50,
      "valor": 6.74
    },
    "ipi": {
      "situacao_tributaria": "50",
      "aliquota": 0,
      "base_calculo": 0,
      "valor": 0
    }
  }
}
```

**Estrutura de `fiscal_snapshot` (imutável — decisão congelada):**

```json
{
  "source": "fiscal_rule",
  "rule_id": 3,
  "rule_version": 2,
  "profile_version_id": 7,
  "tax_regime": "simples_nacional",
  "cfop": "5102",
  "cst_icms": null,
  "csosn": "101",
  "aliquota_icms": 12,
  "reducao_base_icms": 0,
  "aliquota_st": 0,
  "aliquota_mva_st": 0,
  "cst_pis": "49",
  "aliquota_pis": 0.65,
  "cst_cofins": "49",
  "aliquota_cofins": 3.00,
  "cst_ipi": "50",
  "aliquota_ipi": 0,
  "resolved_at": "2026-03-09T14:23:11Z"
}
```

> O `fiscal_snapshot` não muda com o tempo. Mesmo que regras ou perfis sejam atualizados, o documento já emitido sempre reflete as condições tributárias do momento exato da emissão.

---

### 3.3. Tabela `fiscal_profiles` — Identidade Fiscal da Empresa

Arquivo: `app/Models/FiscalProfile.php`

Uma entrada por empresa. Define o regime tributário e o CNAE principal.

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `company_id` | FK (unique) | Empresa dona do perfil |
| `tax_regime` | enum | `mei` / `simples_nacional` / `lucro_presumido` / `lucro_real` |
| `cnae_principal` | string | CNAE principal da empresa |
| `is_active` | boolean | Se o perfil está ativo |

---

### 3.4. Tabela `fiscal_profile_versions` — Parâmetros Tributários Versionados

Arquivo: `app/Models/FiscalProfileVersion.php`

Cada vez que o perfil fiscal é salvo, uma nova versão é criada. Versões antigas são arquivadas com `valid_to`. Documentos fiscais referenciam a versão exata que estava ativa no momento da emissão.

| Grupo | Colunas | O que armazena |
|-------|---------|---------------|
| Controle | `version`, `valid_from`, `valid_to`, `status` | Ciclo de vida da versão |
| ICMS | `icms_cst_default`, `icms_csosn_default`, `icms_aliquota_interna` | Defaults ICMS para o regime |
| ICMS | `icms_reducao_base`, `icms_modalidade_base_calculo` | Ajustes de base de cálculo |
| ICMS-ST | `icms_st_aliquota`, `icms_st_mva`, `icms_st_reducao_base` | Substituição Tributária |
| Interestad. | `icms_aliquotas_interestaduais` | JSON: `{"SP": 12, "RJ": 12, "ES": 7}` |
| PIS | `pis_cst_default`, `pis_aliquota_default` | Default PIS por regime |
| COFINS | `cofins_cst_default`, `cofins_aliquota_default` | Default COFINS por regime |
| IPI | `ipi_cst_default`, `ipi_aliquota_default`, `ipi_enquadramento` | Default IPI |
| CFOP | `cfop_rules` | JSON: mapa `OperationNature → CFOP` |
| Extras | `informacoes_complementares_padrao`, `ruleset_checksum` | Info fiscal padrão + hash das regras |

---

### 3.5. Tabela `fiscal_rules` — Regras de Override Fiscal

Arquivo: `app/Models/FiscalRule.php`

Regras com avaliação condicional que sobrepõem qualquer default de regime ou produto. Têm validade temporal e prioridade.

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `fiscal_profile_version_id` | FK | Versão do perfil a que pertencem |
| `name` | string | Nome descritivo da regra |
| `operation_type` | enum | ENTRADA ou SAIDA |
| `priority` | int | Ordem de avaliação (menor = maior prioridade) |
| `conditions` | JSON | Condições de match (AND; array = OR) |
| `result` | JSON | CFOP e alíquotas a aplicar quando a regra bate |
| `valid_from` | date | Início da vigência |
| `valid_to` | date (nullable) | Fim da vigência (null = sem fim) |
| `is_enabled` | boolean | Ativa/inativa |

**Exemplo de `conditions`:**

```json
{
  "ncm_code_prefix": "5511",
  "customer_state": ["SP", "RJ"],
  "is_final_consumer": true
}
```

**Exemplo de `result`:**

```json
{
  "cfop": "6102",
  "csosn": "103",
  "aliquota_icms": 12,
  "cst_pis": "07",
  "aliquota_pis": 0,
  "cst_cofins": "07",
  "aliquota_cofins": 0
}
```

---

### 3.6. Tabela `nfe_sequences` — Sequenciador de Número da Nota

Arquivo: `app/Models/NfeSequence.php`

Controla o próximo número disponível por empresa, série e natureza de operação.

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `company_id` | FK | Empresa |
| `serie` | string | Série da nota (ex: "1") |
| `operation_nature` | string | Natureza da operação |
| `last_number` | int | Último número emitido |

**Índice único:** `(company_id, serie, operation_nature)`

O incremento é feito com `DB::transaction()` + `lockForUpdate()` — garante que dois envios simultâneos nunca gerem o mesmo número.

---

### 3.7. Tabela `product_taxes` — Configuração Fiscal por Produto

Arquivo: `app/Models/ProductTax.php`

Dados tributários específicos por produto. Usados como segunda prioridade no motor de decisão (abaixo das regras, acima do fallback de regime).

| Coluna | Tipo | O que armazena |
|--------|------|---------------|
| `product_id` | FK (unique) | Produto |
| `product_origin` | int | Origem (0–8, tabela SEFAZ) |
| `ncm_code` | string(8) | Código NCM |
| `cest_code` | string (nullable) | CEST |
| `icms` | JSON | `{situacao_tributaria, aliquota, ...}` |
| `ipi` | JSON | `{situacao_tributaria, aliquota, enquadramento}` |
| `pis` | JSON | `{situacao_tributaria, aliquota}` |
| `cofins` | JSON | `{situacao_tributaria, aliquota}` |

---

### 3.8. `company_preferences` — Configurações IntegraNotas

Não é uma tabela dedicada — as configurações são armazenadas na tabela genérica `company_preferences` sob namespace `integranotas.*`.

| Chave | O que armazena |
|-------|---------------|
| `integranotas.token_producao` | JWT para o ambiente de produção |
| `integranotas.token_homologacao` | JWT para o ambiente de homologação |
| `integranotas.ambiente` | `1` = produção, `2` = homologação |
| `integranotas.serie_padrao` | Série padrão a usar nas notas |
| `integranotas.webhook_secret` | Segredo HMAC para validar callbacks |

---

## 4. Como as Informações são Obtidas

Cada informação necessária para emitir uma NF-e tem uma origem específica. O `BuildNfePayloadAction` é responsável por consolidar tudo em um único payload.

### 4.1. Dados do Emitente (Empresa)

**Origem:** Model `Company` + `company_preferences`

```
Company
├── cnpj, razao_social, nome_fantasia
├── inscricao_estadual, inscricao_municipal, cnae
├── regime_tributario (via FiscalProfile)
└── endereco → CompanyAddress (logradouro, bairro, municipio, uf, cep, pais)

CompanyPreference
└── integranotas.token_*, integranotas.ambiente, integranotas.serie_padrao
```

O `NfeConfigService` resolve token e ambiente por empresa chamando `CompanyPreference`.

### 4.2. Dados do Destinatário (Cliente)

**Origem:** Model `Partner` (customer) + endereço + indicador de IE

```
Partner (customer)
├── cpf ou cnpj
├── nome / razao_social
├── inscricao_estadual
├── state_tax_indicator (contribuinte / isento / não contribuinte)
└── address → PartnerAddress (logradouro, bairro, municipio, uf, cep, pais)
```

O `BuildNfePayloadAction` lê `$document->customer` com eager loading do endereço.

### 4.3. Dados dos Itens

**Origem:** `FiscalDocumentItem` + `Product` (eager loaded)

```
FiscalDocumentItem
├── ncm_code, cfop_code, product_origin, barcode
├── quantity, unit_price, total_price, unit_of_measure
├── discount, freight, insurance, other_expenses
└── tax_data → { imposto: { icms, pis, cofins, ipi } }

Product (via product_id)
├── codigo_produto, descricao
└── barcode (fallback)
```

O `tax_data` do item já contém os impostos no formato exato da API — resultado do motor de decisão.

### 4.4. Dados Tributários — Origem no Motor de Decisão

Para cada item, o contexto fiscal é construído por `ResolveFiscalContextAction`:

```
FiscalContextDTO (entrada do motor)
├── ncm_code          → do FiscalDocumentItem
├── cfop_code         → inferido a partir de operation_nature + estado destino
├── product_origin    → do FiscalDocumentItem
├── customer_state    → do customer.address.state
├── operation_nature  → do FiscalDocument
├── operation_type    → do FiscalDocument (entrada/saída)
├── is_final_consumer → do FiscalDocument
├── is_interestadual  → calculado: estado emitente ≠ estado cliente
├── fiscal_profile_version_id → da versão ativa do FiscalProfile
└── issued_at         → do FiscalDocument (data de emissão)
```

Com esse contexto o motor (`FiscalDecisionService`) resolve todos os dados tributários (ver seção 5).

### 4.5. Dados de Configuração (Número, Série, Ambiente)

**Origem:** `NfeConfigService` + `NfeSequence`

```
NfeConfigService::resolveSerie($companyId)
    → lê CompanyPreference('integranotas.serie_padrao') → padrão "1"

NfeSequence::nextNumber($companyId, $serie, $operationNature)
    → incrementa last_number atomicamente
    → retorna número reservado

NfeConfigService::buildSdkParams($companyId)
    → token + ambiente → instancia CloudDfe\SdkPHP\Nfe
```

### 4.6. Dados de Frete e Pagamento

**Origem:** campos `freight_data` e `payment_data` do `FiscalDocument` (JSON)

São preenchidos pelo usuário via formulário Filament ou importados da fatura quando o documento é gerado via `GenerateFiscalDocumentAction`.

---

## 5. Motor de Decisão Fiscal (Cálculo de Impostos)

Arquivo: `app/Services/Fiscal/FiscalDecisionService.php`

O motor resolve, para **cada item** individualmente, qual CFOP aplicar e quais CST/alíquotas usar para ICMS, PIS, COFINS e IPI. O resultado é um `FiscalDecisionDTO`.

### 5.1. Hierarquia de Prioridade (3 níveis)

```
┌──────────────────────────────────────────────────────────┐
│  Nível 1 — FiscalRule (MAIOR PRIORIDADE)                 │
│  Regras customizadas com condições e vigência temporal   │
│  Gerenciadas em: Configurações → Regras Fiscais          │
├──────────────────────────────────────────────────────────┤
│  Nível 2 — ProductTax                                    │
│  Dados tributários específicos por produto               │
│  Gerenciados em: Cadastro de Produtos → Dados Fiscais    │
├──────────────────────────────────────────────────────────┤
│  Nível 3 — Estratégia do Regime Tributário (FALLBACK)    │
│  Defaults globais do regime: MEI, Simples, LP, LR        │
│  Gerenciados em: Configurações → Perfil Fiscal           │
└──────────────────────────────────────────────────────────┘
```

O motor avalia os níveis em ordem. No primeiro que retornar um resultado válido, para.

### 5.2. Nível 1 — FiscalRule (Regra Customizada)

Arquivo: `app/Services/Fiscal/RuleMatcher.php`

O `RuleMatcher` avalia as `conditions` de cada regra ativa contra o `FiscalContextDTO`:

- As condições são combinadas com **AND** (todas devem ser verdadeiras)
- Valores em array nas condições funcionam como **OR** (qualquer um basta)
- Chaves terminadas em `_prefix` suportam **correspondência de prefixo** (ex: `ncm_code_prefix: "5511"` bate em qualquer NCM que comece com "5511")

Apenas regras com `is_enabled = true`, dentro do período `valid_from/valid_to` e da versão do perfil ativa, são avaliadas. A de menor `priority` que bater é usada.

**Quando usar regras:**
- Produto ou grupo de produtos com tributação diferente do padrão
- Cliente em estado com alíquota ICMS diferente
- Operações de devolução, remessa ou bonificação com CFOP específico
- Vigências sazonais ou por competência

### 5.3. Nível 2 — ProductTax (por Produto)

O motor verifica se o produto possui um registro em `product_taxes`. Se existir e tiver `situacao_tributaria` preenchida para o imposto correspondente, esse valor é usado.

Permite configurar NCM, CEST, origem e alíquotas diretamente no cadastro de cada produto, sem precisar de uma regra global.

### 5.4. Nível 3 — Estratégia de Regime (Fallback)

Arquivo: `app/Services/Fiscal/TaxRegimeStrategies/`

Cada regime tem uma estratégia que retorna os defaults quando os níveis anteriores não produzem resultado:

| Regime | Classe | PIS | COFINS | ICMS |
|--------|--------|-----|--------|------|
| MEI | `MeiStrategy` | CST 99 / 0% | CST 99 / 0% | CSOSN 102 |
| Simples Nacional | `SimplesNacionalStrategy` | CST 49 / 0,65% | CST 49 / 3,00% | CSOSN 102 |
| Lucro Presumido | `LucroPresumidoStrategy` | CST 01 / 1,65% | CST 01 / 7,60% | CST 00 |
| Lucro Real | `LucroRealStrategy` | CST 01 / 1,65% | CST 01 / 7,60% | CST 00 |

A estratégia é selecionada por `TaxRegimeStrategyFactory::make($regime)`.

### 5.5. Cálculo das Bases e Valores

O `FiscalDecisionDTO` contém todos os parâmetros resolvidos. O método `toTaxData()` aplica os cálculos:

**ICMS regular:**

```
base_icms = total_item * (1 - reducao_base_icms / 100)
valor_icms = base_icms * aliquota_icms / 100
```

**ICMS-ST (Substituição Tributária):**

```
base_st = total_item * (1 + aliquota_mva_st / 100)
base_st = base_st * (1 - reducao_base_st / 100)
valor_st = base_st * aliquota_st / 100
```

**ICMS Interestadual:**

Quando `is_interestadual = true`, a alíquota ICMS é lida do mapa `icms_aliquotas_interestaduais` do perfil, indexado pelo estado do destinatário (ex: `"SP" → 12`).

**CFOP automatico:**

O CFOP é determinado consultando o `cfop_rules` da versão do perfil — um JSON que mapeia cada `OperationNature` para um código CFOP:

```json
{
  "VENDA DENTRO DO ESTADO": "5102",
  "VENDA FORA DO ESTADO": "6102",
  "DEVOLUCAO DENTRO DO ESTADO": "5201",
  "DEVOLUCAO FORA DO ESTADO": "6201"
}
```

Fallback: `5102` para operações intraestaduais, `6102` para interestaduais.

### 5.6. Rastreabilidade da Decisão

O `FiscalDecisionDTO` registra a **fonte** da decisão:

| `source` | Significado |
|----------|-------------|
| `fiscal_rule` | A decisão veio de uma `FiscalRule` específica |
| `product_tax` | A decisão veio do `ProductTax` do produto |
| `regime_default` | A decisão veio do fallback de regime |

Essa informação é salva no `fiscal_snapshot` de cada item para rastreabilidade completa de auditorias.

---

## 6. Fluxo Completo de Emissão

### 6.1. Criação via Fatura (fluxo principal)

```
┌─────────────────────────────────────────────────────────────────────┐
│  1. Invoice → Botão "Gerar Documento Fiscal"                        │
│     GenerateFiscalDocumentAction (Filament)                         │
│     └── InvoiceService::createFiscalDocument(invoice, userId)       │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│  2. Criar Cabeçalho                                                 │
│     FiscalDocumentService::create(data, userId)                     │
│     └─► CreateFiscalDocumentAction                                  │
│          ├── FiscalDocumentValidatorResolver::validateCreate()      │
│          │    ├── FiscalDocumentValidator (regras comuns)           │
│          │    ├── NfeDocumentValidator (campos NF-e)                │
│          │    └── FiscalProfileValidator (perfil + CST + CFOP)      │
│          └── FiscalDocument::create()  → status: PENDING           │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│  3. Criar Itens em Lote (da fatura)                                 │
│     FiscalDocumentItemService::createMany(document, itemsData)      │
│     └─► CreateManyFiscalDocumentItemsAction                         │
│          └── INSERT em lote na fiscal_document_items                │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│  4. Motor de Decisão Fiscal (por item)                              │
│     ResolveFiscalContextAction::execute(document)                   │
│     └── para cada item:                                             │
│          ├── FiscalContextDTO::fromFiscalDocumentItem(doc, item)    │
│          └── FiscalDecisionService::resolve(context)               │
│               ├── Nível 1: FiscalRule (RuleMatcher)                 │
│               ├── Nível 2: ProductTax                               │
│               └── Nível 3: TaxRegimeStrategy (regime da empresa)   │
│                                     → FiscalDecisionDTO[]           │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│  5. Persistir Snapshot Imutável                                     │
│     PersistFiscalSnapshotAction::execute(document, decisions)       │
│     ├── document: fiscal_profile_version_id, tax_regime_used        │
│     └── cada item: cfop_code, tax_data, fiscal_snapshot             │
│          fiscal_snapshot = decisão congelada (não muda mais)        │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
         Documento criado. Status: PENDING. Pronto para emissão.
```

### 6.2. Emissão NF-e (assíncrona)

```
┌─────────────────────────────────────────────────────────────────────┐
│  6. Usuário clica "Emitir NF-e"                                     │
│     NfeDocumentService::emitir(document, userId)                    │
│     ├── Valida: nota já enviada? (permite reenvio se REJECTED)      │
│     └── dispatch(SendNfeJob)  ← 3 tentativas: 30s / 60s / 120s     │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓  (fila)
┌─────────────────────────────────────────────────────────────────────┐
│  7. SendNfeJob::handle()                                            │
│     └── SendNfeAction::execute(document, serie, operationNature)    │
│                                                                     │
│     ETAPA A — Reservar Número                                       │
│     ReserveNfeNumberAction::execute()                               │
│     └── NfeSequence::nextNumber()  → SELECT FOR UPDATE              │
│          └── document: document_number, document_series             │
│                                                                     │
│     ETAPA B — Montar Payload                                        │
│     BuildNfePayloadAction::execute(document)                        │
│     └── Carrega: company + address, customer + address, items       │
│          Estrutura JSON completo para IntegraNotas API              │
│          document: nfe_payload = payload montado (para debug)       │
│                                                                     │
│     ETAPA C — Enviar via SDK                                        │
│     NfeConfigService::buildSdkParams(companyId)                     │
│     └── new CloudDfe\SdkPHP\Nfe(token, ambiente)                   │
│          →  $sdk->cria($payload)                                    │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
              ┌────────────────┴────────────────┐
              ↓                                 ↓
   ┌─────────────────────┐          ┌─────────────────────────┐
   │  Código 5023         │          │  Erro (5001 / 5002)     │
   │  (lote enviado)      │          │                         │
   │                      │          │  Salva errors_messages  │
   │  document:           │          │  Job falha (sem retry)  │
   │  - document_key      │          └─────────────────────────┘
   │  - nfe_status =      │
   │    IN_PROCESSING     │
   │  - nfe_ambiente      │
   │                      │
   │  dispatch(Consult    │
   │  NfeJob, delay:15s)  │
   └─────────────────────┘
```

---

## 7. Fluxo de Resposta: Webhook e Polling

A IntegraNotas opera de forma assíncrona. A resposta chega por **dois canais paralelos**:

### 7.1. Canal Primário — Webhook (IntegraNotas → sistema)

```
IntegraNotas (SEFAZ autorizou)
    ↓  POST /webhook/nfe
NfeWebhookController::handle()
    ├── Ignora se origem = "TESTE" (ping de configuração)
    ├── Valida signature HMAC (se webhook_secret configurado)
    ├── Localiza FiscalDocument por document_key
    └── Atualiza conforme status recebido:
         ┌─────────────────────────────────────────────────┐
         │ status = "autorizado"                           │
         │   nfe_status → AUTHORIZED                      │
         │   status     → CONFIRMED                       │
         │   nfe_protocolo, confirmed_at                  │
         ├─────────────────────────────────────────────────┤
         │ status = "cancelado"                            │
         │   nfe_status → CANCELED                        │
         │   status     → CANCELLED                       │
         │   canceled_at                                   │
         ├─────────────────────────────────────────────────┤
         │ outro (rejeitado)                               │
         │   nfe_status → REJECTED                        │
         │   errors_messages[] += mensagem de erro        │
         └─────────────────────────────────────────────────┘
    └── Sempre retorna HTTP 200 (exigência da IntegraNotas)
```

A rota do webhook não tem middleware de autenticação:

```php
Route::post('/webhook/nfe', [NfeWebhookController::class, 'handle'])
    ->name('webhook.nfe')
    ->withoutMiddleware(['auth', 'verified']);
```

### 7.2. Canal Secundário — Polling (fallback)

Caso o webhook não chegue (falha de rede, configuração, etc.), o `ConsultNfeJob` faz consultas periódicas:

```
ConsultNfeJob::handle()
    ├── Se nfe_status já saiu de IN_PROCESSING (webhook chegou) → encerra
    ├── ConsultNfeAction → $sdk->consulta(['chave' => document_key])
    │    ├── Autorizado → atualiza igual ao webhook
    │    └── Ainda em processamento → reagenda
    └── Tentativas: 5 máximo, delays: 15s → 30s → 45s → 60s → 75s
```

### 7.3. Consulta Manual (síncrona)

O usuário pode forçar uma consulta diretamente pelo Filament:

```
Filament Table → Botão "Consultar SEFAZ"  (visível se: nfe_status = IN_PROCESSING)
    ↓
NfeDocumentService::consultar(document, userId)
    ↓  (síncrono — não usa Job)
ConsultNfeAction::execute(document)
    ↓
Atualiza o documento imediatamente
```

---

## 8. Quem Consome Cada Informação

### 8.1. Mapa de Consumo por Entidade

```
fiscal_documents
├── NfeDocumentService          → lê para montar/emitir/consultar o documento
├── BuildNfePayloadAction       → lê cabeçalho para montar o payload
├── SendNfeAction               → lê para enviar, escreve nfe_status/document_key
├── ConsultNfeAction            → lê document_key, escreve status/protocolo
├── NfeWebhookController        → lê por document_key, escreve status/protocolo
├── PrintNfeDanfeAction         → lê document_key para buscar DANFE
├── PrintNfePreviewAction       → lê os dados para gerar preview
├── FiscalDocumentResource      → lê/escreve via formulário Filament
└── GenerateFiscalDocumentAction → cria o documento a partir da Invoice

fiscal_document_items
├── BuildNfePayloadAction       → lê todos os itens com product eager load
├── ResolveFiscalContextAction  → lê para construir FiscalContextDTO por item
├── PersistFiscalSnapshotAction → escreve tax_data, fiscal_snapshot, cfop_code
└── FiscalDocumentItemService   → CRUD dos itens

fiscal_profiles
├── ResolveFiscalContextAction  → lê tax_regime para acionar estratégia correta
├── FiscalDecisionService       → lê regime para o fallback de estratégia
└── FiscalProfileSettingsPage   → lê/escreve via Filament

fiscal_profile_versions
├── FiscalDecisionService       → lê: cfop_rules, aliquotas, CST defaults
├── PersistFiscalSnapshotAction → escreve fiscal_profile_version_id no documento
└── FiscalProfileSettingsPage   → cria nova versão a cada save

fiscal_rules
├── RuleMatcher                 → avalia conditions contra FiscalContextDTO
├── PersistFiscalSnapshotAction → registra fiscal_rule_id + fiscal_rule_version no item
└── FiscalRulesPage             → CRUD das regras via Filament

nfe_sequences
└── ReserveNfeNumberAction      → lê e incrementa last_number atomicamente

product_taxes
├── FiscalDecisionService       → lê para o Nível 2 do motor de decisão
└── ProductTaxService           → CRUD dos dados fiscais por produto

company_preferences (integranotas.*)
├── NfeConfigService            → lê token, ambiente, série, webhook_secret
└── NfeSettingsPage             → escreve via Filament Settings
```

### 8.2. Diagrama de Dependências entre Consumidores

```
Filament UI
    ├── FiscalDocumentResource ──────────────────► FiscalDocumentService
    │                                                   ↓
    │                                          CreateFiscalDocumentAction
    │                                          UpdateFiscalDocumentAction
    │                                          DeleteFiscalDocumentAction
    │
    ├── GenerateFiscalDocumentAction ───────────► InvoiceService
    │                                                   ↓
    │                                          FiscalDocumentService::create()
    │                                          FiscalDocumentItemService::createMany()
    │                                          ResolveFiscalContextAction
    │                                          PersistFiscalSnapshotAction
    │                                                   ↓
    │                                          FiscalDecisionService
    │                                               ├── RuleMatcher → FiscalRule
    │                                               ├── ProductTax
    │                                               └── TaxRegimeStrategy
    │
    ├── NfeSettingsPage ─────────────────────────► CompanyPreference (write)
    ├── FiscalProfileSettingsPage ───────────────► FiscalProfile + Version (write)
    └── FiscalRulesPage ─────────────────────────► FiscalRule (write)

NfeDocumentService
    ├── emitir()     ──────────────────────────► dispatch(SendNfeJob)
    ├── consultar()  ──────────────────────────► ConsultNfeAction
    ├── danfe()      ──────────────────────────► PrintNfeDanfeAction
    └── preview()    ──────────────────────────► PrintNfePreviewAction
                                                      ↓
                                               BuildNfePayloadAction
                                               CloudDfe\SdkPHP\Nfe (SDK)

SendNfeJob (fila)
    └── SendNfeAction
         ├── ReserveNfeNumberAction ──────────► NfeSequence
         ├── BuildNfePayloadAction ───────────► FiscalDocument + items + company + customer
         ├── NfeConfigService ────────────────► CompanyPreference (read)
         └── CloudDfe\SdkPHP\Nfe::cria() ────► IntegraNotas → SEFAZ
              └── dispatch(ConsultNfeJob, 15s)

ConsultNfeJob (fila)
    └── ConsultNfeAction
         └── CloudDfe\SdkPHP\Nfe::consulta() ► IntegraNotas → SEFAZ

NfeWebhookController (HTTP POST /webhook/nfe)
    ├── NfeConfigService::resolveWebhookSecret()
    └── FiscalDocument (update: status, protocolo, confirmed/canceled_at)
```

---

## 9. Integração IntegraNotas (SEFAZ)

### 9.1. SDK e Métodos

Pacote: `cloud-dfe/sdk-php ^0.4.8`

| Método SDK | Action que chama | Finalidade |
|-----------|-----------------|------------|
| `$sdk->cria($payload)` | `SendNfeAction` | Envia a NF-e para processamento na SEFAZ |
| `$sdk->consulta(['chave'])` | `ConsultNfeAction` | Consulta o status de uma NF-e por chave |
| `$sdk->pdf(['chave'])` | `PrintNfeDanfeAction` | Retorna o DANFE em PDF (base64) |
| `$sdk->preview($payload)` | `PrintNfePreviewAction` | Gera preview PDF + XML sem enviar à SEFAZ |

A instância do SDK é construída por empresa:

```php
// NfeConfigService::buildSdkParams($companyId)
new CloudDfe\SdkPHP\Nfe([
    'token'    => $token,   // JWT da empresa (prod ou homolog)
    'ambiente' => $ambiente, // 1=prod / 2=homolog
    'options'  => ['timeout' => 60, 'port' => 443]
]);
```

### 9.2. Códigos de Resposta

| Código | Situação | Tratamento |
|--------|---------|------------|
| `5023` | Lote enviado, aguardando SEFAZ | Salva `document_key`, `nfe_status = IN_PROCESSING`, dispara `ConsultNfeJob` |
| `5001` | Erro de validação nos dados do emitente | Salva erros em `errors_messages`, job **não** faz retry |
| `5002` | Erro de validação nos dados da nota | Idem |
| Webhook `autorizado` | Autorizada pela SEFAZ | `nfe_status = AUTHORIZED`, `status = CONFIRMED`, salva protocolo |
| Webhook `cancelado` | Cancelada | `nfe_status = CANCELED`, `status = CANCELLED` |
| Webhook outro | Rejeitada | `nfe_status = REJECTED`, salva mensagem de erro |

### 9.3. Estrutura do Payload (resumo)

```json
{
  "natureza_operacao": "VENDA DENTRO DO ESTADO",
  "serie": "1",
  "numero": "1035",
  "data_emissao": "2026-03-09T14:00:00-03:00",
  "data_entrada_saida": "2026-03-09T14:00:00-03:00",
  "tipo_operacao": "1",
  "finalidade_emissao": "1",
  "consumidor_final": "1",
  "presenca_comprador": "9",
  "destinatario": {
    "cnpj": "12345678000195",
    "nome": "CLIENTE EXEMPLO LTDA",
    "indicador_inscricao_estadual": "1",
    "inscricao_estadual": "123456789",
    "endereco": {
      "logradouro": "Rua das Flores",
      "numero": "100",
      "bairro": "Centro",
      "municipio": "São Paulo",
      "uf": "SP",
      "cep": "01001000",
      "pais": "Brasil"
    }
  },
  "itens": [
    {
      "numero_item": "1",
      "codigo_produto": "PROD001",
      "descricao": "PRODUTO EXEMPLO",
      "codigo_ncm": "84715010",
      "cfop": "5102",
      "unidade_comercial": "UN",
      "quantidade_comercial": 2,
      "valor_unitario_comercial": "150.00",
      "valor_bruto": "300.00",
      "origem": "0",
      "inclui_no_total": "1",
      "imposto": {
        "icms": { "situacao_tributaria": "101", "modalidade_base_calculo": "3", "aliquota": 12, "valor": 36.00 },
        "pis":  { "situacao_tributaria": "49", "aliquota": 0.65, "valor": 1.95 },
        "cofins": { "situacao_tributaria": "49", "aliquota": 3.00, "valor": 9.00 }
      }
    }
  ],
  "frete": { "modalidade_frete": "9" },
  "pagamento": {
    "formas_pagamento": [{ "meio_pagamento": "01", "valor": "300.00" }]
  }
}
```

---

## 10. Vínculo Fatura → Documento Fiscal

### 10.1. Relações

```
Invoice
├── hasMany FiscalDocuments      (FK: invoice_id)
├── hasMany ServiceOrders        → ServiceOrderItem (serviços + peças)
├── hasMany Requisitions         → RequisitionItem  (produtos/materiais)
└── hasMany ProductionOrders     → ProductionOrderItem (produtos acabados)
```

### 10.2. Origem dos Itens por Tipo de Entidade

| Entidade da Fatura | Tipo de Item Gerado | Documento Fiscal |
|-------------------|--------------------|--------------------|
| `RequisitionItem` | Produto/mercadoria | NF-e |
| `ProductionOrderItem` | Produto acabado | NF-e |
| `ServiceOrderItem` (peça/produto) | Produto | NF-e |
| `ServiceOrderItem` (serviço/mão de obra) | Serviço | NFS-e (futuro) |

### 10.3. Fluxo de Importação

Quando o documento é criado a partir de uma fatura, o `InvoiceService` alimenta o `FiscalDocumentItemService` com os itens da fatura convertidos. Os dados fiscais (NCM, CFOP, tributação) são resolvidos automaticamente pelo motor de decisão imediatamente após a criação dos itens.

---

## 11. Configurações e Parâmetros

### 11.1. Perfil Fiscal (`FiscalProfileSettingsPage`)

Rota Filament: `Configurações → Perfil Fiscal`

Cada save cria uma nova versão imutável do perfil. Documentos já emitidos **não são afetados**.

**O que pode ser configurado:**
- Regime tributário da empresa (MEI / Simples Nacional / Lucro Presumido / Lucro Real)
- CNAE principal
- Defaults ICMS: CST/CSOSN, alíquota interna, redução de base, modalidade de base de cálculo
- Substituição Tributária: alíquota ST, MVA, redução de base ST
- Alíquotas interestaduais por estado (JSON)
- Defaults PIS e COFINS: CST e alíquota
- Defaults IPI: CST, alíquota, enquadramento
- Mapa CFOP por natureza de operação

### 11.2. Regras Fiscais (`FiscalRulesPage`)

Rota Filament: `Configurações → Regras Fiscais`

Regras de override com condições, prioridade e vigência temporal. Avaliadas antes de qualquer default de produto ou regime.

### 11.3. Integração NF-e (`NfeSettingsPage`)

Rota Filament: `Configurações → Integração NF-e`

Configurações de conexão com a IntegraNotas por empresa:
- Token de produção e homologação
- Ambiente ativo (produção / homologação)
- Série padrão
- Segredo do webhook

---

## 12. Enums e Domínios Fiscais

### Regime Tributário (`TaxRegime`)
`MEI` · `SimplesNacional` · `LucroPresumido` · `LucroReal`

### Status do Documento (`Status`)
`PENDING` → `CONFIRMED` / `CANCELLED`

### Status NF-e (`NfeStatus`)
`pending` → `in_processing` → `authorized` / `rejected` / `canceled`

### Modelo de Documento (`DocumentModel`)
`nfe` · `nfse`

### Tipo de Operação (`OperationType`)
`0` = Entrada · `1` = Saída

### Natureza de Operação (`OperationNature`)
`VENDA DENTRO DO ESTADO` · `VENDA FORA DO ESTADO` · `DEVOLUCAO DENTRO DO ESTADO` · `DEVOLUCAO FORA DO ESTADO` · `REMESSA` · `TRANSFERENCIA` · `BONIFICACAO`

### Finalidade de Emissão (`IssuePurpose`)
`1` = Normal · `2` = Complementar · `3` = Ajuste · `4` = Devolução/Retorno

### Indicador de Presença (`BuyerPresenceIndicator`)
`0` = Não presencial (padrão) · `1` = Balcão · `2` = Internet · `3` = Telemarketing · `4` = Domicílio · `5` = Presencial fora do estabelecimento · `9` = Outros

### Modalidade de Frete (`FreightModality`)
`0` = CIF (por conta do emitente) · `1` = FOB (por conta do destinatário) · `9` = Sem frete

### Indicador IE Destinatário (`StateTaxIndicator`)
`1` = Contribuinte · `2` = Isento · `9` = Não contribuinte

---

## 13. NFS-e — Estado Atual e Roadmap

### Estado Atual

O enum `DocumentModel::NFSE` existe. A tabela `fiscal_document_items` possui `service_id` (nullable). Porém **a emissão de NFS-e não está implementada**:

- O `FiscalDocumentValidatorResolver` retorna `null` para o branch NFS-e
- Não existe `NfseDocumentService`, `BuildNfsePayloadAction` ou equivalentes
- O SDK `CloudDfe\SdkPHP\Nfse` não é instanciado em nenhum lugar

### Diferenças NF-e vs NFS-e

| Aspecto | NF-e | NFS-e |
|---------|------|-------|
| Órgão | SEFAZ (estadual) | Prefeitura (municipal) |
| Item | Produto (NCM + CFOP obrigatórios) | Serviço (código LC 116 obrigatório) |
| Impostos | ICMS, PIS, COFINS, IPI | ISS, PIS, COFINS |
| IE | Obrigatória para contribuinte | Não aplicável |
| Processamento | Assíncrono (5023 → webhook) | Pode ser síncrono (depende da prefeitura) |
| SDK | `CloudDfe\SdkPHP\Nfe` | `CloudDfe\SdkPHP\Nfse` |

### Para Implementar NFS-e

1. `NfseDocumentValidator` + `NfseItemValidator`
2. `BuildNfsePayloadAction`
3. `NfseDocumentService` (emitir / consultar)
4. Jobs e webhook para NFS-e (ou adaptação do existente)
5. Adaptar `FiscalRulesPage` e `FiscalProfileSettingsPage` para suporte a ISS
