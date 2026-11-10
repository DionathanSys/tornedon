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

## Quando o estoque já foi baixado pelo fluxo antigo

Use a opção explícita de reaproveitamento para validar as saídas anteriores, ainda sem gravar:

```bash
php artisan fiscal:recover-requisition-links 2766 --reuse-legacy-stock
```

Se o diagnóstico mostrar as saídas validadas e nenhuma pendência, aplique:

```bash
php artisan fiscal:recover-requisition-links 2766 --reuse-legacy-stock --apply
```

O comando recupera os vínculos e cria efeitos fiscais **preparados**, apontando para os movimentos antigos. Não altera o saldo nem marca a NF como autorizada. O preview e o envio revalidam esses movimentos. Quando a nota for autorizada, o processamento fiscal registra as saídas existentes como os movimentos da nota, sem gerar novas baixas. Os movimentos mantêm sua origem histórica (`requisition`, por exemplo).

Para aceitar uma saída antiga, é necessário:

- Uma única movimentação física rastreada à origem/produto, do tipo saída. Devolução, ajuste ou outra saída na mesma origem exigem conferência manual.
- Mesma empresa, produto, registro de estoque ativo, quantidade base, quantidade operacional e unidades.
- Snapshots de quantidade, unidade e fator de conversão completos e consistentes com a nota/requisição.
- Ausência de outro efeito fiscal que já reivindique o movimento ou o item da requisição.

Os movimentos adotados ficam protegidos contra edição/exclusão pelos modelos, mesmo antes da autorização. Repetir o comando ou gerar novos previews não duplica a baixa. Se algum item falhar, a recuperação inteira é desfeita, incluindo vínculos e efeitos preparados.

## Regras da recuperação

- Apenas NF-e de venda vinculada à fatura, sem autorização, cancelamento, fila/processamento ou estoque fiscal já processado.
- Pesquisa apenas nas requisições da mesma fatura e empresa.
- Exige igualdade de produto, unidade e quantidade. Não usa a ordem das linhas nem aproxima unidades diferentes.
- Preserva vínculos existentes e verifica se são compatíveis. Cada origem pode ser utilizada apenas uma vez na nota.
- Mais de uma origem possível, nenhuma origem compatível, vínculos incompatíveis ou dois itens disputando a mesma origem impedem a aplicação inteira.
- Verifica flags de consumo do item/requisição e saídas rastreadas ao item da requisição, à requisição/produto ou ao item fiscal. Sem `--reuse-legacy-stock`, qualquer baixa anterior bloqueia a recuperação e informa os IDs dos movimentos.
- Grava somente os vínculos faltantes, em transação, e registra nota, empresa, fatura e correspondências no log. Pode ser executado novamente sem duplicar vínculos.
- Não cria movimentos, altera saldos, remove reservas, modifica NCM ou envia a NF-e. Com `--reuse-legacy-stock`, prepara o vínculo fiscal da baixa antiga validada.

## Quando o comando indicar pendência

Nenhum vínculo é gravado, mesmo com `--apply`. O comando retorna código de saída 1 e mostra o motivo por item.

Em caso de origem ambígua, é necessário conferir a correspondência comercial. Em caso de baixa anterior, execute o diagnóstico com `--reuse-legacy-stock`. Se a validação também bloquear o reaproveitamento, será necessário conferir os movimentos indicados. O comando não usa apenas produto e quantidade para associar uma saída antiga: também exige origem, estoque, unidades, conversões e ausência de outras movimentações físicas/conflitos fiscais.

O diagnóstico verifica os registros rastreados pelo sistema. Uma baixa manual sem referência à requisição ou à nota precisa ser identificada na conferência operacional do estoque.
