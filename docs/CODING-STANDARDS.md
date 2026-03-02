# Padrões de Codificação

Este documento define os padrões de codificação que devem ser seguidos no desenvolvimento do projeto Tornedon.

## Linguagem e Framework

- **PHP**: Versão 8.3 ou superior
- **Laravel**: Framework principal
- **Filament**: Versão 4 para interfaces administrativas

## Convenções de Código

### PSR (PHP Standards Recommendations)

- Seguir PSR-1, PSR-4, PSR-12 para estrutura e estilo de código
- PSR-4 para autoloading

### Nomeação

- **Classes**: PascalCase (ex: `UserService`)
- **Métodos**: camelCase (ex: `createUser()`)
- **Variáveis**: camelCase (ex: `$userName`)
- **Constantes**: UPPER_SNAKE_CASE (ex: `MAX_ATTEMPTS`)
- **Arquivos**: PascalCase para classes (ex: `UserService.php`)

### Estrutura de Diretórios

Seguir a estrutura padrão do Laravel com adições específicas:

```
app/
├── Console/
├── Domain/  # Lógica de domínio
├── Events/
├── Exceptions/
├── Filament/
├── Http/
├── Listeners/
├── Livewire/
├── Models/
├── Services/  # Camada de serviços
├── Tenancy/   # Multi-tenant
├── Traits/
```

### Arquitetura

Ver [Arquitetura e Padrões.md](Arquitetura%20e%20Padrões.md) para detalhes sobre a arquitetura do projeto.

#### Service Layer Pattern

- Services orquestram o fluxo
- Actions contêm regras de negócio
- Models são apenas para dados

#### Validação

- Usar Classe Validator para validação
- Actions devem validar entrada antes de processar

### Banco de Dados

- Usar migrations para alterações de schema
- Seeders para dados iniciais
- Factories para testes

### Testes

- Unit tests para lógica isolada
- Feature tests para fluxos completos
- Usar PHPUnit
- Cobertura mínima de 80%

### Segurança

- Validar todas as entradas
- Usar policies para autorização
- Evitar SQL injection com Eloquent/Query Builder
- Sanitizar dados de saída

### Documentação

- Comentários em métodos complexos
- PHPDoc para classes e métodos públicos
- Documentação em `/docs`

### Git

- Commits em português ou inglês descritivo
- Branches feature/* para novas funcionalidades
- Pull requests com descrição detalhada

## Ferramentas

- **PHPStan**: Análise estática
- **Laravel Pint**: Formatação de código
- **Prettier**: Para assets front-end

## Exceções

Qualquer desvio deve ser justificado e documentado.