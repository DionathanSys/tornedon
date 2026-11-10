# Faturas — funcionamento, regras e evolução

> Documento-base da documentação de faturas. Atualizar este arquivo ao final de cada implementação desta iniciativa, registrando o que passou a funcionar, as regras aplicadas, as verificações e o que continua planejado.

## 1. Objetivo

Organizar o faturamento de requisições, ordens de serviço (OS) e ordens de produção (OP), distinguindo composição comercial, documentos fiscais, financeiro e operações de reversão/cancelamento.

O nome aprovado da operação é **Reverter Faturamento**. Ela é diferente de **Cancelar Venda** e de **Cancelar NF**.

## 2. Situação da implementação

### Entregue — etapa 1: fundamentos de regras, estados e histórico

- Preservação da composição na confirmação: ordens, itens, quantidades, preços, descontos, deslocamento, totais e condições comerciais.
- Revisões imutáveis de confirmação, mantendo confirmações anteriores quando houver retorno permitido a rascunho.
- Totais de faturas confirmadas/canceladas baseados no retrato da confirmação, quando existente.
- PDF da fatura usando ordens e itens históricos mesmo após a liberação dos vínculos atuais.
- Consulta do histórico na fatura administrativa e na visualização de fatura do BPO.
- Remoção de OS/requisição do rascunho na área administrativa, com retorno para Encerrada, histórico e auditoria.
- Liberação das requisições da mesma fatura vinculadas à OS removida e da OP vinculada à requisição removida, quando aplicável.
- Elegibilidade centralizada para editar composição, confirmar, desfazer confirmação e excluir.
- Bloqueio de novas associações a faturas confirmadas/canceladas e de transferência direta entre faturas sem liberar o vínculo atual.
- Bloqueio de alteração dos itens comerciais de ordens vinculadas a uma fatura confirmada que possui retrato persistido.
- Bloqueio de reabertura de fatura cancelada e de exclusão de fatura com histórico.
- Estrutura persistida dos tipos/estados de operações e exclusão mútua de operações ativas por fatura.
- A ação existente de retorno ao rascunho passa a se apresentar como **Desfazer confirmação**, com impedimentos consultáveis na interface.

### Entregue — ações fiscais e tratamento de pendências

- A edição administrativa da nota e a nota vinculada à fatura usam a mesma fábrica de ações, com visibilidade por tipo e situação fiscal.
- Carta de correção e download das cartas existentes disponíveis diretamente na fatura, reaproveitando os serviços já existentes.
- Geração de outra nota do mesmo tipo liberada após confirmar o cancelamento da anterior, sem vínculo explícito de substituição entre notas.
- Regra de 24 horas após a autorização para cancelamento de NF-e; NFS-e sem limite local de prazo.
- Preservação/recuperação do horário de autorização a partir dos dados do provedor ou do protocolo XML, sem reiniciar o prazo a cada consulta/webhook.
- Registro e tratamento administrativo de pendências por prazo, recusa ou resultado incerto, com histórico, referência externa e comprovantes.
- Comprovante manual não altera a situação fiscal; consulta/webhook confirmam o cancelamento e resolvem a pendência.

### Entregue — rastreabilidade financeira e reversão integral de serviços

- Origem explícita dos títulos: gerado pela fatura ou preexistente vinculado, com histórico preservado.
- Recebimentos de títulos rastreados são estornados com preservação do pagamento original e do movimento compensatório correspondente.
- Ação **Reverter Faturamento**, para administrador da empresa, no administrativo e no BPO.
- Reversão integral de serviços sem produtos/produção, com processamento em fila, etapas persistidas, espera por confirmação bancária e retomada.
- Fatura/venda anterior preservada; OS liberadas para Encerrada apenas na conclusão. Nenhuma nova fatura é criada automaticamente.
- Abas **Histórico financeiro** e **Operações da fatura** nos dois painéis.

### Entregue — reserva e saída pela autorização da NF-e

- Reserva criada/ajustada ao incluir/alterar o item da requisição, com rollback do item quando a reserva falhar.
- Faturar não baixa estoque e não libera a reserva.
- Saída da venda vinculada à fatura processada exclusivamente pela autorização da NF-e, com referência ao item fiscal e à requisição de origem.
- Validação de disponibilidade na reserva e antes do envio fiscal, respeitando a opção de saldo negativo do estoque.
- Produto sem controle de estoque não exige saldo/registro de estoque e não gera reserva ou saída.
- Pendência de estoque após autorização preserva o resultado fiscal e permite reprocessamento pelo administrador.

### Planejado — próximas entregas

- Ampliação de **Reverter Faturamento** para produtos, após definição do ciclo de estoque e das exceções de produção.
- Workflow completo e ação **Cancelar Venda**.
- Ampliação da coordenação de operações para cancelamento definitivo da venda.
- Evolução da visualização da cobertura fiscal por item, caso seja necessária. Não haverá vínculo obrigatório de substituição entre notas.
- Resumos fiscais/financeiros consolidados na interface.
- Eventual parametrização adicional das regras fiscais por órgão/provedor, preservando a definição atual de NFS-e sem limite local.
- Histórico de faturamento apresentado diretamente na tela de cada ordem.
- Revisão e migração assistida das faturas legadas sem retrato de confirmação.

O executor atual atende à reversão de serviços descrita na seção 15. Cancelamento definitivo da venda, produtos e produção permanecem nas próximas entregas.

## 3. Estado comercial da fatura

O enum `App\Enum\Invoice\Status` mantém:

| Código | Apresentação | Regra |
| --- | --- | --- |
| `pending` | Pendente / rascunho | Composição editável, desde que não haja impedimentos |
| `confirmed` | Confirmada | Composição consolidada; alterações exigem ação específica |
| `cancelled` | Cancelada | Registro histórico; não pode ser reaberto |

