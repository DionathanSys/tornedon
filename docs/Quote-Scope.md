# Documentação de Escopo: Orçamentos (Quote)

## 1. Visão Geral
O módulo de Orçamentos (**Quote**) é o ponto de entrada para o processo comercial e produtivo da aplicação. Ele permite registrar solicitações de clientes, detalhar itens (produtos, serviços ou peças customizadas), anexar especificações técnicas e desenhos, e gerenciar o ciclo de vida até a conversão em Ordens de Produção ou Requisições.

## 2. Modelos e Estrutura de Dados

### 2.1 Quote (Orçamento)
Representa o cabeçalho do orçamento.
- **Identificador**: `quote_number` (Ex: ORC-2026-0001), gerado automaticamente.
- **Relacionamentos**:
  - Pertence a uma `Company` (Empresa/Mtenant).
  - Pertence a um `Customer` (Cliente/Parceiro).
  - Possui vários `items` (`QuoteItem`).
  - Pode gerar uma `ProductionOrder` (Ordem de Produção).
  - Pode gerar várias `Requisitions` (Requisições de Material).
- **Campos Principais**:
  - `status`: Estado atual (DRAFT, SENT, APPROVED, REJECTED, EXPIRED).
  - `valid_until`: Data limite de validade.
  - `total_amount`: Soma total dos itens (calculado via atributos).
  - `payment_method` / `payment_condition`: Informações financeiras.

### 2.2 QuoteItem (Item do Orçamento)
Representa cada linha de produto ou serviço no orçamento.
- **Tipos de Item**: Pode ser um `Product` (Estoque/Catálogo) ou um `Service` (Mão de obra).
- **Campos Técnicos (Foco em Manufatura)**:
  - `technical_specifications`: JSON com dimensões, materiais e observações técnicas.
  - `estimated_production_hours`: Estimativa de tempo para produção.
  - `material_cost` / `labor_cost`: Decomposição de custos para formação de preço.
  - `destination`: Destino do item (Stock ou Direct Delivery).

## 3. Regras de Negócio

### 3.1 Ciclo de Vida do Status
1. **DRAFT (Rascunho)**: Estado inicial. Edição livre.
2. **SENT (Enviado)**: Orçamento enviado ao cliente. Bloqueia edições básicas até que retorne para DRAFT ou seja decidido.
3. **APPROVED (Aprovado)**: Cliente aceitou a proposta. Permite a conversão para Produção ou Requisição.
4. **REJECTED (Rejeitado)**: Cliente recusou. Exige um motivo de rejeição (`rejected_reason`).
5. **EXPIRED (Expirado)**: Data `valid_until` ultrapassada. Não permite aprovação sem renovação.

### 3.2 Validações e Comportamentos
- **Numeração Única**: O `quote_number` é gerado no momento da criação com base em uma sequência por empresa.
- **Tipagem de Parceiro**: O `customer_id` deve obrigatoriamente referenciar um `Partner` marcado como cliente.
- **Custos e Totais**: O sistema diferencia `total_amount_services` e `total_amount_products` para fins de análise e tributação inicial.
- **Conversão**: Um orçamento aprovado só pode ser convertido em Ordem de Produção **uma única vez** para evitar duplicação de demanda.

## 4. Integrações de Fluxo (Workflow)

### 4.1 De Orçamento para Produção (Manufatura)
Ao aprovar um orçamento focado em tornearia/usinagem:
1. Os `QuoteItems` marcados para produção são analisados.
2. É criada uma `ProductionOrder` com status `QUEUED`.
3. Informações técnicas (`technical_specifications`) e desenhos anexados são carregados para a ordem de produção para orientar o operador.

### 4.2 De Orçamento para Ordem de Serviço (Mão de Obra)
Caso o orçamento possua itens de serviços (Mão de Obra):
1. O orçamento aprovado pode ser vinculado a uma `ServiceOrder` (Ordem de Serviço).
2. A Ordem de Serviço centraliza a execução da mão de obra, permitindo o apontamento de horas e o controle de status específico de execução de serviços.
3. Mantém-se a rastreabilidade entre a proposta comercial (`Quote`) e a execução técnica (`ServiceOrder`).

### 4.3 De Orçamento para Requisição (Materiais)
Caso o orçamento envolva apenas fornecimento de materiais existentes:
1. Gera-se uma `Requisition` para movimentação de estoque, garantindo que os insumos orçados sejam reservados ou baixados.

## 5. Serviços e Ações (Camada Técnica)
A lógica está centralizada em `App\Services\Quote\Actions`:
- `CreateQuote`: Inicializa o registro e gera numeração.
- `SendForApproval`: Transiciona para `SENT`.
- `ApproveQuote`: Marca como `APPROVED` e registra timestamp/usuário.
- `RejectQuote`: Transiciona para `REJECTED` coletando a justificativa.
- `ConvertToProductionOrder`: Realiza a transição de dados para o módulo de manufatura.

## 6. Documentos Relacionados
- [Regras de Negócio: Fluxo Quote-Production](docs/regras-negocio/quote-production-workflow.md)
