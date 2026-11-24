# Recebimento com despesas retidas — uso e implementação

## Como usar

Na conciliação de um depósito, selecione **Recebimento com despesas retidas**.

1. Localize a parcela a receber e informe o **recebimento bruto**.
2. Adicione uma ou mais despesas retidas.
3. Em cada despesa, informe valor, categoria financeira e favorecido cadastrado ou nome avulso.
4. A categoria deve ser ativa, final, permitida para contas a pagar e vinculada a uma conta contábil da empresa.
5. A competência da despesa assume a data do depósito quando não informada. Centros de custo/resultado não informados são herdados da parcela.
6. Confira bruto, despesas, líquido e diferença. A diferença precisa ser exatamente zero.

Exemplo: recebimento de R$ 4.600,00, despesa de R$ 100,00 e depósito de R$ 4.500,00.

São gerados um recebimento/movimento de entrada de R$ 4.600,00 e uma conta a pagar liquidada/movimento de saída de R$ 100,00. Os dois movimentos são vinculados ao único lançamento do extrato. **Não é criado outro movimento de R$ 4.500,00.**

É possível receber parcialmente, adicionar despesas com categorias distintas e conciliar vários depósitos. Cada recebimento deve ser atribuído a um depósito; use **Dividir recebimento em outro depósito** quando necessário. A conferência é feita por linha bancária, não apenas pelo total selecionado.

## Desconto não é taxa

Uma despesa retida não usa `discount_amount`. Um desconto verdadeiro pode coexistir com a taxa.

Exemplo: título de R$ 1.000,00, desconto de R$ 50,00, recebimento financeiro bruto de R$ 950,00, taxa de R$ 20,00, depósito de R$ 930,00. A DRE com separação de descontos mostra receita recomposta de R$ 1.000,00, desconto de R$ 50,00 e despesa de R$ 20,00.

## DRE e saldos

- Competência: utiliza as parcelas a receber/a pagar, respeitando as respectivas competências.
- Caixa: utiliza os movimentos brutos com seus sinais.
- Previsto: considera apenas os saldos remanescentes; não repete despesas já liquidadas.
- A categoria da despesa resolve a conta contábil. O grupo da DRE depende do vínculo dessa conta no modelo, sem linha fixa de taxas.
- O extrato é apenas conciliado; não participa novamente do saldo.
- Os cálculos de composição, baixas, recálculos e agregados monetários da DRE usam centavos inteiros. Os valores públicos usados na apresentação mantêm a compatibilidade anterior.

## Correção administrativa

**Desfazer conciliação** encaminha a operação para a correção centralizada:

- preserva grupo, componentes, pagamentos e movimentos;
- anula logicamente seus efeitos, sem inventar uma saída bancária;
- recalcula os recebíveis;
- cancela os documentos de despesa criados pela operação;
- remove as alocações ativas e libera o extrato;
- registra motivo, usuário e data na auditoria.

Uma nova conciliação gera outro grupo e outra chave de idempotência. Movimentos e pagamentos históricos anulados não podem ser editados nem reutilizados como baixas válidas. Os documentos preexistentes não são cancelados pela correção.

Os registros ficam consultáveis no histórico. As consultas de efeitos válidos usam `effective()` explicitamente, sem esconder o histórico por um global scope.

## Devolução efetiva

**Registrar devolução efetiva** representa uma devolução integral já ocorrida dos recebimentos da operação. Não envia dinheiro.

Informe a data efetiva e selecione apenas as despesas ressarcidas pelo intermediador. A taxa permanece paga se não houver ressarcimento confirmado. Os estornos usam a data informada, preservando os períodos na DRE de caixa.

Recebimentos confirmados por provedor mantêm a exigência do fluxo bancário de devolução; não podem ser revertidos individualmente por esta ação. Também não é permitido alterar uma baixa ou despesa isoladamente quando vinculada à operação.

Para vincular a saída correspondente do extrato, use **Conciliar devolução de recebimento**. Essa modalidade relaciona os estornos da operação pelo líquido, sem gerar novos movimentos. Desfazer esse vínculo apenas libera o extrato; a devolução financeira permanece registrada.

## Estruturas e contratos