Nas transições de `status` pelo modelo, os indicadores `pending`, `confirmed` e `canceled` são sincronizados. Alteração de situação não deve ser feita pela edição genérica da fatura: utilizar os serviços/actions correspondentes.

O campo `cancellation_reason` prevê:

| Código | Significado |
| --- | --- |
| `rebilling` | Reversão de faturamento, liberando ordens para outro faturamento |
| `sale_cancelled` | Cancelamento definitivo da venda |

O preenchimento desses motivos e os efeitos de cancelamento serão coordenados pelos respectivos workflows. A existência do campo não autoriza cancelar a fatura por atualização manual.

### Transições de negócio

```text
Rascunho ── confirmar ──> Confirmada
    ^                        |
    └── desfazer confirmação ─┘  (somente quando elegível)

Confirmada ── Reverter Faturamento ──> Cancelada / rebilling [serviços implementados]
Confirmada ── Cancelar Venda ────────> Cancelada / sale_cancelled [workflow planejado]
```

Cancelar NF não altera automaticamente o estado comercial da fatura.

## 4. Vínculo atual e histórico são diferentes

### Vínculo atual

O `invoice_id` da ordem indica em qual fatura ela está atualmente. Uma ordem não pode integrar duas faturas ativas. Também não pode ser transferida diretamente de uma para outra: primeiro é necessário liberar o vínculo atual pelo fluxo apropriado.

Mesmo um rascunho já reserva a ordem para aquele faturamento. Disponibilidade não pode ser determinada apenas pelo status da ordem.

Não haverá faturamento parcial de uma mesma ordem nesta primeira versão.

### Histórico da composição

`invoice_confirmations` armazena as revisões de confirmação:

- Empresa, fatura, revisão, responsável e data.
- Totais em centavos inteiros.
- Cliente, identificação da fatura e condições comerciais da confirmação.

`invoice_sources` registra a participação das ordens:

- Empresa e fatura.
- Revisão de confirmação, quando a participação foi confirmada.
- Tipo estável (`requisition`, `service_order`, `production_order`), ID e número da ordem.
- Retrato de itens, quantidades, unidade, preço, desconto, observações e valores.
- Deslocamento da OS e indicação de participação no total financeiro.
- Data do registro, liberação, motivo e responsável, quando aplicável.

Os valores de OP são informativos: não são somados novamente aos totais já representados pela requisição.

Remoções de rascunho também são registradas nessa tabela, sem revisão de confirmação. O retrato é imutável; os campos de liberação são separados dos dados históricos. Nesta etapa, `linked_at` representa o momento do registro histórico (confirmação ou remoção), não uma reconstrução da data da primeira associação.

As referências históricas às ordens são identificadores, não uma dependência dos valores atuais do cadastro. Alterações posteriores do nome de um produto/serviço ou de uma ordem não reescrevem a confirmação anterior.

## 5. Preservação dos R$ 1.000 de uma fatura anterior

Exemplo: fatura #100 com OS #10 de R$ 300, OS #11 de R$ 200 e requisição #20 de R$ 500.

Ao confirmar, a composição e o total de R$ 1.000 são registrados. Quando o workflow de reversão estiver implementado, ele preservará esse retrato, cancelará a fatura pelo motivo correto e liberará os vínculos atuais apenas após suas validações.

Mesmo se as ordens entrarem em outra fatura com novos valores:

- A #100 continuará exibindo R$ 1.000, suas ordens e os valores originais.
- A nova fatura terá outra confirmação e seus próprios valores.
- A #100 não deve participar de faturamento ativo ou gerar cobrança duplicada.

### Origem dos valores

| Situação | Origem |
| --- | --- |
| Rascunho | Composição atual das ordens |
| Confirmada com histórico | Última confirmação persistida |
| Cancelada com histórico | Última confirmação persistida, preservada |
| Registro legado sem histórico | Cálculo anterior pelas ordens ainda vinculadas |

`Invoice::preloadAmounts()` carrega confirmações em lote; registros sem retrato usam as agregações anteriores. O PDF usa as ordens históricas quando existe confirmação válida para o estado atual.

## 6. Confirmação

A confirmação ocorre em transação, com bloqueio da fatura. Falhas retornam sem preservar efeitos parciais da confirmação no banco.

Verificações incluem:

1. Fatura em rascunho, sem cancelamento e sem operação ativa.
2. Existência de ordens e itens válidos.
3. Ausência de documentos fiscais/recebíveis que impeçam uma nova confirmação.
4. Ordens da mesma empresa e cliente da fatura.
5. Condições financeiras e fiscais exigidas pelos serviços existentes.

O retrato é criado na confirmação normal e na finalização de venda rápida do BPO. O caminho existente de geração de documento fiscal que confirma um rascunho também captura a composição. A geração de várias notas durante uma confirmação não cria revisões duplicadas nem confirma a fatura antes de concluir essa confirmação.

O histórico congela os valores comerciais. O controle dos efeitos fiscais, bancários e de estoque continua nos serviços de domínio correspondentes.

Compatibilidade com a ação existente de geração financeira independente: quando uma fatura foi confirmada pela geração de documento fiscal sem condições financeiras definidas, essa ação ainda pode preencher os campos financeiros inicialmente nulos, desde que não haja recebíveis nem operação ativa. Ela não pode sobrescrever condições já definidas. O preenchimento posterior não reescreve as condições originalmente registradas no retrato histórico.

## 7. Remover ordens do rascunho

Ação administrativa: **Remover da fatura**, nas relações de requisições e OS.

Regras:

