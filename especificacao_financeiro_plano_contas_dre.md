# Especificação — Plano de Contas, Categorias, Centros de Custo, Centros de Resultado e DRE Configurável

## 1. Objetivo

Implementar no sistema uma estrutura financeira configurável por empresa (`Company`) que permita:

- Categorias financeiras operacionais;
- Plano de Contas hierárquico e opcional;
- Centro de Custo opcional;
- Centro de Resultado opcional;
- DRE configurável por empresa;
- Histórico consistente das classificações financeiras;
- Empresas simples utilizando apenas categorias;
- Empresas mais maduras utilizando categorias + plano de contas + centros + DRE;
- Quantidade ilimitada de níveis no Plano de Contas;
- Relatórios agregados por qualquer nível da hierarquia.

A arquitetura deve evitar acoplamento entre:

1. Categoria;
2. Plano de Contas;
3. Centro de Custo;
4. Centro de Resultado;
5. Estrutura do DRE.

Cada conceito possui responsabilidade própria.

---

# 2. Conceitos

## 2.1 Categoria

A categoria é a classificação operacional que o usuário seleciona no dia a dia.

Exemplos:

- Diesel
- ARLA
- Peças
- Pneus
- Salários
- Contabilidade
- Aluguel
- Receita de Frete

Uma categoria pode, opcionalmente, estar vinculada a uma conta do Plano de Contas.

Exemplo:

```text
Categoria: Diesel
Plano de Contas: 2.01.01 - Combustíveis
```

A empresa não deve ser obrigada a utilizar Plano de Contas.

Portanto:

```text
categories.chart_account_id = nullable
```

---

# 3. Plano de Contas

## 3.1 Estrutura hierárquica

O Plano de Contas deve utilizar uma árvore recursiva baseada em `parent_id`.

Exemplo:

```text
2. Custos Operacionais
└── 2.01 Custos da Frota
    └── 2.01.01 Combustíveis
        ├── 2.01.01.01 Diesel
        └── 2.01.01.02 ARLA
```

No banco:

| id | code | name | parent_id |
|---:|---|---|---:|
| 10 | 2 | Custos Operacionais | null |
| 11 | 2.01 | Custos da Frota | 10 |
| 12 | 2.01.01 | Combustíveis | 11 |
| 13 | 2.01.01.01 | Diesel | 12 |
| 14 | 2.01.01.02 | ARLA | 12 |

Não é necessário relacionar `2.01.01` diretamente com `2`.

A cadeia é obtida através dos pais:

```text
Combustíveis.parent
→ Custos da Frota

Custos da Frota.parent
→ Custos Operacionais
```

Ou seja:

```text
Combustíveis
→ parent
→ Custos da Frota
→ parent
→ Custos Operacionais
```

Esse padrão permite profundidade ilimitada.

---

# 4. Modelagem sugerida

## 4.1 chart_accounts

```text
chart_accounts
- id
- company_id
- parent_id nullable
- code nullable
- name
- type
- nature nullable
- is_postable boolean
- is_active boolean
- sort_order integer
- created_at
- updated_at
- deleted_at nullable
```

### Campos

#### `company_id`

Empresa proprietária da estrutura.

#### `parent_id`

Referência para `chart_accounts.id`.

`null` significa uma conta raiz.

#### `code`

Código visual/gerencial.

Exemplos:

```text
1
1.01
1.01.01
2
2.01
2.01.01
```

O código não deve ser utilizado para determinar a hierarquia.

A hierarquia deve sempre ser determinada por `parent_id`.

#### `type`

Sugestão de enum:

```text
asset
liability
equity
revenue
cost
expense
financial_revenue
financial_expense
other
```

Caso o sistema tenha foco inicialmente apenas gerencial/DRE, o enum pode ser simplificado para:

```text
revenue
deduction
cost
expense
financial_result
other
```

#### `nature`

Opcionalmente:

```text
debit
credit
```

#### `is_postable`

Define se a conta pode receber lançamentos.

Exemplo:

```text
2 Custos Operacionais
is_postable = false

2.01 Custos da Frota
is_postable = false

2.01.01 Combustíveis
is_postable = true
```

Importante:

O sistema também deve permitir uma estrutura simples onde uma conta raiz seja lançável:

```text
Combustível
parent_id = null
is_postable = true
```

Assim uma empresa pode utilizar Plano de Contas sem criar filhos.

---

# 5. Model Laravel

Exemplo:

```php
class ChartAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'nature',
        'is_postable',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('code');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
```

---

# 6. Como acessar todos os ancestrais

## 6.1 Solução simples em PHP

```php
public function ancestors(): Collection
{
    $ancestors = collect();
    $current = $this->parent;

    while ($current) {
        $ancestors->push($current);
        $current = $current->parent;
    }

    return $ancestors;
}
```

Para:

```text
2 Custos Operacionais
└── 2.01 Custos da Frota
    └── 2.01.01 Combustíveis
        └── 2.01.01.01 Diesel
```

Chamando:

```php
$diesel->ancestors();
```

retorna:

```text
Combustíveis
Custos da Frota
Custos Operacionais
```

---

# 7. Como descobrir a conta raiz

```php
public function root(): self
{
    $account = $this;

    while ($account->parent) {
        $account = $account->parent;
    }

    return $account;
}
```

Exemplo:

```php
$diesel->root();
```

retorna:

```text
2 - Custos Operacionais
```

---

# 8. Descendentes

Para relatórios será necessário obter todos os filhos, netos e níveis inferiores.

