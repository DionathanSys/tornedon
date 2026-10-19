# Plano técnico — Conciliação de empenhos e recebimentos

## 1. Objetivo e situação

Implementar o fluxo de importação de empenhos em PDF, conciliação com ordens de serviço encerradas, faturamento individual com encaminhamento da NFS-e para emissão e posterior conciliação dos pagamentos informados pelo cliente em PDF.

Este documento define a implementação a executar. Os nomes de novas classes, tabelas e rotas abaixo são propostos; os componentes existentes citados são pontos de integração já identificados no projeto.

### Regras acordadas

- Funcionalidade habilitada por empresa, com configuração por cliente.
- Um empenho corresponde a uma OS e uma OS corresponde a um empenho.
- O patrimônio está no nome/descrição cadastrado do veículo ou equipamento, no formato `PAT XXXX`.
- Buscar OS encerradas, ainda não faturadas, da mesma empresa e cliente.
- Comparar patrimônio e valor total da ordem, em centavos, sem tolerância automática.
- Priorizar a data de abertura (`order_date`) crescente; desempatar pelo ID.
- O usuário revisa e seleciona as conciliações antes do faturamento.
- Cada OS selecionada gera sua própria fatura, confirmada com padrões configurados, e sua NFS-e é encaminhada para emissão.
- Corpo da NFS-e: nome do serviço selecionado para o documento.
- Rodapé/informações complementares: mensagem fixa configurável, patrimônio e número/ano do empenho, preservando o requisito inicial de identificar o empenho na nota.
- No pagamento, sugerir quitação integral somente quando o valor do relatório corresponder ao saldo em aberto.
- Divergências, retenções e pagamentos parciais exigem revisão; não aplicar diferenças como desconto automaticamente.
- Registrar recebimentos somente para linhas selecionadas pelo usuário.
- Preservar PDFs, dados extraídos, vínculos e histórico de execução.

## 2. Documentos de referência

Arquivos fornecidos para validar o leitor, fora do repositório:

| Arquivo | Empenho | Patrimônio | Valor |
| --- | --- | --- | ---: |
| `NE 14728 - 142186.pdf` | 14728/2026 | 142186 | R$ 400,00 |
| `NE 14731 - 79071.pdf` | 14731/2026 | 79071 | R$ 540,00 |
| `NE 14732 - 92181.pdf` | 14732/2026 | 92181 | R$ 190,00 |

O relatório `pgto 2g setemb.pdf` tem 13 páginas e total declarado de R$ 57.330,00. Nele, o empenho 14732/2026 aparece com patrimônio 92181, R$ 190,00, nota fiscal 247 de 13/07/2026 e pagamento em 27/08/2026.

Os quatro exemplos possuem texto extraível. A primeira versão atenderá esse layout da Prefeitura de Chapecó por um leitor específico versionado. PDFs sem texto ou com layout não reconhecido terão diagnóstico explícito, preservando o original e permitindo revisão; OCR não é requisito da primeira entrega.

## 3. Navegação e apresentação dos dados

### Localização

No painel administrativo `/admin`, dentro do cluster **Financeiro**, adicionar:

1. **Empenhos** — entrada principal de importação, conciliação e faturamento.
2. **Pagamentos de empenhos** — importação dos relatórios e revisão das baixas.

As configurações ficam em uma página própria **Configurações de empenhos**, acessível pelo cabeçalho da listagem de empenhos. A página permite selecionar o cliente; não criar um menu adicional de configuração para cada cliente.

Resources e páginas seguirão a organização existente em `app/Filament/Clusters/Financial/Resources/`. Recursos novos pertencem explicitamente ao `FinancialCluster`. A primeira entrega será no painel administrativo.

### 3.1. Listagem de empenhos

Cabeçalho:

- **Importar empenhos**: cliente obrigatório e upload múltiplo de PDFs; mostrar resumo de arquivos aceitos e duplicados.
- **Importações**: histórico de lotes, arquivos, progresso e erros.
- **Configurações**: abrir configuração empresa/cliente.

