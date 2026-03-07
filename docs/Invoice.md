# Objetivo
Agrupar registros, unindo eles em uma única fatura. Os registros podem contemplar ServiceOrder, Requisition ou ProductionOrder. 
Esse vinculo é do tipo HasMany, ou seja a Invoice e o registro pai.
Ele deve ser capaz de retornar, através de atributo, os valores da somatoria dos seus registros filhos.

# Requisitos
- Não possuir registros de clientes diferentes;
- Não permitir adição, deleção ou qualquer edição dos registros filhos, após a mesma ter gerado um documento fiscal.
- A criação da Invoice deve **ser feita sempre pelo Service da mesma**.

# Fluxo de criação
A invoice nesse primeiro momento deve ter sua criação através do faturamento dos registros filhos, ou seja no index/edit de um registro filho deve existir uma action (deve ser em classe exclusiva) onde permita criar a invoice através dos registros sejam eles S.O. Requisition ou P.O..
Após o registro ser faturado ele deve ser vinculado ao Invoice, lembre que existe o pattern State sendo usado nessas entidades filhas, ajuste para que o método invoice do State do respection Model, receba o invoice_id para fazer o vinculo ao mesmo tempo que altera o status do registro, essa parte deve estar dentro de uma transaction, permitindo que se houver algum erro o estado do registro retorne ao que era antes.
A action do front, deve ser do tipo bulk para o index e simples para o edit, deve validar se são do mesmo custumer_id e se estão no status/state encerrado ou equivalente a isso.
A action deve ter um botão ou checkbox que questiona se o documento fiscal já deve ser solitado, ou a invoice deve ser deixada em aberto. essa etapa do fiscal document não será implementada agora, apenas crie esse opcional.
O invoice dentro do service do model filho (OS, PO ou Req) deve poder receber um ou mais registros.
A invoice deve conter uma forma de poder importar registros de dentro do editPage

