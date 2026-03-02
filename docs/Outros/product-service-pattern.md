# Product Service - Padrão de Implementação

## 📋 Estrutura de Camadas

### 1️⃣ Validator (Validação Reutilizável)
**Local:** `app/Services/Product/Validators/ProductValidator.php`

```php
class ProductValidator
{
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, [
            'name' => 'required|string|max:255',
            // ... regras
        ], [
            'name.required' => 'O nome é obrigatório',
            // ... mensagens
        ])->validate(); // Lança ValidationException
    }
    
    public static function validateUpdate(array $data): array
    {
        // Regras para atualização (com 'sometimes')
    }
}
```

**Responsabilidades:**
- Definir regras de validação
- Lançar `ValidationException` em caso de erro
- Reutilizável entre criação e atualização

---

### 2️⃣ Action (Execução Atômica)
**Local:** `app/Services/Product/Actions/CreateProductAction.php`

```php
class CreateProductAction
{
    use HandlesActionResponse; // ✅ Trait de comunicação
    
    public function __construct(
        private int $createdBy, // ✅ Parâmetros de contexto
    ) {}
    
    public function execute(array $data): ?Product
    {
        try {
            // 1. Validação
            $validated = ProductValidator::validateCreate($data);
            
            // 2. Lógica de negócio
            $validated['created_by'] = $this->createdBy;
            
            // 3. Persistência (SEM transaction)
            $product = Product::create($validated);
            
            $this->setSuccess(); // ✅ Marca sucesso
            return $product;
            
        } catch (ValidationException $e) {
            $this->setError('Falha de validação', $e->errors());
            Log::error(__METHOD__, ['error_code' => $this->getErrorCode()]);
            return null; // ✅ Retorna null, não lança exceção
            
        } catch (QueryException $e) {
            $this->setError('Erro ao criar produto', ['database' => [$e->getMessage()]]);
            Log::error(__METHOD__, ['error_code' => $this->getErrorCode()]);
            return null;
            
        } catch (\Exception $e) {
            $this->setError('Erro inesperado', ['error' => [$e->getMessage()]]);
            Log::error(__METHOD__, ['error_code' => $this->getErrorCode()]);
            return null;
        }
    }
}
```

**Responsabilidades:**
- Executar operação atômica
- Capturar TODAS as exceções
- Usar `setError()` e retornar `null` (não lançar exceções)
- Não usar `DB::transaction()` (fica no Service)

---

### 3️⃣ Service (Orquestração + Transaction)
**Local:** `app/Services/Product/ProductService.php`

```php
class ProductService
{
    use HandlesServiceResponse; // ✅ Trait de comunicação
    
    public function create(array $data, int $createdBy): ?Product
    {
        $this->resetResponse(); // ✅ Limpa estado anterior
        
        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductAction($createdBy);
                $product = $action->execute($data);
                
                if ($action->hasError()) { // ✅ Verifica erro da Action
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );
                    
                    Log::error(__METHOD__, [
                        'error_code' => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors' => $action->getErrors(),
                    ]);
                    
                    return null;
                }
                
                $this->setSuccess('Produto criado com sucesso');
                return $product;
            });
            
        } catch (\Exception $e) {
            // Só pega exceções não tratadas (rollback, etc)
            $this->setError('Erro ao processar criação', ['error' => [$e->getMessage()]]);
            Log::error(__METHOD__, ['error_code' => $this->getErrorCode()]);
            return null;
        }
    }
}
```

**Responsabilidades:**
- Orquestrar múltiplas Actions
- Envolver em `DB::transaction()`
- Propagar erros das Actions
- Resetar estado (`resetResponse()`)

---

### 4️⃣ Controller/Filament (Apresentação)
**Local:** `app/Filament/Clusters/Inventory/Resources/Products/Pages/CreateProduct.php`

```php
protected function handleRecordCreation(array $data): Model
{
    $service = app(ProductService::class);
    $product = $service->create($data, Auth::id());
    
    if ($service->hasError() || $product === null) {
        notify::error(
            message: $service->getMessageUser(),
            errorCode: $service->getErrorCode()
        );
        $this->halt(); // Para execução no Filament
    }
    
    return $product;
}
```

**Responsabilidades:**
- Chamar Service
- Verificar `hasError()`
- Exibir notificação ao usuário
- Parar execução se houver erro

---

## 🔄 Fluxo de Comunicação

```
Controller/Filament
    ↓ chama
Service (com transaction)
    ↓ chama
Action (sem transaction)
    ↓ usa
Validator
```

## ✅ Checklist de Validação

- [ ] Validator tem métodos `validateCreate` e `validateUpdate` separados
- [ ] Action usa `HandlesActionResponse` trait
- [ ] Action captura TODAS as exceções (ValidationException, QueryException, Exception)
- [ ] Action retorna `null` em erro (não lança exceções)
- [ ] Action NÃO usa `DB::transaction()`
- [ ] Service usa `HandlesServiceResponse` trait
- [ ] Service usa `resetResponse()` no início de cada método
- [ ] Service envolve Action em `DB::transaction()`
- [ ] Service verifica `$action->hasError()` e propaga
- [ ] Controller verifica `$service->hasError()` antes de usar resultado
- [ ] Logs incluem `error_code` em todos os erros

## 🎯 Comparação: Antes vs Depois

| Aspecto | ❌ Antes | ✅ Agora |
|---------|----------|----------|
| Validação | Dentro da Action | Validator separado |
| Exceções | Lançadas (`throw`) | Capturadas (`return null`) |
| Transaction | Na Action | No Service |
| Comunicação | Exception-based | Trait-based (`hasError()`) |
| Logs | Inconsistente | Sempre com `error_code` |
| Estado | Não resetado | `resetResponse()` |
| Reutilização | Baixa | Alta |

## 📝 Exemplo Completo de Uso

```php
// Controller
$service = app(ProductService::class);
$product = $service->create([
    'name' => 'Produto Teste',
    'unit' => 'UN',
    'company_id' => 1,
], Auth::id());

if ($service->hasError()) {
    // Erro! Tratar aqui
    $errors = $service->getErrors();
    $message = $service->getMessageUser();
    $errorCode = $service->getErrorCode();
} else {
    // Sucesso!
    echo "Produto criado: " . $product->name;
}
```