Indicadores: aguardando processamento, prontos para revisão, sem correspondência, faturados e com erro. Indicadores respeitam os filtros de empresa/cliente.

Colunas da tabela:

| Grupo | Dados exibidos |
| --- | --- |
| Empenho | Número/ano, emissão, valor, cliente |
| Patrimônio | Número extraído e nome do equipamento encontrado |
| OS sugerida/vinculada | Número com link, abertura, valor total |
| Conciliação | Situação, diferença de valor, quantidade de candidatas |
| Processamento | Etapa atual, última falha resumida |
| Resultado | Fatura com link e situação da NFS-e |

Filtros: cliente, situação da conciliação, etapa de faturamento, lote, período de emissão e divergências. Busca por empenho, patrimônio e OS.

Linhas sem resultado continuam visíveis e explicam o motivo: patrimônio ausente, equipamento não localizado, OS sem valor correspondente ou OS já comprometida.

### 3.2. Detalhe/revisão do empenho

Página com cabeçalho resumido e seções:

- **Documento**: PDF original com visualização/download autorizado, dados extraídos, objeto e valores bruto/retido/líquido.
- **Conciliação**: empenho e OS lado a lado; candidatas ordenadas por abertura; indicar claramente a mais antiga e as alternativas.
- **Faturamento**: fatura, contas a receber e documentos fiscais vinculados, com links.
- **Histórico**: extração, correções, seleção de OS, usuário, data, tentativas e falhas.

Correções de extração exigem motivo e preservam o conteúdo originalmente extraído. Selecionar outra OS exige a mesma elegibilidade da sugestão; não permitir ignorar empresa, cliente, status ou diferença de valor para faturar automaticamente.

### 3.3. Ações de empenhos

| Ação | Local | Comportamento |
| --- | --- | --- |
| Visualizar PDF | Linha e detalhe | Abrir original com autorização por empresa |
| Revisar conciliação | Linha | Abrir detalhe e escolher/corrigir dados |
| Buscar novamente | Linha/detalhe e lote selecionado | Recalcular sugestões não executadas |
| Aprovar vínculo | Detalhe | Reservar OS para o empenho com revalidação |
| Desvincular | Detalhe | Liberar vínculo apenas antes de criar fatura; auditar |
| Faturar e emitir NFS-e | Linha/detalhe e seleção em lote | Prévia, confirmação da seleção e processamento sequencial |
| Retomar processamento | Linha/detalhe e seleção com falha | Continuar da última etapa persistida |
| Reprocessar arquivo | Histórico de importações | Nova tentativa de extração; não sobrescrever vínculos executados |
| Ignorar/Reabrir | Linha/detalhe | Retirar da fila operacional ou devolver para revisão com motivo |

O modal **Faturar e emitir NFS-e** mostra quantidade de OS, total, padrões financeiros, corpo/rodapé de exemplo e registros não elegíveis. A execução é assíncrona, com progresso e resultado por empenho. “Encaminhada para emissão” não significa “autorizada”; a situação fiscal será lida do documento existente.

### 3.4. Configuração empresa/cliente

- Habilitação do fluxo e leitor de PDF utilizado.
- Serviço fiscal padrão, limitado aos registros válidos para a empresa.
- Forma/condição de pagamento, categoria financeira e demais campos efetivamente exigidos pelo serviço de confirmação.
- Conta financeira padrão para recebimentos, validada novamente na baixa.
- Mensagem fixa do rodapé, com prévia.
- Corpo gerado pelo nome do serviço fiscal selecionado; exigir escolha quando não houver resolução inequívoca.
- Composição final do rodapé: mensagem fixa + `Patrimônio: {patrimonio}` + `Empenho: {numero}/{exercicio}`.

Não truncar silenciosamente informações obrigatórias. Validar o tamanho dos campos contra os limites atuais e do emissor. Persistir snapshot dos padrões usados em cada execução para que mudanças de configuração não alterem documentos já gerados.

