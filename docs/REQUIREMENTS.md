# Requisitos do Projeto

Este documento descreve os requisitos funcionais e não funcionais do sistema Tornedon.

## Visão Geral

Tornedon é um sistema de gestão empresarial focado em controle de estoque, produção e vendas, utilizando Laravel e Filament.

## Requisitos Funcionais

### 1. Gestão de Produtos
- Cadastro de produtos com controle de estoque
- Definição de produtos com/sem controle de estoque
- Configuração de permissão de estoque negativo por produto
- Categorização de produtos

### 2. Controle de Estoque
- Movimentações de entrada e saída
- Controle de saldos por produto
- Validação de estoque antes de vendas/requisições
- Histórico de movimentações

### 3. Ordens de Produção
- Criação automática a partir de cotações aprovadas
- Estados: Pendente, Em Produção, Enviado para QC, Retornado, Cancelado, Finalizado
- Consumo de matéria-prima
- Geração de produto acabado
- Controle de workflow

### 4. Requisições
- Registro de vendas/consumo de produtos
- Validação de estoque disponível
- Geração automática de movimentações de saída
- Estados: Aberta, Fechada

### 5. Cotações
- Criação de cotações com itens
- Aprovação de cotações
- Geração automática de ordens de produção/requisições
- Controle de status dos itens (Pendente, Aprovado, Rejeitado, Vinculado)

### 6. Multi-tenant
- Isolamento de dados por empresa
- Configurações específicas por tenant

### 7. Interface Administrativa
- Painéis Filament para gestão
- Relatórios e dashboards
- Controle de permissões

## Requisitos Não Funcionais

### Performance
- Tempo de resposta < 2s para operações comuns
- Suporte a 100 usuários simultâneos
- Otimização de queries N+1

### Segurança
- Autenticação e autorização robustas
- Validação de todas as entradas
- Proteção contra ataques comuns (XSS, CSRF, SQL Injection)
- Logs de auditoria

### Usabilidade
- Interface intuitiva com Filament
- Mensagens de erro claras
- Navegação consistente

### Escalabilidade
- Arquitetura preparada para crescimento
- Separação clara de responsabilidades
- Service Layer Pattern

### Manutenibilidade
- Código seguindo PSR
- Cobertura de testes > 80%
- Documentação atualizada

### Compatibilidade
- PHP 8.1+
- Laravel 10+
- MySQL 8.0+ ou PostgreSQL 13+
- Navegadores modernos

## Regras de Negócio

### Estoque
- Produtos sem controle de estoque devem vir de ordens de produção
- Estoque negativo só permitido se configurado no produto
- Todas as movimentações devem ser rastreáveis

### Produção
- Ordens de produção consomem matéria-prima e geram produto
- Workflow deve ser respeitado
- Qualidade deve ser controlada

### Vendas
- Requisições só podem ser criadas se houver estoque
- Cotações aprovadas geram ordens automaticamente

## Dependências Externas

- Laravel Framework
- Filament Admin Panel
- Spatie Permission
- MoneyPHP para valores monetários
- Outros pacotes conforme composer.json

## Métricas de Qualidade

- Cobertura de testes: > 80%
- Complexidade ciclomática: < 10
- Duplicação de código: < 5%
- Tempo de build: < 5 minutos