Exemplo:

```text
Custos da Frota
├── Combustíveis
│   ├── Diesel
│   └── ARLA
├── Pneus
└── Manutenção
    ├── Peças
    └── Serviços
```

Um relatório sobre `Custos da Frota` deve somar movimentações associadas a:

```text
Custos da Frota
Combustíveis
Diesel
ARLA
Pneus
Manutenção
Peças
Serviços
```

### Relação recursiva

Pode-se criar:

```php
public function childrenRecursive(): HasMany
{
    return $this->children()->with('childrenRecursive');
}
```

Para carregar a árvore.

Entretanto, para agregações SQL e relatórios grandes, não deve ser feita uma quantidade excessiva de queries em loop.

Considere utilizar:

- CTE recursiva (`WITH RECURSIVE`);
- pacote específico para árvores;
- materialized path;
- nested set;

somente se o volume ou complexidade futura justificar.

Para a primeira implementação, adjacency list (`parent_id`) é suficiente.

---

# 9. Regra fundamental da hierarquia

Nunca determinar ancestralidade pelo código textual.

Evitar lógica como:

```php
str_starts_with($child->code, $parent->code);
```

Errado:

```text
2.01.01 pertence ao 2 porque começa com "2"
```

Correto:

```text
chart_accounts.parent_id
```

O código é apenas representação visual.

A estrutura real é:

```text
id 12 parent_id 11
id 11 parent_id 10
id 10 parent_id null
```

---

# 10. Categorias

## Tabela

```text
categories
- id
- company_id
- chart_account_id nullable
- name
- type
- is_active
- created_at
- updated_at
- deleted_at nullable
```

### Relações

```php
class Category extends Model
{
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }
}
```

Uma conta pode possuir várias categorias:

```php
class ChartAccount extends Model
{
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
```

Exemplo:

```text
2.01.01 Combustíveis

Categorias:
- Diesel
- ARLA
- Gasolina
- Etanol
- Aditivos
```

---

# 11. Categoria e Plano de Contas não são a mesma coisa

Responsabilidades:

```text
Categoria
= classificação operacional

Plano de Contas
= classificação gerencial/financeira

DRE
= apresentação do resultado
```

Exemplo:

```text
Categoria
Diesel

↓

Plano de Contas
2.01.01 Combustíveis

↓

DRE
Custos Variáveis
```

---

# 12. Centro de Custo

Responsável por responder:

```text
Onde ou por quem o recurso foi consumido?
```

Exemplos:

```text
Oficina
Administrativo
Almoxarifado
Comercial
Filial Chapecó
```

Tabela:

```text
cost_centers
- id
- company_id
- parent_id nullable
- code nullable
- name
- is_active
- sort_order
- timestamps
- deleted_at nullable
```

Pode-se permitir hierarquia opcional também.

Exemplo:

```text
Oficina
├── Mecânica
├── Elétrica
└── Borracharia
```

---

# 13. Centro de Resultado

Responsável por responder:

```text
Qual unidade de negócio/operação deve carregar essa receita ou despesa?
```

Exemplos:

```text
Transporte
Oficina
Venda de Peças
Locação
```

Tabela:

```text
result_centers
- id
- company_id
- parent_id nullable
- code nullable
- name
- is_active
- sort_order
- timestamps
- deleted_at nullable
```

---

# 14. Movimentação financeira

A movimentação deve guardar a classificação histórica utilizada no momento do lançamento.

Sugestão:

```text
financial_transactions
- id
- company_id
- financial_account_id
- category_id nullable
- chart_account_id nullable
- cost_center_id nullable
- result_center_id nullable
- partner_id nullable
- amount
- type
- transaction_date
- description
- ...
```

## Por que salvar `chart_account_id` se a categoria já possui?

Porque a classificação da categoria pode mudar futuramente.

Exemplo:

Hoje:

```text
Categoria Peças
→ 2.01 Manutenção
```

Em 2028:

```text
Categoria Peças
→ 2.03 Custos da Frota
```

Os lançamentos antigos não devem automaticamente mudar de classificação.

No momento do lançamento:

```php
$transaction->category_id = $category?->id;

$transaction->chart_account_id =
    $category?->chart_account_id;
```

O `chart_account_id` gravado na transação representa um snapshot classificatório.

---

# 15. Alteração de categoria

Quando a categoria tiver seu Plano de Contas alterado:

```text
NÃO atualizar automaticamente as transações históricas.
```

Pode existir futuramente uma funcionalidade explícita:

```text
Reclassificar movimentações
```

onde o usuário seleciona:

- intervalo;
- categorias;
- nova conta;
- confirmar reclassificação.

Essa operação deve ser auditável.

---

# 16. DRE

O DRE não deve ser inferido rigidamente pela árvore do Plano de Contas.

O usuário deve poder criar uma estrutura própria de DRE.

Exemplo:

```text
Receita Bruta
(-) Deduções
= Receita Líquida

(-) Custos Variáveis
= Margem de Contribuição

(-) Custos Fixos
(-) Despesas Administrativas
= Resultado Operacional

(+/-) Resultado Financeiro
= Resultado Líquido
```

---

# 17. Modelos de DRE

Tabela:

```text
dre_models
- id
- company_id
- name
- description nullable
- is_default
- is_active
- created_at
- updated_at
```

Uma empresa pode possuir mais de um modelo.

Exemplos:

```text
DRE Gerencial
DRE Simplificado
DRE Diretoria
DRE Operacional
```

---

# 18. Linhas do DRE

