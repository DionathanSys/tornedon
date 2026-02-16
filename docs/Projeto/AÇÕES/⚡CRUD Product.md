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
	- [x] origem valor de venda (Select: fixo, calculado, definir no ato)
	- [x] Outro códigos, código ref. externo KeyValue: Ref. - Cód.? **Como pesquisar?**
	- [x] tipo de item
	- [x] código fábrica
	- [x] peso bruto
	- [x] peso líquido
	- [x] código de barras
	- [ ] ipi
		- [x] cód enquadramento
		- [ ] cst ipi venda
		- [ ] aliq. ipi venda
		- [ ] cst ipi compra
		- [ ] aliq ipi compra
	- [ ] indicador escala relevante
	- [ ] cnpj fabr.
	- [ ] cód beneficio fisca
	- [ ] cfop?
	- [ ] icms?