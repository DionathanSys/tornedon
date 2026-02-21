# Sistema de Autorização - Service Order Items

## ✅ Implementado

### 1. Trait de Autorização
**`app/Traits/AuthorizesServiceOrderItemActions.php`**
- ✅ `canAddItems()` - Verifica permissão + estado
- ✅ `canEditItems()` - Verifica permissão + estado
- ✅ `canDeleteItems()` - Verifica permissão + estado
- ✅ `canModifyItemPricing()` - Verifica permissão + estado
- ✅ `authorizeItemAction()` - Lança exceção se não autorizado

### 2. Policy de Service Order
**`app/Policies/ServiceOrderPolicy.php`**
- ✅ Controle de permissões por usuário
- ✅ Validação de empresa (multi-tenant)
- ✅ Permissões granulares (view, update, delete, etc.)

### 3. States Atualizados
**`app/Services/ServiceOrder/States/`**
- ✅ `ServiceOrderState.php` - Base com métodos de permissão
- ✅ `OpenState.php` - Permite todas operações
- ✅ `ClosedState.php` - Não permite modificações
- ✅ `InvoicedState.php` - Completamente bloqueado
- ✅ `CancelledState.php` - Completamente bloqueado

### 4. Trait de Parse de Valores
**`app/Traits/ParsesMoneyValues.php`**
- ✅ Converte valores BR (1.234,56) para float
- ✅ Reutilizável em toda aplicação

### 5. Action Atualizada
**`app/Filament/.../CreateItemAction.php`**
- ✅ Importa traits de autorização
- ✅ Controla visibilidade do botão
- ✅ Valida antes de criar item
- ✅ Corrigido problema de conversão de valores

### 6. User Model
**`app/Models/User.php`**
- ✅ Método `belongsToCompany()` adicionado

### 7. Exemplos de Implementação
- ✅ `CreateItemActionExample.php` - Exemplo completo de criar
- ✅ `EditItemActionExample.php` - Exemplo completo de editar
- ✅ `DeleteItemActionExample.php` - Exemplo completo de excluir

### 8. Documentação
**`docs/service-order-authorization.md`**
- ✅ Guia completo de uso
- ✅ Matriz de permissões por estado
- ✅ Exemplos práticos
- ✅ Como customizar e estender

## 📋 Próximos Passos

### 1. Registrar a Policy
```php
// app/Providers/AppServiceProvider.php
use App\Models\ServiceOrder;
use App\Policies\ServiceOrderPolicy;

Gate::policy(ServiceOrder::class, ServiceOrderPolicy::class);
```

### 2. Configurar Permissões
Configure as permissões no seu sistema. Você pode usar o método `can()` do User model com Filament Shield ou Gates customizados:

```php
// Exemplo: definir gates customizados no AppServiceProvider
Gate::define('modify_service_order_pricing', function (User $user) {
    return $user->hasRole('manager') || $user->hasRole('admin');
});

// Ou implemente o método can() no User model
public function can($abilities, $arguments = [])
{
    // Sua lógica de verificação de permissões
}
```

### 3. Atualizar States Restantes
Verifique se tem mais estados e adicione os métodos de permissão.

### 4. Aplicar nas Actions Existentes

**EditItemAction:**
```php
use AuthorizesServiceOrderItemActions;

EditAction::make()
    ->visible(fn ($record, RelationManager $livewire) => 
        (new self())->canEditItems($livewire->getOwnerRecord())
    )
    ->using(function (Model $record, array $data, RelationManager $livewire) {
        (new self())->authorizeItemAction('edit', $livewire->getOwnerRecord());
        // ... resto do código
    });
```

**DeleteItemAction:**
```php
use AuthorizesServiceOrderItemActions;

DeleteAction::make()
    ->visible(fn ($record, RelationManager $livewire) => 
        (new self())->canDeleteItems($livewire->getOwnerRecord())
    )
    ->before(function ($record, RelationManager $livewire) {
        (new self())->authorizeItemAction('delete', $livewire->getOwnerRecord());
    });
```

### 5. Testar Cenários

**Teste 1: Ordem Aberta**
- ✅ Deve permitir adicionar itens
- ✅ Deve permitir editar itens
- ✅ Deve permitir excluir itens

**Teste 2: Ordem Encerrada**
- ❌ Não deve permitir adicionar itens
- ❌ Não deve permitir editar itens
- ❌ Não deve permitir excluir itens

**Teste 3: Ordem Faturada**
- ❌ Não deve permitir nenhuma modificação

**Teste 4: Permissão de Modificar Preços**
- ✅ Usuário com permissão deve poder editar preços
- ❌ Usuário sem permissão deve ver campo desabilitado

## 🔧 Como Usar

### Exemplo Básico
```php
use App\Traits\AuthorizesServiceOrderItemActions;

class MinhaAction
{
    use AuthorizesServiceOrderItemActions;
    
    public function handle($serviceOrder)
    {
        // Verifica se pode
        if ($this->canAddItems($serviceOrder)) {
            // faz algo
        }
        
        // Ou lança exceção
        $this->authorizeItemAction('add', $serviceOrder);
    }
}
```

### No Filament
```php
->visible(function (RelationManager $livewire) {
    $order = $livewire->getOwnerRecord();
    return (new self())->canAddItems($order);
})
```

## 📊 Matriz de Permissões

| Estado      | Adicionar | Editar | Excluir | Modificar Preço |
|-------------|-----------|--------|---------|-----------------|
| Aberta      | ✅        | ✅     | ✅      | ✅*             |
| Encerrada   | ❌        | ❌     | ❌      | ❌              |
| Faturada    | ❌        | ❌     | ❌      | ❌              |
| Cancelada   | ❌        | ❌     | ❌      | ❌              |

\* Requer permissão adicional `modify_service_order_pricing`

## 🎯 Vantagens

✅ **Segurança** - Dupla validação (user + state)  
✅ **UX Melhor** - Botões ficam invisíveis quando não permitido  
✅ **Reutilizável** - Mesma lógica em qualquer lugar  
✅ **Testável** - Fácil criar testes unitários  
✅ **Extensível** - Adicionar novos estados e permissões facilmente  
✅ **Auditável** - Logs de tentativas bloqueadas  

## 📝 Notas Importantes

1. **Sempre use a trait** nas Actions que manipulam itens
2. **Valide no `.using()`** além de controlar visibilidade
3. **Customize mensagens** para melhor UX
4. **Estados são imutáveis** - cada estado define suas regras
5. **Permissões são cumulativas** - precisa de permissão E estado correto