Tabela:

```text
dre_lines
- id
- dre_model_id
- parent_id nullable
- name
- code nullable
- line_type
- operation
- display_sign
- sort_order
- is_bold
- is_visible
- created_at
- updated_at
```

Sugestão para `line_type`:

```text
account_group
subtotal
formula
header
separator
```

Sugestão para `operation`:

```text
add
subtract
none
```

---

# 19. Relação DRE ↔ Plano de Contas

Tabela pivot:

```text
dre_line_chart_account
- dre_line_id
- chart_account_id
```

Uma linha do DRE pode agregar várias contas.

Exemplo:

```text
Linha DRE: Combustíveis

Contas:
- Diesel
- ARLA
- Gasolina
```

Ou:

```text
Linha DRE: Custos da Frota

Conta vinculada:
- 2.01 Custos da Frota
```

Se a regra for incluir descendentes, o sistema deve agregar automaticamente todas as contas filhas.

---

# 20. Configuração da associação do DRE

Na pivot, considerar adicionar:

```text
include_descendants boolean default true
```

Estrutura:

```text
dre_line_chart_account
- dre_line_id
- chart_account_id
- include_descendants
```

Exemplo:

```text
DRE:
Custos da Frota

Conta:
2.01 Custos da Frota

include_descendants = true
```

O relatório incluirá:

```text
2.01
2.01.01
2.01.01.01
2.01.01.02
2.01.02
2.01.03
...
```

Isso elimina a necessidade de selecionar manualmente cada conta filha.

---

# 21. Validação de ciclos

É obrigatório impedir estruturas circulares.

Nunca permitir:

```text
A → B
B → C
C → A
```

Ao alterar `parent_id`, validar:

1. não pode ser o próprio registro;
2. o novo pai não pode ser um descendente do registro.

Exemplo de regra:

```php
if ($newParent->id === $account->id) {
    throw ValidationException::withMessages([
        'parent_id' => 'Uma conta não pode ser pai dela mesma.',
    ]);
}
```

Também percorrer ancestrais do novo pai para assegurar que a conta atual não esteja entre eles.

---

# 22. Multi-tenancy

Todas as entidades devem pertencer a uma empresa:

```text
Company
├── Categories
├── ChartAccounts
├── CostCenters
├── ResultCenters
└── DreModels
```

Nunca permitir relação entre registros de empresas diferentes.

Exemplo inválido:

```text
Category company_id = 1
ChartAccount company_id = 2
```

A validação deve existir no domínio e não depender somente do formulário Filament.

---

# 23. Filament — Plano de Contas

Criar Resource para gerenciamento.

Visual ideal:

```text
Plano de Contas

[ + Nova Conta ]

▼ 2 Custos Operacionais
   ▼ 2.01 Custos da Frota
      ▼ 2.01.01 Combustíveis
         • 2.01.01.01 Diesel
         • 2.01.01.02 ARLA
      • 2.01.02 Pneus
      • 2.01.03 Manutenção
```

Ações:

```text
Editar
Adicionar filho
Desativar
Excluir
```

Ao clicar em `Adicionar filho`, preencher automaticamente:

```text
parent_id = conta selecionada
```

---

# 24. Formulário de Plano de Contas

Campos:

```text
Nome *
Código
Conta pai
Tipo *
Permite lançamento?
Ativa?
Ordem
```

Conta pai:

- somente da empresa atual;
- não incluir a própria conta;
- não incluir descendentes da própria conta durante edição.

---

# 25. UX simplificada

Não obrigar o usuário a entender todos os conceitos.

Configurações financeiras da empresa:

```text
[✓] Utilizar categorias
[ ] Utilizar plano de contas
[ ] Utilizar centro de custo
[ ] Utilizar centro de resultado
[ ] Utilizar DRE gerencial
```

Essa configuração pode controlar quais campos aparecem nos formulários.

Entretanto, evite remover dados ou tabelas quando um recurso for desabilitado.

Trate como feature/configuração de utilização.

---

# 26. Categorias no Filament

Cadastro:

```text
Nome
Tipo
Plano de Contas (opcional)
Ativa
```

Se a empresa não utiliza Plano de Contas:

```text
ocultar chart_account_id
```

Se utiliza:

```text
mostrar chart_account_id
```

---

# 27. Movimentação financeira no Filament

Exemplo:

```text
Descrição
Valor
Data
Tipo
Conta financeira

Categoria
Plano de Contas
Centro de Custo
Centro de Resultado
```

Ao selecionar categoria:

```text
category.chart_account_id
→ preencher automaticamente chart_account_id
```

O usuário pode ou não ter permissão para alterar manualmente a conta, conforme regra da empresa.

Sugestão de configuração:

```text
allow_manual_chart_account_override
```

---

# 28. Regra de preenchimento

Fluxo recomendado:

```text
Selecionou categoria
        ↓
Categoria possui chart_account_id?
        ↓ sim
Preencher chart_account_id da movimentação
        ↓
Usuário possui permissão/configuração para alterar?
        ├── não → campo somente leitura
        └── sim → permitir override
```

---

# 29. Agregação por ancestral

Considere:

```text
2 Custos Operacionais
└── 2.01 Custos da Frota
    └── 2.01.01 Combustíveis
        ├── Diesel
        └── ARLA
```

Movimentações:

```text
Diesel = R$ 100.000
ARLA   = R$ 10.000
```

Então:

```text
Combustíveis = R$ 110.000
Custos da Frota = R$ 110.000
Custos Operacionais = R$ 110.000
```

