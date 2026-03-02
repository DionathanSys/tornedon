# Requisitos

## Funcionalidades Principais
- Registrar vendas de produtos;
- Produtos engloba itens que possuem controle de estoque ou não. Para os que não controlam estoque, eles obrigatoriamente deve ser originados de uma **Production Order**;
- Quanto à estoque negativo, deve ser obedecido o que foi definido no cadastro do produto, devendo validar.
	- Quando o produto possui 'has_stock_control' = true e 'allow_negative' = false, deve ser validado o saldo do item, antes da criação do RequisitionItem

## Campos e Validações

### Identificação
- **Número**: Geração automática e única por empresa
- **Data de Venda**: Obrigatória, data da transação
- **Status**: Estados definidos por State Pattern (Aberta, Fechada, etc.)

### Relacionamentos
- **Cliente**: Obrigatório, referência a Partner
- **Empresa**: Obrigatório, isolamento multi-tenant
- **Ordem de Serviço**: Opcional, vinculação com ServiceOrder
- **Cotação**: Opcional, origem da requisição
- **Vendedor**: Opcional, usuário responsável pela venda
- **Equipamento**: Opcional, equipamento relacionado
- **Fatura**: Opcional, vinculação com Invoice quando faturada

### Financeiro
- **Método de Pagamento**: Enum (dinheiro, cartão, etc.)
- **Condição de Pagamento**: Enum (à vista, parcelado, etc.)

### Logística
- **Endereço de Entrega**: Opcional, texto
- **Data de Entrega**: Opcional, data
- **Observações**: Opcional, texto livre

### Controle de Estoque
- **Estoque Consumido**: Flag booleano indicando se o estoque foi debitado
- Validação de saldo disponível antes de fechar requisição
- Geração automática de Stock Movements (EXIT) quando fechada

### Metadados
- **Informações Adicionais**: JSON para dados customizados
- **Criado por**: Auditoria, referência ao usuário
- **Atualizado por**: Auditoria, referência ao usuário
- Soft deletes para exclusão lógica

## Regras de Negócio

### Criação
- Requisitos podem ser criados manualmente ou automaticamente a partir de cotações aprovadas
- Validação de estoque para produtos com controle ativo
- Geração automática de número sequencial por empresa

### Modificação
- Apenas status pode ser alterado após criação
- Transições de estado controladas por State Pattern
- Validações específicas por estado

### Fechamento
- Ao fechar, consumir estoque via StockMovementService
- Gerar ProductionOrders automaticamente se necessário
- Vincular com Invoice se faturada

### Relacionamentos
- Uma requisição pode ter múltiplos itens (RequisitionItem)
- Uma requisição pode gerar múltiplas ordens de produção
- Suporte a exclusão lógica (soft deletes)

## Estados da Requisição

- **Aberta**: Estado inicial, permite modificações
- **Fechada**: Finalizada, estoque consumido, não permite alterações

## Integrações

- **StockMovementService**: Para controle de movimentações de estoque
- **ProductionOrderService**: Para geração automática de ordens
- **InvoiceService**: Para faturamento
- **State Pattern**: Para controle de workflow