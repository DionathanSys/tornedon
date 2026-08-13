# Como Preencher uma DRE

A DRE, Demonstração do Resultado do Exercício, é um relatório usado para entender se a empresa teve lucro ou prejuízo em determinado período.

No sistema, a DRE é configurável por empresa e é montada a partir de linhas vinculadas ao Plano de Contas.

## 1. Antes de preencher a DRE

Antes de montar uma DRE, verifique se já existem:

- Plano de Contas cadastrado
- Categorias financeiras vinculadas ao Plano de Contas
- Lançamentos financeiros com categoria
- Competência preenchida nos lançamentos
- Centros de custo ou resultado, se forem usados no relatório

## 2. Criar o modelo de DRE

Acesse:

`Financeiro > Modelos DRE`

Crie um novo modelo informando:

- Nome: exemplo `DRE Gerencial`
- Chave do modelo: exemplo `dre_gerencial`
- Ativo: sim
- Modelo padrão: sim, se este for o principal da empresa

A chave do modelo é importante para comparar ou consolidar DREs entre empresas.

## 3. Criar as linhas da DRE

Dentro do modelo, cadastre as linhas que aparecerão no relatório.

Exemplo de estrutura:

| Código | Nome | Tipo | Operação |
|---|---|---|---|
| RECEITA_BRUTA | Receita Bruta | Grupo de Contas | Soma |
| DEDUCOES | Deduções da Receita | Grupo de Contas | Subtrai |
| RECEITA_LIQUIDA | Receita Líquida | Subtotal | Soma |
| CUSTOS | Custos | Grupo de Contas | Subtrai |
| LUCRO_BRUTO | Lucro Bruto | Subtotal | Soma |
| DESPESAS | Despesas Operacionais | Grupo de Contas | Subtrai |
| RESULTADO | Resultado Operacional | Subtotal | Soma |

## 4. Tipos de linha

Cada linha pode ter um tipo.

### Grupo de Contas

Usado quando a linha deve buscar valores do Plano de Contas.

Exemplo:

`Receita Bruta` vinculada às contas de receita.

### Subtotal

Usado para somar linhas filhas.

Exemplo:

`Lucro Bruto` pode somar `Receita Líquida` menos `Custos`.

### Linha Informativa

Usada apenas para organizar visualmente a DRE.

Exemplo:

`Resultado Financeiro`

## 5. Vincular contas do Plano de Contas

Nas linhas do tipo `Grupo de Contas`, vincule uma ou mais contas contábeis.

Exemplo:

Linha:

`Receita Bruta`

Contas vinculadas:

- `Receitas de Serviços`
- `Receitas de Produtos`
- `Receitas Operacionais`

Se a opção `Incluir descendentes` estiver marcada, o sistema também considera as contas filhas da conta selecionada.

## 6. Definir a operação

A operação define se o valor da linha entra somando ou subtraindo.

Use:

- `Soma` para receitas e resultados positivos
- `Subtrai` para custos, despesas, impostos e deduções

Exemplo:

| Linha | Operação |
|---|---|
| Receita Bruta | Soma |
| Deduções | Subtrai |
| Custos | Subtrai |
| Despesas | Subtrai |

## 7. Configurar a hierarquia

As linhas podem ter linha pai.

Exemplo simples:

```text
Receita Bruta
Deduções
Receita Líquida
Custos
Lucro Bruto
Despesas Operacionais
Resultado Operacional
```

Exemplo com agrupamento:

```text
Receitas
  Receita de Serviços
  Receita de Produtos

Custos
  Combustível
  Peças
  Mão de obra

Despesas
  Administrativas
  Comerciais
  Financeiras
```

## 8. Configurar a exibição

Cada linha pode ter configurações visuais:

- Ordem
- Negrito
- Visível ou oculta
- Profundidade visual

A profundidade visual controla o recuo da linha no relatório, sem depender da profundidade real do Plano de Contas.

## 9. Preencher os lançamentos financeiros

A DRE depende dos lançamentos financeiros.

Para que os valores apareçam corretamente:

- A categoria financeira deve estar vinculada a uma conta do Plano de Contas
- O lançamento deve ter data de competência
- O lançamento não pode estar cancelado
- O lançamento deve pertencer à empresa selecionada

## 10. Competência ou caixa

Na hora de gerar o relatório, escolha o modo.

### Competência

Considera a data de competência do lançamento.

Use quando quiser analisar o resultado econômico do período.

### Caixa

Considera movimentações financeiras realizadas no caixa ou banco.

Use quando quiser analisar o que efetivamente entrou ou saiu no período.

## 11. Visões da DRE

O relatório pode ser gerado em diferentes visões.

### Realizado

Mostra apenas valores já pagos ou recebidos.

### Previsto + Realizado

Mostra valores em aberto e realizados dentro do período.

### Comparativo

Reservado para comparação entre períodos, como mês atual contra mês anterior.

## 12. Centros de custo e resultado

Se quiser analisar uma operação específica, filtre por:

- Centro de custo
- Centro de resultado

Exemplos:

- `Resultado Transporte`
- `Resultado Oficina`
- `Resultado Venda de Peças`

Use esses filtros quando quiser uma DRE segmentada.

## 13. DRE consolidada entre empresas

Para consolidar várias empresas, elas precisam ter modelos DRE equivalentes.

Isso significa que os modelos devem ter:

- Mesma chave do modelo
- Mesma estrutura de linhas
- Mesmos códigos
- Mesmas operações
- Mesma configuração estrutural

Se os modelos forem diferentes, o sistema não consolida os resultados.

## 14. Exemplo prático

Estrutura sugerida:

| Código | Linha | Tipo | Operação | Contas |
|---|---|---|---|---|
| RECEITA_BRUTA | Receita Bruta | Grupo de Contas | Soma | Receitas |
| IMPOSTOS | Impostos sobre Receita | Grupo de Contas | Subtrai | Impostos |
| RECEITA_LIQUIDA | Receita Líquida | Subtotal | Soma | - |
| CUSTOS | Custos Operacionais | Grupo de Contas | Subtrai | Custos |
| LUCRO_BRUTO | Lucro Bruto | Subtotal | Soma | - |
| DESPESAS | Despesas Operacionais | Grupo de Contas | Subtrai | Despesas |
| RESULTADO | Resultado Operacional | Subtotal | Soma | - |

## 15. Conferência final

Antes de usar a DRE oficialmente, confira:

- Todas as linhas importantes foram cadastradas
- As contas corretas foram vinculadas
- As operações de soma/subtração estão corretas
- As categorias financeiras apontam para o Plano de Contas
- Os lançamentos possuem competência
- O período do relatório está correto
- Os centros de custo e resultado foram preenchidos quando necessário
