# Arquitetura Base

```md
Filament (UI)  
↓  
Service (orquestra fluxo)  
↓  
Actions (regra de negócio isolada)   
↓  
Models
```

### Regra rígida:

-  Filament NÃO acessa Model
-  Service NÃO contém regra de negócio pesada
-  Model NÃO contém regra de domínio
-  Action executa regra

# Responsabilidades Claras

##  Service (Fluxo)

Exemplo: App\Services\Requisition\RequisitionService

Responsável por:

- Iniciar transação
- Chamar actions
- Garantir ordem correta
- Garantir multi-tenant

Não faz cálculo complexo.

---

##  Action (Regra real)

Exemplo: App\Services\Requisition\Actions\CreateRequisitionAction

Responsável por:

- Validar
- Criar Requisition
# Fluxo Completo – Requisição

## Cenário:

### Criar requisição que consome estoque.

**Fluxo ideal:**

Filament  
  ↓  
RequisitionService  
  ↓  
CreateRequisitionAction  
  ↓  
MovementStockService
  ↓  
App\Services\StockMovement\Actions\CreateStockMovementAction  
  ↓  
MovementStock  
  ↓  
App\Services\StockMovement\Actions\ApplyMovementToProductStockAction
  ↓
ProductsStock

# Fluxo – Ordem de Produção

Ordem de produção pode:

- Consumir matéria-prima
- Gerar produto acabado

Fluxo:

ProductionOrderService  
   ↓  
ConsumeRawMaterialAction  
   ↓  
RegisterStockExitAction  

Depois:  
   ↓  
MovementStockService
  ↓  
App\Services\StockMovement\Actions\CreateStockMovementAction  
  ↓  
MovementStock  
  ↓  
App\Services\StockMovement\Actions\ApplyMovementToProductStockAction
  ↓
ProductsStock

**Sempre via MovementStock.**
