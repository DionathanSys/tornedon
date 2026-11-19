# Contas a pagar dos DF-e detectados

## Geração e vínculos

A geração financeira pelo DF-e procura a nota de entrada pela empresa e chave de acesso. O vínculo funciona tanto gerando a conta antes da importação quanto importando a nota primeiro.

Uma conta já vinculada ao DF-e ou à nota é reaproveitada. Se a nota possuir mais de uma conta e o DF-e ainda não indicar qual utilizar, a ação pede a seleção por **Vincular conta a pagar existente**. Contas de outra empresa, fornecedor, nota ou já associadas a outro DF-e são rejeitadas.

A confirmação da entrada pode processar o estoque com financeiro existente, sem criar uma segunda conta. O financeiro gerado pela confirmação da nota também passa a ser reconhecido pelo DF-e correspondente.

## Revisão financeira

No modal **Gerar conta a pagar**, é possível:

- Informar categoria financeira, centro de custo e centro de resultado.
- Usar os valores e vencimentos das duplicatas do XML (`cobr/dup`).
- Calcular parcelas pela condição de pagamento, incluindo dia fixo do mês e condição personalizada.
- Informar e revisar individualmente valores, vencimentos, descrições e observações.
- Consultar o total fiscal, o valor líquido da fatura quando informado e a soma das parcelas.
- Informar o número do boleto e configurar a conta financeira do pagamento automático no vencimento.

Ao alterar a condição ou o primeiro vencimento, utilize **Calcular / atualizar parcelas** e revise a grade antes de gerar. A soma das parcelas deve ser igual ao valor da conta. Diferenças entre o valor da conta e o total fiscal exigem justificativa, registrada na auditoria.

A situação financeira, saldo e próximo vencimento consideram as parcelas não ignoradas e não canceladas. A listagem também oferece filtros para documentos sem financeiro e com vínculos financeiros pendentes.

## Correção de registros anteriores

No menu de ações do DF-e, **Sincronizar vínculos financeiros** reconhece a nota pela chave e completa as referências existentes, preservando valores, parcelas e baixas.

Para processar uma empresa inteira:

```bash
php artisan sefaz:repair-financial-links ID_DA_EMPRESA --dry-run
php artisan sefaz:repair-financial-links ID_DA_EMPRESA
```

A simulação não persiste alterações. Cada DF-e é processado em uma transação independente. Conflitos são exibidos por documento, sem substituir vínculos existentes. O comando retorna código de falha se algum documento exigir tratamento manual.

Para contas já cadastradas sem vínculo com a nota ou com o DF-e, utilize a seleção explícita de conta existente. Correspondências apenas por valor ou número da nota não são associadas automaticamente.