- Fatura precisa estar em rascunho e sem operação, documentos fiscais ou recebíveis impeditivos.
- Revalidar a situação sob bloqueio e conferir empresa, cliente e vínculo atual.
- Autorizar atualização da fatura e da ordem na interface; o serviço também verifica participação do usuário na empresa.
- Registrar histórico e auditoria antes da liberação, na mesma transação.
- OS e requisição retornam para **Encerrada**, independentemente de terem sido automaticamente encerradas durante o faturamento.
- Requisição perde `invoice_id`, `invoiced_at` e `status_before_invoicing`.
- Remover OS também libera as requisições daquela OS que integram a mesma fatura.
- Quando a requisição removida tem OP vinculada à mesma fatura, liberar a OP para **Concluída**.
- Não consumir, devolver ou liberar estoque automaticamente por essa remoção. As ordens permanecem encerradas; remover vínculo comercial não representa retorno físico.

A exclusão permitida de um rascunho sem histórico também devolve as ordens vinculadas para Encerrada (OP para Concluída).

Após uma remoção registrada, a fatura tem histórico e não pode ser excluída fisicamente. O encerramento desse rascunho com histórico será tratado por ação preservadora específica em uma próxima entrega.

## 8. Elegibilidade centralizada

`InvoiceActionEligibilityService` retorna `allowed` e uma lista de `blockers` com `code` e `message`. Não substitui a autorização de usuário/empresa.

| Ação implementada | Condições principais |
| --- | --- |
| Editar composição | Rascunho; sem operação ativa, documentos fiscais ou recebíveis |
| Confirmar | Condições de rascunho e composição existente; demais verificações da confirmação |
| Desfazer confirmação | Confirmada; sem operação ativa, decisão financeira de venda rápida, comunicação fiscal impeditiva, recebimentos registrados ou cobranças externas |
| Excluir | Rascunho sem documentos fiscais, recebíveis, confirmações, histórico de composição ou operações |

Uma nota que já teve comunicação fiscal não se torna elegível para retorno simples ao rascunho só porque foi cancelada. O caminho é **Reverter Faturamento**, preservando o registro anterior, respeitado o escopo de serviços desta entrega.

O retorno existente a rascunho permanece conservador: recebimentos históricos e cobranças externas também impedem essa ação. Já a reversão de serviços aceita recebimentos integralmente estornados quando pagamento, parcela, conta e movimento compensatório estão consistentes.

## 9. Operações em andamento

Tipos previstos em `OperationType`:

- `return_to_draft`: Desfazer confirmação.
- `reverse_billing`: Reverter Faturamento.
- `cancel_sale`: Cancelar Venda.

Estados previstos em `OperationStatus`:

| Código | Interpretação | Mantém exclusividade? |
| --- | --- | --- |
| `requested` | Solicitada | Sim |
| `running` | Em execução | Sim |
| `awaiting_external` | Aguardando confirmação externa | Sim |
| `needs_intervention` | Requer intervenção | Sim |
| `completed` | Concluída | Não |
| `aborted` | Abortada | Não |

`invoice_operations` guarda empresa, fatura, tipo, estado, justificativa, solicitante e `request_id` único. O `active_slot` é preenchido pelo modelo, com restrição única de banco para impedir duas operações ativas na mesma fatura.

`ProcessInvoiceReversalJob` executa a reversão de serviços. As etapas ficam em `steps`, com estado, mensagem e horário; `last_message` resume o andamento. Uma rotina agendada a cada minuto retoma solicitações, execuções interrompidas e operações aguardando retorno externo. Operações que requerem intervenção dependem de retomada explícita pelo administrador. Não há ação de aborto após efeitos externos: não marcar operações manualmente como concluídas/abortadas para contornar pendências.

## 10. Regras aprovadas para os próximos workflows

### Corrigir somente a nota

O usuário utiliza a ação existente de cancelar a nota e, após a confirmação do cancelamento, gera outra nota pela fatura. As duas notas permanecem vinculadas à mesma fatura; não é necessário um campo de substituição entre elas nem um assistente de substituição.

Não há uma lista adicional de campos permitidos para corrigir a nova nota: a empresa decide quais informações fiscais alterar, utilizando os formulários existentes e suas validações técnicas. A composição e os valores comerciais históricos da fatura continuam preservados. Não duplicar recebíveis nem movimentos de estoque.

### Reverter Faturamento

Se a intenção é manter as ordens e refaturar:

1. Exigir estorno prévio dos recebimentos vigentes.
2. Resolver todas as notas fiscais e cobranças aplicáveis.
3. Preservar o retrato histórico da fatura.
4. Cancelar a fatura pelo motivo `rebilling`.
5. Liberar OS para Encerrada; produtos e OP aguardam o estudo de estoque/produção.
6. Permitir que o usuário crie outra fatura posteriormente, sem criação automática.

Se houve autorização fiscal e a composição precisa mudar, criar outra fatura, preservando a anterior. Não reabrir a fatura cancelada.

### Cancelar Venda

Encerrar definitivamente a operação comercial, tratando notas, financeiro, cobranças e documentos operacionais. Cancelamento comercial não comprova devolução física de mercadoria: estoque deve refletir o fato ocorrido.

### Origem do financeiro

Um título criado pela fatura é diferente de um título preexistente apenas associado a ela. A origem é persistida em `invoice_receivable_links`. O segundo é preservado, incluindo parcelas e cobranças próprias, liberando somente os vínculos criados pelo faturamento. Não se infere origem pela descrição, valor ou data.

### Prazos fiscais

Regra aprovada e implementada: NF-e até 24 horas após a autorização, incluindo o instante exato do limite; NFS-e sem limite local de horas ou de mês. A aceitação do cancelamento da NFS-e permanece a cargo da prefeitura/provedor. A janela temporal não substitui as demais condições fiscais, como circulação da mercadoria.