### 3.5. Relatórios de pagamentos

Listagem com cliente, nome do arquivo, importação, período de pagamentos, número de linhas, total declarado, total extraído, total recebido e situação.

Cabeçalho: **Importar relatório**, com cliente obrigatório e um PDF por importação.

Detalhe com resumo de integridade e tabela de linhas:

| Origem do relatório | Dados localizados no sistema |
| --- | --- |
| Empenho/ano, patrimônio, página | Empenho vinculado e OS |
| Nota fiscal e data de emissão | Fatura, NFS-e e situação fiscal |
| Data de pagamento e valor | Contas/parcelas, saldo atual e diferença |
| Objeto e linha original | Situação da conciliação e motivo de pendência |

Filtros: compatíveis, divergentes, não localizados, já recebidos, ignorados e erro. Acesso ao PDF e identificação da página em cada linha.

| Ação | Local | Comportamento |
| --- | --- | --- |
| Revisar linha | Linha | Conferir origem, vínculos, valores e diagnóstico |
| Buscar novamente | Linha/seleção | Atualizar vínculos e saldos antes da baixa |
| Confirmar recebimento | Linha/seleção | Solicitar conta financeira e exibir data/valor de cada linha |
| Abrir conta/fatura/NFS-e | Linha | Navegar ao registro autorizado |
| Ignorar/Reabrir | Linha | Controlar pendências com motivo |

Linhas compatíveis podem ser selecionadas em lote. Divergências não entram na baixa automática: a revisão orienta para o fluxo financeiro existente quando for necessário tratar parcial, retenção, encargos ou desconto. Depois, a linha pode ser reconciliada com recebimento existente de forma explícita, sem novo pagamento.

No modal de baixa, usar a data efetiva de cada linha, não a data da importação. Solicitar competência quando exigido pelas regras financeiras atuais. Se a condição gerou várias parcelas, exibir a distribuição por vencimento/ID e liquidar o saldo total apenas quando a soma coincidir com o valor informado.

### 3.6. Visibilidade nos registros existentes

- OS: seção de leitura **Empenho** com número/ano, valor, situação e link.
- Fatura: referência ao empenho e link para a conciliação.
- Conta a receber: referência ao relatório/linha nos recebimentos originados por essa integração.
- NFS-e: textos persistidos nos campos fiscais existentes; consulta dos vínculos pela fatura.

## 4. Modelo de dados proposto

Valores persistidos em centavos inteiros, usando o padrão de casts monetários do projeto nas fronteiras. Não calcular conciliação por igualdade de `float`.

| Tabela / model | Responsabilidade e campos centrais |
| --- | --- |
| `commitment_settings` / `CommitmentSetting` | `company_id`, `customer_id`, habilitação, leitor, padrões financeiros/fiscais e mensagem fixa; unique empresa/cliente |
| `commitment_imports` / `CommitmentImport` | Lote: empresa, cliente, tipo (`commitments` ou `payments`), usuário e situação |
| `commitment_import_files` / `CommitmentImportFile` | Arquivo: lote, empresa/cliente, SHA-256, anexo, estado, texto extraído, versão do leitor, erros, totais e contagem de páginas |
| `customer_commitments` / `CustomerCommitment` | Empresa/cliente, número/ano, emissão, patrimônio normalizado, objeto, valores, dados originais/corrigidos, arquivo/página, situação de revisão |
| `commitment_billings` / `CommitmentBilling` | Vínculo exclusivo empenho/OS, fatura, snapshot da aprovação/configuração, etapa, tentativas e último erro |
| `commitment_payment_lines` / `CommitmentPaymentLine` | Arquivo, posição/páginas, empenho/ano, documento fiscal, data/valor do pagamento, objeto, dados originais, vínculo e situação de revisão |
| `commitment_receipts` / `CommitmentReceipt` | Evento de quitação aprovado, chave de deduplicação por empresa/cliente, data/valor, empenho/fatura, usuário e situação |
| `commitment_receipt_payments` / `CommitmentReceiptPayment` | Liga quitação aos pagamentos de parcelas existentes/criados; suporta várias parcelas sem um campo único insuficiente |

