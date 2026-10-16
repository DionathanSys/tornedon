# Reinício operacional de uma empresa

## Diagnóstico (sem alterações)

Após disponibilizar o código e aplicar as migrations (`php artisan migrate`):

```bash
php artisan company:reset-operations 13 --dry-run
php artisan company:reset-operations 13 --dry-run --json
```

Sem opções, o comando também opera em simulação. `--json` emite o plano completo,
incluindo todos os IDs de exclusão e desvinculação. O retorno é `0` se não houver
impedimentos e `1` se houver pendências ou erros. O JSON não contém credenciais.

O diagnóstico mostra:

- Identidade da empresa e quantidades por tabela.
- Documentos fiscais, seus IDs, estados locais e decisão de preservar/excluir/conferir.
- Boletos e cobranças Pix com conexão, identificação no provedor, status, valor e vencimento.
- Saldos de abertura/atual por conta e quantidades/custos de estoque.
- Vínculos que serão removidos de registros preservados.
- Impedimentos: estados fiscais indefinidos, vínculos entre empresas e dependências
  não contempladas pela política de exclusão.

Valores monetários no diagnóstico são apresentados em reais, não em centavos.
Os dados são locais: o comando não consulta nem modifica APIs bancárias ou fiscais.
Os estados/pendências externos devem ser conferidos nos provedores.

## O que é preservado

Empresa, usuários, vínculos de acesso, clientes/fornecedores, produtos, serviços,
equipamentos, configurações, categorias, contas financeiras, cartões e conexões
bancárias. As sequências de numeração, inclusive NF-e e NFS-e/RPS, não são reiniciadas.

Notas autorizadas, canceladas e rejeitadas são preservadas com seus itens, impostos,
payloads, anexos, protocolos, vínculos entre notas/devoluções e histórico fiscal.
Elas são desvinculadas das faturas removidas e marcadas com `operational_reset_at`.
Retornos fiscais e consultas continuam atualizando os estados, mas as ações de
estoque, geração de contas e estornos ignoram esses documentos históricos.
Jobs de envio pendentes também não reenviam documentos marcados como históricos.

Rascunhos sem evidência de envio são excluídos. Documentos em processamento,
conciliação de RPS ou com situação de envio indefinida bloqueiam a execução,
até que sua situação seja esclarecida. Não altere status diretamente no banco
apenas para remover o impedimento.

## O que é removido/zerado

Orçamentos, ordens, requisições, pedidos de produção, garantias, faturas, contas,
parcelas, baixas, cobranças locais, eventos bancários existentes, extratos,
conciliações, movimentações de cartões, fechamentos e créditos de compras,
movimentações de estoque e suas dependências. Inclui registros com soft delete.

As contas financeiras mantêm o cadastro, mas ficam sem movimentações e com abertura
zero. Estoques mantêm o cadastro e parâmetros (mínimos, máximos, preços), mas ficam
com quantidades/reservas/custos zero e sem os dados da última movimentação.
Arquivos físicos permanecem no storage; referências de anexos operacionais são
removidas. Arquivos fiscais e suas referências são preservados. Não há exclusões
de arquivos externos que possam comprometer o rollback do banco.

## IntegraBancos e retornos após a exclusão

A exclusão é **local**, sem solicitar cancelamento/baixa de cobranças no banco.
Cobranças externas podem continuar ativas e ser pagas.

Mantendo a conexão e recebendo um webhook válido de um boleto excluído, o fluxo
existente responde HTTP 200, armazena o evento sem boleto associado e o marca para
revisão (`Evento recebido sem boleto local correspondente.`). Não recria boleto,
conta, parcela ou saldo. Esses novos eventos podem aparecer após a limpeza.
Credenciais/assinatura continuam sendo validadas normalmente.

## Execução

1. Revisar o diagnóstico e resolver todos os impedimentos.
2. Fazer backup recuperável do banco e dos arquivos.
3. Pausar os agendadores, parar os workers e aguardar todas as tarefas em execução.
   Não basta executar `queue:restart` se um supervisor reiniciar os workers.
4. Colocar a aplicação em manutenção e garantir que outros nós/processos ou URLs
   de bypass não escrevam durante o reinício. Na configuração de manutenção em
   arquivos, colocar todos os nós em manutenção.
5. Executar:

```bash
php artisan down
php artisan company:reset-operations 13 --execute --workers-stopped
```

O comando exige manutenção, a indicação de workers/agendadores parados e a digitação
interativa do ID da empresa. Não há execução destrutiva com `--no-interaction`.
O plano é recalculado dentro da transação, mantendo as constraints ativas. Qualquer
falha reverte as alterações no banco. Um lock impede duas execuções simultâneas
para a mesma empresa; use cache compartilhado em instalações com múltiplos nós.

6. Conferir notas/cadastros preservados e saldos zerados. Executar novamente o
   diagnóstico se necessário (somente contagens do histórico devem permanecer).
7. Retirar a manutenção (`php artisan up`) e retomar workers e agendadores.
8. Cadastrar novos saldos iniciais, se desejado.

O comando não apaga filas compartilhadas nem reinicia IDs/autoincrementos.
Jobs bancários para cobranças removidas encontram os registros ausentes e encerram
sem chamadas ao banco. Uma restauração deve recuperar o backup coerente da empresa;
não existe operação inversa de desfazer o reinício.
