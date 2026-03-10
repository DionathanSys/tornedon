# Arquitetura Fiscal NF-e - Criação, Validação e Emissão

## Objetivo

Documentar como a aplicação monta e emite NF-e no fluxo `criacao -> validacao -> emissao`, com foco no bloco `itens` do payload da IntegraNotas (`/nfe`) e no de-para com os dados internos.

## Escopo

- Documento fiscal modelo NF-e.
- Fluxo iniciado a partir de uma `Invoice`.
- Itens persistidos em `fiscal_document_items` e serializados em `BuildNfePayloadAction`.

## Fluxo Arquitetural (fim a fim)

1. **Criacao do documento fiscal (header + itens base)**
- Entrada pelo modal Filament: `app/Filament/Clusters/Financial/Resources/Invoices/Pages/Actions/GenerateFiscalDocumentAction.php`.
- Orquestracao principal: `app/Services/Invoice/InvoiceService.php` (`createFiscalDocument`).
- Header salvo via `FiscalDocumentService`/`CreateFiscalDocumentAction`.
- Itens base montados a partir de `invoice.requisitions.items.product.tax` e inseridos em lote por `CreateManyFiscalDocumentItemsAction`.

2. **Enriquecimento fiscal (snapshot + impostos)**
- Resolucao fiscal por item: `app/Services/Fiscal/Actions/ResolveFiscalContextAction.php`.
- Motor de decisao: `app/Services/Fiscal/FiscalDecisionService.php`.
- Persistencia da decisao em cada item: `app/Services/Fiscal/Actions/PersistFiscalSnapshotAction.php`.
- Campos atualizados por item: `cfop_code`, `tax_data`, `fiscal_snapshot`, `fiscal_rule_id`, `fiscal_rule_version`.

3. **Montagem do payload e emissao**
- Serializacao para IntegraNotas: `app/Services/FiscalDocument/Actions/BuildNfePayloadAction.php`.
- Envio via SDK: `app/Services/FiscalDocument/Actions/SendNfeAction.php`.
- Execucao assincrona: `app/Jobs/SendNfeJob.php` (disparado por `app/Services/FiscalDocument/NfeDocumentService.php`).

## Camadas de validacao

1. **Validacao de entrada (UI/Form)**
- Regras de natureza, tipo da operacao, finalidade, consumidor final, presenca e frete no modal de geracao.

2. **Validacao de cabecalho**
- `app/Services/FiscalDocument/Validators/FiscalDocumentValidator.php`.
- Regras comuns de `customer_id`, `company_id`, `document_type`, `status`, datas, blocos JSON.

3. **Validacao especifica de NF-e (header)**
- `app/Services/FiscalDocument/Validators/NfeDocumentValidator.php`.
- Exige operacao, finalidade, indicadores e `freight_data`.

4. **Validacao de itens**
- `app/Services/FiscalDocument/Validators/Items/NfeItemValidator.php`.
- Na criacao (`validateCreateMany`): campos fiscais podem iniciar `nullable`.
- Na validacao completa (`validate`): exige `ncm_code`, `cfop_code`, origem e bloco `tax_data.imposto.icms/pis/cofins`.

5. **Validacao de regras fiscais por empresa**
- `app/Services/FiscalDocument/Validators/FiscalProfileValidator.php` (via resolver).
- Exige perfil fiscal ativo e natureza de operacao configurada com CFOP.

## Onde os dados ficam armazenados

### 1) Cabecalho do documento
- Tabela: `fiscal_documents`.
- Modelo: `app/Models/FiscalDocument.php`.
- Migracao base: `database/migrations/2025_05_26_021924_create_fiscal_documents_table.php`.
- Campos de integracao NF-e: `nfe_status`, `nfe_ambiente`, `nfe_protocolo`, `nfe_payload`, `nfe_sequence_id` em `database/migrations/2026_03_04_000002_add_nfe_fields_to_fiscal_documents_table.php`.
- Vinculo ao perfil fiscal usado: `fiscal_profile_id`, `tax_regime_used`.

### 2) Itens do documento
- Tabela: `fiscal_document_items`.
- Modelo: `app/Models/FiscalDocumentItem.php`.
- Migracao base: `database/migrations/2025_05_26_021928_create_fiscal_document_items_table.php`.
- Campos adicionais de item NF-e: `description`, `barcode`, `cest_code`, `taxable_*`, descontos/frete/seguro/outras despesas, `additional_information` em `database/migrations/2026_03_08_000002_add_nfe_item_fields_to_fiscal_document_items.php`.
- Snapshot fiscal imutavel: `fiscal_snapshot`, `fiscal_rule_id`, `fiscal_rule_version` em `database/migrations/2026_03_09_100004_add_fiscal_snapshot_to_fiscal_document_items_table.php`.