Fora da janela ou sem elegibilidade, encaminhar para tratamento fiscal específico. Não produzir uma devolução automaticamente para qualquer erro.

O horário da autorização não é inferido da data de emissão nem da chegada da consulta/webhook. Quando não existe um horário confiável registrado, tentar recuperar dos dados do provedor ou do `dhRecbto` do protocolo XML; se continuar desconhecido, bloquear o cancelamento automático da NF-e e registrar pendência. Consultas posteriores não prorrogam um horário já conhecido.

## 11. Compatibilidade com dados existentes

A migration é aditiva e não reconstrói automaticamente confirmações antigas. Não é possível inferir com segurança o retrato original de uma fatura se suas ordens já foram alteradas ou desvinculadas.

- Novas confirmações pelos fluxos implementados geram histórico.
- Registros legados sem histórico mantêm o cálculo anterior e precisam de revisão antes de qualquer liberação destrutiva futura.
- O bloqueio de itens comerciais considera confirmações persistidas para manter compatibilidade com esses registros legados.
- A reversão implementada bloqueia faturas legadas sem retrato e títulos sem origem financeira rastreada. Não há reconstrução ou classificação presumida automática.
- Atualizações em massa por query builder não executam eventos Eloquent. Código de negócio deve usar os serviços; migrações/importações precisam observar essas regras explicitamente.

## 12. Implantação e arquivos principais

Aplicar a migration antes de utilizar a versão nova:

```bash
php artisan migrate
```

Migration: `database/migrations/2026_11_08_000001_create_invoice_history_tables.php`.

Principais pontos:

- `app/Services/Invoice/InvoiceCompositionService.php`
- `app/Services/Invoice/InvoiceDraftCompositionService.php`
- `app/Services/Invoice/InvoiceActionEligibilityService.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceConfirmation.php`
- `app/Models/InvoiceSource.php`
- `app/Models/InvoiceOperation.php`
- `app/Services/Invoice/Support/InvoicePdfDataFormatter.php`
- Relação administrativa/BPO **Histórico da composição**.

## 13. Verificações e histórico de entregas

### 2026-11-08 — Etapa 1

Implementada a fundação descrita na seção 2. Testes de integração cobrem preservação do valor histórico e do PDF após novo faturamento, revisões imutáveis, remoção de rascunho sem estoque, retorno das ordens a Encerrada, isolamento por empresa, bloqueios de composição e exclusividade de operações.

Verificação final: **144 testes aprovados, 869 asserções**. Cobertura: confirmação, descontos, deslocamento, recebíveis, venda rápida do BPO, webhook, OS, requisições, produção, consulta do histórico e remoção pela interface administrativa. Inclui falha simulada depois da captura para comprovar rollback do retrato e das condições comerciais, além de retorno elegível ao rascunho seguido de nova revisão de confirmação.

Comandos de verificação:

```bash
php artisan config:clear
php artisan test tests/Feature/Services/Invoice tests/Feature/Services/Requisition tests/Feature/Services/ServiceOrder tests/Feature/Services/ProductionOrder tests/Feature/Services/CommercialDocumentDeletionTest.php tests/Feature/Filament/Invoices tests/Feature/Http/NfeWebhookControllerTest.php tests/Feature/Filament/Bpo/QuickSalesTest.php
```

Laravel Pint aplicado aos arquivos PHP novos e alterados. A migration foi exercitada no banco SQLite dos testes; sua aplicação no banco da instalação segue o comando da seção 12. Não foram disparados cancelamentos fiscais/bancários reais nesta etapa.

## 14. Ações fiscais compartilhadas e pendências

### 14.1. Ações disponíveis

`FiscalDocumentRecordActions` é a origem das ações do header da edição administrativa da nota e do ActionGroup da nota dentro da fatura. Inclui emissão, consulta, preview, download, cancelamento, carta de correção, download da carta, conciliação de RPS, configuração de devolução e exclusão quando aplicáveis.

- NF-e autorizada: cancelar, emitir CC-e e baixar DANFE/XML, conforme permissões.
- NFS-e autorizada: cancelar e baixar PDF/XML, conforme permissões.
- Carta de correção: apenas NF-e autorizada; o download também considera a existência de cartas já registradas.
- Conciliação de RPS: apenas NFS-e em conciliação.
- Exclusão: somente documentos elegíveis sem comunicação fiscal, fila ou pendência ativa, revalidando pelo serviço existente.
- Consulta: inclui notas com pendência de cancelamento, mesmo que não estejam em processamento de emissão.

As ações revalidam acesso à empresa e permissão da operação. A fatura é atualizada após a ação fiscal para refletir a liberação da geração. A visualização de fatura no BPO também oferece os documentos fiscais e os comandos de geração, respeitando as permissões individuais.

### 14.2. Quando gerar outra nota

A decisão é feita por tipo: uma NF-e ativa impede outra NF-e; uma NFS-e ativa impede outra NFS-e. Notas canceladas permanecem no histórico e deixam de bloquear quando o cancelamento foi confirmado e não há pendência fiscal ativa.

Uma alteração somente do status local para cancelada não libera nova nota se a situação fiscal ainda indicar autorização/processamento. A verificação também ocorre no serviço, sob bloqueio da fatura, e não apenas na visibilidade do botão.

Nenhum vínculo adicional entre nota anterior e nova é criado. A relação com a mesma fatura é o histórico aprovado. Gerar outra nota não confirma novamente a fatura nem recria suas contas a receber.

### 14.3. Pendências fiscais

Tabela `fiscal_document_issues`, com empresa, nota, motivo, mensagem, estado, histórico de eventos, responsável, comprovante e data de resolução. Só pode existir uma pendência ativa por nota.

