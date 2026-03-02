# Sistema de Autorização de Itens de Ordem de Serviço

## Visão Geral

Este sistema implementa um controle de permissões em **duas camadas**:

1. **Permissões do Usuário** - Via Laravel Policies
2. **Estado da Ordem** - Via State Pattern

## Arquitetura

```
┌─────────────────────────────────────┐
│  Filament Action (UI)               │
│  - visible()                        │
│  - disabled()                       │
│  - before()                         │
└──────────┬──────────────────────────┘
           │
           v
┌─────────────────────────────────────┐
│  AuthorizesServiceOrderItemActions  │ (Trait)
│  - canAddItems()                    │
│  - canEditItems()                   │
│  - canDeleteItems()                 │
│  - canModifyItemPricing()           │
│  - authorizeItemAction()            │
└──────────┬──────────────────────────┘
           │
           ├──> Gate::allows('update', $order)  ──> ServiceOrderPolicy
           │
           └──> $order->state()->canAddItems()  ──> State Pattern
```

## Componentes Criados

### 1. Trait: AuthorizesServiceOrderItemActions
**Local:** `app/Traits/AuthorizesServiceOrderItemActions.php`

```php
// Uso
use AuthorizesServiceOrderItemActions;

// Verifica se pode adicionar
if ($this->canAddItems($serviceOrder)) {
    // permitido
}

// Lança exceção se não autorizado
$this->authorizeItemAction('add', $serviceOrder);
```

### 2. Policy: ServiceOrderPolicy
**Local:** `app/Policies/ServiceOrderPolicy.php`

Define permissões baseadas no usuário:
- `view` - Ver ordens
- `update` - Atualizar ordens
- `delete` - Excluir ordens
- `modifyPricing` - Modificar preços/descontos
- `close` - Encerrar ordens
- `cancel` - Cancelar ordens
- `invoice` - Faturar ordens

**Registrar no Provider:**
```php
// app/Providers/AuthServiceProvider.php
protected $policies = [
    ServiceOrder::class => ServiceOrderPolicy::class,
];
```

### 3. States: ServiceOrderState
**Local:** `app/Services/ServiceOrder/States/`

Cada estado define quais operações são permitidas:

#### OpenState (Aberta)
✅ Permite tudo: adicionar, editar, excluir, modificar preços

#### ClosedState (Encerrada)
❌ Não permite modificações em itens
✅ Pode faturar
✅ Pode cancelar

#### InvoicedState (Faturada)
❌ Não permite NENHUMA modificação

#### CancelledState (Cancelada)
❌ Não permite NENHUMA modificação

## Como Usar nas Actions do Filament

### CreateItemAction

```php
use AuthorizesServiceOrderItemActions;

CreateAction::make()
    // Esconde o botão se não tiver permissão
    ->visible(function (RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        return (new self())->canAddItems($serviceOrder);
    })
    
    // Desabilita com tooltip explicativo
    ->disabled(function (RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        return !(new self())->canAddItems($serviceOrder);
    })
    ->disabledTooltip(function (RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        $state = $serviceOrder->status->description();
        return "Não é possível adicionar itens quando a ordem está {$state}";
    })
    
    // Validação antes de salvar
    ->using(function (array $data, RelationManager $livewire): ?Model {
        $serviceOrder = $livewire->getOwnerRecord();
        
        try {
            (new self())->authorizeItemAction('add', $serviceOrder);
        } catch (\Throwable $e) {
            notify::error(message: $e->getMessage());
            return null;
        }
        
        // ... resto da lógica
    });
```

### EditItemAction

```php
EditAction::make()
    ->visible(function ($record, RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        return (new self())->canEditItems($serviceOrder);
    })
    
    // Campos condicionais baseados em permissão
    ->schema([
        Money::make('unit_price')
            ->disabled(function (RelationManager $livewire) {
                $serviceOrder = $livewire->getOwnerRecord();
                return !(new self())->canModifyItemPricing($serviceOrder);
            }),
    ])
    
    ->using(function (Model $record, array $data, RelationManager $livewire): ?Model {
        $serviceOrder = $livewire->getOwnerRecord();
        
        try {
            (new self())->authorizeItemAction('edit', $serviceOrder);
        } catch (\Throwable $e) {
            notify::error(message: $e->getMessage());
            return null;
        }
        
        // ... atualização
    });
```

### DeleteItemAction