### 3) Fontes tributarias da decisao
- Por produto: `product_taxes` (`app/Models/ProductTax.php`).
- Por empresa/regime: `fiscal_profiles` (`app/Models/FiscalProfile.php`).

## De -> Para dos itens (API IntegraNotas x Aplicacao)

Legenda:
- **Origem/Alimentacao** = como o campo e preenchido no fluxo.
- **Armazenamento** = onde o valor fica persistido antes da emissao.

| Campo API (`itens[]`) | Equivalente na aplicacao | Armazenamento | Origem/Alimentacao |
|---|---|---|---|
| `numero_item` | indice do loop em `BuildNfePayloadAction` (`$index + 1`) | nao depende de coluna; calculado no envio | Serializado no momento do payload a partir da ordem dos itens carregados |
| `codigo_produto` | `FiscalDocumentItem.product_code` | `fiscal_document_items.product_code` | Vem de `product.product_code` na montagem dos itens em `InvoiceService::createFiscalDocument` |
| `descricao` | `FiscalDocumentItem.description` (fallback `product.name`) | `fiscal_document_items.description` | Preenchido na criacao dos itens; fallback no payload se descricao vier vazia |
| `codigo_ncm` | `FiscalDocumentItem.ncm_code` | `fiscal_document_items.ncm_code` | Vem de `product.tax.ncm_code` na criacao |
| `cfop` | `FiscalDocumentItem.cfop_code` | `fiscal_document_items.cfop_code` | Definido por `FiscalDecisionService` e gravado por `PersistFiscalSnapshotAction` |
| `unidade_comercial` | `FiscalDocumentItem.unit_of_measure` | `fiscal_document_items.unit_of_measure` | Vem do item da requisicao (ou unidade do produto) |
| `quantidade_comercial` | `FiscalDocumentItem.quantity` | `fiscal_document_items.quantity` | Vem do item da requisicao |
| `valor_unitario_comercial` | `FiscalDocumentItem.unit_price` | `fiscal_document_items.unit_price` | Vem do item da requisicao |
| `valor_bruto` | `FiscalDocumentItem.total_price` | `fiscal_document_items.total_price` | Calculado na criacao (`quantity * unit_price`) |
| `unidade_tributavel` | `FiscalDocumentItem.taxable_unit` (fallback `unit_of_measure`) | `fiscal_document_items.taxable_unit` | Se nao preenchido, payload usa fallback automatico |
| `quantidade_tributavel` | `FiscalDocumentItem.taxable_quantity` (fallback `quantity`) | `fiscal_document_items.taxable_quantity` | Se nao preenchido, payload usa fallback automatico |
| `valor_unitario_tributavel` | `FiscalDocumentItem.taxable_unit_price` (fallback `unit_price`) | `fiscal_document_items.taxable_unit_price` | Se nao preenchido, payload usa fallback automatico |
| `origem` | `FiscalDocumentItem.product_origin` | `fiscal_document_items.product_origin` | Vem de `product.tax.product_origin` na criacao |
| `inclui_no_total` | `FiscalDocumentItem.included_in_total` | `fiscal_document_items.included_in_total` | Definido como `true` na criacao; serializado como `'1'/'0'` |
| `valor_desconto` | `FiscalDocumentItem.discount_amount` | `fiscal_document_items.discount_amount` | Campo proprio do item (default 0 quando nao informado) |
| `valor_frete` | `FiscalDocumentItem.freight_amount` | `fiscal_document_items.freight_amount` | Campo proprio do item (default 0 quando nao informado) |
| `valor_seguro` | `FiscalDocumentItem.insurance_amount` | `fiscal_document_items.insurance_amount` | Campo proprio do item (default 0 quando nao informado) |
| `valor_outras_despesas` | `FiscalDocumentItem.other_expenses_amount` | `fiscal_document_items.other_expenses_amount` | Campo proprio do item (default 0 quando nao informado) |
| `informacoes_adicionais` | `FiscalDocumentItem.additional_information` (fallback `tax_data['informacoes_adicionais']`) | `fiscal_document_items.additional_information` e/ou `fiscal_document_items.tax_data` | Prioriza campo textual do item; fallback para tax_data |

