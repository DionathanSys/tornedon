# Instituições de cartão e recebimento de repasses

## Cadastro

Em **Financeiro → Instituições de cartão**, cadastre a instituição que processa
os pagamentos e seu prazo de repasse em **dias corridos**. Taxas não são
configuradas nem calculadas neste cadastro.

O toggle **Padrão** na listagem seleciona a instituição sugerida nos novos
recebimentos em cartão. Marcar outra instituição remove o padrão anterior
somente da mesma empresa. Desativar a instituição também remove sua preferência.

## Faturamento e baixa automática

Na confirmação da fatura ou geração de contas, selecione a instituição e a data
da venda no cartão. O primeiro vencimento é essa data mais o prazo de repasse;
as demais parcelas seguem o intervalo de 30 dias do faturamento.

O pagamento do cliente no cartão não representa entrada de dinheiro no banco.
O recebível permanece em aberto até a baixa. Para automatizá-la, marque
**Registrar recebimento automaticamente no vencimento?** e selecione uma conta
financeira ativa da empresa. Essa configuração pertence ao recebível, não à
instituição, e pode ser alterada na edição do contas a receber.

A rotina `account-receivables:process-auto-receipts` executa diariamente às 00:15,
registra o saldo em aberto das parcelas vencendo no dia e gera os movimentos de
caixa pela rotina financeira existente. Parcelas canceladas ou totalmente
recebidas não são processadas. Reexecutar a rotina não duplica baixas.

Para processar uma data específica (por exemplo, um dia em que o scheduler não
executou):

```sh
php artisan account-receivables:process-auto-receipts --date=2026-10-08
```

## Antecipação

Nas parcelas do contas a receber, use **Registrar recebimento / antecipação**.
Informe o valor recebido, a data e a conta financeira. A antecipação pode ser
parcial: o restante mantém o vencimento original e, se a baixa automática estiver
habilitada, será baixado nessa data. A competência pode ser escolhida quando
o recebimento ocorrer em mês diferente do vencimento.

## Atualização e histórico

Execute `php artisan migrate` no ambiente de implantação e mantenha o scheduler
Laravel em execução. Recebíveis existentes continuam com baixa automática
desabilitada até que ela seja configurada.

O modelo e o resource usam o nome `CardInstitution`. A tabela
`card_payment_profiles`, sua chave estrangeira e os snapshots financeiros
anteriores foram preservados para manter o histórico. Novos recebíveis não
aplicam taxas; alterações apenas na configuração de baixa não recalculam os
valores ou prazos históricos.
