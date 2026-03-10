# Considerações de Arquitetura Fiscal (Atual)

## Diretriz principal

A aplicação não utiliza mais motor de `FiscalRule` customizado.
A decisão fiscal segue esta ordem fixa:

1. `ProductTax` (cadastro fiscal do produto)
2. `FiscalProfile` (fallback por empresa/regime para o que não estiver informado no produto)

## Como funciona hoje

1. O item nasce no documento fiscal com dados comerciais.
2. O `FiscalDecisionService` tenta resolver tributacao pelo `ProductTax`.
3. Se nao houver informacao suficiente no produto, aplica defaults do `FiscalProfile` via estrategia do regime.
4. O resultado e persistido em `tax_data` e `fiscal_snapshot` no item.
5. O `BuildNfePayloadAction` serializa esse snapshot para o payload da API.

## Pontos de modelagem recomendados

- Manter no produto apenas dados fiscais estruturais:
  - `ncm_code`
  - `cest_code`
  - `product_origin`
  - blocos `icms`, `pis`, `cofins`, `ipi`
- Nao fixar CFOP diretamente no produto como unica fonte.
- Centralizar fallback por operacao no `FiscalProfile` (`cfop_rules` + defaults tributarios).
- Preservar historico no item fiscal com `fiscal_snapshot` e `tax_data`.

## O que foi descontinuado

- Tabela `fiscal_rules`.
- Relacao `fiscal_rule_id` / `fiscal_rule_version` nos itens fiscais.
- Pagina de configuracao de regras fiscais customizadas.
- Dependencia de `RuleMatcher` no runtime.

## Referencias tecnicas

- `app/Services/Fiscal/FiscalDecisionService.php`
- `app/Services/Fiscal/Actions/ResolveFiscalContextAction.php`
- `app/Services/Fiscal/Actions/PersistFiscalSnapshotAction.php`
- `app/Services/FiscalDocument/Actions/BuildNfePayloadAction.php`
- `app/Models/ProductTax.php`
- `app/Models/FiscalProfile.php`
- `database/migrations/2026_03_10_120000_remove_fiscal_rules_and_rule_columns.php`