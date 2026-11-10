# Recuperação de vínculos de NF-e antiga

Notas criadas antes da inclusão de `fiscal_document_items.requisition_item_id` podem falhar no preview com “Item fiscal sem vínculo rastreado à requisição”. A migration que adicionou o campo não preencheu as notas existentes.

## Executar no ambiente com acesso ao banco

Primeiro, diagnostique a nota (sem gravar alterações):

```bash
php artisan fiscal:recover-requisition-links 2766
```

O cabeçalho informa empresa e fatura. A tabela mostra item da NF, produto, quantidade, unidade, IDs candidatos dos itens de requisição e eventuais pendências.

Se o diagnóstico indicar vínculos recuperáveis e nenhuma pendência:

```bash
php artisan fiscal:recover-requisition-links 2766 --apply
```

Opcionalmente, use `--company=ID` em ambos os comandos para exigir que a nota pertença à empresa informada.

Depois de aplicar, gere o preview novamente na tela da NF. As demais validações fiscais e de saldo continuam sendo executadas normalmente.

## Regras da recuperação

- Apenas NF-e de venda vinculada à fatura, sem autorização, cancelamento, fila/processamento ou estoque fiscal já processado.
- Pesquisa apenas nas requisições da mesma fatura e empresa.
- Exige igualdade de produto, unidade e quantidade. Não usa a ordem das linhas nem aproxima unidades diferentes.
- Preserva vínculos existentes e verifica se são compatíveis. Cada origem pode ser utilizada apenas uma vez na nota.
- Mais de uma origem possível, nenhuma origem compatível, vínculos incompatíveis ou dois itens disputando a mesma origem impedem a aplicação inteira.
- Verifica flags de consumo do item/requisição e saídas rastreadas ao item da requisição, à requisição/produto ou ao item fiscal. Se houver saída anterior, informa os IDs dos movimentos e bloqueia a recuperação para conferência do estoque.
- Grava somente os vínculos faltantes, em transação, e registra nota, empresa, fatura e correspondências no log. Pode ser executado novamente sem duplicar vínculos.
- Não cria movimentos, altera saldos, remove reservas, reconcilia baixas antigas, modifica NCM ou envia a NF-e.

## Quando o comando indicar pendência

Nenhum vínculo é gravado, mesmo com `--apply`. O comando retorna código de saída 1 e mostra o motivo por item.

Em caso de origem ambígua, é necessário conferir a correspondência comercial. Em caso de baixa anterior, é necessário conferir os movimentos indicados e regularizar seu vínculo fiscal antes de continuar. Este comando não associa automaticamente uma saída antiga a uma NF-e: não é possível garantir essa correspondência somente por produto e quantidade.

O diagnóstico verifica os registros rastreados pelo sistema. Uma baixa manual sem referência à requisição ou à nota precisa ser identificada na conferência operacional do estoque.
