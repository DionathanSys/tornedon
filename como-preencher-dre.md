# Como montar e utilizar a DRE

A DRE mostra as receitas, custos, despesas e o resultado da empresa em um período. A maneira recomendada de começar é usar **Montar minha DRE**.

## 1. Preparar os cadastros

- Cadastre o **Plano de Contas** da empresa.
- Vincule as **categorias financeiras** às respectivas contas do plano.
- Classifique as parcelas a pagar e a receber. Parcelas sem conta contábil não aparecem nos grupos da DRE.
- Informe a competência nos lançamentos. Na ausência dela, o relatório usa o vencimento; para movimentos manuais, usa a data da transação.

## 2. Montar a DRE sem cadastrar linhas manualmente

1. Entre na empresa desejada e abra **Financeiro → Relatórios financeiros → DRE**.
2. Clique em **Montar minha DRE**, no topo da página. O botão também está disponível na listagem de **Cadastros financeiros → Modelos de DRE**.
3. Informe o nome, por exemplo **DRE Gerencial**, e mantenha **Usar como modelo padrão** marcado se quiser abri-lo automaticamente nas próximas consultas.
4. Confira o grupo sugerido para **cada conta**. A sugestão usa o tipo cadastrado no plano, por isso deve ser revisada:

   | Grupo | Exemplos |
   |---|---|
   | Receitas de vendas e serviços | Vendas de peças, prestação de serviços |
   | Deduções e impostos sobre vendas | Impostos incidentes sobre faturamento |
   | Custos | Materiais e serviços consumidos na operação |
   | Despesas operacionais | Aluguel, administração, despesas comerciais |
   | Receitas e despesas financeiras | Rendimentos, juros, tarifas bancárias |
   | Outros resultados | Outros ganhos e perdas |
   | Não incluir nesta DRE | Contas patrimoniais, transferências e contas sem efeito no resultado |

5. Clique em **Criar DRE pronta para usar**.

O sistema cria a estrutura, os vínculos, os sinais e os resultados automaticamente. Na tela do relatório, a nova DRE já fica selecionada. Na listagem de modelos, você será levado à edição do modelo criado.

Cada conta é vinculada **uma única vez e sem incluir suas filhas automaticamente**. Todas as contas existentes são apresentadas para conferência, inclusive as contas-pai. Isso evita somar a mesma conta filha duas vezes. Se o plano estiver vazio, cadastre as contas antes de concluir.

É necessário ter permissão para criar modelos de DRE para utilizar a montagem guiada.

## 3. Estrutura criada automaticamente

```text
Receitas de vendas e serviços
Deduções e impostos sobre vendas
Receita líquida
Custos
Lucro bruto
Despesas operacionais
Resultado operacional
Receitas e despesas financeiras
Despesas financeiras — descontos concedidos
Outros resultados
Resultado final
```

Receita líquida, lucro bruto, resultado operacional e resultado final são **resultados acumulados**: somam os grupos anteriores, sem somar novamente os outros resultados intermediários.

Os valores conservam o sinal natural dos lançamentos: recebimentos positivos e pagamentos negativos. **Não é preciso configurar “Subtrair” para despesas no modelo guiado.** Essa operação inverte o sinal que o lançamento já possui.

## 4. Consultar o relatório

Escolha modelo, período, modo e visão, e clique em **Aplicar filtros**.

### Modo

- **Competência:** usa a competência da parcela, ou seu vencimento se não houver competência. Um desconto concedido em recebimento posterior fica associado à competência da parcela. Assim, uma concessão ou estorno pode atualizar o resultado de um período anterior.
- **Caixa:** usa as movimentações financeiras efetivas e suas datas. Recebimentos sem movimento de caixa/banco não compõem o realizado desse modo.

### Visão

- **Realizado:** valores já recebidos/pagos; em competência, usa a competência das parcelas, não a data do recebimento/pagamento.
- **Previsto:** saldos ainda em aberto. Não repete descontos já concedidos.
- **Previsto + realizado:** combina os valores realizados com os saldos em aberto.
- **Previsto e realizado separados:** apresenta as duas bases separadamente.
- **Comparativo:** compara os períodos informados usando a base selecionada.