### Restrições e relações

- Empenho unique em `(company_id, customer_id, number, fiscal_year)`.
- `commitment_billings`: unique em empenho e em OS; `invoice_id` unique quando preenchido para preservar faturamento individual.
- Arquivo deduplicado por empresa/cliente/tipo/hash. Reenvio retorna o registro existente.
- Linha unique por arquivo/posição; preservar todas as ocorrências de relatórios diferentes.
- Cada linha pode referenciar um `commitment_receipt` existente. Assim, PDFs sobrepostos podem reconhecer a mesma quitação sem descartar evidência.
- A primeira versão aceita apenas quitação integral neste fluxo: unique por empenho no registro de quitação; novas ocorrências parciais ficam para revisão.
- Não usar somente número de nota como identificador: restringir por empresa, cliente, tipo e contexto da fatura.
- Usar relações para consultar documentos fiscais da fatura; ela pode possuir NFS-e e NF-e.
- Nenhuma exclusão em cascata deve apagar faturas, documentos fiscais ou recebimentos existentes. Preservar importações já executadas; usar ignorar/reabrir.
- Vínculos ativos de OS impedem concorrência entre sugestões aprovadas. Sugestões sozinhas não reservam registros; um lote resolve conflitos em ordem determinística antes da execução.

### Estados independentes

- Arquivo: `queued`, `processing`, `processed`, `needs_review`, `failed`.
- Conciliação: `unmatched`, `suggested`, `approved`, `ignored`.
- Faturamento: `pending`, `invoiced`, `confirmed`, `emission_queued`, `failed`.
- Linha de pagamento: `unmatched`, `matched`, `divergent`, `already_received`, `received`, `ignored`.
- Situação fiscal exibida diretamente do documento: não duplicar autorização/rejeição em um estado paralelo.

## 5. Extração dos PDFs

Criar `App\Services\Commitments\Pdf\PdfTextExtractor` e leitores específicos `ChapecoCommitmentParser` e `ChapecoPaymentReportParser`, retornando DTOs sem efeitos financeiros.

Escolha inicial: adaptador para `pdftotext` (Poppler), executado com argumentos seguros via Symfony Process, UTF-8 e layout preservado. Verificar disponibilidade no ambiente e documentar instalação em desenvolvimento/produção. Usar arquivo temporário privado, timeout, limite de páginas/tamanho configurável e remoção em `finally`. Não assumir que a ferramenta usada para analisar os PDFs nesta conversa está disponível na aplicação.

### Empenho

- Identificar o documento pelo título e campos esperados.
- Extrair número/ano do cabeçalho, sem confundir com pré-empenho.
- Extrair patrimônio do objeto resumido, com normalização de acentos e variações `PAT`, `PAT.` e `PATRIMÔNIO N°`.
- Extrair `Valor deste empenho`, retenção e valor líquido; conferir repetições do valor no documento.
- Extrair emissor e credor; validar compatibilidade dos documentos com cliente e empresa configurados. Inconsistência fica para revisão.
- Preservar número original e normalizado; não fazer correspondência parcial de patrimônio.

### Relatório de pagamentos

- Ler todo o documento mantendo estado de grupo de data e registro atual entre páginas.
- Descartar cabeçalhos/rodapés repetidos sem descartar continuação do objeto/documento fiscal.
- Diferenciar data de pagamento, emissão do empenho e emissão da nota.
- Separar cada empenho com valor e documentos fiscais associados; múltiplas notas na mesma ocorrência exigem revisão explícita.
- Extrair patrimônio quando disponível; ausência não impede buscar por empenho já vinculado.
- Conferir soma das linhas com total declarado do credor; totais repetidos de unidade/geral não são novos pagamentos.
- Bloquear baixa em lote enquanto houver inconsistência estrutural de extração/totais. Divergência de conciliação em uma linha não invalida outras quando a extração foi validada.
- Extração ambígua nunca deve retornar um resultado vazio como sucesso.