## De -> Para do bloco `imposto`

| Campo API (`itens[].imposto`) | Equivalente na aplicacao | Armazenamento | Origem/Alimentacao |
|---|---|---|---|
| `valor_aproximado_tributos` | `tax_data.imposto.valor_aproximado_tributos` | `fiscal_document_items.tax_data` (JSON) | Opcional; quando presente, e lido do `tax_data` no payload |
| `icms.*` | `tax_data.imposto.icms.*` | `fiscal_document_items.tax_data` (JSON) | Calculado por `FiscalDecisionDTO::toTaxData()` com base em decisao fiscal e `total_price` |
| `pis.*` | `tax_data.imposto.pis.*` | `fiscal_document_items.tax_data` (JSON) | Calculado por `FiscalDecisionDTO::toTaxData()` |
| `cofins.*` | `tax_data.imposto.cofins.*` | `fiscal_document_items.tax_data` (JSON) | Calculado por `FiscalDecisionDTO::toTaxData()` |
| `ipi.*` (quando aplicavel) | `tax_data.imposto.ipi.*` | `fiscal_document_items.tax_data` (JSON) | Incluido condicionalmente por `FiscalDecisionDTO::toTaxData()` |

## Campos da doc da API sem mapeamento explicito no payload atual

| Campo da API (itens) | Situacao na aplicacao |
|---|---|
| `cest` (quando esperado pela API em item) | Existe `fiscal_document_items.cest_code`, mas hoje nao e serializado em `BuildNfePayloadAction` |
| `ean`/`codigo_barras` (quando esperado pela API) | Existe `fiscal_document_items.barcode`, mas hoje nao e serializado em `BuildNfePayloadAction` |

## Como os campos sao alimentados (resumo operacional)

1. **Fonte comercial (Invoice/Requisition)**
- `InvoiceService::createFiscalDocument` monta os itens com quantidade, unidade, preco, descricao e codigos base.

2. **Fonte fiscal por produto e perfil**
- `FiscalDecisionService` busca dados em `ProductTax` e faz fallback para defaults de `FiscalProfile`.
- Resolve CFOP por natureza da operacao (`cfop_rules`) e estrutura CST/aliquotas.

3. **Persistencia do snapshot fiscal**
- `PersistFiscalSnapshotAction` grava decisao em `fiscal_snapshot` e impostos calculados em `tax_data`.

4. **Serializacao final para IntegraNotas**
- `BuildNfePayloadAction` converte `FiscalDocument + items` em JSON final (`itens[]`, `imposto`, totais e blocos complementares).

5. **Emissao assincrona e retorno**
- `NfeDocumentService::emitir` despacha `SendNfeJob`.
- `SendNfeAction` envia via SDK, grava status/chave/payload e registra erros de validacao retornados pela API.

## Referencias de codigo (pontos centrais)

- `app/Services/Invoice/InvoiceService.php` (metodo `createFiscalDocument`)
- `app/Services/FiscalDocumentItem/Actions/CreateManyFiscalDocumentItemsAction.php`
- `app/Services/Fiscal/Actions/ResolveFiscalContextAction.php`
- `app/Services/Fiscal/FiscalDecisionService.php`
- `app/Domain/DTO/Fiscal/FiscalDecisionDTO.php`
- `app/Services/Fiscal/Actions/PersistFiscalSnapshotAction.php`
- `app/Services/FiscalDocument/Actions/BuildNfePayloadAction.php`
- `app/Services/FiscalDocument/Actions/SendNfeAction.php`
- `app/Jobs/SendNfeJob.php`
- `app/Services/FiscalDocument/Validators/FiscalDocumentValidator.php`
- `app/Services/FiscalDocument/Validators/NfeDocumentValidator.php`
- `app/Services/FiscalDocument/Validators/Items/NfeItemValidator.php`

## Observacoes importantes

- No payload atual, `numero_item` e montado pelo indice do loop e nao pela coluna `item_number`.
- Ha fallback silencioso para campos tributaveis (`taxable_*`) quando nao preenchidos.
- Campos `cest_code` e `barcode` existem no banco, mas nao sao enviados atualmente no bloco `itens`.
- Os nomes em ingles para textos fiscais padrao de perfil ja estao previstos (ex.: `additional_tax_information_default`) e sao usados no payload quando o documento nao traz override.
