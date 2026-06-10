# Manual: Ordem de Serviço, Faturamento e Emissão de Notas

## Objetivo

Este manual orienta o fluxo operacional de:

1. Criação da Ordem de Serviço (OS)
2. Inclusão de serviços e produtos
3. Encerramento e faturamento
4. Confirmação da fatura
5. Geração e emissão das notas fiscais

## Visão geral do fluxo

O processo padrão segue esta sequência:

1. Criar a OS
2. Salvar a OS
3. Editar a OS e completar os dados
4. Adicionar os serviços da OS
5. Adicionar produtos, quando necessário
6. Encerrar a OS
7. Faturar a OS
8. Confirmar a fatura
9. Gerar e emitir a NF-e e/ou NFS-e

## 1. Criando a Ordem de Serviço

No menu de vendas, acesse **Ordens de Serviço** e clique em **Nova Ordem de Serviço**.

Na criação inicial da OS, o preenchimento principal é o **Cliente**.

Após informar o cliente, salve a OS para que o registro seja criado e a tela de edição seja liberada.

Importante:

1. Na etapa de criação, informe o **Cliente**
2. Clique em salvar para criar a OS
3. Somente depois continue o preenchimento na tela de edição

## 2. Editando a OS após salvar

Depois de salvar e abrir a OS em edição, complete os demais dados, como:

1. **Equipamento**
2. **Prioridade**
3. **Tipo**
4. **Data da Ordem**
5. Campos de observação e atendimento, quando aplicável

Alerta:

1. Se a OS não for salva primeiro, as demais etapas do fluxo não ficam disponíveis corretamente
2. Serviços e produtos devem ser incluídos após a OS já estar criada

## 3. Adicionando serviços na OS

Na edição da OS, localize a seção **Serviços**.

Nessa área, é possível:

1. Clicar em **Serviço** para adicionar um item de serviço já cadastrado
2. Clicar em **Novo serviço** para cadastrar um serviço sem sair da OS

Ao incluir o serviço, revise:

1. Quantidade
2. Valor unitário
3. Desconto, se houver
4. Observações do item

Os serviços lançados na OS serão a base do faturamento de serviços e da futura **NFS-e**.

## 4. Inclusão de produtos na OS

Sim, a OS permite incluir produtos.

Na edição da OS, localize a seção **Produtos** e clique em **Produto**.

Ao adicionar um produto, o sistema gera ou utiliza uma **requisição vinculada** à Ordem de Serviço. Isso significa que os produtos da OS ficam controlados por essa requisição vinculada.

Preencha os dados do produto, como:

1. Produto
2. Unidade
3. Quantidade
4. Valor unitário
5. Desconto, se houver
6. Observações

## 5. Como os produtos impactam o faturamento

Os produtos adicionados na OS entram no faturamento por meio da requisição vinculada.

Na prática:

1. **Serviços da OS** geram o valor de serviços da fatura
2. **Produtos da requisição vinculada** geram o valor de produtos da fatura

Isso também impacta os documentos fiscais:

1. Itens de **produto** alimentam a **NF-e**
2. Itens de **serviço** alimentam a **NFS-e**

## 6. Encerrando a Ordem de Serviço

Depois de revisar os dados, clique em **Encerrar**.

No encerramento, o sistema pode apresentar opções como:

1. **Enviar e-mail ao encerrar**
2. **Faturar ao encerrar**

Se a opção **Faturar ao encerrar** for marcada, a fatura será criada automaticamente ao final do encerramento.

Se houver requisição vinculada, ela também participa do mesmo faturamento.

## 7. Faturando a Ordem de Serviço

Se a OS já estiver encerrada e ainda não tiver sido faturada, clique em **Faturar**.

Importante:

1. O botão **Faturar** fica disponível para OS **encerrada**
2. Se houver requisição vinculada, ela será faturada junto na mesma fatura

Após faturar, o sistema redireciona para a **Fatura**.

## 8. Revisando a Fatura

Na tela da fatura, confira:

1. Cliente
2. Forma de pagamento
3. Condição de pagamento
4. Categoria financeira
5. **Valor de Serviços**
6. **Valor de Produtos**
7. Desconto
8. Valor total

Se o cadastro do cliente estiver sem endereço válido, ajuste isso antes de prosseguir com os documentos fiscais.

## 9. Confirmando a Fatura

Com a fatura revisada, clique em **Confirmar**.

Na confirmação, o sistema:

1. Gera automaticamente os documentos fiscais necessários
2. Gera as contas a receber
3. Permite, opcionalmente, disparar a emissão dos documentos fiscais na mesma etapa

Durante a confirmação, revise também:

1. Forma de pagamento
2. Condição de pagamento
3. Categoria financeira
4. Se os valores serão marcados como já recebidos
5. Se a emissão das notas será disparada imediatamente

## 10. Geração das notas fiscais

Após confirmar a fatura, o sistema pode gerar:

1. **NF-e**, quando houver itens de produto
2. **NFS-e**, quando houver itens de serviço

Resumo prático:

1. Fatura com apenas serviços: gera **NFS-e**
2. Fatura com apenas produtos: gera **NF-e**
3. Fatura com serviços e produtos: pode gerar **NFS-e** e **NF-e**

## 11. Emissão da NFS-e

Para a nota de serviço, use a ação **Emitir NFS-e**.

Pontos de atenção:

1. Se a fatura tiver mais de um serviço nas OS vinculadas, pode ser necessário escolher qual serviço será usado como base da descrição fiscal
2. A descrição do item da NFS-e pode ser ajustada antes da geração e da emissão
3. A emissão é **assíncrona**, ou seja, ela entra em fila para processamento

Depois, acompanhe o status e use **Consultar NFS-e** se precisar atualizar o retorno mais recente.

## 12. Emissão da NF-e

Para a nota de produto, use a ação **Emitir NF-e**.

Pontos de atenção:

1. A NF-e é gerada com base nos itens de produto da fatura
2. As regras fiscais cadastradas serão usadas no documento
3. A emissão também é **assíncrona**, entrando em fila para processamento

Depois, acompanhe o status e utilize a consulta da nota, quando necessário.

## 13. Boas práticas operacionais

1. Sempre salve a OS logo após informar o cliente
2. Complete os demais dados somente na edição da OS
3. Sempre revise serviços, produtos, descontos e observações antes de encerrar a OS
4. Confirme se os produtos realmente foram incluídos na seção **Produtos** da OS
5. Verifique se o cliente possui endereço válido antes da emissão fiscal
6. Revise a forma e a condição de pagamento antes de confirmar a fatura
7. Em faturamentos mistos, valide se haverá emissão de **NF-e** e **NFS-e**

## Resumo final

O fluxo recomendado é:

1. Criar a OS informando o cliente
2. Salvar a OS
3. Editar a OS e preencher os demais dados
4. Incluir serviços
5. Incluir produtos, se necessário
6. Encerrar a OS
7. Faturar
8. Confirmar a fatura
9. Gerar e emitir as notas fiscais correspondentes

Sempre que houver produtos na OS, eles serão tratados pela requisição vinculada e poderão compor a **NF-e**. Já os serviços da OS compõem a **NFS-e**.
