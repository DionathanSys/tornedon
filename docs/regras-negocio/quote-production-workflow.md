# Fluxo de Orçamento e Produção sob Encomenda

## Visão Geral

Este documento descreve o fluxo completo de trabalho implementado para tornearias e empresas de manufatura sob encomenda, cobrindo desde o recebimento do pedido do cliente até a entrega final ou entrada em estoque.

## Modelos do Sistema

### Quote (Orçamento)
Representa uma solicitação de orçamento do cliente com especificações técnicas e desenhos.

**Campos principais:**
- `quote_number`: Número único gerado automaticamente (ORC-2026-0001)
- `partner_id`: Cliente solicitante
- `status`: DRAFT, SENT, APPROVED, REJECTED, EXPIRED
- `valid_until`: Data de validade do orçamento
- `total_amount`: Valor total calculado automaticamente
- Suporte a múltiplos anexos: desenhos técnicos, especificações, fotos

### QuoteItem (Item do Orçamento)
Cada peça/item incluído no orçamento.

**Campos principais:**
- `product_id`: Produto relacionado (opcional para peças customizadas)
- `description`: Descrição da peça
- `quantity`: Quantidade solicitada
- `unit_price`: Preço unitário
- `technical_specifications`: JSON com dimensões, tolerâncias, material, operações
- `estimated_production_hours`: Horas estimadas de produção
- `material_cost` e `labor_cost`: Custos separados

### ProductionOrder (Ordem de Produção)
Ordem criada após aprovação do orçamento ou manualmente.

**Campos principais:**
- `production_order_number`: Número único (PRD-2026-0001)
- `quote_id`: Orçamento de origem (opcional)
- `status`: QUEUED, IN_PROGRESS, QC_CHECK, COMPLETED, CANCELLED
- `priority`: LOW, NORMAL, HIGH, URGENT
- `destination_type`: STOCK ou DIRECT_DELIVERY
- `assigned_operator`: Operador responsável
- `assigned_machine`: Máquina/equipamento

### ProductionOrderItem (Item da Ordem de Produção)
Cada peça em produção com rastreamento detalhado.

**Campos principais:**
- `quantity`: Quantidade solicitada
- `quantity_produced`: Quantidade produzida
- `quantity_approved`: Quantidade aprovada no controle de qualidade
- `quantity_rejected`: Quantidade rejeitada
- `production_notes`: Observações de produção
- `qc_notes`: Observações do controle de qualidade

## Fluxo de Trabalho

### 1. Criação do Orçamento

**Processo:**
1. Cliente solicita orçamento com especificações técnicas
2. Sistema cria Quote em status DRAFT
3. Usuário adiciona itens (QuoteItem) com:
   - Descrição da peça
   - Quantidade
   - Especificações técnicas (JSON)
   - Preço unitário
   - Custos estimados
4. Upload de desenhos técnicos e especificações (Spatie Media Library)
5. Sistema calcula total automaticamente

**Validações:**
- Pelo menos 1 item obrigatório
- Partner deve existir e ser do tipo CUSTOMER
- Valores devem ser positivos
- Especificações técnicas são opcionais mas recomendadas

**Serviço:** `QuoteService::create($data, $createdBy)`

### 2. Envio para Aprovação

**Processo:**
1. Usuário revisa orçamento
2. Envia para aprovação (status → SENT)
3. Cliente recebe orçamento (processo externo)

**Validações:**
- Apenas orçamentos em DRAFT podem ser enviados
- Deve ter ao menos um item

**Serviço:** `QuoteService::sendForApproval($quote, $userId)`

### 3. Aprovação/Rejeição do Orçamento

**Aprovação:**
- Status: SENT → APPROVED
- Registra data e usuário que aprovou
- Orçamento fica disponível para conversão

**Rejeição:**
- Status: SENT → REJECTED
- Motivo da rejeição é obrigatório
- Registra no campo `rejected_reason`

**Expiração:**
- Orçamentos com `valid_until` no passado são marcados EXPIRED
- Orçamentos expirados não podem ser aprovados

**Serviços:**
- `QuoteService::approve($quote, $approvedBy)`
- `QuoteService::reject($quote, $reason, $rejectedBy)`

### 4. Conversão em Ordem de Produção

**Processo:**
1. Orçamento aprovado é convertido
2. Sistema cria ProductionOrder em status QUEUED
3. Todos os QuoteItems viram ProductionOrderItems
4. Mantém vínculo com orçamento original
5. Define destino: STOCK ou DIRECT_DELIVERY
6. Define prioridade: LOW, NORMAL, HIGH, URGENT