Nenhuma duplicação de lançamento é necessária.

A movimentação continua vinculada somente à sua conta real.

O relatório sobe os valores pela hierarquia.

---

# 30. Não duplicar vínculos

Evitar:

```text
transaction
├── chart_account_id = Diesel
├── parent_chart_account_id = Combustíveis
├── grandparent_chart_account_id = Custos da Frota
└── root_chart_account_id = Custos Operacionais
```

Isso cria redundância e risco de inconsistência.

Correto:

```text
transaction.chart_account_id = Diesel
```

A ancestralidade é descoberta pela árvore.

---

# 31. Performance

Para volume baixo/médio, utilizar a estrutura simples com `parent_id`.

Quando necessário otimizar relatórios, avaliar CTE recursiva do MySQL/MariaDB.

Exemplo conceitual:

```sql
WITH RECURSIVE descendants AS (
    SELECT id, parent_id
    FROM chart_accounts
    WHERE id = :rootId

    UNION ALL

    SELECT ca.id, ca.parent_id
    FROM chart_accounts ca
    INNER JOIN descendants d
        ON ca.parent_id = d.id
)
SELECT id
FROM descendants;
```

Depois:

```sql
SELECT SUM(amount)
FROM financial_transactions
WHERE chart_account_id IN (...descendantIds);
```

Encapsular essa lógica em serviço/query object/repository para não espalhar SQL pela aplicação.

---

# 32. Services / Actions sugeridos

Seguindo arquitetura orientada a Services + Actions:

```text
Domain/
└── Finance/
    ├── ChartAccount/
    │   ├── Actions/
    │   │   ├── CreateChartAccountAction
    │   │   ├── UpdateChartAccountAction
    │   │   ├── DeleteChartAccountAction
    │   │   └── MoveChartAccountAction
    │   ├── Queries/
    │   │   ├── GetChartAccountAncestorsQuery
    │   │   ├── GetChartAccountDescendantsQuery
    │   │   └── GetChartAccountTreeQuery
    │   └── DTOs/
    │
    ├── Category/
    ├── CostCenter/
    ├── ResultCenter/
    └── Dre/
```

---

# 33. Serviço para árvore

Exemplo:

```php
final class ChartAccountTreeService
{
    public function ancestors(ChartAccount $account): Collection
    {
        $result = collect();
        $current = $account->parent;

        while ($current) {
            $result->push($current);
            $current = $current->parent;
        }

        return $result;
    }

    public function root(ChartAccount $account): ChartAccount
    {
        $current = $account;

        while ($current->parent) {
            $current = $current->parent;
        }

        return $current;
    }
}
```

---

# 34. DRE — processamento

Criar um serviço dedicado:

```text
GenerateDreReportService
```

Entrada:

```text
company
dre_model
start_date
end_date
cost_center optional
result_center optional
comparison_period optional
```

Saída sugerida:

```text
DreReportDTO
└── lines[]
    ├── id
    ├── name
    ├── type
    ├── amount
    ├── percentage
    ├── children[]
    └── metadata
```

---

# 35. Filtros do DRE

Preparar para:

```text
Período
Empresa
Centro de Custo
Centro de Resultado
Conta do Plano
Categoria
Filial
```

Especialmente:

```text
DRE por Centro de Resultado
```

Exemplo:

```text
Resultado Transporte
Resultado Oficina
Resultado Venda de Peças
```

---

# 36. Comparativos

Estruturar o serviço de DRE para futuramente suportar:

```text
Atual
Anterior
Variação R$
Variação %
% da Receita
```

Exemplo:

| Linha | Jul/26 | Ago/26 | Var. | % Receita |
|---|---:|---:|---:|---:|
| Receita Líquida | 500.000 | 550.000 | +10% | 100% |
| Combustível | 140.000 | 160.000 | +14,3% | 29,1% |

---

# 37. Integridade

Implementar validações:

- não permitir pai de outra empresa;
- não permitir categoria vinculada a conta de outra empresa;
- não permitir centro de custo de outra empresa;
- não permitir centro de resultado de outra empresa;
- não permitir ciclos;
- não excluir conta utilizada por movimentação sem tratamento;
- preferir desativação/soft delete;
- preservar histórico financeiro.

---

# 38. Exclusão

Se uma conta estiver sendo utilizada:

```text
não executar hard delete
```

Opções:

1. impedir exclusão;
2. permitir somente desativar;
3. utilizar SoftDeletes.

Preferência:

```text
SoftDeletes + is_active
```

---

# 39. Código do Plano de Contas

O usuário pode preencher manualmente ou o sistema pode sugerir.

Não utilizar o código como chave estrutural.

Exemplo válido:

```text
parent_id = 10
code = "2.01"
```

Se o usuário alterar:

```text
2.01 → CF-01
```

a árvore não pode ser afetada.

---

# 40. Estrutura mínima permitida

Uma empresa pode utilizar:

```text
Plano de Contas

Combustível
Peças
Salários
Aluguel
Fretes
```

Todos:

```text
parent_id = null
is_postable = true
```

Isso deve funcionar normalmente.

---

# 41. Estrutura avançada permitida

Também deve funcionar:

```text
2 Custos
└── 2.01 Custos Operacionais
    └── 2.01.01 Frota
        └── 2.01.01.01 Manutenção
            └── 2.01.01.01.001 Peças
```

Sem limite artificial de profundidade.

---

# 42. Testes obrigatórios

Criar testes para:

### Plano de Contas