| Estado | Significado |
| --- | --- |
| `open` | Tratamento necessário ou andamento registrado |
| `awaiting_confirmation` | Resultado externo incerto ou procedimento externo informado, aguardando consulta/retorno |
| `resolved` | Cancelamento confirmado pela integração ou decisão administrativa de manter a nota |

O cancelamento da NF-e fora do prazo, sem horário confirmado ou recusado pelo órgão registra pendência. Recusa de NFS-e também registra pendência. Uma falha com resultado potencialmente incerto exige consulta antes de repetir a solicitação.

**Tratar pendência fiscal** é restrita ao administrador da empresa: vínculo ativo como proprietário no acesso legado ou permissão `team.manage` em escopo empresa no modelo de papéis. Administração da plataforma sem vínculo com a empresa não concede acesso operacional.

Encaminhamentos disponíveis:

1. **Registrar andamento / manter pendente:** acrescenta justificativa ao histórico; não elimina uma espera por confirmação externa.
2. **Informar procedimento/cancelamento externo:** exige referência/protocolo e comprovante; fica aguardando confirmação sem modificar o status fiscal.
3. **Manter a nota:** encerra apenas a pendência administrativa. A nota continua autorizada e bloqueando nova geração do mesmo tipo. Uma solicitação de resultado incerto precisa ser consultada antes desse encerramento.

Comprovantes podem ser anexados no modal ou selecionados entre anexos da própria nota e empresa. Formatos: PDF, XML, TXT, JPG e PNG; tamanho máximo de 10 MB. O armazenamento e os downloads utilizam a infraestrutura existente de anexos, sem abrir novas rotas públicas.

Consulta que ainda confirma autorização retira a incerteza e mantém o tratamento aberto. Cancelamento confirmado por consulta, webhook ou retorno de sucesso do cancelamento resolve a pendência. Não há botão para transformar um comprovante manual em cancelamento fiscal.

### 14.4. Implantação desta entrega

Aplicar a migration aditiva `2026_11_08_000002_create_fiscal_document_issues_table.php` com `php artisan migrate` antes de utilizar esta versão.

Principais serviços novos:

- `FiscalCancellationPolicy`: elegibilidade e prazo.
- `FiscalAuthorizationTime`: recuperação/preservação do horário.
- `FiscalDocumentIssueService`: pendências, administrador, comprovantes e conciliação.
- `InvoiceFiscalDocumentEligibilityService`: liberação de nova geração por tipo.

### 2026-11-08 — Ações fiscais e pendências

Decisões incorporadas: sem lista adicional de campos corrigíveis; sem vínculo obrigatório de substituição; ações fiscais compartilhadas; NF-e com prazo de 24 horas após autorização; NFS-e sem limite local; tratamento de pendências disponível nesta entrega para administrador da empresa.

Verificação final: **259 testes aprovados, 1.668 asserções**. Cobertura inclui carta de correção no header e na fatura, liberação de nova nota após cancelamento, preservação da fatura, prazo exato da NF-e, NFS-e de meses anteriores, recusa/timeout de integração, horário de autorização, comprovantes, administrador da empresa e fluxo dentro da fatura BPO.

```bash
php artisan config:clear
php artisan test tests/Feature/Services/FiscalDocument tests/Feature/Services/Invoice tests/Feature/Filament/Invoices tests/Feature/Http/NfeWebhookControllerTest.php tests/Feature/Filament/Bpo/FiscalDocumentsWorkspaceTest.php tests/Feature/Filament/Bpo/QuickSalesTest.php
```

Laravel Pint aplicado aos arquivos PHP desta entrega. As integrações externas foram simuladas nos testes; a migration foi exercitada no SQLite dos testes. A regressão também identificou e corrigiu a leitura de contas a pagar quando o formulário de criação da nota ainda não possui registro persistido.

## 15. Rastreabilidade financeira e Reverter Faturamento de serviços

### 15.1. Decisões aprovadas

- Reversão sempre integral, sem selecionar apenas algumas OS, parcelas ou notas da fatura.
- Nova fatura fica a cargo do usuário, em outro momento.
- Fatura e venda BPO anteriores são preservadas, com identificação de reversão para refaturamento.
- OS ficam vinculadas até concluir todos os cancelamentos necessários; ao final retornam para **Encerrada**.
- Recebimentos vigentes precisam de estorno prévio; recebimento surgido durante a execução interrompe o progresso.
- Operação permitida ao administrador da empresa, sem aprovação adicional, nos painéis administrativo e BPO.
- Esta entrega atende exclusivamente serviços sem requisições/produtos ou OP associada à fatura. Não movimenta estoque.

### 15.2. Como identificar título preexistente

`invoice_receivable_links` registra a associação com origem explícita:

| Origem | Identificação | Efeito da reversão |
| --- | --- | --- |
| `generated` | Conta criada pelos fluxos de geração da fatura, inclusive confirmação e geração fiscal/financeira independente | Cancelar título e parcelas depois de resolver recebimentos e cobranças |
| `linked_existing` | Título que existia antes e foi associado pelo fluxo de venda BPO | Preservar título, parcelas e cobranças; restaurar os vínculos anteriores |

São persistidos empresa, fatura, ID original do título, referência ao registro atual, responsável, data, vínculos anteriores e retrato financeiro. Ao liberar, gravar motivo e novo retrato com parcelas, pagamentos, movimentos originais e estornos. A identificação original e os retratos liberados não podem ser reescritos.

Um título pode ser associado a uma única fatura ativa. Seu histórico continua existindo após liberar o vínculo. Caso um título não recebido seja excluído por um fluxo existente, o histórico mantém o ID original e seu retrato, mesmo que a referência ao registro atual fique nula.