Não versionar os PDFs comerciais fornecidos. Criar fixtures sintéticas representativas para testes e validar os originais localmente com comando de diagnóstico sem faturar/baixar dados.

## 6. Serviços e integrações

Novos serviços em `app/Services/Commitments/`:

| Serviço | Responsabilidade |
| --- | --- |
| `ImportCommitmentFilesService` | Validar contexto, armazenar anexos, deduplicar e enfileirar arquivos |
| `SuggestCommitmentMatchesService` | Extrair PAT do nome do equipamento e sugerir OS elegíveis |
| `ApproveCommitmentMatchService` | Reservar vínculo exclusivo com revalidação e auditoria |
| `BillCommitmentService` | Faturar uma OS e confirmar a fatura com snapshot dos padrões |
| `QueueCommitmentFiscalEmissionService` | Encaminhar documentos existentes e registrar resultado recuperável |
| `MatchCommitmentPaymentsService` | Resolver empenho → OS/fatura → notas/contas/parcelas e divergências |
| `ReceiveCommitmentPaymentService` | Validar quitação, registrar pagamentos e guardar vínculo idempotente |

Jobs propostos: `ProcessCommitmentImportFileJob` e `ProcessCommitmentBillingBatchJob`. O lote de faturamento processa itens em sequência, cada empenho com transação própria. Uma falha não bloqueia os outros; o resumo mostra sucesso/falha individual. Locks e idempotência devem suportar timeout e reentrega do job.

### Reutilização do domínio existente

- Faturar por `InvoiceServiceOrderWorkflow::execute()` e obter a fatura existente/criada.
- Confirmar por `InvoiceService::confirm()`: esse serviço já cria documentos fiscais pendentes e contas a receber.
- Passar `nfse_service_id`, `nfse_item_description` e `nfse_additional_information`, conforme `Services/Invoice/Actions/ConfirmInvoiceAction.php`.
- Encaminhar NFS-e por `NfseDocumentService::emitir()`; acompanhar pelos estados existentes e retornos fiscais.
- Registrar baixas por `AccountReceivableService::registerInstallmentPayment()`; manter cálculo de saldos, movimentos financeiros e auditoria atuais.
- Adicionar origem `commitment_report` à validação financeira existente, incluindo rótulos e demais pontos de enumeração de origem.
- Reutilizar `AttachmentService`, `HasAttachments` e rotas autorizadas de anexos, registrando tipos específicos de PDF de empenho/relatório na configuração.

### Valor da ordem e requisições

O model atual distingue `total_amount`/`services_total_amount` de `grand_total_amount`, que inclui requisição vinculada. Implementar a comparação com o total geral da ordem apresentado ao usuário (`grand_total_amount`), incluindo serviços líquidos, deslocamento e requisição vinculada. Exibir a composição no detalhe para evitar ambiguidades.

O workflow existente anexa requisições à mesma fatura e pode gerar NF-e além da NFS-e. Antes de confirmar automaticamente uma fatura mista, validar se todos os dados fiscais exigidos estão disponíveis. Se não estiverem, manter a fatura pendente com diagnóstico e acesso à revisão normal; não omitir produtos nem transferir seu valor para a NFS-e. A NFS-e mantém a parcela de serviços calculada pelo domínio existente.

## 7. Transações, retomada e duplicidade