- criar conta raiz;
- criar filho;
- criar neto;
- criar 5+ níveis;
- recuperar parent;
- recuperar root;
- recuperar ancestors;
- recuperar descendants;
- impedir self-parent;
- impedir ciclo;
- impedir parent de outra empresa;
- soft delete;
- conta raiz lançável;
- conta agrupadora não lançável.

### Categorias

- categoria sem plano;
- categoria com plano;
- impedir plano de outra empresa;
- alteração da classificação não deve mudar transação histórica.

### Transações

- copiar chart_account_id da categoria;
- permitir categoria sem plano;
- armazenar centro de custo;
- armazenar centro de resultado;
- preservar histórico.

### DRE

- linha com uma conta;
- linha com várias contas;
- incluir descendentes;
- não incluir descendentes quando desabilitado;
- subtotal;
- soma;
- subtração;
- filtros;
- múltiplos níveis.

---

# 43. Critérios de aceite

A implementação será considerada concluída quando:

1. cada empresa puder criar sua própria estrutura;
2. o Plano de Contas aceitar qualquer profundidade;
3. uma empresa puder utilizar apenas contas raiz;
4. categorias puderem existir sem Plano de Contas;
5. categorias puderem apontar para uma conta;
6. movimentações preservarem a conta utilizada no momento do lançamento;
7. centros de custo e resultado forem opcionais;
8. o DRE puder ser configurado independentemente do Plano de Contas;
9. linhas do DRE puderem agregar contas e descendentes;
10. relatórios puderem consolidar valores nos ancestrais;
11. ciclos forem impossíveis;
12. nenhuma relação cross-company for aceita;
13. a interface Filament respeitar as configurações da empresa.

---

# 44. Resumo arquitetural

```text
Company
│
├── Category
│      └── chart_account_id nullable
│
├── ChartAccount
│      ├── parent_id nullable
│      └── children[]
│
├── CostCenter
│
├── ResultCenter
│
├── DreModel
│      └── DreLine
│             └── ChartAccounts
│
└── FinancialTransaction
       ├── category_id
       ├── chart_account_id
       ├── cost_center_id
       └── result_center_id
```

Fluxo:

```text
Movimentação
      │
      ├──── Categoria
      │         │
      │         ▼
      │    Plano de Contas
      │
      ├──── Centro de Custo
      │
      └──── Centro de Resultado

Plano de Contas
      │
      ▼
Configuração do DRE
      │
      ▼
Relatório Gerencial
```

---

# 45. Decisão arquitetural principal

Usar `parent_id` com adjacency list como estrutura oficial da hierarquia.

Para telas administrativas pequenas, Eloquent com eager loading pode ser utilizado.

Para DRE, relatórios e agregações hierárquicas, utilizar CTE recursiva e agregação no banco desde a implementação inicial, evitando loops Eloquent/lazy loading na camada Filament.

Motivos:

- estrutura simples;
- natural no Eloquent;
- flexível;
- profundidade ilimitada;
- fácil manutenção;
- consultas pesadas permanecem no banco;
- evita N+1 na interface.

Não armazenar todos os ancestrais diretamente na movimentação.

Uma conta conhece somente seu pai imediato:

```text
Diesel.parent_id = Combustíveis
Combustíveis.parent_id = Custos da Frota
Custos da Frota.parent_id = Custos Operacionais
Custos Operacionais.parent_id = null
```

A árvore completa é consequência dessas relações.


---

# 46. REVISÃO ARQUITETURAL — Cálculo x Apresentação da Hierarquia

Esta seção complementa e, quando houver conflito, substitui as orientações anteriores sobre processamento e apresentação da árvore.

## 46.1 Princípio fundamental

A profundidade real do Plano de Contas NÃO deve determinar a profundidade apresentada no DRE.

São responsabilidades diferentes:

```text
Plano de Contas
= estrutura classificatória real

include_descendants
= quais contas entram no cálculo

display_depth
= quanto da hierarquia será apresentado

DRE
= estrutura gerencial de apresentação
```

Exemplo de Plano de Contas:

```text
2 Custos Operacionais
└── 2.01 Custos da Frota
    └── 2.01.01 Combustíveis
        ├── 2.01.01.01 Diesel
        └── 2.01.01.02 ARLA
```

O usuário pode configurar uma linha do DRE apontando apenas para:

```text
2 Custos Operacionais
```

com:

```text
include_descendants = true
display_depth = 0
```

O cálculo considera todos os níveis abaixo, mas o relatório apresenta somente:

```text
Custos Operacionais ........ R$ 180.000
```

Não deve ser necessário apresentar os filhos para que seus valores sejam considerados.

---

# 47. `include_descendants` pertence ao cálculo

Na relação entre linha do DRE e Plano de Contas:

```text
dre_line_chart_account
- dre_line_id
- chart_account_id
- include_descendants boolean default true
```

A propriedade `include_descendants` define exclusivamente quais contas participam do cálculo.

Exemplo:

```text
Linha DRE:
Custos Operacionais

Conta:
2 - Custos Operacionais

include_descendants = true
```

Devem entrar no cálculo:

```text
2
2.01
2.01.01
2.01.01.01
2.01.01.02
...
```

Mesmo que nenhuma dessas contas filhas seja apresentada individualmente.

Se:

```text
include_descendants = false
```

somente movimentações diretamente vinculadas à conta selecionada entram no cálculo.

---

# 48. `display_depth` pertence à apresentação

Adicionar à configuração da linha do DRE:

```text
dre_lines
- ...
- display_depth nullable
```

Sugestão semântica:

```text
0 = somente a própria linha
1 = linha + primeiro nível
2 = linha + dois níveis
3 = linha + três níveis
null = usar configuração padrão / detalhamento completo quando aplicável
```

Exemplo:

Plano:

```text
Custos Operacionais
├── Custos da Frota
│   ├── Combustíveis
│   │   ├── Diesel
│   │   └── ARLA
│   └── Manutenção
└── Administrativo
```

### `display_depth = 0`

```text
Custos Operacionais ........ 180.000
```

### `display_depth = 1`

```text
Custos Operacionais ........ 180.000
├── Custos da Frota ........ 150.000
└── Administrativo .........  30.000
```

### `display_depth = 2`

```text
Custos Operacionais ........ 180.000
├── Custos da Frota ........ 150.000
│   ├── Combustíveis ....... 100.000
│   └── Manutenção .........  50.000
└── Administrativo .........  30.000
```

O cálculo total de `Custos Operacionais` continua igual independentemente do `display_depth`.

---

# 49. DRE não deve reproduzir obrigatoriamente o Plano de Contas

A estrutura visual do DRE é independente da árvore do Plano de Contas.

Exemplo:

Plano de Contas:

```text
2 Custos Operacionais
└── 2.01 Frota
    ├── 2.01.01 Combustíveis
    │   ├── Diesel
    │   └── ARLA
    ├── 2.01.02 Pneus
    └── 2.01.03 Manutenção
```

DRE:

```text
Receita Líquida
(-) Custos Operacionais
= Margem Operacional
(-) Despesas Administrativas
(+/-) Resultado Financeiro
= Resultado Líquido
```

A linha `Custos Operacionais` pode estar vinculada à conta `2 Custos Operacionais` com descendentes habilitados e apresentar somente uma linha.

---

# 50. Performance — não calcular árvore no Filament

O Filament/Livewire NÃO deve ser responsável pelo processamento financeiro da árvore.

Evitar:

```php
foreach ($accounts as $account) {
    while ($account->parent) {
        // cálculo
    }
}
```

especialmente dentro de:

- `Table`;
- `Widget`;
- `Resource`;
- callbacks de coluna;
- `formatStateUsing`;
- `getStateUsing`;
- renderização Blade;
- loops Livewire.

O problema principal não é o loop PHP isoladamente.

O problema é:

```text
loop
+
lazy loading Eloquent
+
renderizações Livewire
=
N+1 queries e degradação de performance
```

A UI deve receber dados já processados.

---

# 51. Separação de responsabilidades

Arquitetura recomendada:

```text
Filament / Livewire
        ↓
GenerateDreReportService
        ↓
Queries especializadas
        ↓
Banco de Dados
        ↓
DreReportDTO
        ↓
Filament apenas apresenta
```

O Filament não deve conhecer detalhes sobre como os descendentes foram encontrados.

---

# 52. Estratégia inicial para árvores

Manter:

```text
parent_id
```

como fonte oficial da hierarquia.

Padrão:

```text
Adjacency List
```

Exemplo:

```text
Diesel.parent_id = Combustíveis
Combustíveis.parent_id = Custos da Frota
Custos da Frota.parent_id = Custos Operacionais
Custos Operacionais.parent_id = null
```

Não adicionar inicialmente:

- `root_id`;
- `parent_1_id`;
- `parent_2_id`;
- `parent_3_id`;
- arrays de ancestrais;
- cópia de todos os ancestrais na movimentação.

---

# 53. CTE recursiva como estratégia preferencial de consulta

Para relatórios, DRE e agregações, preferir CTE recursiva no banco em vez de loops Eloquent com lazy loading.

Exemplo conceitual:

```sql
WITH RECURSIVE account_tree AS (
    SELECT
        id,
        parent_id,
        0 AS depth
    FROM chart_accounts
    WHERE id = :root_id

    UNION ALL

    SELECT
        ca.id,
        ca.parent_id,
        at.depth + 1
    FROM chart_accounts ca
    INNER JOIN account_tree at
        ON ca.parent_id = at.id
    WHERE ca.company_id = :company_id
)
SELECT id, parent_id, depth
FROM account_tree;
```

Essa consulta retorna a conta raiz e todos os seus descendentes.

---

# 54. Query Object para descendentes

Encapsular a implementação.

Exemplo conceitual:

```php
final class GetChartAccountDescendantsQuery
{
    public function execute(
        int $companyId,
        int $chartAccountId,
        bool $includeSelf = true,
        ?int $maxDepth = null,
    ): Collection {
        // Executar CTE recursiva.
    }
}
```

A aplicação deve depender dessa abstração e não de SQL espalhado por Resources e Services.

---

# 55. Profundidade na CTE

A CTE deve calcular `depth`.

Exemplo:

```text
Conta                  depth
-----------------------------
Custos Operacionais      0
Custos da Frota          1
Combustíveis             2
Diesel                   3
ARLA                     3
```

Isso permite utilizar a mesma consulta para apresentação.

Por exemplo:

```text
display_depth = 1
```

pode limitar a apresentação a:

```sql
WHERE depth <= 1
```

Importante:

O limite de apresentação NÃO deve limitar as contas utilizadas no cálculo do total.

Cálculo e apresentação devem ser tratados separadamente.

---

# 56. Cálculo agregado

Evitar:

1. buscar descendentes;
2. carregar milhares de Models;
3. executar uma query de soma para cada conta.