Títulos antigos já associados sem rastreabilidade não são classificados por aproximação: bloqueiam reversão automática até revisão. Valores/datas iguais não comprovam origem.

### 15.3. Como comprovar estorno do recebimento

O caminho de rastreabilidade é:

```text
Fatura → vínculo financeiro → título → parcela → recebimento
  → movimento original (origin_type + origin_id)
    → movimento compensatório (reversal_of_id)
```

Um recebimento vigente impede iniciar a reversão. Para aceitar um recebimento histórico já estornado, verificar:

1. Empresa e associação consistentes.
2. `returned_amount` integralmente igual ao valor recebido.
3. Parcela e título sem saldo recebido vigente.
4. Quando houve conta/movimento financeiro, existência do movimento original, da marca de estorno e de compensação da mesma empresa/conta, no valor correspondente e na direção inversa.
5. Ausência de recebimentos ou eventos bancários financeiros a regularizar nas cobranças.

Uma saída manual avulsa de mesmo valor não comprova estorno. Alterar apenas o indicador do pagamento também não libera a operação.

Para títulos com histórico de fatura, a ação existente de remoção do recebimento se apresenta como **Estornar recebimento**: mantém o pagamento e registra a compensação, em vez de apagar a cadeia de rastreabilidade. Recebimento sem conta financeira pode não ter movimento de caixa; nesse caso, preservar o registro de seu estorno sem inventar uma movimentação inexistente.

Recebimentos bancários continuam usando o fluxo existente de devolução confirmada do provedor. A reversão da fatura não devolve dinheiro automaticamente e não estorna recebimentos por conta própria.

### 15.4. Etapas da reversão

1. Autorizar administrador ativo da própria empresa e validar justificativa.
2. Bloquear fatura sem retrato histórico, composição divergente, produto/produção, origem financeira desconhecida, emissão ainda em fila/processamento ou recebimento vigente.
3. Registrar operação única e idempotente. Desativar novos disparos automáticos de cobrança/recebimento dos títulos gerados pela fatura.
4. Para cada NFS-e, reconhecer cancelamentos anteriores, encerrar documentos não enviados ou rejeitados sem autorização anterior, ou solicitar cancelamento ao provedor quando autorizada. O encerramento local preserva o status de rejeição e não inventa um cancelamento externo. Guardar a etapa antes da chamada externa.
5. Quando o resultado anterior for incerto, consultar antes de repetir. Uma consulta que confirme a nota ainda autorizada permite nova tentativa somente em retomada explícita.
6. Revalidar recebimentos entre etapas externas.
7. Cancelar cobranças dos títulos gerados pela fatura pelos serviços bancários existentes. Cobranças nunca enviadas podem ser desativadas localmente. Cobranças preexistentes de título apenas vinculado são preservadas.
8. Aguardar confirmação dos cancelamentos; falhas/divergências exigem intervenção. As OS permanecem vinculadas durante a espera.
9. Revalidar sob bloqueio da fatura, dos títulos, parcelas e cobranças antes da conclusão local.
10. Cancelar títulos/parcelas gerados; liberar associações dos preexistentes; registrar o histórico financeiro da liberação.
11. Marcar a fatura **Cancelada — Reversão de faturamento**, com responsável/data/motivo `rebilling`.
12. Liberar todas as OS para Encerrada, marcar liberação no histórico e concluir a operação.

Falhas parciais preservam as etapas já concluídas. Uma NFS-e já cancelada não é reenviada para cancelamento. O fluxo não apaga notas nem fatura e não cria outra venda/fatura automaticamente.

A venda BPO anterior continua ligada à fatura revertida. Uma nova ação explícita do usuário pode selecionar a mesma OS encerrada em outra venda; a existência da venda anterior revertida não impede essa reutilização.

### 15.5. Uso e execução

- Botão **Reverter Faturamento** na fatura administrativa e na visualização da fatura BPO.
- O mesmo botão permite retomar uma operação ativa, após corrigir seus impedimentos.
- Abas **Operações da fatura** e **Histórico financeiro** mostram andamento, etapas e referências de recebimentos/estornos.
- `ProcessInvoiceReversalJob` processa a solicitação na fila. `routes/console.php` retoma a cada minuto operações solicitadas, interrompidas ou aguardando confirmação externa.
- Estado **Requer intervenção** não é repetido automaticamente. Após tratar a causa, outro administrador autorizado também pode retomar.
- Revogação do acesso do responsável interrompe a execução; não há bypass de administrador de plataforma sem vínculo com a empresa.

Aplicar `php artisan migrate` para a migration `2026_11_08_000003_add_invoice_financial_traceability.php`. Manter o consumidor de filas e o scheduler Laravel em execução na instalação, além das filas bancárias já utilizadas pelas integrações.

Principais serviços: `InvoiceFinancialLinkService`, `InvoiceReceiptReversalService` e `ReverseServiceInvoiceWorkflow`.

### 15.6. Próxima etapa: estoque e produção

Definições registradas para o estudo seguinte:

- Há empresas que não controlam estoque e precisam apenas incluir um item na venda. Não presumir que todo produto deva gerar reserva, saída ou entrada.
- Avaliar a responsabilidade da NF-e versus faturamento na saída de estoque, mantendo os movimentos rastreáveis e sem duplicação no refaturamento.
- Cancelamento de venda ligada à produção pode corrigir uma OP gerada em duplicidade; isso não significa que uma peça foi fisicamente produzida, devolvida ou retornou à empresa.
- Separar correção de duplicidade operacional de cancelamento de produção executada e de retorno físico. Não gerar reposição de estoque ou reversão de insumos pela simples existência de uma OP cancelada.
- Cancelamento definitivo de venda de serviço foi autorizado como regra de negócio, mas a ação **Cancelar Venda** ainda é uma entrega distinta de **Reverter Faturamento**.