1. Aprovação trava empenho e OS, revalida empresa/cliente, patrimônio, valor, status e exclusividade e persiste o vínculo.
2. Faturamento e gravação de `invoice_id` ocorrem na mesma transação local. Converter retorno `false` de workflows em exceção para rollback; retorno falso de callback `DB::transaction()` não desfaz alterações por si só.
3. Confirmação e persistência da etapa ocorrem atomicamente, respeitando falhas sinalizadas pelos serviços existentes.
4. Encaminhamento fiscal é posterior ao commit, utilizando a fila fiscal existente. Não chamar provedor externo dentro da transação financeira.
5. Na retomada, conferir fatura e documentos já existentes; nunca recriar para contornar erro de emissão. Respeitar estados que bloqueiam reenvio e pendência de conciliação RPS.
6. Baixa trava empenho/quitação e títulos em ordem determinística, recalcula o saldo e valida data, valor e conta antes de chamar o serviço financeiro.
7. Para várias parcelas, registrar todas as baixas e vínculos em uma única transação por empenho. Falha em qualquer parcela desfaz a quitação completa.
8. Registrar criação de pagamentos e vínculo de idempotência na mesma transação. Linha repetida aponta para quitação existente.
9. Conta já recebida por ação manual, boleto, PIX ou recebimento automático deve ser identificada; nunca gerar uma segunda baixa.
10. Estornos usam o fluxo financeiro existente. A integração deve detectar vínculo cujo pagamento foi removido/estornado e encaminhar para revisão; não reabrir automaticamente para uma nova baixa.

## 8. Tenancy, autorização e auditoria

- Páginas/actions obtêm empresa pelo tenant atual e verificam permissão financeira.
- Serviços e jobs recebem `company_id`, `customer_id` e usuário explicitamente; não dependem de `Filament::getTenant()` dentro da fila.
- Escopar cada consulta de equipamento, OS, fatura, documento, conta, parcela, serviço e conta financeira.
- Validar acesso da empresa ao cliente e pertencimento de todas as referências recebidas em formulários.
- Policies para novos registros; ocultar menu não substitui autorização em actions/downloads.
- Registrar auditoria de importação, correção, aprovação, desvinculação, faturamento, emissão solicitada e recebimento.
- Não expor stack trace na tabela; apresentar mensagem operacional e manter detalhes técnicos no log com IDs de correlação.

## 9. Sequência de execução e entregáveis

Cada etapa entrega código verificável e atualiza este checklist. Ordem de implementação:

- [x] **1 — Base:** migrations, models, enums, policies, configuração e tipos de anexos; validar unicidade e tenancy.
- [ ] **2 — PDFs:** extrator, leitores, DTOs e comando de diagnóstico local; conferir os três empenhos, relatório e total declarado.
- [ ] **3 — Importação:** formulário múltiplo, histórico de arquivos, jobs individuais, progresso e reprocessamento.
- [ ] **4 — Conciliação:** listagem/detalhe, sugestões, correções, reserva exclusiva de OS e busca por abertura/valor/patrimônio.
- [ ] **5 — Faturamento:** configuração financeira/fiscal, prévia de corpo/rodapé, ação individual/lote, confirmação e retomada por etapa.
- [ ] **6 — Emissão:** encaminhamento pela fila existente e apresentação da situação real da NFS-e; validação do mapeamento de rodapé no emissor utilizado.
- [ ] **7 — Pagamentos:** importação do relatório, integridade dos totais, identificação de vínculos, revisão e baixa integral selecionada.
- [ ] **8 — Integração visual:** referências na OS, fatura e recebimentos; filtros, mensagens e navegação final.
- [ ] **9 — Homologação:** cenários ponta a ponta, documentação operacional, dependência Poppler e instruções de implantação.

Não executar faturamento, emissão ou baixa sobre dados reais para validar os parsers. Homologar as ações financeiras em ambiente apropriado e a emissão conforme ambiente fiscal configurado.

### Registro de execução — etapa 1 (19/10/2026)

