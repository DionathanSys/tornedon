# Módulo RH — funcionários, sócios e recibos

## Ativação

1. Execute `php artisan migrate` para criar as tabelas do RH.
2. Acesse **Equipe e acessos → Papéis e permissões → Usar papéis sugeridos** para incluir os modelos **Gestor de RH** e **Assistente de RH**.
3. Para atualizar um papel já existente, edite-o e use **Adicionar permissões de um modelo de RH**. Revise as permissões e salve. As concessões atuais são preservadas, inclusive restrições já definidas para as mesmas ações.
4. Atribua os papéis às pessoas que trabalharão no módulo. Os modelos de RH usam o painel administrativo.

O administrador legado que possui o vínculo de proprietário da empresa pode configurar o RH. Demais usuários legados precisam de concessão explícita; acesso irrestrito legado ao painel não implica acesso automático ao RH. Para vínculos no novo controle, valem os papéis ativos da empresa.

## Operação

- Cadastre a pessoa em **Parceiros** e crie seu vínculo em **RH → Funcionários e sócios**. Criação, edição e consulta usam páginas próprias. Uma pessoa pode ser funcionário e sócio na mesma empresa.
- Os campos monetários usam o componente Money do Leandro, no formato brasileiro. A categoria financeira possui pesquisa e cadastro rápido na própria página, condicionado à permissão de criar categorias.
- Crie um lançamento com competência, vencimento, natureza e itens manuais de proventos/descontos. O valor-base do vínculo é uma referência; os itens são informados na competência.
- São atendidos salário, pró-labore, distribuição de lucros e adiantamento. INSS, IRRF e demais rubricas são informados manualmente.
- A classificação financeira do vínculo é usada quando não houver classificação específica no lançamento. O plano de contas é derivado da categoria, seguindo o padrão do contas a pagar.
- Confirme o lançamento para gerar uma única conta a pagar pelo valor líquido. Competência e vencimento são independentes. Um lançamento totalmente compensado pode ter líquido zero e não gera nova obrigação financeira.
- Abra o lançamento para conduzir todo o processo na mesma página: conferir os valores, confirmar, acompanhar a conta vinculada, registrar pagamentos, emitir PDFs, coletar assinaturas e estornar pagamentos. O menu de ações da listagem fica no botão de três barras.
- Use **Registrar pagamento** na página do lançamento. A baixa é registrada pelo serviço financeiro existente, mantendo a competência original. O papel Gestor de RH permite gerar os títulos, mas baixa e estorno dependem das permissões financeiras. Combine os papéis necessários ou configure essas permissões no editor de acessos.
- Após a baixa, use **Emitir recibo** no pagamento correspondente. Os recibos e seus PDFs ficam na própria página e também em **Recibos de RH**. Cada pagamento parcial tem seu recibo; o documento informa o saldo existente na emissão.
- O demonstrativo pode ser emitido antes da baixa e é identificado como documento sem quitação.

## Assinatura e data no recibo

Na lista de recibos do lançamento, use **Coletar assinatura** para o beneficiário assinar com toque, mouse ou caneta. A operação exige `hr_receipts.sign`, além da consulta ao recibo e ao lançamento. Os modelos de RH incluem essa nova permissão; em papéis já existentes, acrescente-a pelo editor ou reaplique o modelo preservando as concessões atuais.

A assinatura eletrônica é coletada em tela e incorporada ao PDF. A data e hora são registradas pelo servidor no ato da assinatura. O recibo conserva os valores e a identificação originais; a assinatura é armazenada separadamente, com identificação do documento, operador e dados de auditoria, e não pode ser substituída.

Sem assinatura eletrônica, o PDF traz linha para assinatura do beneficiário e espaço de data manual ao lado. Com assinatura eletrônica, esses espaços exibem a imagem e a data/hora da coleta. A data do pagamento continua identificada separadamente no documento.

Ao atualizar uma instalação existente, execute novamente `php artisan migrate` para criar a tabela de assinaturas.

## Adiantamentos e histórico

Adiantamentos têm lançamento e pagamento próprios. Ao lançar salário ou pró-labore, selecione o adiantamento e o valor a abater. Somente a parcela efetivamente paga e ainda disponível pode ser utilizada; juros e multas não aumentam o valor disponível para abatimento. A compensação não cria outra saída de caixa.

Rascunhos não reservam saldo de adiantamento. A confirmação revalida e reserva os valores, impedindo consumo duplicado. Para estornar um adiantamento consumido, cancele primeiro os lançamentos que o utilizaram. Cancelar um lançamento com baixa exige tratar os pagamentos no financeiro antes.

Lançamentos confirmados não são reabertos nem editados. Cancele e crie outro quando necessário. Recibos preservam os dados da emissão; alteração ou exclusão de uma baixa invalida seus recibos anteriores, que continuam no histórico. PDFs invalidados não podem ser baixados como comprovantes válidos.

## Permissões

O editor existente contém a área **Recursos Humanos**, com `hr_people.*`, `hr_entries.*` e `hr_receipts.*`. As verificações são aplicadas nas telas, serviços, consultas e downloads. O escopo `own` em lançamentos significa autoria do registro, não acesso do funcionário à própria remuneração.

Financeiro, Consulta e BPO não recebem acesso ao detalhamento do RH pelos modelos padrão. O acesso aos títulos financeiros continua seguindo as permissões de contas a pagar. Uma concessão de RH a BPO respeita o limite de delegação existente.

CSV de cadastros e lançamentos, demonstrativos e PDFs exigem suas permissões de exportação e acesso aos registros. Downloads usam autenticação e URLs assinadas, com nova verificação das permissões a cada acesso.

## Verificação

`php artisan test tests/Feature/Services/Hr/HrWorkflowTest.php`