### 2026-11-09 — Rastreabilidade e reversão de serviços

Implementados os itens 1 e 2 do encaminhamento aprovado.

Regressão principal: **562 testes aprovados, 3.967 asserções**, em duas execuções independentes (280 testes financeiros e 282 testes de fatura/fiscal/interface/BPO). Depois da revisão final, as verificações focadas também passaram; a última execução do workflow reuniu **18 testes e 111 asserções**, incluindo bloqueio de novo título por callback fiscal durante a reversão, revogação de acesso no meio do processamento e encerramento local de NFS-e rejeitada sem autorização. As execuções focadas repetem parte da regressão e não devem ser somadas como casos distintos.

```bash
php artisan config:clear
php artisan test tests/Feature/Services/Financial
php artisan test tests/Feature/Services/Invoice tests/Feature/Services/FiscalDocument tests/Feature/Filament/Invoices tests/Feature/Filament/Bpo/QuickSalesTest.php tests/Feature/Filament/Bpo/FiscalDocumentsWorkspaceTest.php tests/Feature/Http/NfeWebhookControllerTest.php
php artisan test tests/Feature/Services/Invoice/ReverseServiceInvoiceWorkflowTest.php
```

Pint aplicado aos arquivos PHP alterados/novos e `git diff --check` sem problemas. As migrations foram exercitadas no SQLite dos testes. Os provedores foram simulados; não houve solicitação de cancelamento em banco/prefeitura real durante a verificação.

## 16. Estoque — fechamento aprovado

As decisões desta seção orientam a implementação detalhada na seção 17.

### Decisões confirmadas

- A venda sempre utilizará um produto previamente cadastrado. Não haverá item avulso nesta etapa.
- Reaproveitar `Product.has_stock_control` (**Controla estoque**) como referência do produto. Produto sem controle não deve exigir saldo, reserva ou movimento.
- `ProductStock.allow_negative` é uma política diferente: mantém o controle e os movimentos, permitindo saldo negativo conforme a configuração do vínculo de estoque.
- Manter a reserva no momento em que o item é adicionado à requisição, como no fluxo atual; atualizar/liberar a reserva nos ajustes do item.
- A saída padrão da venda será comandada pela **autorização da NF-e**, não pelo faturamento da requisição.
- Para o sistema, a peça saiu no momento da autorização da NF-e. Não haverá fluxo adicional de entrega antecipada nem regra separada de expedição nesta entrega.
- Cancelar a nota não comprova retorno físico e não repõe estoque automaticamente.
- Manter a configuração de saldo negativo no registro de estoque; não criar uma opção concorrente desnecessária na fatura.

### Políticas existentes que precisam ser conciliadas

Também existe `Requisition.stock_control_enabled`, utilizado por modelos de venda. Foi preservado como política da operação: quando desabilitado, não haverá reserva/saída, mesmo se o produto normalmente controlar estoque. Se habilitado, utilizar o cadastro do produto. A decisão é registrada em `RequisitionItem.stock_control_snapshot`; alteração posterior do cadastro não reescreve o comportamento do item já incluído. Trocar o produto do item estabelece uma nova política para esse produto.

### Validação aprovada

Validar disponibilidade na reserva e antes do envio da NF, com aplicação dos movimentos sob bloqueio transacional do estoque. A reserva da própria requisição deve ser considerada a favor da operação; não descontá-la duas vezes. Respeitar `allow_negative` em ambos os momentos.

Quando a NF já estiver autorizada e surgir divergência no estoque, preservar a autorização e registrar pendência de estoque para regularização. Não fingir falha fiscal nem descartar o retorno externo porque a validação local falhou.

### Momento da saída

A autorização da NF-e confirma a saída no fluxo de venda. Sem autorização, requisição e fatura mantêm a reserva, mas não geram baixa. Não foi implementado evento de entrega/expedição separado. Entradas, produção, remessas e devoluções específicas mantêm seus serviços próprios; esta mudança não transforma toda NF avulsa em venda de estoque.

### Cancelamento e reemissão

- Sem saída registrada: liberar/manter a reserva conforme cancelamento definitivo ou refaturamento; não criar entrada.
- Saída por NF autorizada: considerar a peça fora da empresa; o cancelamento fiscal isolado não cria entrada nem recompõe reserva.
- Reemissão da mesma origem, produto e quantidade: vincular a nova NF à saída física já registrada, sem outra baixa.
- Retorno físico real: registrar evento próprio, com sua quantidade e vínculo ao movimento de origem.
- OP duplicada: tratar como correção de registro conforme a execução efetiva, sem presumir peça produzida ou devolvida.

## 17. Implementação da saída de venda na NF-e

### 17.1. Reserva transacional

Os listeners de criação/atualização/exclusão do item reconciliam sua reserva pelo saldo dos movimentos da própria origem (`requisition_item`), e não apenas pela existência de um evento anterior. Repetir um evento não duplica a reserva. Diminuir quantidade, trocar produto ou remover item libera somente a quantidade que pertence àquela origem.

A requisição e o item são bloqueados antes de alterações; o saldo é revalidado sob bloqueio do estoque. Se falhar a criação/alteração da reserva, a transação também desfaz a inclusão/alteração do item. Estoque ausente/inativo para produto controlado não é tratado como saldo livre.

`allow_negative = false` impede reservar além do disponível e impede saída que tornaria o saldo físico negativo ou consumiria reservas de outras operações. Com `allow_negative = true`, manter os registros e permitir insuficiência de saldo. Desabilitar controle é diferente de permitir saldo negativo.

### 17.2. Preparação e validação fiscal