Preferir agregação no banco.

Exemplo conceitual:

```sql
WITH RECURSIVE account_tree AS (
    ...
)
SELECT
    SUM(ft.amount) AS total
FROM financial_transactions ft
INNER JOIN account_tree at
    ON at.id = ft.chart_account_id
WHERE ft.company_id = :company_id
  AND ft.transaction_date BETWEEN :start_date AND :end_date;
```

Isso permite calcular o total da linha do DRE sem materializar toda a árvore no PHP.

---

# 57. Agregação por nível para detalhamento

Quando `display_depth > 0`, o relatório pode precisar apresentar os grupos intermediários.

A query/service deve ser capaz de retornar uma estrutura semelhante a:

```text
[
    {
        "id": 10,
        "name": "Custos Operacionais",
        "depth": 0,
        "amount": 180000,
        "children": [
            {
                "id": 11,
                "name": "Custos da Frota",
                "depth": 1,
                "amount": 150000
            },
            {
                "id": 20,
                "name": "Administrativo",
                "depth": 1,
                "amount": 30000
            }
        ]
    }
]
```

A soma de uma conta agrupadora deve incluir seus descendentes, sem exigir movimentação diretamente nela.

---

# 58. DTO de saída

O `GenerateDreReportService` deve retornar DTOs e não Models Eloquent destinados a cálculo na interface.

Sugestão:

```php
final readonly class DreLineResultDTO
{
    public function __construct(
        public int $lineId,
        public string $name,
        public string $lineType,
        public float $amount,
        public ?float $percentage,
        public int $depth,
        public Collection $children,
    ) {}
}
```

E:

```php
final readonly class DreReportDTO
{
    public function __construct(
        public int $dreModelId,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public Collection $lines,
    ) {}
}
```

---

# 59. Evitar N+1

Nunca executar em uma tabela:

```php
TextColumn::make('total')
    ->getStateUsing(
        fn (ChartAccount $record) =>
            $record->children
                ->sum(...)
    );
```

se isso depender de queries por registro.

Também evitar:

```php
foreach ($accounts as $account) {
    $account->parent;
}
```

sem eager loading ou consulta especializada.

---

# 60. Eager loading — onde faz sentido

Eager loading continua válido para telas administrativas pequenas.

Exemplo:

```php
ChartAccount::query()
    ->with('childrenRecursive')
    ->whereNull('parent_id')
    ->get();
```

Pode ser utilizado para:

- árvore de configuração;
- formulário;
- pequenos selects;
- navegação administrativa.

Não utilizar como mecanismo principal para calcular DRE e grandes agregações.

---

# 61. Estratégias alternativas avaliadas

## 61.1 Adjacency List + CTE

Estrutura:

```text
parent_id
```

Vantagens:

- simples;
- natural no Eloquent;
- fácil inserir;
- fácil mover;
- profundidade ilimitada;
- CTE resolve descendentes eficientemente;
- não exige manutenção de metadados adicionais.

Desvantagem:

- consultas hierárquicas precisam de CTE ou processamento específico.

### Decisão

ESTRATÉGIA RECOMENDADA PARA A IMPLEMENTAÇÃO ATUAL.

---

## 61.2 Materialized Path

Exemplo:

```text
id | path
10 | /10/
11 | /10/11/
12 | /10/11/12/
13 | /10/11/12/13/
```

Busca de descendentes:

```sql
WHERE path LIKE '/10/%'
```

Vantagens:

- consultas de descendentes simples;
- boa performance de leitura;
- fácil identificar ancestralidade.

Desvantagens:

- mover uma conta exige atualizar o path de todos os descendentes;
- aumenta complexidade de escrita;
- exige garantir consistência entre `parent_id` e `path`, caso ambos existam.

### Decisão

Não implementar inicialmente.

Considerar somente se métricas reais mostrarem necessidade.

---

## 61.3 Nested Set

Estrutura:

```text
id
lft
rgt
```

Vantagens:

- consultas de subárvore extremamente eficientes;
- adequado para árvores com muitas leituras e poucas alterações.

Desvantagens:

- movimentação/reordenação mais complexa;
- manutenção mais difícil;
- maior complexidade operacional.

### Decisão

Não recomendado para a primeira implementação.

---

## 61.4 Closure Table

Outra alternativa possível:

```text
chart_account_closure
- ancestor_id
- descendant_id
- depth
```

Exemplo:

```text
ancestor | descendant | depth
10       | 10         | 0
10       | 11         | 1
10       | 12         | 2
10       | 13         | 3
11       | 12         | 1
11       | 13         | 2
12       | 13         | 1
```

Vantagens:

- busca de ancestrais muito rápida;
- busca de descendentes muito rápida;
- profundidade já disponível;
- excelente para relatórios hierárquicos frequentes.

Desvantagens:

- tabela adicional;
- inserções e movimentações exigem manutenção das relações;
- maior complexidade de domínio.

### Decisão

Não implementar inicialmente.

É uma boa alternativa futura caso consultas hierárquicas se tornem um gargalo mensurável.

---

# 62. Decisão de performance

Implementação inicial:

```text
Armazenamento
→ adjacency list (`parent_id`)

Consultas administrativas simples
→ Eloquent + eager loading

Relatórios/DRE/agregações
→ CTE recursiva + agregação SQL

Interface
→ DTO já processado
```

Essa separação deve ser respeitada.

---

# 63. Índices recomendados

Adicionar índices adequados.

Para `chart_accounts`:

