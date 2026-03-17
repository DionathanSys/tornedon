# Itens Documento Fiscal
- Tem o objetivo de organizar a criação/edição de registros da tabela *fiscal_document_items*, onde existe duas situações, itens para uma NF (mercadoria) e para NFS (serviços), existem campos compartilhados e outros que são obrigatórios para um e não para o outro.

## Campos comuns
- fiscal_document_id
- item_number
- description
- unit_price
- quantity
- total_price
- discount_amount
- included_in_total
- additional_information
- tax_data
- fiscal_snapshot
- created_by
- updated_by
## Itens (Serviço)
- service_id
- iss_exigibility
- iss_rate
- iss_amount
- iss_withheld

## Itens (Mercadorias)
- product_id
- product_code
- product_origin
- ncm_code
- barcode
- cest_code
- cfop_code
- unit_of_measure
- taxable_unit
- taxable_quantity
- taxable_unit_price
- freight_amount
- insurance_amount
- other_expenses_amount


