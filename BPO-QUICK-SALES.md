# Venda rápida BPO — contrato e implantação

## Fluxo entregue

1. Em **Modelos de venda**, cadastrar um modelo empresarial de NF-e ou NFS-e, natureza de venda opcional, controle de estoque e informações adicionais da NFS-e.
2. Em **Vendas e faturamento → Nova venda**, selecionar modelo, cliente ativo, data e itens. NFS-e utiliza um serviço; NF-e aceita múltiplos produtos. Informar preços e descontos em reais.
3. Salvar rascunho. Os serviços existentes criam uma OS aberta ou requisição aberta com os itens reais. Não há fatura nem recebível nessa etapa. Requisições com estoque habilitado usam as reservas existentes.
4. **Finalizar** apresenta total, política financeira, forma e condição de pagamento, campos de parcelamento personalizado e categoria. Pode gerar recebível, vincular um título compatível ou operar somente fiscal.
5. A finalização encerra e fatura a origem operacional, prepara o documento fiscal e aplica o financeiro em uma transação local. Recebível nasce nessa confirmação, sem autorização fiscal e sem baixa automática.
6. O operador pode marcar **Solicitar emissão após finalizar** ou executar **Solicitar emissão** depois. Falha fiscal não desfaz a venda nem cria outro recebível.

`BpoSale` é o registro de coordenação da bancada, não uma segunda base de itens ou valores. Guarda UUID de criação, empresa, modelo/snapshot, OS/requisição, fatura, revisão e configurações financeiras. O snapshot de modelo evita que uma mudança posterior transforme uma venda iniciada. O tipo do modelo não pode ser trocado no mesmo rascunho.

## Fiscal, financeiro e estoque

- NF-e utiliza as naturezas de venda existentes e o resolvedor fiscal atual; CFOP/tributação seguem perfil, regras fiscais e cadastro dos produtos. Natureza vazia mantém resolução automática conforme UF. Modelos não mantêm uma tabela paralela de tributos.
- NFS-e utiliza o canal/modelo fiscal configurado na empresa, inclusive Nacional quando configurado. A interface não constitui homologação de novos cenários fiscais.
- `invoices.financial_policy = null` mantém o legado. Vendas rápidas gravam `create_receivable`, `link_existing` ou `fiscal_only`.
- Webhook e consultas continuam no serviço compartilhado, mas não geram obrigações para faturas cuja política já foi definida pela venda rápida. Geração financeira independente e retorno da fatura ao estado pendente são bloqueados para essa origem.
- Vinculação exige mesmo cliente/empresa, valor original compatível, título não cancelado e ausência de vínculos prévios. Parcelas e pagamentos existentes não são modificados. O vínculo é auditado.
- Boleto/Pix não são disparados automaticamente pela finalização BPO nesta entrega. Também não há baixa automática por condição à vista.
- Estoque é opcional **por modelo da empresa**. `requisitions.stock_control_enabled` tem padrão `true`, preservando os registros existentes. Com `false`, adição/edição/remoção de itens não reserva estoque e os caminhos de encerramento/autorização não consomem produtos.
- Com estoque habilitado, a requisição conserva o marco existente: reserva durante preparação e saída após autorização fiscal. A política não pode mudar depois de incluir itens. Não foi implantado um novo marco de saída na finalização comercial.

## Integridade e permissões

### Indicadores e filtros da bancada

A tela apresenta **Valor faturado**, **Vendas faturadas**, **Ticket médio** e **Em rascunho** (quantidade e valor em preparação). O faturamento é o total comercial líquido dos itens, incluindo deslocamento de OS quando houver, de vendas finalizadas com fatura confirmada e não cancelada. Não depende da autorização fiscal, da geração de recebível ou de pagamento. Rascunhos são apresentados separadamente.

O período inicial é o mês atual pela data da venda (`order_date` da OS ou `sale_date` da requisição). É possível selecionar **Data da finalização**, mudar as datas ou deixá-las vazias para consultar o histórico. Os limites inicial/final são inclusivos. Há filtros por situação da venda, NF-e/NFS-e, cliente, modelo, situação fiscal e política financeira; a busca encontra cliente, modelo, número da venda ou fatura.

Todos os cards acompanham a mesma consulta filtrada/pesquisada da tabela e consideram todas as páginas. Consultas e agregações obedecem às permissões da venda e da origem operacional. As métricas de faturamento exigem também consulta às faturas; sem essa permissão, aparecem indisponíveis. A soma usa totais persistidos em centavos, sem multiplicar valores por parcelas ou documentos.

