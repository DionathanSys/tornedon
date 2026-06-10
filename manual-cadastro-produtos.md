# Manual: Cadastro de Produtos

## Objetivo

Este manual orienta o cadastro e a manutenção de produtos no sistema, usando os nomes reais exibidos na interface.

## Onde acessar

No menu principal, acesse **Estoque** e depois **Produtos**.

Nessa tela, você pode:

1. Consultar os produtos já cadastrados
2. Filtrar por categoria, unidade, controle de estoque e fabricação própria
3. Criar um novo produto
4. Editar um produto existente

## Visão geral do processo

O fluxo recomendado é:

1. Acessar **Produtos**
2. Clicar em **Produto** para criar um novo cadastro
3. Preencher a aba **Geral**
4. Preencher a aba **Preços**
5. Preencher a aba **Conversões**, se necessário
6. Salvar o produto
7. Reabrir ou continuar na edição para complementar a aba **Impostos**, quando aplicável

## 1. Criando um produto

Na tela de **Produtos**, clique em **Produto**.

O cadastro é organizado por abas.

## 2. Aba Geral

Na aba **Geral**, seção **Informações do Produto**, preencha os principais dados do cadastro.

### Campos principais

1. **Código do Produto**
2. **Nome**
3. **Descrição**
4. **Categoria**
5. **Unidade**
6. **Tipo de Item**
7. **Código Fábrica**
8. **Peso Bruto**
9. **Peso Líquido**
10. **Código de Barras**

### Campos de controle

1. **Fabricação Própria**
2. **Controla Estoque?**
3. **Ativo**

### Outros códigos

O campo **Outros Códigos (Ref. / Cód.)** permite registrar referências adicionais do produto.

Use esse recurso quando o mesmo item possuir:

1. Código interno alternativo
2. Referência do fornecedor
3. Código comercial complementar

## 3. Campo Código do Produto

O campo **Código do Produto** aparece na tela, mas fica bloqueado para edição.

Isso indica que o código não é preenchido manualmente nessa etapa.

## 4. Campo Categoria

No campo **Categoria**, você pode:

1. Selecionar uma categoria existente
2. Criar uma nova categoria no momento do cadastro

Se optar por criar uma categoria na hora, informe:

1. **Nome**
2. **Descrição**, se necessário

## 5. Campo Unidade

O campo **Unidade** é obrigatório.

Atenção:

1. A unidade é definida no cadastro inicial
2. Na edição do produto, esse campo fica bloqueado
3. Por isso, revise com cuidado antes de salvar

## 6. Aba Preços

Na aba **Preços**, seção **Precificação**, configure os dados comerciais do produto.

### Campos disponíveis

1. **Margem de Lucro (%)**
2. **Preço Mínimo de Venda**
3. **Origem do Preço de Venda**
4. **Valor de Venda Fixo**

### Como preencher

Use **Origem do Preço de Venda** para definir a lógica comercial do item.

Se a origem escolhida for preço fixo, preencha também o campo **Valor de Venda Fixo**.

## 7. Aba Conversões

Na aba **Conversões**, você pode cadastrar **Unidades alternativas**.

Esse recurso é útil quando o produto é comprado, armazenado ou vendido em unidades diferentes.

Exemplo:

1. Unidade padrão: `JG`
2. Unidade alternativa: `CX`
3. Conversão: `1 CX = 2 JG`

Para cada conversão, informe:

1. **Unidade alternativa**
2. **Fator para unidade padrão**

## 8. Salvando o cadastro

Depois de preencher os dados necessários, salve o produto.

Alerta importante:

1. Revise a **Unidade** antes de salvar
2. Revise se o produto deve ou não **Controlar Estoque**
3. Revise se o cadastro deve ficar **Ativo**

## 9. Edição do produto

Após salvar, o produto pode ser aberto em edição para manutenção dos dados.

Na edição, além dos campos já cadastrados, podem aparecer controles adicionais, como:

1. **Permite Venda**
2. Aba **Impostos**

## 10. Campo Permite Venda

O campo **Permite Venda** aparece na edição do produto.

Esse campo indica se o item pode ser usado em operações de venda e faturamento.

Use com atenção para evitar que um produto ativo fique indisponível para uso comercial.

## 11. Aba Impostos

A aba **Impostos** aparece na edição do produto.

Nela, é possível complementar a tributação do item.

### Tributação básica

Na seção **Tributação**, informe, quando aplicável:

1. **Origem do Produto**
2. **NCM**
3. **Código CEST**

### Regras fiscais adicionais

Também existem seções para configuração de:

1. **Regras ICMS**
2. **Regras IPI**

Esses campos devem ser preenchidos com critério fiscal, conforme a operação da empresa e a classificação do produto.

## 12. Consulta e filtros na listagem

Na listagem de produtos, o sistema permite localizar registros por:

1. **Código**
2. **Nome**
3. **Código de Barras**
4. **Código Fábrica**
5. Referências adicionais

Também é possível filtrar por:

1. **Ativo**
2. **Categoria**
3. **Unidade**
4. **Controla estoque?**
5. **Fabricação própria?**

## 13. Boas práticas de cadastro

1. Cadastre o **Nome** de forma clara e padronizada
2. Escolha corretamente a **Unidade**, pois ela fica bloqueada na edição
3. Marque **Controla Estoque?** somente quando o item realmente participar do estoque
4. Use **Outros Códigos** para registrar referências úteis de fornecedores ou do mercado
5. Revise os dados fiscais na aba **Impostos** antes de usar o produto em faturamento
6. Mantenha o produto **Ativo** apenas quando ele puder ser utilizado normalmente
7. Valide o campo **Permite Venda** na edição, quando o item precisar ser comercializado

## Resumo final

O cadastro de produtos é dividido em etapas.

Resumo recomendado:

1. Criar o produto em **Estoque > Produtos**
2. Preencher a aba **Geral**
3. Configurar a aba **Preços**
4. Informar **Conversões**, se necessário
5. Salvar o cadastro
6. Revisar a edição do produto
7. Completar a aba **Impostos**, quando aplicável

Esse fluxo ajuda a manter o produto corretamente preparado para estoque, venda e faturamento.
