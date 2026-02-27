# Implementações Completadas

## ✅ Controle de Movimentação de Estoque (2026-02-24)
- **Status**: Pronto para Migration e Deploy
- **Padrão**: RequisitionService (Service + Actions + Validators)
- **Localização**: `app/Services/StockMovement/`, `app/Filament/Clusters/Inventory/Resources/StockMovements/`
- **Documentação**: Ver `docs/README-STOCK-MOVEMENTS.md`
- **Próximo**: Executar `php artisan migrate`
- **Features**:
  - 7 tipos de movimento (Entry, Exit, Adjustment, Transfer, Return, Consumption, Loss)
  - Table com 50+ campos incluindo auditoria
  - Service com CRUD + restore
  - Validator com regras comuns compartilhadas
  - Resource Filament com páginas (List, Create, Edit)
  - Actions Filament (Create Modal, Restore)
  - Todos os padrões do projeto seguidos

---

# Lembretes
- Para as listagens, verificar qual forma de montas as queries para que o usuário não veja o que não pertença à ele. Pois se ele pertence a mais de uma empresa é interessante ele poder ter acesso aos registros das duas ao mesmo tempo, usando um select e validando no back se ele tem permissão.
- Outra opção é ele estar logado e uma empresa estar ativa, e ele poder mudar qual esta ativa, porém nesse caso não pode visualizar os registros das duas ao mesmo tempo.


# Cadastro de Parceiros
iniciar solicitando o CPF/CNPJ, realizar a consulta no DB para verificar se já esta cadastrado, caso esteja importar os dados, caso contrário realizar a busca na API.