# Lembretes
- Para as listagens, verificar qual forma de montas as queries para que o usuário não veja o que não pertença à ele. Pois se ele pertence a mais de uma empresa é interessante ele poder ter acesso aos registros das duas ao mesmo tempo, usando um select e validando no back se ele tem permissão.
- Outra opção é ele estar logado e uma empresa estar ativa, e ele poder mudar qual esta ativa, porém nesse caso não pode visualizar os registros das duas ao mesmo tempo.


# Cadastro de Parceiros
iniciar solicitando o CPF/CNPJ, realizar a consulta no DB para verificar se já esta cadastrado, caso esteja importar os dados, caso contrário realizar a busca na API.