**Validações:**
- Apenas orçamentos APPROVED podem ser convertidos
- Orçamento não pode estar expirado
- Cada orçamento só pode gerar uma ordem de produção

**Serviço:** `QuoteService::convertToProductionOrder($quote, $data, $createdBy)`

**Parâmetros adicionais:**
```php
$data = [
    'priority' => 'normal',          // LOW, NORMAL, HIGH, URGENT
    'destination_type' => 'stock',   // STOCK, DIRECT_DELIVERY
    'observations' => 'Observações adicionais',
];
```

### 5. Início da Produção

**Processo:**
1. Operador inicia produção
2. Status: QUEUED → IN_PROGRESS
3. Registra `started_at`
4. Pode atribuir operador e máquina

**Validações:**
- Apenas ordens em QUEUED podem ser iniciadas

**Serviço:** `ProductionOrderService::start($productionOrder, $userId)`

### 6. Atualização de Progresso

**Processo:**
1. Durante produção, operador atualiza quantidades
2. Para cada item:
   - `quantity_produced`: Quantidade produzida até o momento
   - `quantity_approved`: Quantidade aprovada
   - `quantity_rejected`: Quantidade rejeitada/com defeito
   - `production_notes`: Observações de produção
   - `actual_production_hours`: Horas reais gastas

**Validações:**
- Apenas ordens em IN_PROGRESS podem ser atualizadas
- `quantity_approved + quantity_rejected ≤ quantity_produced`
- Sistema calcula total de horas automaticamente

**Serviço:** `ProductionOrderService::updateProgress($productionOrder, $itemsProgress, $userId)`

**Exemplo de dados:**
```php
$itemsProgress = [
    1 => [  // ID do ProductionOrderItem
        'quantity_produced' => 10,
        'quantity_approved' => 9,
        'quantity_rejected' => 1,
        'production_notes' => 'Rejeição: dimensional fora de tolerância',
        'actual_production_hours' => 5.5,
    ],
    2 => [
        'quantity_produced' => 5,
        'quantity_approved' => 5,
        'quantity_rejected' => 0,
        'actual_production_hours' => 3.0,
    ],
];
```

### 7. Controle de Qualidade

**Processo:**
1. Produção finalizada → status IN_PROGRESS → QC_CHECK
2. Controle de qualidade verifica itens
3. Atualiza `quantity_approved` e `quantity_rejected`
4. Preenche `qc_notes` com observações
5. Se necessário, retorna para IN_PROGRESS (retrabalho)

**Transições permitidas:**
- QC_CHECK → IN_PROGRESS (retrabalho)
- QC_CHECK → COMPLETED (aprovado)
- QC_CHECK → CANCELLED (cancelado)

### 8. Conclusão da Produção

**Processo:**
1. Todas as peças produzidas e aprovadas
2. Status: QC_CHECK → COMPLETED
3. Registra `completed_at`
4. Processa destino automaticamente

**Destinos:**

#### STOCK (Entrada em Estoque)
- Handler: `StockDestinationHandler`
- Para cada item com `product_id`:
  - Localiza ou cria registro em `product_stocks`
  - Adiciona `quantity_approved` ao estoque disponível
  - Atualiza `last_movement_type = 'PRODUCTION_ENTRY'`
  - Registra data do movimento
- Itens sem `product_id` são ignorados com log de warning

#### DIRECT_DELIVERY (Entrega Direta)
- Handler: `DirectDeliveryDestinationHandler`
- Se `requisition_id` já existe:
  - Atualiza requisição existente com quantidades produzidas
- Se não existe:
  - Cria nova Requisition automaticamente
  - Status: OPEN
  - `stock_consumed = false` (produção já tratou o estoque)
  - Cria RequisitionItems baseados nos ProductionOrderItems
  - Vincula requisition à production order

**Validações:**
- Apenas ordens em IN_PROGRESS ou QC_CHECK podem ser concluídas
- Recomendado que todas as quantidades estejam preenchidas

**Serviço:** `ProductionOrderService::complete($productionOrder, $userId)`

## Transições de Status

### Quote
```
DRAFT → SENT → APPROVED ─┐
                         │
       ↓                 │
    REJECTED             │
       ↓                 │
    EXPIRED              ↓
                   ProductionOrder
```

### ProductionOrder
```
QUEUED → IN_PROGRESS → QC_CHECK → COMPLETED
   ↓          ↓           ↓
   └──────────┴───────────┴────→ CANCELLED
                  ↑
                  └─── (retrabalho)
```

## Regras de Negócio

### Orçamentos

