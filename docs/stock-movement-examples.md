# Exemplos de Uso - Sistema de Movimentação de Estoque

## 1. Executar Migration

```bash
cd c:\laragon\www\tornedon

# Executar migration
php artisan migrate

# Ou se preferir um ambiente limpo
php artisan migrate:fresh
```

A tabela `stock_movements` será criada no banco de dados.

---

## 2. Acessar via Filament

1. Acesse o painel administrativo: `http://localhost/admin`
2. No menu lateral, procure por **Estoque** (Inventory Cluster)
3. Clique em **Movimentações de Estoque**
4. A tela listará todas as movimentações registradas

### Listagem
- Veja todas as movimentações com filtros e busca
- Ordene por data, produto, tipo de movimento
- Visualize detalhes como usuário responsável e custo

### Criar Movimentação
- Clique no botão **+ Criar**
- Preencha os campos obrigatórios:
  - **Tipo de Movimento**: Entrada, Saída, Ajuste, etc.
  - **Produto** (via ProductStock)
  - **Quantidade**
  - **Usuário Responsável**
- Preencha dados opcionais (custo, motivo, observações)
- Clique em **Salvar**

### Editar Movimentação
- Clique no registro desejado
- Altere os dados
- Clique em **Salvar**

### Deletar Movimentação
- Abra o registro
- Clique no botão **Deletar** (lixeira)
- Confirme a ação
- O registro é soft-deletado (pode ser restaurado)

---

## 3. Uso Programático

### Exemplo 1: Criar uma Entrada de Estoque

```php
<?php

use App\Services\StockMovement\StockMovementService;
use App\Enum\StockMovement\Type;
use Illuminate\Support\Facades\Auth;

// Get the service
$service = app(StockMovementService::class);

// Prepare data
$data = [
    'product_stock_id'  => 1,  // ID do registro em product_stocks
    'product_id'        => 10, // ID do produto
    'type'              => Type::ENTRY->value, // 'entry'
    'quantity'          => 100.000,             // 100 unidades
    'unit_cost'         => 25.50,              // R$ 25,50
    'total_cost'        => 2550.00,            // R$ 2.550,00
    'user_id'           => 5,                  // ID do usuário responsável
    'company_id'        => 1,                  // ID da empresa
    'reason'            => 'Compra do fornecedor',
    'observations'      => 'Nota Fiscal #12345 - Data: 24/02/2026',
    'reference_type'    => 'purchase_order',
    'reference_id'      => 456,
];

// Create movement
$movement = $service->create($data, Auth::id());

if ($service->hasError()) {
    // Handle error
    echo "Erro: " . $service->getMessage();
    echo "Código: " . $service->getErrorCode();
} else {
    // Success
    echo "Movimentação criada com ID: " . $movement->id;
}
```

### Exemplo 2: Registrar Saída (Consumo)

```php
$data = [
    'product_stock_id'  => 1,       // ID do ProductStock
    'product_id'        => 10,      // ID do produto
    'type'              => Type::CONSUMPTION->value, // 'consumption'
    'quantity'          => 50.000,  // 50 unidades consumidas
    'unit_cost'         => 25.50,   // Custo unitário
    'total_cost'        => 1275.00, // Custo total
    'user_id'           => 3,       // Usuário responsável
    'company_id'        => 1,       // Empresa
    'reason'            => 'Consumo em produção',
    'reference_type'    => 'production_order',
    'reference_id'      => 789,
];

$movement = $service->create($data, Auth::id());
```

### Exemplo 3: Registrar Ajuste (Quebra/Perda)

```php
$data = [
    'product_stock_id'  => 1,
    'product_id'        => 10,
    'type'              => Type::LOSS->value, // 'loss'
    'quantity'          => 5.000,  // 5 unidades
    'unit_cost'         => 25.50,
    'total_cost'        => 127.50,
    'user_id'           => 4,
    'company_id'        => 1,
    'reason'            => 'Quebra detectada em auditoria',
    'observations'      => 'Produto danificado, descartado',
];

$movement = $service->create($data, Auth::id());
```

### Exemplo 4: Listar Movimentações com Filtros

```php
// List all movements for a company
$allMovements = $service->list(companyId: 1);

// List entries only
$entries = $service->list(
    companyId: 1,
    filters: [
        'type' => Type::ENTRY->value
    ]
);

// List movements of a specific product
$productMovements = $service->listByProduct(
    productId: 10,
    companyId: 1
);

// List with date range (useful for reports)
$movements = $service->list(
    companyId: 1,
    filters: [
        'from_date' => '2026-01-01',
        'to_date'   => '2026-02-28',
    ]
);
```

### Exemplo 5: Buscar uma Movimentação

```php
// Get by ID
$movement = $service->find(id: 1, companyId: 1);

if ($movement) {
    echo "Produto: " . $movement->product->name;
    echo "Tipo: " . $movement->type->label();
    echo "Quantidade: " . $movement->quantity;
    echo "Data: " . $movement->created_at->format('d/m/Y H:i');
}
```

### Exemplo 6: Atualizar uma Movimentação

