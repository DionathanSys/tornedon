# State Machine - ProductionOrder

## 📚 O que é State Machine?

Uma **State Machine (Máquina de Estados)** é um padrão de design que:
- Gerencia os estados de um objeto de forma explícita
- Define quais transições são permitidas entre estados
- Centraliza a lógica de mudança de estado
- Previne transições inválidas com exceções claras

## 🎯 Fluxo de Estados

```
┌─────────────────────────────────────────────────────────┐
│                                                         │
│   QUEUED (Na Fila)                                     │
│       │                                                 │
│       │ start()                                         │
│       ▼                                                 │
│   IN_PROGRESS (Em Produção)                            │
│       │                                                 │
│       │ sendToQC()                                     │
│       ▼                                                 │
│   QC_CHECK (Controle de Qualidade)                     │
│       │   │                                             │
│       │   │ returnToProduction()                       │
│       │   └────────────────────┐                       │
│       │                        ▼                       │
│       │                   IN_PROGRESS                  │
│       │                                                 │
│       │ complete()                                     │
│       ▼                                                 │
│   COMPLETED (Concluído)                                │
│                                                         │
│   A qualquer momento: cancel() → CANCELLED             │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

## 🏗️ Estrutura de Arquivos

```
app/
├── Domain/Exceptions/ProductionOrder/
│   └── InvalidStateTransitionException.php
├── Services/ProductionOrder/
│   ├── StateResolver.php
│   └── States/
│       ├── ProductionOrderState.php (classe abstrata)
│       ├── QueuedState.php
│       ├── InProgressState.php
│       ├── QcCheckState.php
│       ├── CompletedState.php
│       └── CancelledState.php
└── Models/
    └── ProductionOrder.php (método state())
```

## 💡 Como Usar

### 1. Iniciar Produção

```php
$productionOrder = ProductionOrder::find(1);

try {
    $productionOrder->state()->start();
    // Status muda de QUEUED para IN_PROGRESS
    // Campo started_at é preenchido automaticamente
} catch (InvalidStateTransitionException $e) {
    // Não é possível iniciar neste estado
    echo $e->getMessage();
}
```

### 2. Enviar para QC

```php
try {
    $productionOrder->state()->sendToQC();
    // Status muda de IN_PROGRESS para QC_CHECK
} catch (InvalidStateTransitionException $e) {
    echo $e->getMessage();
}
```

### 3. Retornar da QC para Produção (Item Reprovado)

```php
try {
    $productionOrder->state()->returnToProduction();
    // Status volta de QC_CHECK para IN_PROGRESS
} catch (InvalidStateTransitionException $e) {
    echo $e->getMessage();
}
```

### 4. Concluir Ordem

```php
try {
    $productionOrder->state()->complete();
    // Status muda de QC_CHECK para COMPLETED
    // Campo completed_at é preenchido automaticamente
} catch (InvalidStateTransitionException $e) {
    echo $e->getMessage();
}
```

### 5. Cancelar Ordem

```php
try {
    $productionOrder->state()->cancel();
    // Status muda para CANCELLED
    // Campo cancelled_at é preenchido automaticamente
    // Pode ser feito de qualquer estado (exceto COMPLETED/CANCELLED)
} catch (InvalidStateTransitionException $e) {
    echo $e->getMessage();
}
```

## 🔄 Exemplo Completo de Workflow

```php
use App\Models\ProductionOrder;
use App\Domain\Exceptions\ProductionOrder\InvalidStateTransitionException;

$order = ProductionOrder::find(1);

// 1. Iniciar produção
try {
    $order->state()->start();
    echo "Produção iniciada!\n";
} catch (InvalidStateTransitionException $e) {
    echo "Erro: {$e->getMessage()}\n";
}

// 2. Atualizar progresso (lógica de negócio)
foreach ($order->items as $item) {
    $item->update([
        'quantity_produced' => 100,
    ]);
}

// 3. Enviar para QC
try {
    $order->state()->sendToQC();
    echo "Enviado para QC!\n";
} catch (InvalidStateTransitionException $e) {
    echo "Erro: {$e->getMessage()}\n";
}