1. **Numeração automática:** Gerada no formato ORÇ-YYYY-NNNN (ex: ORÇ-2026-0001)
2. **Número único por empresa:** Controlado por `quote_sequences` com lock pessimista
3. **Validade:** Orçamentos com `valid_until` no passado não podem ser aprovados
4. **Conversão única:** Cada orçamento só pode gerar uma ordem de produção
5. **Total calculado:** Soma automática dos itens com descontos aplicados
6. **Anexos ilimitados:** Suporta múltiplos arquivos em diferentes coleções

### Ordens de Produção

1. **Numeração automática:** Formato PRD-YYYY-NNNN (ex: PRD-2026-0001)
2. **Número único por empresa:** Controlado por `production_order_sequences`
3. **Prioridades:** Usado para ordenação de fila e relatórios
4. **Rastreamento de horas:** Estimadas vs reais para análise de eficiência
5. **Validação de quantidades:** `produced ≥ approved + rejected`
6. **Destino flexível:** Pode ser alterado até conclusão
7. **Vínculo com orçamento:** Opcional - ordens podem ser criadas manualmente

### Controle de Estoque

1. **Entrada automática:** Ordens com destino STOCK atualizam `product_stocks`
2. **Movimento registrado:** Tipo `PRODUCTION_ENTRY` para rastreabilidade
3. **Produtos opcionais:** Itens sem `product_id` não afetam estoque
4. **Transações atômicas:** Rollback completo em caso de erro

### Requisições

1. **Criação automática:** Ordens com destino DIRECT_DELIVERY geram requisition
2. **Vínculo mantido:** `requisition_id` salvo na production order
3. **Estoque não consumido:** Flag `stock_consumed = false` (já tratado na produção)
4. **Preços vazios:** RequisitionItems precisam de preços preenchidos manualmente

## Integrações

### Product
- Campo `is_custom_manufacturing` identifica produtos feitos sob encomenda
- QuoteItems e ProductionOrderItems podem referenciar produtos existentes
- Produtos customizados podem ter `product_id = null`

### ProductStock
- Atualizado automaticamente quando `destination_type = STOCK`
- Movimento registrado para auditoria
- Suporta produtos sem código (customizados)

### Requisition
- Gerada automaticamente quando `destination_type = DIRECT_DELIVERY`
- Permite faturamento posterior via fluxo normal
- Integra produção com vendas

### Partner
- Orçamentos e ordens vinculados a clientes
- Validação de tipo (deve ser CUSTOMER)
- Multi-tenancy mantido

## Multi-tenancy

Todos os modelos respeitam isolamento por empresa:
- `company_id` obrigatório em todas as tabelas
- Numeração independente por empresa
- Consultas filtradas automaticamente (via Filament)
- Sequências isoladas

## Permissões Sugeridas

### Quotes
- `view_quotes`: Visualizar orçamentos
- `create_quotes`: Criar novos orçamentos
- `edit_quotes`: Editar orçamentos
- `delete_quotes`: Excluir orçamentos
- `approve_quotes`: Aprovar/rejeitar orçamentos
- `send_quotes`: Enviar para aprovação

### Production Orders
- `view_production_orders`: Visualizar ordens
- `create_production_orders`: Criar ordens
- `edit_production_orders`: Editar ordens
- `delete_production_orders`: Excluir ordens
- `operate_production_orders`: Iniciar/atualizar/concluir produção
- `assign_production_orders`: Atribuir operadores/máquinas

## Métricas e Relatórios

### Quote
- Taxa de conversão: Aprovados / Total enviados
- Valor médio por orçamento
- Tempo médio de resposta
- Taxa de rejeição por motivo

### ProductionOrder
- Eficiência: Horas reais vs estimadas
- Taxa de qualidade: Aprovados / Produzidos
- Tempo médio de produção
- Taxa de defeitos por operador/máquina
- Fila de produção por prioridade

## Exemplos de Uso

### Criar orçamento completo
```php
use App\Services\Quote\QuoteService;

$quoteService = new QuoteService();

$data = [
    'company_id' => 1,
    'partner_id' => 5,
    'description' => 'Peças para equipamento XYZ',
    'valid_until' => now()->addDays(30),
    'observations' => 'Cliente solicita entrega em 15 dias',
    'items' => [
        [
            'product_id' => null,  // Peça customizada
            'description' => 'Eixo de transmissão em aço 1045',
            'quantity' => 10,
            'unit_of_measure' => 'PC',
            'unit_price' => 150.00,
            'technical_specifications' => [
                'material' => 'Aço 1045',
                'diametro' => '50mm',
                'comprimento' => '300mm',
                'tolerancia' => '±0.05mm',
                'operacoes' => 'Tornear, fresar, retificar',
            ],
            'estimated_production_hours' => 2.5,
            'material_cost' => 50.00,
            'labor_cost' => 100.00,
        ],
    ],
];

$quote = $quoteService->create($data, auth()->id());

if ($quoteService->isSuccess()) {
    // Upload de desenhos técnicos
    $quote->addMedia($request->file('drawing'))
          ->toMediaCollection('technical_drawings');
          
    echo "Orçamento criado: " . $quote->quote_number;
}
```