- `bank_reconciliation_groups`: identidade, modalidade, situação, idempotência e snapshots da operação.
- `bank_reconciliation_group_items`: relações tipadas com pagamentos e movimentos, origem das despesas e indicação de documentos gerados.
- `bank_statement_allocations`: valores positivos em centavos; o sinal vem da direção do movimento. A restrição de mesma direção das conciliações comuns permanece.
- `account_payable_installment_payments.returned_amount`: decimal em reais, na mesma escala de `amount`, para registrar ressarcimentos sem apagar pagamentos.
- Recebimentos continuam usando `MoneyCast`; pagamentos a pagar mantêm sua escala decimal existente. `Money::stored()` respeita essa diferença.

Os serviços são `ReconcileReceiptWithRetainedExpensesService`, `ReceiptWithRetainedExpensesReversalService` e `ReconcileRetainedReceiptReturnService`. A criação e liquidação dos documentos continuam nos serviços financeiros existentes.

## Instalação e validação

Execute as migrations antes de disponibilizar as telas:

```bash
php artisan migrate
```

Testes focados:

```bash
php artisan test tests/Feature/Services/Financial/RetainedReceiptReconciliationTest.php
php artisan test tests/Feature/Services/Financial/DreReportServiceTest.php
php artisan test tests/Unit/FinancialMoneyTest.php
node --test tests/Frontend/bank-reconciliation-workbench.test.mjs
```

Os testes de concorrência usam processos e conexões independentes, exigem `pcntl` e um banco MySQL/MariaDB exclusivo com nome terminado em `_tests`. Eles executam `migrate:fresh` nesse banco. Exemplo, com credenciais e conexão de testes previamente configuradas:

```bash
RUN_MYSQL_CONCURRENCY_TESTS=1 DB_CONNECTION=mysql DB_DATABASE=tornedon_retained_tests php artisan test tests/Feature/Services/Financial/RetainedReceiptConcurrencyTest.php
```

Sem a opção explícita, esses testes são ignorados na suíte SQLite. Cobrem mesma chave de idempotência, mesma linha do extrato e disputa pela mesma parcela em depósitos distintos.

## Resultado da revalidação completa — 19/11/2026

A execução completa inicial desta rodada apresentou **1.617 testes aprovados e 9 falhas**. A investigação identificou cenários de teste desatualizados; as correções preservam as validações do ERP:

1. **Geração de recebíveis e prazos de pagamento:** os dados de teste agora vinculam as ordens à fatura enquanto ela ainda é rascunho e somente depois a confirmam. Permanece proibido alterar a composição de uma fatura confirmada.
2. **Recebimento de empenho estornado:** o teste passou a exigir preservação do pagamento e da evidência, movimento de estorno vinculado, saldo restaurado e bloqueio de nova baixa. A expectativa antiga de exclusão física foi removida.
3. **Preflight de estoque:** o item é criado pelo `RequisitionItemService`, com reserva e snapshots corretos, em vez de herdar a marcação legada de estoque consumido. O teste continua verificando o bloqueio se faltar saldo antes do envio da NF-e.
4. **Remessas na ordem de serviço:** o teste verifica a página e a tabela do relation manager após seu carregamento, respeitando o lazy loading.
5. **Comando de testes:** `composer test` agora desabilita o timeout de processos do Composer, evitando interromper a suíte após 300 segundos.

Resultado final:

- Suíte PHP completa: **1.626 testes passaram, 10.929 verificações, nenhuma falha**, em cerca de 15 minutos.
- Os **3 testes de concorrência**, ignorados na execução SQLite por serem opt-in, foram executados separadamente em MariaDB: **3 passaram, 36 verificações**.
- Suíte JavaScript completa: **60 testes passaram**.
- Testes focados nos cinco arquivos corrigidos: **30 passaram, 189 verificações**.
- Fluxo SEFAZ na execução completa final: **23 testes passaram, 138 verificações**, sem necessidade de correção nesta rodada.
- Formatação dos arquivos corrigidos verificada com Pint, `git diff --check` sem erros e `composer.json` validado.

Na validação anterior da implementação também passaram os 448 testes financeiros focados e os 27 testes da nova funcionalidade/precisão em MariaDB; o build do frontend foi concluído. As nove falhas remanescentes da suíte geral foram resolvidas na revalidação acima, sem desabilitar testes nem afrouxar regras de negócio.

As migrations foram exercitadas pelos testes SQLite e MariaDB. A migration do banco utilizado pela aplicação deve ser aplicada no momento de disponibilizar a funcionalidade.