// 4. QC aprova ou reprova
if ($order->getQualityRate() > 95) {
    // Aprovar e concluir
    try {
        $order->state()->complete();
        echo "Ordem concluída com sucesso!\n";
    } catch (InvalidStateTransitionException $e) {
        echo "Erro: {$e->getMessage()}\n";
    }
} else {
    // Reprovar e retornar para produção
    try {
        $order->state()->returnToProduction();
        echo "Retornado para reparo!\n";
    } catch (InvalidStateTransitionException $e) {
        echo "Erro: {$e->getMessage()}\n";
    }
}
```

## 🎨 Uso no Filament (Actions Customizadas)

### Action para Iniciar Produção

```php
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use App\Notification\NotifyService as notify;

Action::make('start_production')
    ->label('Iniciar Produção')
    ->icon(Heroicon::Play)
    ->color('success')
    ->requiresConfirmation()
    ->visible(fn (ProductionOrder $record) => $record->status === Status::QUEUED)
    ->action(function (ProductionOrder $record) {
        try {
            $record->state()->start();
            notify::success('Produção iniciada com sucesso!');
        } catch (InvalidStateTransitionException $e) {
            notify::error($e->getMessage());
        }
    })
```

### Action para Enviar para QC

```php
Action::make('send_to_qc')
    ->label('Enviar para QC')
    ->icon(Heroicon::ClipboardDocumentCheck)
    ->color('warning')
    ->requiresConfirmation()
    ->visible(fn (ProductionOrder $record) => $record->status === Status::IN_PROGRESS)
    ->action(function (ProductionOrder $record) {
        try {
            $record->state()->sendToQC();
            notify::success('Enviado para controle de qualidade!');
        } catch (InvalidStateTransitionException $e) {
            notify::error($e->getMessage());
        }
    })
```

### Action para Concluir

```php
Action::make('complete')
    ->label('Concluir Ordem')
    ->icon(Heroicon::CheckCircle)
    ->color('success')
    ->requiresConfirmation()
    ->visible(fn (ProductionOrder $record) => $record->status === Status::QC_CHECK)
    ->action(function (ProductionOrder $record) {
        try {
            $record->state()->complete();
            notify::success('Ordem concluída com sucesso!');
        } catch (InvalidStateTransitionException $e) {
            notify::error($e->getMessage());
        }
    })
```

## ✅ Vantagens da State Machine

1. **Segurança**: Impossível fazer transições inválidas
2. **Clareza**: Código auto-documentado sobre o que é permitido
3. **Manutenibilidade**: Lógica de estado centralizada
4. **Logs**: Cada transição é registrada automaticamente
5. **Testabilidade**: Fácil testar cada estado isoladamente

## 🧪 Testes

```php
use Tests\TestCase;
use App\Models\ProductionOrder;
use App\Enum\ProductionOrder\Status;

class ProductionOrderStateTest extends TestCase
{
    public function test_can_start_production_from_queued()
    {
        $order = ProductionOrder::factory()->create([
            'status' => Status::QUEUED
        ]);
        
        $order->state()->start();
        
        $this->assertEquals(Status::IN_PROGRESS, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->started_at);
    }
    
    public function test_cannot_start_production_from_completed()
    {
        $order = ProductionOrder::factory()->create([
            'status' => Status::COMPLETED
        ]);
        
        $this->expectException(InvalidStateTransitionException::class);
        $order->state()->start();
    }
}
```

## 📊 Comparação: Antes vs Depois

### ❌ Antes (sem State Machine)

```php
// Código espalhado, sem validação
$order->update(['status' => Status::COMPLETED]);
// Pode causar problemas se o status atual for inválido
```

### ✅ Depois (com State Machine)

```php
// Transição segura e validada
$order->state()->complete();
// Lança exceção se não for permitido neste estado
```

## 🔧 Extensões Futuras

### 1. Eventos (já implementado via Log)
```php
// Em cada estado, você pode adicionar:
event(new ProductionOrderStarted($this->productionOrder));
```

### 2. Hooks/Callbacks
```php
// Adicionar em ProductionOrderState:
protected function beforeTransition(): void {}
protected function afterTransition(): void {}
```

### 3. Histórico de Estados
```php
// Criar migration para production_order_state_history
Schema::create('production_order_state_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('production_order_id');
    $table->string('from_state');
    $table->string('to_state');
    $table->foreignId('user_id');
    $table->timestamp('transitioned_at');
});
```

## 📝 Notas

- Estados finais (`COMPLETED`, `CANCELLED`) não permitem transições
- Logs são registrados automaticamente em `storage/logs/laravel.log`
- Validações de transição são feitas antes de modificar o banco
- Exceções são descritivas e contêm o estado atual e a ação tentada