### Regras de acesso e coordenação

- Empresa e ator são fornecidos pelo contexto autorizado; IDs de clientes, catálogo, categoria, título e modelo são relidos no servidor.
- Criação é protegida por UUID único por empresa e lock empresarial; finalização bloqueia a coordenação e verifica revisão. Repetir finalização reconhece a fatura já vinculada. Editar rascunho com revisão antiga é recusado.
- Serviços que retornam `null`/`false` provocam falha e rollback do workflow externo, inclusive após preparar documentos.
- Os novos modelos estão no catálogo de permissões. Os filhos operacionais continuam herdando autorização de OS/requisição.
- Acesso BPO e alcances são reavaliados nas ações, incluindo solicitação posterior de emissão. Admin e Operation continuam usando os mesmos registros e serviços.
- O papel sugerido **Operador de vendas BPO** reúne permissões de venda, consulta de catálogo/cliente, manutenção/faturamento de OS/requisição, confirmação de fatura, emissão e criação/manutenção de recebíveis. Para configurar modelos, conceder separadamente `sale_templates.create/update`.
- A política sem recebível/vínculo exige `fiscal_documents.approve`; vinculação exige ainda alteração sobre o título específico. O papel pode ser personalizado para restringir essas ações.
- Nenhum atendimento ou papel existente recebe permissões novas automaticamente.

## Publicação

```bash
php artisan migrate
npm run build
```

Publicar código/build e reiniciar workers pelo procedimento habitual. A migration é aditiva: cria `sale_templates`/`bpo_sales` e acrescenta os campos de estoque/política. A operação normal continua sem novos seeders. Configurar os modelos e os papéis explicitamente por empresa.

As verificações de desenvolvimento usam SQLite de teste. Concorrência real deve ser homologada com o banco/cache e múltiplos workers do ambiente operacional. O desenvolvimento não aplica migrations ao banco operacional nem realiza emissão fiscal real.

### Verificação desta entrega

- `COMPOSER_PROCESS_TIMEOUT=0 composer test`: 1.122 testes PHP passaram, com 6.861 asserções; inclui 17 testes novos da venda rápida e regressões de Admin/Operation.
- `node --test tests/Frontend/*.test.mjs`: 38 testes passaram.
- `npm run build`: build Vite/PWA concluído.
- Pint aplicado nos arquivos PHP alterados e `git diff --check` sem erros.

O limite padrão de 300 segundos do Composer interrompe a suíte completa neste ambiente; a execução acima usa uma variável somente no comando, sem alterar a configuração do projeto.

## Pendências do painel BPO

### Faturamento e fiscal

- Cartões de crédito/débito e instituições de cartão na finalização rápida (a tela atual oferece métodos sem essas particularidades).
- Cadastros rápidos de cliente/serviço/produto; hoje a venda seleciona os cadastros existentes.
- Editor fiscal dedicado no BPO para corrigir rejeições, cenários mais amplos de modelos de NF-e, frete, destinatário e tributação excepcional. Manutenção fiscal detalhada permanece no Admin conforme autorização.
- Cancelamento comercial integrado, tratamento de estoque/cobranças/pagamentos, exclusão de rascunhos e duplicação de venda na bancada.
- Linha do tempo completa, prévia detalhada de parcelas e visualização/download/envio de PDF/XML diretamente na bancada.
- Homologação real de NFS-e Nacional/NF-e por emitente/cenário, incluindo retenções e distinção entre total fiscal e líquido financeiro. A entrega reaproveita os cálculos atuais, sem ampliar sua cobertura tributária.
- Processador único durável de retornos, inbox fiscal, recuperação de falha entre commit e envio, reconciliação financeira e revisão dos locks/timeouts fiscais.

### Cobrança e operação BPO

- Coordenador de cobrança após autorização fiscal, retomada e gestão de boleto/Pix na experiência de venda.
- Homologação das capacidades bancárias de Sicoob/Sicredi/Bradesco e integração automática de extratos conforme contrato; OFX permanece disponível.
- Recorrências/contratos, caixa de documentos, tarefas e pendências com responsável.
- Portal e aprovação opcional do cliente, sem execução bancária.
- Fechamento mensal, pacote gerencial/contábil e migração assistida do Conta Azul.

Essas frentes correspondem ao backlog do plano técnico, não a capacidades liberadas por esta entrega.
