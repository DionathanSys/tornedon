# Objetivo
Gerar um registro FiscalDocument a partir de um registro de invoice.

# Requisitos
- Precisa existir uma action, de classe exclusiva, onde ele precisa permitir o seu uso na pagina de edição do registro e no index do resource Invoice.
- Ela será exclusiva para gerar um FiscalDocument do tipo Enum\FiscalDocument\DocumentModel\NFE.
- Na camada de Service, no Invoice, precisa validar se o registro possui no mínimo um item do tipo RequisitionItem, caso não possua, deve retornar essa informação ao front. Essa camada deve inicia a transaction.
- Precisa de uma action no service, que ira controlar o fluxo de criação tanto do registro FiscalDocument quanto dos registros FiscalDocumentItem.
- A criação dos registros deve ser feita através do service de cada model.