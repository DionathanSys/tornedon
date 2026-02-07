# Regras de Negócio - Criação de Partners

## Índice
1. [Visão Geral](#visão-geral)
2. [Modelos Envolvidos](#modelos-envolvidos)
3. [Fluxo de Criação](#fluxo-de-criação)
4. [Regras de Validação](#regras-de-validação)
5. [Associação Partner-Company](#associação-partner-company)
6. [Tratamento de Erros](#tratamento-de-erros)
7. [Logs e Auditoria](#logs-e-auditoria)

---

## Visão Geral

O sistema de criação de Partners (Parceiros) permite cadastrar fornecedores e clientes que se relacionam com as empresas (tenants) do sistema. Um Partner é uma entidade compartilhada que pode estar associada a múltiplas empresas, cada uma com configurações específicas através da tabela pivot `company_partner`.

### Conceitos Principais

- **Partner**: Entidade global que representa um fornecedor ou cliente (CPF/CNPJ)
- **CompanyPartner**: Relacionamento entre Partner e Company com configurações específicas
- **Type**: Tipo de relacionamento (supplier/customer) - um partner pode ter ambos os tipos simultaneamente
- **Document**: Documento único (CPF ou CNPJ) que identifica o partner no sistema

---

## Modelos Envolvidos

### 1. Partner
**Localização**: `app/Models/Partner.php`

#### Campos
| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `name` | string(255) | Sim | Nome do parceiro |
| `document_type` | enum | Sim | Tipo de documento: `cpf` ou `cnpj` |
| `document_number` | string | Sim | Número do documento (único no sistema) |
| `state_tax_id` | integer | Não | Inscrição Estadual |
| `state_tax_indicator` | enum | Sim | Indicador da situação tributária estadual |
| `municipal_tax_id` | integer | Não | Inscrição Municipal |
| `is_active` | boolean | Sim | Status ativo/inativo (padrão: true) |
| `created_by` | integer | Sim | ID do usuário que criou |
| `updated_by` | integer | Não | ID do último usuário que atualizou |

#### Relacionamentos
- `createdBy()`: BelongsTo User
- `updatedBy()`: BelongsTo User
- `address()`: HasMany Address
- `contacts()`: HasMany Contact
- `companies()`: BelongsToMany Company (através de company_partner)

#### Soft Deletes
✅ Habilitado - Registros não são excluídos permanentemente

---

### 2. CompanyPartner
**Localização**: `app/Models/CompanyPartner.php`

Tabela pivot que relaciona Partners com Companies e armazena configurações específicas da relação.

#### Campos
| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `partner_id` | integer | Sim | ID do partner |
| `company_id` | integer | Sim | ID da company |
| `type` | array | Sim | Tipos de relacionamento: `['supplier']`, `['customer']` ou `['supplier','customer']` |
| `invoice_threshold` | decimal | Sim | Valor mínimo para faturamento (R$ 0,00 a R$ 99.999.999,00) |
| `is_active` | boolean | Sim | Status ativo/inativo do relacionamento |

#### Relacionamentos
- `company()`: BelongsTo Company
- `partner()`: BelongsTo Partner
- `addresses()`: HasMany Address

#### Chave Única
A combinação `partner_id` + `company_id` deve ser única (um partner só pode ter uma associação com cada company).

---

## Fluxo de Criação

### Fluxo Principal
**Arquivo**: `app/Filament/Clusters/Partners/Resources/CompanyPartners/Pages/CreateCompanyPartner.php`

```
1. Usuário preenche formulário no Filament
2. mutateFormDataBeforeCreate() adiciona company_id do tenant
3. handleRecordCreation() é executado dentro de DB::transaction
4. PartnerService::findOrCreatePartner() busca ou cria o Partner
5. PartnerService::associatePartnerCompany() cria o CompanyPartner
6. Se houver erro em qualquer etapa, rollback automático via transaction
7. Se sucesso, retorna CompanyPartner criado
```

### Diagrama de Fluxo

```
┌─────────────────────────────────────────┐
│  CreateCompanyPartner (Filament Page)   │
└──────────────────┬──────────────────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │ mutateFormDataBeforeCreate() │
    │  - Adiciona company_id       │
    └──────────────┬───────────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │  DB::transaction inicio      │
    └──────────────┬───────────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │ PartnerService               │
    │ ::findOrCreatePartner()      │
    └──────────────┬───────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌───────────────┐     ┌───────────────┐
│ Partner já    │     │ Criar novo    │
│ existe?       │     │ Partner       │
│ Retornar      │     │               │
└───────┬───────┘     └───────┬───────┘
        │                     │
        └──────────┬──────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │ PartnerService               │
    │ ::associatePartnerCompany()  │
    └──────────────┬───────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌───────────────┐     ┌───────────────┐
│ Já associado? │     │ Criar nova    │
│ Retornar      │     │ associação    │
│ existing      │     │               │
└───────┬───────┘     └───────┬───────┘
        │                     │
        └──────────┬──────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │  DB::transaction commit      │
    └──────────────┬───────────────┘
                   │
                   ▼
    ┌──────────────────────────────┐
    │  Retorna CompanyPartner      │
    └──────────────────────────────┘
```

---

## Regras de Validação

### Validação de Partner
**Arquivo**: `app/Services/Partner/Validators/PartnerValidator.php`

#### Regras

| Campo | Regras | Mensagem de Erro |
|-------|--------|------------------|
| `name` | required, string, max:255 | "O nome do parceiro é obrigatório." |
| `document_type` | required, in:cnpj,cpf | "O tipo de documento informado é inválido." |
| `document_number` | required, unique:partners, validação customizada | "O número do documento é obrigatório." / "Este documento já está cadastrado." |
| `state_tax_id` | nullable, integer | - |
| `state_tax_indicator` | required, in:[1,2,9] | "O indicador de inscrição estadual informado é inválido." |
| `municipal_tax_id` | nullable, integer | - |

#### Validação Customizada de Documento

```php
// CPF deve ter exatamente 14 caracteres (com formatação: 999.999.999-99)
if (document_type === 'cpf' && strlen(document_number) !== 14) {
    // Erro: "O CPF deve conter exatamente 14 caracteres."
}

// CNPJ deve ter exatamente 18 caracteres (com formatação: 99.999.999/9999-99)
if (document_type === 'cnpj' && strlen(document_number) !== 18) {
    // Erro: "O CNPJ deve conter exatamente 18 caracteres."
}
```

#### Indicador de Situação Tributária Estadual

**Enum**: `App\Enum\Tax\StateTaxIndicator`

| Valor | Constante | Descrição |
|-------|-----------|-----------|
| `1` | CONTRIBUINTE_ICMS | Contribuinte ICMS (informar a IE do destinatário) |
| `2` | CONTRIBUINTE_ICMS_ISENTO | Contribuinte isento de Inscrição no cadastro de Contribuintes do ICMS |
| `9` | NAO_CONTRIBUINTE | Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS |

---

### Validação de CompanyPartner
**Arquivo**: `app/Services/Partner/Validators/CompanyPartnerValidator.php`

#### Regras

| Campo | Regras | Mensagem de Erro |
|-------|--------|------------------|
| `type` | required, array, min:1 | "O tipo de vínculo com o parceiro é obrigatório." |
| `type.*` | required, in:supplier,customer | "Tipo de vínculo inválido." |
| `invoice_threshold` | required, numeric, min:0, max:99999999 | "É obrigatório definir valor mín. para faturamento." |
| `is_active` | required, boolean | "É obrigatório definir o status como Ativo/Inativo." |

#### Tipos de Partner

**Enum**: `App\Enum\Partner\Type`

| Valor | Constante | Descrição |
|-------|-----------|-----------|
| `supplier` | SUPPLIER | Fornecedor |
| `customer` | CUSTOMER | Cliente |

**Importante**: Um CompanyPartner pode ter ambos os tipos simultaneamente:
- `['supplier']` - Apenas fornecedor
- `['customer']` - Apenas cliente
- `['supplier', 'customer']` - Fornecedor e cliente

---

## Associação Partner-Company

### Método: findOrCreatePartner()
**Arquivo**: `app/Services/Partner/PartnerService.php`

Este método implementa a lógica de reutilização de partners existentes:

```php
public function findOrCreatePartner(int $createdBy, array $data): ?Partner
```

#### Lógica

1. **Busca por documento existente**
   ```php
   Partner::where('document_number', $data['document_number'])->first()
   ```

2. **Se encontrado**:
   - Loga informação de reutilização
   - Retorna partner existente
   - Define status de sucesso

3. **Se não encontrado**:
   - Chama `createPartner()` para criar novo
   - Retorna novo partner criado

#### Benefícios
✅ Evita duplicação de partners com mesmo documento  
✅ Permite que um partner seja compartilhado entre múltiplas companies  
✅ Mantém dados fiscais consistentes  

---

### Método: associatePartnerCompany()
**Arquivo**: `app/Services/Partner/PartnerService.php` e `app/Services/Partner/Actions/AssociatePartnerCompany.php`

Cria a associação entre Partner e Company.

```php
public function associatePartnerCompany(
    int $partnerId, 
    int $companyId, 
    array $data
): ?CompanyPartner
```

#### Lógica

1. **Valida dados** usando CompanyPartnerValidator

2. **Verifica associação existente**:
   ```php
   CompanyPartner::where('partner_id', $partnerId)
       ->where('company_id', $companyId)
       ->first()
   ```

3. **Se já associado**:
   - Loga informação
   - Retorna associação existente (não cria duplicata)

4. **Se não associado**:
   - Cria novo registro CompanyPartner
   - Retorna associação criada

#### Prevenção de Duplicatas
✅ Verifica existência antes de criar  
✅ Chave única no banco de dados (partner_id + company_id)  
✅ Retorna registro existente sem erro  

---

## Tratamento de Erros

### Estratégia de Erros

O sistema utiliza traits para padronizar o tratamento de erros:

- **HandlesServiceResponse**: Usado em Services
- **HandlesActionResponse**: Usado em Actions

### Métodos Disponíveis

```php
// Definir erro
$this->setError(string $message, array $errors = [], int $status = 500, ?string $errorCode = null)

// Definir sucesso
$this->setSuccess(string $message = 'Operação realizada com sucesso')

// Verificar estado
$this->hasError(): bool

// Obter informações
$this->getMessage(): string
$this->getErrors(): array
$this->getErrorCode(): string
$this->getStatus(): int
$this->getMessageUser(): string
```

### Fluxo de Erro no CreateCompanyPartner

```php
protected function handleRecordCreation(array $data): Model
{
    return DB::transaction(function () use ($data) {
        $service = new PartnerService();
        $partner = $service->findOrCreatePartner(Auth::id(), $data);

        if ($service->hasError() || $partner === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt(); // Para execução e faz rollback
        }

        $result = $service->associatePartnerCompany(
            $partner->id,
            $data['company_id'],
            $data['company_partner']
        );

        if ($service->hasError()) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt(); // Para execução e faz rollback
        }

        return $result;
    });
}
```

### Tipos de Erro

1. **Erro de Validação** (ValidationException)
   - Status: 422
   - Retorna array de erros por campo
   - Exemplo: "O CPF deve conter exatamente 14 caracteres."

2. **Erro de Negócio**
   - Status: 500 (padrão) ou customizado
   - Retorna mensagem específica
   - Exemplo: "Erro ao cadastrar parceiro"

3. **Erro de Exception**
   - Status: 500
   - Captura exceções não tratadas
   - Loga trace completo

### Notificação ao Usuário

O sistema usa `NotifyService` para exibir erros ao usuário:

```php
notify::error(
    message: "Mensagem amigável para o usuário",
    errorCode: "ERR_20260204_123456_abc123"
);
```

---

## Logs e Auditoria

### Níveis de Log

#### 1. Log de Debug
Usado durante desenvolvimento e troubleshooting:

```php
Log::debug(__METHOD__ . '@' . __LINE__, [
    'message' => 'Iniciando associação de parceiro com empresa',
    'partner_id' => $partnerId,
    'company_id' => $companyId,
    'data' => $data,
]);
```

#### 2. Log de Info
Para eventos importantes do fluxo:

```php
Log::info(__METHOD__ . '@' . __LINE__, [
    'message' => 'Parceiro existente encontrado, reutilizando',
    'partner_id' => $existing->id,
    'document_number' => $data['document_number'],
]);
```

#### 3. Log de Error
Para todos os erros capturados:

```php
Log::error(__METHOD__ . '@' . __LINE__, [
    'error_code' => $this->getErrorCode(),
    'message' => 'Erro ao cadastrar parceiro',
    'exception' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
    'data' => $data,
]);
```

### Informações Rastreáveis

Todos os logs incluem:
- `__METHOD__`: Classe e método onde ocorreu
- `__LINE__`: Linha exata do código
- `error_code`: Código único para rastreamento
- `message`: Descrição do evento
- `data`: Dados relevantes (sanitizados quando necessário)
- `trace`: Stack trace (apenas em errors)

### Auditoria de Registros

O modelo Partner mantém auditoria através de:

```php
protected $fillable = [
    'created_by',  // Quem criou
    'updated_by',  // Quem atualizou pela última vez
    // ... outros campos
];
```

Timestamps automáticos:
- `created_at`: Data/hora de criação
- `updated_at`: Data/hora da última atualização
- `deleted_at`: Data/hora de soft delete (se aplicável)

---

## Exemplos de Uso

### Exemplo 1: Criar Partner CPF como Cliente

```php
$data = [
    'name' => 'João da Silva',
    'document_type' => 'cpf',
    'document_number' => '123.456.789-00',
    'state_tax_indicator' => '9', // Não Contribuinte
    'company_partner' => [
        'type' => ['customer'],
        'invoice_threshold' => 1000.00,
        'is_active' => true,
    ],
];

// O sistema irá:
// 1. Buscar se existe partner com CPF 123.456.789-00
// 2. Se não existir, criar novo Partner
// 3. Criar CompanyPartner associando ao tenant atual
```

### Exemplo 2: Criar Partner CNPJ como Fornecedor e Cliente

```php
$data = [
    'name' => 'Empresa XYZ Ltda',
    'document_type' => 'cnpj',
    'document_number' => '12.345.678/0001-00',
    'state_tax_id' => 123456789,
    'state_tax_indicator' => '1', // Contribuinte ICMS
    'municipal_tax_id' => 987654,
    'company_partner' => [
        'type' => ['supplier', 'customer'],
        'invoice_threshold' => 5000.00,
        'is_active' => true,
    ],
];
```

### Exemplo 3: Reutilizar Partner Existente

```php
// Partner com CNPJ 12.345.678/0001-00 já existe no sistema

$data = [
    'document_number' => '12.345.678/0001-00',
    // ... outros dados
    'company_partner' => [
        'type' => ['customer'],
        'invoice_threshold' => 2000.00,
        'is_active' => true,
    ],
];

// O sistema irá:
// 1. Encontrar Partner existente
// 2. Reutilizar o Partner encontrado
// 3. Criar NOVA associação CompanyPartner para o tenant atual
// 4. O Partner estará disponível para múltiplas companies
```

---

## Pontos de Atenção

### ⚠️ Transações
- Toda criação é executada dentro de `DB::transaction`
- Se qualquer etapa falhar, rollback automático
- Garante consistência entre Partner e CompanyPartner

### ⚠️ Documento Único
- `document_number` é único globalmente no sistema
- Não é possível ter dois Partners com mesmo documento
- Mesmo que estejam em companies diferentes

### ⚠️ Formatação de Documento
- CPF: 14 caracteres com formatação (999.999.999-99)
- CNPJ: 18 caracteres com formatação (99.999.999/9999-99)
- Sistema valida tamanho exato

### ⚠️ Soft Delete
- Partners não são excluídos permanentemente
- Use `deleted_at` para verificar status
- Queries devem usar `withTrashed()` se necessário

### ⚠️ Multi-tenancy
- `company_id` é adicionado automaticamente do tenant ativo
- Isolamento entre tenants via `company_partner`
- Um Partner pode pertencer a múltiplos tenants

---

## Arquivos Relacionados

### Models
- `app/Models/Partner.php`
- `app/Models/CompanyPartner.php`
- `app/Models/Company.php`
- `app/Models/User.php`

### Services
- `app/Services/Partner/PartnerService.php`

### Actions
- `app/Services/Partner/Actions/CreatePartner.php`
- `app/Services/Partner/Actions/AssociatePartnerCompany.php`

### Validators
- `app/Services/Partner/Validators/PartnerValidator.php`
- `app/Services/Partner/Validators/CompanyPartnerValidator.php`

### Filament Pages
- `app/Filament/Clusters/Partners/Resources/CompanyPartners/Pages/CreateCompanyPartner.php`

### Enums
- `app/Enum/Partner/Type.php`
- `app/Enum/Tax/StateTaxIndicator.php`

### Traits
- `app/Traits/HandlesServiceResponse.php`
- `app/Traits/HandlesActionResponse.php`

### Notifications
- `app/Notification/NotifyService.php`

---

**Última atualização**: 04/02/2026  
**Versão do documento**: 1.0  
**Autor**: Documentação gerada a partir do código-fonte