```php
DeleteAction::make()
    ->visible(function ($record, RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        return (new self())->canDeleteItems($serviceOrder);
    })
    
    ->before(function ($record, RelationManager $livewire) {
        $serviceOrder = $livewire->getOwnerRecord();
        (new self())->authorizeItemAction('delete', $serviceOrder);
    });
```

## Regras de Negócio

### Matriz de Permissões por Estado

| Ação                 | Aberta | Encerrada | Faturada | Cancelada |
|---------------------|--------|-----------|----------|-----------|
| Adicionar Item      | ✅     | ❌        | ❌       | ❌        |
| Editar Item         | ✅     | ❌        | ❌       | ❌        |
| Excluir Item        | ✅     | ❌        | ❌       | ❌        |
| Modificar Preço     | ✅*    | ❌        | ❌       | ❌        |
| Encerrar Ordem      | ✅     | ❌        | ❌       | ❌        |
| Faturar Ordem       | ❌     | ✅        | ❌       | ❌        |
| Cancelar Ordem      | ✅     | ✅        | ❌       | ❌        |

\* Requer permissão adicional `modify-pricing`

## Customização

### Adicionar Nova Permissão

1. **Adicionar método no Trait:**
```php
// app/Traits/AuthorizesServiceOrderItemActions.php
protected function canApproveItems(ServiceOrder $order, ?User $user = null): bool
{
    $user = $user ?? auth()->user();
    
    if (!Gate::forUser($user)->allows('approve', $order)) {
        return false;
    }
    
    return $order->state()->canApproveItems();
}
```

2. **Adicionar método no State base:**
```php
// app/Services/ServiceOrder/States/ServiceOrderState.php
public function canApproveItems(): bool
{
    return false;
}
```

3. **Implementar em estados específicos:**
```php
// app/Services/ServiceOrder/States/OpenState.php
public function canApproveItems(): bool
{
    return true;
}
```

### Customizar Regras de Estado

Edite os arquivos em `app/Services/ServiceOrder/States/`:

```php
// Por exemplo: permitir edição em ordem encerrada
class ClosedState extends ServiceOrderState
{
    public function canEditItems(): bool
    {
        // Regra customizada
        return $this->ordem->created_at->diffInHours(now()) < 24;
    }
}
```

## Permissões do Laravel

Configure as permissões no seu sistema de ACL. Você pode usar o sistema nativo do Laravel com Gates e Policies, ou integrar com Filament Shield:

```php
// Exemplo com Gates personalizados
Gate::define('modify_service_order_pricing', function (User $user) {
    return $user->hasRole('manager') || $user->hasRole('admin');
});

// Ou use Filament Shield plugin se preferir
```

## Testando

```php
// Em Tinker ou testes
$order = ServiceOrder::find(1);
$user = User::find(1);

// Testar permissão
$trait = new class {
    use \App\Traits\AuthorizesServiceOrderItemActions;
};

$trait->canAddItems($order, $user); // true/false
$trait->canEditItems($order, $user); // true/false
```

## Exemplos Práticos

### Exemplo 1: Diferentes Permissões por Role

```php
// Manager: pode editar preços apenas em ordens abertas
if ($user->hasRole('manager') && $order->status->value === 'aberta') {
    // OK
}

// Financeiro: pode modificar preços sempre
if ($user->hasRole('financeiro')) {
    // OK
}
```

### Exemplo 2: Validação no Service Layer

```php
// ServiceOrderItemService
public function create(array $data, int $userId): ?ServiceOrderItem
{
    $order = ServiceOrder::find($data['service_order_id']);
    $user = User::find($userId);
    
    $authorizer = new class {
        use AuthorizesServiceOrderItemActions;
    };
    
    if (!$authorizer->canAddItems($order, $user)) {
        $this->setError('Não é possível adicionar itens neste estado da ordem');
        return null;
    }
    
    // ... criar item
}
```

## Vantagens desta Abordagem

✅ **Separação de Responsabilidades** - UI (Filament) / Autorização (Trait) / Estado (State Pattern)

✅ **Reutilizável** - Mesma lógica em Actions, Services, Controllers

✅ **Testável** - Fácil criar testes unitários para cada estado

✅ **Extensível** - Adicionar novos estados e permissões sem quebrar o código

✅ **Type-Safe** - Com Enum States, evita erros de string

✅ **Feedback Claro** - Mensagens específicas para cada situação

## Próximos Passos

1. Registrar a Policy no `AuthServiceProvider`
2. Configurar permissões no banco de dados
3. Aplicar a trait nas Actions existentes
4. Criar testes para cada cenário
5. Adicionar audit log das ações bloqueadas
