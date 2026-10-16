# Integridade financeira — primeira entrega da etapa 1

## Regras implementadas

- Faturas e serviços de contas a pagar/receber executam operações atômicas também quando as actions retornam erro, preservando os contratos públicos `null`/`false` e as mensagens existentes. `RunsFinancialTransactions` provoca rollback antes de devolver o resultado de falha.
- Baixas bloqueiam o cabeçalho antes da parcela. Edição/exclusão de pagamentos segue a mesma ordem, relendo os registros.
- O valor da baixa é o dinheiro efetivamente pago/recebido. O limite considera principal, pagamentos anteriores, juros, multa e desconto; comparações são feitas em centavos.
- R$ 1.000 + R$ 100 de encargos permite quitação por R$ 1.100. R$ 1.000 com desconto de R$ 100 permite quitação por R$ 900. Excedente sem justificativa na composição é rejeitado enquanto não existir um módulo de créditos.
- A edição considera o valor anterior do próprio pagamento. Alterar uma parcela não pode deixá-la com pagamentos superiores à obrigação ajustada.
- Contas e parcelas com pagamentos são protegidas contra exclusão nos serviços e no método `delete()` dos models, incluindo exclusões padrão do Filament. Exclusões SQL em massa/cascatas não executam métodos dos models e continuam exigindo fluxos específicos, como o reinício operacional controlado.
- Cancelamento de contas a pagar sem pagamentos cancela também as parcelas e gera auditoria. A devolução de compra utiliza esse fluxo. A reversão restaura os estados das parcelas a partir do snapshot, quando disponível. Contas parcialmente pagas exigem tratamento explícito antes de cancelar/substituir.
- Sincronização de status relê as parcelas e preserva cabeçalhos cancelados.
- Movimentos derivados não aceitam edição manual. Movimentos conciliados/estornados e transferências conciliadas também são protegidos contra edição. A edição de uma baixa vinculada a movimento conciliado exige desfazer a conciliação.
- DRE de caixa inclui original e contrapartida do estorno em suas respectivas datas. Competência inclui contrapartidas de movimentos manuais, evitando contar novamente estornos de pagamentos já representados pelas parcelas.
- DRE consolidada revalida o acesso às empresas e o vínculo ativo no cálculo.
- Policies compartilham as permissões já existentes de receber, pagar e caixa entre Admin e Operation, incluindo parcelas, pagamentos, cobranças e conciliação.

## Baixa automática e confirmação bancária

A baixa por vencimento continua automática e utiliza o saldo relido sob bloqueio. Contas canceladas não participam do processamento.

Novos pagamentos identificam a origem: `manual`, `due_date`, `pix`, `bank_slip` ou `bank_statement`, conforme o fluxo. Registros anteriores permanecem com origem nula, sem inferência histórica.

Quando existe uma única cobrança registrada compatível com o saldo e a conta, a baixa por vencimento preserva seu identificador. A confirmação posterior pode vincular-se a essa mesma baixa se identidade, valor, conta e movimento forem compatíveis. Nesse caso, registra `bank_confirmed_at` sem criar outra baixa ou outro movimento.

Recebimento manual anterior, diferenças ou vínculos ambíguos não são presumidos como o mesmo pagamento: a cobrança/evento é encaminhada para revisão. Isso também preserva a possibilidade de um segundo pagamento real que precisa de crédito/devolução.

Novas cobranças guardam a conta financeira escolhida na preparação. A liquidação usa esse snapshot, mesmo que a parcela ou a conexão sejam alteradas depois. Cobranças antigas sem snapshot utilizam a conta da conexão vinculada; não é possível reconstruir com certeza a configuração histórica só pelo código.

Boletos novos são preparados pelo saldo restante, não pelo valor integral anterior à baixa parcial.

## Banco de dados e implantação

Migration adicionada:

`database/migrations/2026_10_16_000001_add_financial_integrity_metadata.php`

Ela adiciona origem/confirmação às baixas e conta financeira às cobranças. Aplicar a migration antes de executar o código atualizado e reiniciar workers pelo procedimento normal de implantação.

```bash
php artisan migrate
```

Nenhuma correção automática de dados históricos faz parte da migration.

## Diagnóstico de dados existentes

```bash
php artisan financial:audit-integrity
php artisan financial:audit-integrity --company=1 --limit=200
```

O comando é somente leitura e verifica pagamentos sem movimentos, divergências entre pagamento/movimento, movimentos não estornados sem pagamento de origem, totais de parcelas e parcelas abertas em cabeçalhos cancelados. Exibe uma amostra limitada, mas conta todas as ocorrências encontradas. Retorna código 1 quando encontra inconsistências e código 2 para argumentos inválidos.

Ocorrências precisam de análise de negócio antes de saneamento. O diagnóstico não comprova duplicidade econômica nem substitui conciliação com o banco.

## Verificação

```bash
composer test
vendor/bin/pint --dirty --test
```

Regressões adicionadas cobrem falhas após gravação, exclusão com pagamento parcial, encargos/descontos, edição excedente, cancelamento, proteção de movimentos, estornos na DRE, isolamento de empresas, confirmação de boleto/PIX após baixa automática, revisão após baixa manual e diagnóstico somente leitura.

## Continuidade da etapa

- Validar concorrência real no banco operacional e com múltiplos workers. SQLite em memória/fila síncrona não comprovam esses comportamentos.
- Homologar os payloads bancários reais, datas efetivas, encargos externos e recuperação de liquidação por consulta.
- Completar a máquina de estados de boleto/PIX, incluindo devoluções e eventos fora de ordem.
- Definir e implementar permissões mais granulares para baixar, aprovar, estornar e conciliar; esta entrega reutiliza as permissões de módulo existentes.
- Revisar os diagnósticos históricos e planejar saneamento específico.
- Ampliar auditoria de alterações/exclusões de baixas e controles de fechamento de período.

O webhook NFe mantém seu contrato de HTTP 200; os testes existentes desse fluxo fazem parte da suíte.
