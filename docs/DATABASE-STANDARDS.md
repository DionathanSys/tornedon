# Padrões de Banco de Dados

Este documento define os padrões para modelagem e uso do banco de dados no projeto Tornedon.

## Estrutura Geral

- Usar InnoDB como engine
- Charset: utf8mb4
- Collation: utf8mb4_unicode_ci

## Convenções de Nomeação

### Tabelas
- snake_case
- Plural (ex: `users`, `products`)
- Prefixo para relacionamentos (ex: `user_permissions`)

### Colunas
- snake_case
- Prefixo para chaves estrangeiras (ex: `user_id`, `product_id`)
- Campos booleanos: `is_*` ou `has_*` (ex: `is_active`, `has_stock_control`)

### Índices
- `idx_table_column` para índices simples
- `idx_table_col1_col2` para compostos

## Tipos de Dados

### Numéricos
- `id`: BIGINT UNSIGNED AUTO_INCREMENT
- `quantities`: DECIMAL(10,2) ou DECIMAL(15,4) conforme precisão
- `amounts`: DECIMAL(10,2) (usar MoneyPHP)

### Strings
- `VARCHAR(255)` para textos curtos
- `TEXT` para textos longos
- `ENUM` para valores fixos

### Datas
- `created_at`, `updated_at`: TIMESTAMP
- Campos de data: DATE ou DATETIME

## Relacionamentos

### Chaves Estrangeiras
- Sempre definir constraints
- ON DELETE: RESTRICT ou CASCADE conforme regra de negócio
- Nome: `fk_table_referenced_table`

### Many-to-Many
- Tabela pivot: `table1_table2`
- Colunas: `table1_id`, `table2_id`, `created_at`, `updated_at`

## Soft Deletes

- Usar `deleted_at` TIMESTAMP NULL
- Índice em `deleted_at`

## Multi-tenant

- Campo `company_id` em todas as tabelas
- Índice composto em `(company_id, id)`
- Políticas para isolamento de dados

## Migrations

### Estrutura
```php
Schema::create('table_name', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained();
    // outros campos
    $table->timestamps();
    $table->softDeletes();

    $table->index(['company_id', 'created_at']);
});
```

### Boas Práticas
- Uma migration por alteração lógica
- Rollback possível
- Usar `change()` para modificações
- Documentar mudanças complexas

## Seeders e Factories

- Usar factories para dados de teste
- Seeders para dados essenciais
- Dados fake/realistas

## Consultas

### Otimização
- Evitar N+1 queries
- Usar eager loading
- Índices apropriados
- Cache quando necessário

### Segurança
- Nunca concatenar SQL
- Usar bindings
- Validar entradas

## Backup e Monitoramento

- Backups regulares
- Monitorar queries lentas
- Logs de queries em desenvolvimento

## Ferramentas

- Laravel migrations
- Tinker para testes
- phpMyAdmin/Adminer para visualização