`SaleNfeStockService` atende à NF-e de saída vinculada a fatura, exceto cenários com serviço específico como devolução de compra. O preflight é executado no enfileiramento e novamente antes do envio.

Cada item gerado pela fatura recebe `fiscal_document_items.requisition_item_id`. O serviço valida empresa e vínculo à fatura, quantidade efetiva da nota, unidade e origem comercial. Quantidades do mesmo produto são validadas conjuntamente. A reserva da própria nota é creditada uma única vez; reservas de outras operações não são consideradas disponíveis.

`fiscal_stock_effects` mantém o retrato por item fiscal: produto, estoque, origem, controle aplicável, unidade/quantidade base, fator de conversão, movimento associado, situação, erro e momento do processamento. A conversão preparada é preservada quando os dados do item não mudaram. Alterar a unidade base após reserva/preparação exige regularização; a quantidade anterior não é reinterpretada na unidade nova. A precisão deve ser compatível com as três casas decimais do estoque.

Não há faturamento parcial de ordem nesta versão. O vínculo comercial de cada item fiscal deve ser único dentro da nota para permitir rastreabilidade sem atribuições ambíguas.

### 17.3. Autorização, atomicidade e idempotência

Na autorização, bloquear e revalidar a nota e os estoques, liberar as reservas de suas origens e aplicar as saídas na mesma transação. Falha em qualquer item desfaz todas as novas saídas e liberações daquela tentativa. O status autorizado da nota, recebido antes do processamento de estoque, é preservado.

A saída usa `source_type = fiscal_document_item` e `source_id` do item efetivamente autorizado, com referência adicional ao documento e ao item da requisição. `stock_consumed_at` na requisição somente é atualizado após a baixa fiscal; ele não é mais o gatilho para baixar na confirmação da fatura. Produto sem controle gera efeito `not_applicable`, sem inventar consumo físico.

Efeitos processados são imutáveis. Movimento ligado a efeito fiscal processado não pode ser editado/excluído pela ação manual de estoque. Repetição de consulta/webhook/job reconhece o efeito existente e não gera nova saída.

### 17.4. Cancelamento fiscal e reemissão

Cancelar a NF não equivale a retorno de mercadoria. Não é gerada entrada automática. Ao emitir outra NF para a mesma origem, com o mesmo produto, unidade base e quantidade, reutilizar a saída existente se a nota anterior teve cancelamento confirmado. Essa referência é de rastreabilidade de estoque; não cria vínculo obrigatório de substituição entre as notas.

Origem já atendida por outra nota ativa, ou saída anterior com produto/quantidade incompatível, exige regularização antes de novo processamento. Não reduzir estoque novamente nem criar devolução presumida. Baixas legadas feitas na requisição sem vínculo fiscal validado também exigem revisão; o sistema não as apaga nem gera outra baixa por aproximação.

### 17.5. Pendência após autorização

Se a nota já foi autorizada e a aplicação do estoque falhar, guardar efeito `blocked` e mensagem da divergência. A nota continua autorizada. Após corrigir saldo/origem/configuração, o administrador da empresa pode usar **Reprocessar estoque da NF-e**, disponível nas ações compartilhadas da nota e da fatura, inclusive no BPO. A ação não reenvia a nota à SEFAZ e não duplica itens já processados.

### 17.6. Implantação e escopo

Aplicar a migration `2026_11_09_000001_add_fiscal_stock_effects.php` com `php artisan migrate`, após as migrations anteriores de histórico financeiro. Não foi feita baixa nem reposição retroativa de registros existentes.

A reversão integral de faturamento já entregue continua limitada a serviços. Ampliação para produtos, devolução física e cancelamento/correção de OP duplicada são os próximos fluxos; não foram confundidos com a simples transferência do momento da baixa para a NF-e.

### 2026-11-09 — Estoque comandado pela autorização

Implementado o fechamento aprovado: produto cadastrado, reserva na inclusão, validação nas duas etapas e saída na autorização.

Verificação desta entrega: **386 cenários verificados** entre regressão fiscal, fatura, BPO, requisições, itens, estoque, produção, OS, reinício operacional, conversão de unidades e interface de operação. A última execução focada de estoque e preflight passou com **25 testes e 158 asserções**, incluindo a ação de reprocessamento, sua restrição ao administrador e proteção contra mudança de unidade base. A reexecução repete casos da regressão; não é somada como cobertura distinta.

```bash
php artisan config:clear
php artisan test tests/Feature/Services/FiscalDocument tests/Feature/Services/Invoice tests/Feature/Filament/Invoices tests/Feature/Filament/Bpo/QuickSalesTest.php tests/Feature/Filament/Bpo/FiscalDocumentsWorkspaceTest.php tests/Feature/Http/NfeWebhookControllerTest.php
php artisan test tests/Feature/Services/Requisition tests/Feature/Services/RequisitionItem tests/Feature/Services/StockMovement tests/Feature/Services/ProductionOrder tests/Feature/Services/ServiceOrder tests/Feature/Listeners/RequisitionItem tests/Feature/Services/Company/CompanyOperationalResetTest.php tests/Feature/Services/Amounts tests/Feature/Services/Shared tests/Feature/Filament/Operation/OperationServiceOrderProductsTest.php tests/Unit/Services/Product/ProductUnitConversionServiceTest.php
php artisan test tests/Feature/Services/FiscalDocument/SaleNfeStockFlowTest.php tests/Feature/Services/FiscalDocument/FiscalEmissionPreflightServiceTest.php
```

Pint aplicado aos arquivos PHP novos/alterados. Integrações fiscais simuladas nos testes e migrations exercitadas no SQLite. A implantação requer aplicar as migrations e reiniciar os workers para utilizar o novo processamento.
