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

### Planejado — próximas entregas

- Workflow completo e ação **Reverter Faturamento**.
- Workflow completo e ação **Cancelar Venda**.
- Acompanhamento por etapa, retomada e conciliação das operações fiscais/bancárias.
- Cobertura fiscal por item e emissão de nota substituta sem duplicar efeitos.
- Resumos fiscais/financeiros consolidados na interface.
- Política configurável de prazos fiscais por órgão/provedor.
- Classificação persistida da origem de cada vínculo financeiro: criado pela fatura ou título preexistente vinculado.
- Histórico de faturamento apresentado diretamente na tela de cada ordem.
- Revisão e migração assistida das faturas legadas sem retrato de confirmação.

As tabelas de operações são a fundação dessas próximas entregas; não executam cancelamentos externos nem disponibilizam os novos workflows nesta etapa.

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

Confirmada ── Reverter Faturamento ──> Cancelada / rebilling [workflow planejado]
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

Compatibilidade com a ação existente de geração financeira independente: quando uma fatura foi confirmada pela geração de documento fiscal sem condições financeiras definidas, essa ação ainda pode preencher os campos financeiros inicialmente nulos, desde que não haja recebíveis nem operação ativa. Ela não pode sobrescrever condições já definidas. O retrato original permanece intacto; no PDF, quando não havia forma de pagamento na confirmação, são apresentadas as condições financeiras atuais posteriormente estabelecidas.

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

Uma nota que já teve comunicação fiscal não se torna elegível para retorno simples ao rascunho só porque foi cancelada. O caminho planejado é **Reverter Faturamento**, preservando o registro anterior.

O retorno existente a rascunho permanece conservador: recebimentos históricos e cobranças externas também impedem essa ação. A análise de recebimentos já estornados para a reversão completa será implementada com o workflow financeiro específico.

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

Nesta entrega não há executor dessas operações nem transições automáticas entre esses estados. Etapas individuais, resultados externos, condições de aborto e retomada pertencem à próxima implementação. Não marcar uma operação como concluída/abortada manualmente para contornar pendências.

## 10. Regras aprovadas para os próximos workflows

### Corrigir somente a nota

Se a venda está correta e o erro é fiscal, preservar fatura, ordens e financeiro. Cancelar/corrigir a nota conforme elegibilidade e emitir substituta quando necessário, mantendo a cadeia de documentos. Não duplicar recebíveis nem movimentos de estoque.

### Reverter Faturamento

Se a intenção é manter as ordens e refaturar:

1. Exigir estorno prévio dos recebimentos vigentes.
2. Resolver todas as notas fiscais e cobranças aplicáveis.
3. Preservar o retrato histórico da fatura.
4. Cancelar a fatura pelo motivo `rebilling`.
5. Liberar ordens para Encerrada / OP para Concluída.
6. Permitir outra fatura com nova composição.

Se houve autorização fiscal e a composição precisa mudar, criar outra fatura, preservando a anterior. Não reabrir a fatura cancelada.

### Cancelar Venda

Encerrar definitivamente a operação comercial, tratando notas, financeiro, cobranças e documentos operacionais. Cancelamento comercial não comprova devolução física de mercadoria: estoque deve refletir o fato ocorrido.

### Origem do financeiro

Um título criado pela fatura é diferente de um título preexistente apenas associado a ela. O segundo não deve ser cancelado automaticamente. Antes dos workflows destrutivos, persistir essa origem e definir a liberação da associação.

### Prazos fiscais

Parâmetros operacionais aprovados: NF-e até 24 horas e NFS-e dentro do mês de emissão. Implementar parametrização e validação por emissor/modelo: o prazo da NF-e normalmente toma a autorização como marco, e a NFS-e depende do município/provedor. A janela temporal não substitui as demais condições fiscais, como circulação da mercadoria.

Fora da janela ou sem elegibilidade, encaminhar para tratamento fiscal específico. Não produzir uma devolução automaticamente para qualquer erro.

## 11. Compatibilidade com dados existentes

A migration é aditiva e não reconstrói automaticamente confirmações antigas. Não é possível inferir com segurança o retrato original de uma fatura se suas ordens já foram alteradas ou desvinculadas.

- Novas confirmações pelos fluxos implementados geram histórico.
- Registros legados sem histórico mantêm o cálculo anterior e precisam de revisão antes de qualquer liberação destrutiva futura.
- O bloqueio de itens comerciais considera confirmações persistidas para manter compatibilidade com esses registros legados.
- Os futuros workflows devem impedir a liberação de uma fatura confirmada legada sem um retrato validado.
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