- Migration `2026_10_19_000001_create_commitment_workflow_tables.php` cria as oito tabelas do domínio, com rollback em ordem inversa, índices de identidade e vínculos exclusivos de empenho/OS/fatura.
- Models e enums implementados, com scopes explícitos de empresa/cliente e validação de pertencimento dos vínculos. A empresa e o cliente de um registro persistido não podem ser transferidos.
- Configuração persistida pelo `CommitmentSettingsService`, com autorização explícita, validação de referências ativas da empresa, serialização da gravação e auditoria. A interface de configuração será integrada nas etapas de telas/faturamento.
- Policies registradas para os oito models concretos, usando `account_receivables.access` no painel administrativo e bloqueando exclusão pela interface para preservar histórico.
- Tipos `commitment_pdf` e `commitment_payment_report` registrados em anexos, com armazenamento privado local. Download/preview desses tipos exige vínculo ao arquivo de importação correto e permissão financeira ativa na empresa.
- Vínculos com pagamentos guardam snapshot e mantêm evidência quando um pagamento financeiro é removido; a referência passa a nula para revisão posterior.
- Verificação: `php artisan test tests/Feature/Commitments/CommitmentFoundationTest.php tests/Feature/Services/Attachments/AttachmentServiceTest.php tests/Feature/Services/Financial/FinancialAuthorizationTest.php` — **34 testes aprovados, 140 assertions**, sendo 15 testes novos do domínio.
- PHP alterado formatado com Pint; migration validada por criação e rollback/reaplicação no SQLite em memória dos testes.
- Próxima entrega: etapa 2, extração dos PDFs e diagnóstico dos arquivos de referência.

## 10. Verificação e critérios de aceite

### Testes automatizados relevantes

- Extração correta dos três exemplos de empenho por fixtures equivalentes.
- Relatório com datas herdadas, cabeçalhos repetidos, registros divididos entre páginas e total declarado.
- Valores com milhares, acentos e variações de patrimônio; patrimônio ausente/ambíguo.
- OS mais antiga por abertura, desempate por ID, valor em centavos e composição do total.
- Empresa/cliente incorretos, OS não encerrada, já faturada ou reservada para outro empenho.
- Mesmo arquivo e mesmo empenho reimportados; dois jobs tentando usar a mesma OS.
- Fatura individual, corpo com nome do serviço, rodapé com mensagem/PAT/empenho e snapshot de padrões.
- Falha de confirmação com rollback; falha fiscal com retomada sem nova fatura/documento.
- Fatura mista e dados fiscais incompletos com pendência explícita.
- Baixa compatível, múltiplas parcelas, saldo alterado depois da sugestão e rollback na segunda parcela.
- Relatórios sobrepostos, conta já recebida e recebimento removido/estornado.
- Políticas, ações em lote e acesso autorizado a PDFs entre tenants.

Executar testes focados por etapa (`php artisan test tests/...`), formatar PHP alterado com `vendor/bin/pint` e, após integração, executar `composer test`. Executar `npm run build` se houver mudanças de assets. Validar concorrência e locks no banco usado em produção, pois SQLite em memória não reproduz todas essas condições.

### Critérios de aceite funcional

1. Usuário importa vários empenhos e consulta o original e resultado de cada arquivo.
2. Para 14732/2026, o leitor apresenta patrimônio 92181 e R$ 190,00.
3. A sugestão seleciona a OS elegível mais antiga por abertura e permite revisão antes da execução.
4. Apenas empenhos selecionados são faturados, cada OS em sua própria fatura.
5. Corpo e rodapé da NFS-e seguem a configuração e o documento é encaminhado à fila de emissão existente.
6. Falhas são recuperáveis sem duplicar vínculos, faturas, documentos ou contas a receber.
7. O relatório identifica 14732/2026, NF 247 e pagamento em 27/08/2026, mostrando a cadeia de vínculos disponível.
8. Somente linhas selecionadas e compatíveis são baixadas; divergências permanecem para revisão.
9. Reimportar arquivos/relatórios não repete ações financeiras.
10. Todos os registros e PDFs respeitam acesso por empresa e mantêm histórico consultável.
