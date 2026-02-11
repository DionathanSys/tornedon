## Detalhes
- Necessário possuir classe de Service com sub classes representando *actions*, responsáveis por fazer as operações.
- Possuir comunicação entre as camadas, respeitando o fluxo:
```md
	Filament -> ProductService -> Action
```
- Utilizar as traits existentes em Actions e Service, para realizar a comunicação de sucesso, erros etc.
## Task
- [ ] Registrar o produto em Product  Stock;
	- [ ] Criar campo na tabela de produtos, do tipo booleano, para definir se o produto controla ou não estoque.
	- [ ] O registro no Stock deve ser feito apenas se o item terá controle de estoque.
	- [ ] Isso deve ser verificado quando o mesmo é criado, e também quando é atualizado, criando quando marcado como sim, ou excluindo caso exista e for atualizado para 'não';