```php
$movement = $service->find(id: 1, companyId: 1);

if ($movement) {
    $updated = $service->update(
        movement: $movement,
        data: [
            'quantity'      => 120.000,    // Corrigir quantidade
            'observations'  => 'Quantidade corrigida conforme auditoria',
        ],
        updatedBy: Auth::id()
    );

    if ($service->hasError()) {
        echo "Erro ao atualizar: " . $service->getMessage();
    } else {
        echo "Movimentação atualizada com sucesso";
    }
}
```

### Exemplo 7: Deletar uma Movimentação

```php
$movement = $service->find(id: 1, companyId: 1);

// Soft delete (pode ser restaurado)
if ($service->delete($movement)) {
    echo "Movimentação marcada como deletada";
}

// Force delete (permanente)
if ($service->forceDelete($movement)) {
    echo "Movimentação deletada permanentemente";
}

// Restore a soft-deleted movement
if ($service->restore(id: 1)) {
    echo "Movimentação restaurada";
}
```

---

## 4. Usar em Controladores/Livewire

```php
<?php

namespace App\Http\Controllers;

use App\Services\StockMovement\StockMovementService;
use App\Enum\StockMovement\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockAuditController extends Controller
{
    public function registerMovement(Request $request)
    {
        $service = app(StockMovementService::class);

        $data = $request->validate([
            'product_stock_id' => 'required|exists:product_stocks,id',
            'type'             => 'required|in:entry,exit,adjustment,transfer,return,consumption,loss',
            'quantity'         => 'required|numeric|min:0.001',
            'user_id'          => 'required|exists:users,id',
            'reason'           => 'nullable|string|max:500',
            'observations'     => 'nullable|string|max:1000',
        ]);

        $data['company_id'] = Auth::user()->company_id;
        $movement = $service->create($data, Auth::id());

        if ($service->hasError()) {
            return response()->json([
                'success' => false,
                'message' => $service->getMessage(),
            ], 422);
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Movimentação registrada com sucesso',
            'movement'   => $movement,
        ]);
    }

    public function listMovements(Request $request)
    {
        $service = app(StockMovementService::class);

        $movements = $service->list(
            companyId: Auth::user()->company_id,
            filters: $request->only(['type', 'product_id', 'from_date', 'to_date'])
        );

        return response()->json($movements);
    }
}
```

---

## 5. Integração com Requisições

```php
<?php

// Quando uma requisição é criada e items são adicionados,
// registrar saída de estoque automaticamente

namespace App\Services\Requisition;

use App\Services\StockMovement\StockMovementService;
use App\Enum\StockMovement\Type;

class RequisitionService
{
    public function closeRequisition(Requisition $requisition, int $userId)
    {
        // ... lógica de fechamento ...

        // Register stock movements for each item
        foreach ($requisition->items as $item) {
            $stockService = app(StockMovementService::class);

            $stockService->create([
                'product_stock_id' => $item->product->productStock->id,
                'product_id'       => $item->product_id,
                'type'             => Type::EXIT->value,
                'quantity'         => $item->quantity,
                'unit_cost'        => $item->unit_cost,
                'total_cost'       => $item->quantity * $item->unit_cost,
                'user_id'          => $userId,
                'company_id'       => $requisition->company_id,
                'reason'           => 'Consumo de requisição',
                'reference_type'   => 'requisition',
                'reference_id'     => $requisition->id,
            ], $userId);
        }
    }
}
```

---

## 6. Queries Úteis

### Ver todas as movimentações
```php
use App\Models\StockMovement;

$movements = StockMovement::with(['product', 'user', 'createdBy'])
    ->orderBy('created_at', 'desc')
    ->paginate(50);
```

### Ver movimentações de um produto
```php
$movements = StockMovement::where('product_id', 10)
    ->where('company_id', 1)
    ->orderBy('created_at', 'desc')
    ->get();
```

### Totalizar entradas vs saídas
```php
use App\Enum\StockMovement\Type;

$entries = StockMovement::where('company_id', 1)
    ->where('type', Type::ENTRY->value)
    ->sum('quantity');

$exits = StockMovement::where('company_id', 1)
    ->whereIn('type', [Type::EXIT->value, Type::CONSUMPTION->value])
    ->sum('quantity');
```

### Ver movimentações deletadas
```php
$deleted = StockMovement::onlyTrashed()
    ->where('company_id', 1)
    ->get();
```

---

## 7. Troubleshooting

### Erro: "Product stock not found"
- Certifique-se de que o `product_stock_id` existe na tabela `product_stocks`
- Verifique se o produto está cadastrado

### Erro: "Validation error"
- Verifique se todos os campos obrigatórios foram preenchidos
- Verifique os tipos de dados (quantity deve ser numérico, type deve ser um valor válido)

### Movimento não aparece na listagem
- Verifique se a empresa está correta (company_id)
- Consulte os logs: `storage/logs/laravel.log`
- Verifique se o registro foi soft-deleted

### Performance em grandes volumes
- Use paginação: `$movements->paginate(50)`
- Use índices na coluna `created_at` e `company_id`
- Implemente cache para relatórios frequentes