### Aprovar e converter em produção
```php
$quoteService->approve($quote, auth()->id());

if ($quoteService->isSuccess()) {
    $productionData = [
        'priority' => 'high',
        'destination_type' => 'direct_delivery',
        'observations' => 'Cliente urgente',
    ];
    
    $productionOrder = $quoteService->convertToProductionOrder(
        $quote,
        $productionData,
        auth()->id()
    );
    
    echo "Ordem criada: " . $productionOrder->production_order_number;
}
```

### Atualizar progresso de produção
```php
$productionOrderService = new ProductionOrderService();

// Iniciar produção
$productionOrderService->start($productionOrder, auth()->id());

// Atualizar progresso
$progress = [
    1 => [  // ID do item
        'quantity_produced' => 10,
        'quantity_approved' => 9,
        'quantity_rejected' => 1,
        'production_notes' => 'Rejeição: dimensional',
        'actual_production_hours' => 5.5,
    ],
];

$productionOrderService->updateProgress($productionOrder, $progress, auth()->id());

// Concluir (automático vai para estoque ou cria requisition)
$productionOrderService->complete($productionOrder, auth()->id());
```

## Arquivos Principais

### Models
- `app/Models/Quote.php`
- `app/Models/QuoteItem.php`
- `app/Models/ProductionOrder.php`
- `app/Models/ProductionOrderItem.php`
- `app/Models/QuoteSequence.php`
- `app/Models/ProductionOrderSequence.php`

### Enums
- `app/Enum/Quote/Status.php`
- `app/Enum/ProductionOrder/Status.php`
- `app/Enum/ProductionOrder/Priority.php`
- `app/Enum/ProductionOrder/DestinationType.php`

### Services
- `app/Services/Quote/QuoteService.php`
- `app/Services/Quote/QuoteNumberGenerator.php`
- `app/Services/Quote/Validators/QuoteValidator.php`
- `app/Services/Quote/Actions/CreateQuote.php`
- `app/Services/Quote/Actions/SendForApproval.php`
- `app/Services/Quote/Actions/ApproveQuote.php`
- `app/Services/Quote/Actions/RejectQuote.php`
- `app/Services/Quote/Actions/ConvertToProductionOrder.php`
- `app/Services/ProductionOrder/ProductionOrderService.php`
- `app/Services/ProductionOrder/ProductionOrderNumberGenerator.php`
- `app/Services/ProductionOrder/Validators/ProductionOrderValidator.php`
- `app/Services/ProductionOrder/Actions/CreateProductionOrder.php`
- `app/Services/ProductionOrder/Actions/StartProduction.php`
- `app/Services/ProductionOrder/Actions/UpdateProgress.php`
- `app/Services/ProductionOrder/Actions/CompleteProduction.php`
- `app/Services/ProductionOrder/DestinationHandlers/StockDestinationHandler.php`
- `app/Services/ProductionOrder/DestinationHandlers/DirectDeliveryDestinationHandler.php`

### Migrations
- `database/migrations/2026_02_09_145428_create_quotes_table.php`
- `database/migrations/2026_02_09_145430_create_quote_items_table.php`
- `database/migrations/2026_02_09_145431_create_production_orders_table.php`
- `database/migrations/2026_02_09_145432_create_production_order_items_table.php`
- `database/migrations/2026_02_09_164310_create_quote_sequences_table.php`
- `database/migrations/2026_02_09_164312_create_production_order_sequences_table.php`
- `database/migrations/2026_02_09_162622_add_is_custom_manufacturing_to_products_table.php`

## Próximos Passos

1. **Filament Resources**: Criar interfaces administrativas para Quote e ProductionOrder
2. **Dashboard Widgets**: Métricas de produção em tempo real
3. **Relatórios**: Eficiência, qualidade, custos
4. **Notificações**: Alertas para aprovações e conclusões
5. **API**: Endpoints para integração externa (opcional)

---

**Versão:** 1.0  
**Data:** 09/02/2026  
**Autor:** Sistema Tornedon
