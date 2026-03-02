# Sistema de Controle de Movimentação de Estoque

## Visão Geral
Implementação completa de um sistema de controle de movimentações de estoque seguindo os padrões adotados no projeto.

## Estrutura Implementada

### 1. Enum - Tipos de Movimento
**Localização:** `app/Enum/StockMovement/Type.php`

Define os tipos de movimentação possíveis:
- **ENTRY**: Entrada de estoque
- **EXIT**: Saída de estoque
- **ADJUSTMENT**: Ajuste de estoque
- **TRANSFER**: Transferência entre locais
- **RETURN**: Devolução
- **CONSUMPTION**: Consumo de produção
- **LOSS**: Perda/Estrago

Cada tipo tem:
- `label()`: Rótulo legível em português
- `color()`: Cor para exibição no Filament (badges)

---

### 2. Migration - Tabela `stock_movements`
**Localização:** `database/migrations/2026_02_24_000000_create_stock_movements_table.php`

#### Estrutura da Tabela:
```
- id (PK)
- product_stock_id (FK → product_stocks)
- product_id (FK → products)
- company_id (FK → companies)
- type (Enum)
- quantity (decimal 12,3)
- unit_cost (decimal 12,4)
- total_cost (decimal 12,4)
- user_id (FK → users) - Usuário responsável
- reason (TEXT) - Motivo da movimentação
- observations (TEXT)
- reference_type (VARCHAR) - Ex: requisition, service_order
- reference_id (BIGINT) - ID da referência
- additional_info (JSON)
- created_by (FK → users)
- updated_by (FK → users)
- timestamps e soft deletes
```

**Para executar a migration:**
```bash
php artisan migrate
```

---

### 3. Model - StockMovement
**Localização:** `app/Models/StockMovement.php`

#### Relacionamentos:
- `productStock()`: BelongsTo ProductStock
- `product()`: BelongsTo Product
- `company()`: BelongsTo Company
- `user()`: BelongsTo User (responsável)
- `createdBy()`: BelongsTo User (auditoria)
- `updatedBy()`: BelongsTo User (auditoria)

#### Casts:
- `type`: Convertido para Enum `Type`
- Campos numéricos com precisão específica
- JSON para dados adicionais

---

### 4. Validator - StockMovementValidator
**Localização:** `app/Services/StockMovement/Validators/StockMovementValidator.php`

#### Estrutura:
- `commonRules()`: Regras compartilhadas entre create e update
- `commonMessages()`: Mensagens em português compartilhadas
- `validateCreate(array $data)`: Validação para criação
- `validateUpdate(array $data)`: Validação para atualização

#### Regras Principales:
```php
- product_stock_id: obrigatório, inteiro, existe em product_stocks
- product_id: obrigatório, inteiro, existe em products
- type: obrigatório, deve ser um valor válido do Enum
- quantity: obrigatório, numérico, deve ser > 0.001
- unit_cost: opcional, numérico
- total_cost: opcional, numérico
- user_id: obrigatório, inteiro, existe em users
- reason: opcional, string até 500 caracteres
- observations: opcional, string até 1000 caracteres
- reference_type/reference_id: opcionais para rastreabilidade
- additional_info: opcional, array
```

---

### 5. Actions - Operações Atômicas
**Localização:** `app/Services/StockMovement/Actions/`

#### CreateStockMovementAction
Cria uma nova movimentação com validação e logging

#### UpdateStockMovementAction
Atualiza uma movimentação existente

#### DeleteStockMovementAction
- `execute()`: Soft delete
- `forceDelete()`: Delete permanente

**Características:**
- Trait `HandlesActionResponse` para gerenciar respostas
- Logging detalhado de todas as operações
- Tratamento de exceções específico

---

### 6. Service - Orquestração de Operações
**Localização:** `app/Services/StockMovement/StockMovementService.php`

#### Métodos de Consulta:
```php
list(int $companyId, array $filters = []): Collection
find(int $id, int $companyId = null): ?StockMovement
listByProduct(int $productId, int $companyId): Collection
```

#### Métodos de Escrita (com Transactions):
```php
create(array $data, int $createdBy): ?StockMovement
update(StockMovement $movement, array $data, int $updatedBy): ?StockMovement
delete(StockMovement $movement): bool
forceDelete(StockMovement $movement): bool
restore(int $id): ?StockMovement
```

**Características:**
- Orquestração via Actions
- Transações DB para integridade
- Trait `HandlesServiceResponse` para gerenciar sucesso/erro
- Logging extenso de todas as operações

---

### 7. Resource Filament
**Localização:** `app/Filament/Clusters/Inventory/Resources/StockMovements/`

#### StockMovementResource
Recurso principal dentro do cluster `InventoryCluster`

#### Pages:
1. **ListStockMovements** (`Pages/ListStockMovements.php`)
   - Listagem com filtros por tipo, data e período
   - Ordenação padrão por data decrescente
   - Busca em produto, motivo e observações

2. **CreateStockMovement** (`Pages/CreateStockMovement.php`)
   - Formulário via Schema `StockMovementForm`
   - Mutation de dados antes de criar
   - Validação via Service
   - Notificações ao usuário
   - Redirecionamento para listagem

3. **EditStockMovement** (`Pages/EditStockMovement.php`)
   - Edição via Service
   - Delete action com confirmação
   - Soft delete ou restore
   - Logging de todas as operações

#### Tabela - StockMovementsTable
**Localização:** `Tables/StockMovementsTable.php`