```text
INDEX(company_id)
INDEX(parent_id)
INDEX(company_id, parent_id)
INDEX(company_id, is_active)
```

Para `financial_transactions`:

```text
INDEX(company_id, transaction_date)
INDEX(company_id, chart_account_id)
INDEX(company_id, category_id)
INDEX(company_id, cost_center_id)
INDEX(company_id, result_center_id)
```

Para relatórios frequentes, avaliar índice composto:

```text
INDEX(company_id, chart_account_id, transaction_date)
```

A escolha final deve considerar queries reais e `EXPLAIN`.

---

# 64. Cache

Não implementar cache prematuramente.

Primeiro:

1. utilizar índices;
2. utilizar agregação SQL;
3. eliminar N+1;
4. analisar queries;
5. medir tempo real.

Se necessário posteriormente, considerar cache do resultado da DRE por:

```text
company_id
dre_model_id
start_date
end_date
cost_center_id
result_center_id
```

Toda invalidação deve considerar alterações financeiras no período.

---

# 65. Configuração visual do DRE

Na tela de configuração da linha:

```text
Nome: Custos Operacionais

Tipo:
[ Grupo de contas ]

Contas:
[ 2 - Custos Operacionais ]

[x] Incluir contas descendentes

Detalhamento:
[ Somente total ]
```

Alternativamente:

```text
Detalhamento:
- Somente total
- Mostrar 1 nível
- Mostrar 2 níveis
- Mostrar 3 níveis
- Completo
```

Mapeamento:

```text
Somente total
→ display_depth = 0

Mostrar 1 nível
→ display_depth = 1

Mostrar 2 níveis
→ display_depth = 2

Completo
→ display_depth = null
```

---

# 66. Exemplo completo

Plano de Contas:

```text
2 Custos Operacionais
├── 2.01 Frota
│   ├── 2.01.01 Combustíveis
│   │   ├── Diesel
│   │   └── ARLA
│   ├── 2.01.02 Pneus
│   └── 2.01.03 Manutenção
└── 2.02 Administrativo
```

Movimentações:

```text
Diesel .............. 100.000
ARLA ................. 10.000
Pneus ................ 20.000
Manutenção ........... 40.000
Administrativo ....... 30.000
```

Total:

```text
Custos Operacionais = 200.000
```

Configuração DRE:

```text
dre_line:
    name = "Custos Operacionais"
    display_depth = 0

pivot:
    chart_account_id = 2
    include_descendants = true
```

Apresentação:

```text
Custos Operacionais ........ R$ 200.000
```

Se alterar somente:

```text
display_depth = 1
```

apresentação:

```text
Custos Operacionais ........ R$ 200.000
├── Frota .................. R$ 170.000
└── Administrativo ......... R$  30.000
```

Os lançamentos e o cálculo base não mudam.

---

# 67. Regra para contas agrupadoras

Uma conta com filhos pode ou não ser lançável conforme configuração.

Não assumir automaticamente:

```text
possui filhos => não permite lançamento
```

A propriedade:

```text
is_postable
```

é a fonte da regra.

Entretanto, como padrão de UX, ao criar uma conta agrupadora o sistema pode sugerir:

```text
is_postable = false
```

sem tornar isso uma limitação estrutural.

---

# 68. Relatório de Plano de Contas x DRE

Devem existir conceitos distintos.

## Relatório do Plano de Contas

Pode apresentar a árvore financeira real:

```text
Custos Operacionais
├── Frota
│   ├── Combustíveis
│   │   ├── Diesel
│   │   └── ARLA
│   └── Manutenção
└── Administrativo
```

## DRE

Apresenta a estrutura definida pelo usuário:

```text
Receita Líquida
(-) Custos Operacionais
= Margem
(-) Despesas
= Resultado
```

Não confundir os dois relatórios.

---

# 69. Atualização dos critérios de aceite

Além dos critérios anteriores, validar:

14. o DRE pode calcular todos os descendentes sem apresentá-los;
15. `include_descendants` afeta cálculo, não apresentação;
16. `display_depth` afeta apresentação, não cálculo;
17. uma linha com `display_depth = 0` apresenta somente seu total agregado;
18. nenhuma agregação financeira relevante é calculada dentro de callbacks de apresentação do Filament;
19. consultas recursivas de relatório são encapsuladas fora da UI;
20. não ocorre N+1 ao gerar DRE;
21. índices necessários são criados;
22. a implementação suporta CTE recursiva no banco utilizado pelo projeto;
23. a DRE retorna DTOs prontos para apresentação;
24. a profundidade do Plano de Contas não limita nem obriga a profundidade visual do DRE.

---

# 70. Diretriz final para a IA implementadora

Priorizar clareza e separação de responsabilidades.

A regra conceitual final é:

```text
Categoria
    ↓
classifica operacionalmente

Plano de Contas
    ↓
organiza financeiramente em árvore

Centro de Custo
    ↓
indica onde/quem consumiu

Centro de Resultado
    ↓
indica qual negócio/operação recebeu o resultado

DRE
    ↓
define como os dados financeiros serão apresentados
```

Para hierarquia:

```text
parent_id
= verdade estrutural
```

Para cálculo do DRE:

```text
include_descendants
= escopo financeiro
```

Para apresentação:

```text
display_depth
= escopo visual
```

Para performance:

```text
Filament
    ↓
Service
    ↓
Query Object
    ↓
CTE + agregação no banco
    ↓
DTO
    ↓
Filament
```

Não implementar recursão financeira pesada diretamente na camada de apresentação.