Centros de custo e resultado permitem segmentar a análise de uma empresa. Para consolidar empresas, cada uma precisa de modelo equivalente, com a mesma chave e estrutura. A montagem guiada utiliza a chave `dre_gerencial_guiada_v1`; confira o mapeamento das contas em cada empresa.

## 5. Desconto concedido na hora de receber

Registre normalmente o recebimento da parcela, preenchendo o **valor efetivamente recebido** e o **desconto concedido** nos respectivos campos.

Exemplo: parcela de R$ 1.000,00, com desconto de R$ 50,00, recebida por R$ 950,00:

| Linha da DRE | Valor |
|---|---:|
| Receitas de vendas e serviços | R$ 1.000,00 |
| Despesas financeiras — descontos concedidos | − R$ 50,00 |
| Resultado final, sem outros lançamentos | R$ 950,00 |

O sistema recompõe a receita antes do desconto no recebimento e apresenta o desconto como despesa financeira. **Não lance outra despesa ou saída de caixa para o mesmo desconto.** No banco entram apenas R$ 950,00; a separação acontece no relatório.

### Recebimento parcial

Parcela de R$ 1.000,00, recebimento de R$ 400,00 e desconto de R$ 50,00:

| Visão | Receita antes do desconto | Desconto | Resultado líquido |
|---|---:|---:|---:|
| Realizado | R$ 450,00 | − R$ 50,00 | R$ 400,00 |
| Previsto | R$ 550,00 | R$ 0,00 | R$ 550,00 |
| Previsto + realizado | R$ 1.000,00 | − R$ 50,00 | R$ 950,00 |

### Estorno

- No modo caixa, o estorno desfaz a composição da receita e do desconto **na data do movimento de estorno**, inclusive se ocorrer em outro mês.
- Em competência, recebimentos devolvidos deixam de contribuir com o desconto automático, acompanhando a recomposição da parcela.

A linha automática considera somente descontos de recebimentos das contas incluídas no modelo e respeita empresa e filtros por centro. Descontos comerciais aplicados na venda ou na fatura não são reclassificados como descontos financeiros de recebimento. Juros e multas mantêm o tratamento existente; esta funcionalidade separa especificamente os descontos concedidos no recebimento.

## 6. Conferir e ajustar depois

- A seção **Contas fora deste relatório** informa contas sem vínculo. Confira se a exclusão é intencional.
- Contas cadastradas depois da montagem não são incluídas automaticamente. Abra **Cadastros financeiros → Modelos de DRE**, edite o modelo e ajuste suas linhas.
- No grupo desejado, selecione a conta nova. Para mudar uma conta de grupo, remova-a do anterior e inclua-a no novo.
- Uma conta não deve aparecer em dois grupos. Nos modelos com descontos automáticos, o relatório bloqueia vínculos sobrepostos para evitar duplicidade.
- Mantenha **uma única** linha do tipo **Descontos concedidos no recebimento**. Ela não precisa de contas vinculadas diretamente.
- O botão **Montar minha DRE** cria um novo modelo; não altera os modelos anteriores.

## 7. Modelos manuais e modelos já existentes

O cadastro manual continua disponível em **Criar modelo manual**. Os tipos de linha são:

- **Grupo de contas:** apura valores das contas vinculadas.
- **Subtotal:** soma as linhas-filhas.
- **Resultado acumulado:** soma os grupos anteriores no mesmo nível, sem repetir resultados acumulados anteriores.
- **Descontos concedidos no recebimento:** separa os descontos financeiros e recompõe a receita dos grupos correspondentes.
- **Cabeçalho:** organiza e totaliza suas linhas-filhas.
- **Separador:** organização visual.

Modelos anteriores, sem a linha automática de descontos, continuam apresentando os valores líquidos como antes. Para adotar a estrutura recomendada, crie um modelo pelo botão **Montar minha DRE**, confira as contas e use-o como padrão.

Vínculos antigos que incluíam descendentes são preservados ao editar uma linha. Novos vínculos consideram somente a conta selecionada. Revise vínculos entre contas-pai e contas-filhas caso adapte um modelo antigo.
