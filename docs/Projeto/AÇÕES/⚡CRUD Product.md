## Detalhes
- Necessário possuir classe de Service com sub classes representando *actions*, responsáveis por fazer as operações.
- Possuir comunicação entre as camadas, respeitando o fluxo:
```md
	Filament -> ProductService -> Action
```
- Utilizar as traits existentes em Actions e Service, para realizar a comunicação de sucesso, erros etc.
## Task
- [x] Registrar o produto em Product  Stock;
	- [x] Criar campo na tabela de produtos, do tipo booleano, para definir se o produto controla ou não estoque.
	- [x] O registro no Stock deve ser feito apenas se o item terá controle de estoque.
	- [x] Isso deve ser verificado quando o mesmo é criado, e também quando é atualizado, criando quando marcado como sim, ou excluindo caso exista e for atualizado para 'não';
- [x] Incluir atualização dos impostos dentro da tela de produtos;
- [ ] Incluir campos para: 
	- [ ] origem valor de venda (Select: fixo, calculado, definir no ato)
	- [ ] Outro códigos, código ref. externo KeyValue: Ref. - Cód.? **Como pesquisar?**
	- [ ] tipo de item
	- [ ] código fábrica
	- [ ] peso bruto
	- [ ] peso líquido
	- [ ] código de barras
	- [ ] ipi
		- [ ] cód enquadramento
		- [ ] cst ipi venda
		- [ ] aliq. ipi venda
		- [ ] cst ipi compra
		- [ ] aliq ipi compra
	- [ ] indicador escala relevante
	- [ ] cnpj fabr.
	- [ ] cód beneficio fisca
	- [ ] cfop?
	- [ ] icms?