**Colunas:**
- Data (sortável)
- Produto (searchable, sortável)
- Tipo de Movimento (badge com cor)
- Quantidade (formatado)
- Custo Unitário (oculto por padrão)
- Custo Total (oculto por padrão)
- Usuário Responsável (searchable)
- Motivo (searchable, oculto por padrão)
- Referência (oculto por padrão)
- Observações (limitado, oculto por padrão)
- Auditoria (oculto por padrão)

**Filtros:**
- Tipo de Movimento (select)
- Período de Movimentação (date range)

#### Schema - StockMovementForm
**Localização:** `Schemas/StockMovementForm.php`

**Componentes:**
- Select para Tipo de Movimento
- Select para Produto (via ProductStock com label do produto)
- TextInput para Quantidade
- Money input para Custo Unitário
- Money input para Custo Total
- Select para Usuário Responsável
- TextInputs para Motivo, Tipo Ref, ID Ref
- Textarea para Observações

---

### 8. Actions Filament
**Localização:** `Actions/`

#### CreateStockMovementFromModalAction
Action para criar movimentação via modal (reutilizável em outras páginas)

#### RestoreStockMovementAction
Action para restaurar movimentações deletadas (soft restore)

---

## Padrão Adotado

### Fluxo de Dados:
```
Filament Page
    ↓
Service (orquestração + transaction)
    ↓
Actions (operação atômica)
    ↓
Validator (validação de dados)
    ↓
Model (persistência)
```

### Tratamento de Erro:
```
Validator → throws ValidationException
    ↓
Action catches e usa `HandlesActionResponse`
    ↓
Service catches e usa `HandlesServiceResponse`
    ↓
Filament Page recebe e notifica usuário
```

### Logging:
- DEBUG: Início de operações
- INFO: Operações bem-sucedidas
- ERROR: Falhas e exceções

### Auditoria:
- Todos os registros têm created_by e updated_by
- Soft deletes para histórico
- additional_info para dados flexíveis

---

## Como Usar

### 1. Criar uma Movimentação
```php
$service = app(StockMovementService::class);

$data = [
    'product_stock_id' => 1,
    'product_id' => 10,
    'type' => Type::ENTRY->value,
    'quantity' => 100,
    'unit_cost' => 10.50,
    'total_cost' => 1050.00,
    'user_id' => 5,
    'company_id' => 1,
    'reason' => 'Compra de fornecedor',
    'observations' => 'Nota fiscal #123',
];

$movement = $service->create($data, Auth::id());

if ($service->hasError()) {
    echo $service->getMessage(); // Mensagem de erro
}
```

### 2. Listar Movimentações
```php
$movements = $service->list(
    companyId: 1,
    filters: [
        'type' => 'entry',
        'product_id' => 10,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
    ]
);
```

### 3. Atualizar uma Movimentação
```php
$movement = $service->find(id: 5, companyId: 1);

$updated = $service->update(
    movement: $movement,
    data: ['quantity' => 150],
    updatedBy: Auth::id()
);
```

### 4. Deletar uma Movimentação
```php
// Soft delete
$service->delete($movement);

// Force delete
$service->forceDelete($movement);

// Restore
$service->restore(movementId: 5);
```

---

## Arquivos Criados

```
app/
├── Enum/
│   └── StockMovement/
│       └── Type.php
├── Models/
│   └── StockMovement.php
├── Services/
│   └── StockMovement/
│       ├── StockMovementService.php
│       ├── Actions/
│       │   ├── CreateStockMovementAction.php
│       │   ├── UpdateStockMovementAction.php
│       │   └── DeleteStockMovementAction.php
│       └── Validators/
│           └── StockMovementValidator.php
└── Filament/
    └── Clusters/
        └── Inventory/
            └── Resources/
                └── StockMovements/
                    ├── StockMovementResource.php
                    ├── Actions/
                    │   ├── CreateStockMovementFromModalAction.php
                    │   └── RestoreStockMovementAction.php
                    ├── Pages/
                    │   ├── ListStockMovements.php
                    │   ├── CreateStockMovement.php
                    │   └── EditStockMovement.php
                    ├── Schemas/
                    │   └── StockMovementForm.php
                    └── Tables/
                        └── StockMovementsTable.php

database/
└── migrations/
    └── 2026_02_24_000000_create_stock_movements_table.php
```

---

## Próximos Passos

1. **Executar Migration:**
   ```bash
   php artisan migrate
   ```

2. **Testar via Filament:**
   - Acessar `/admin/inventory/stock-movements`
   - Criar nova movimentação
   - Listar, editar e deletar

3. **Integração com outros módulos:**
   - Requisições podem criar entradas quando produtos são consumidos
   - Ordens de produção podem criar consumos
   - Sistema de devoluções pode criar movimentações de retorno

4. **Relatórios:**
   - Possibilidade de gerar relatórios de movimentação por período
   - Análise de entrada vs saída

---

## Notas Importantes

- ✅ Segue padrão `RequisitionService` do projeto
- ✅ Validador com método compartilhado de rules e messages
- ✅ Actions em classes exclusivas
- ✅ Service como controle de fluxo com transações
- ✅ Resource no cluster de Inventory
- ✅ Logging extenso em DEBUG, INFO e ERROR
- ✅ Auditoria completa (created_by, updated_by)
- ✅ Soft deletes para historicidade
- ✅ Filtros e busca na listagem
- ✅ Integração com NotifyService
