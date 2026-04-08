- [x] Registrar info. de tempo de garantia padrão 
- [x] Inserir campo no cadastro do parceiro para definir se requer aprovação.
- [x] Incluir valor total da OS.
- [x] Precisa atribuir valor de km para deslocamento padrão na edição

# Inclusões
- Estoque: insumos, produtos, ferramentas;
- Controle de ativos, imobilizados;
- Orçamento: 
	- incluir custos detalhado de orçamento;
	- valor do imposto que será gerado;
	- incluir campo para definir margem de lucro no orçamento geral;
- Ordem de Produção:
	- duplicar ordem existente;
	- maquina/equipamento - remover;
	- arquivo gerado para produção:
		- Dt. Hr inicio automático
		- Dt Hr Fim (manual);
- Ordem de Serviço:
	- Simples apenas para registro;
- Contas à pagar;
- Contas à receber;
- Relatório de NF's
- Fatura;

Realiza o reparo em um equipamento, compra produtos para utilizar, é interessante que esses produtos sejam registrados no cadastro de produto e armazenados no histórico do veículo;

- Quando criar

# TODO Atualizado
## ServiceOrder
- [ ] Criar informação para *Valor do KM* para cálculo do custo de deslocamento.
- [ ] Persistir essa informação no banco de dados ou recalcular o valor na hora de preencher o form (Valor de Deslocament / Distância em KM)

## Documento Fiscal
- [ ] Mover component de *info_complementares_compra* do Perfil Fiscal para dentro do documento fiscal para poder inserir informação na hora de emitir.
- [ ] Montar modo automático para preencher essa informação.
- [ ] Remover component de seleção da fatura
- [ ] Buscar informações padrões que estão no perfil, para utilizar antes da emissão no momento da criação.

## Fatura
- [ ] Verificar alteração de status das faturas
- [ ] Exibir OS e REQ vinculadas

## Contas à Pagar/Receber
- [ ] Implementar

## Notas de Entrada
- [ ] Modelar e unir com movimentação de estoque

## Analisar Funcionamento
- [ ] Replicação dos dados de partners
