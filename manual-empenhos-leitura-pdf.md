# Empenhos — manual de leitura e diagnóstico de PDFs

## Dependência de execução

O leitor usa **Poppler / pdftotext** no servidor que executa o comando e, nas etapas de importação, nos workers da fila.

Ubuntu/Debian:

```bash
sudo apt-get install poppler-utils
pdftotext -v
```

Não há nova dependência Composer. O processo é executado pelo Symfony Process disponível no projeto, com argumentos separados, sem montar um comando de shell com o nome do PDF.

Para um executável fora do `PATH`, configurar:

```dotenv
COMMITMENTS_PDFTOTEXT_BINARY=/usr/bin/pdftotext
```

Os limites ficam em `config/commitments.php`: 20 MiB de arquivo, 100 páginas, 8 MiB de texto/saída e 30 segundos por extração. O PDF é copiado para um arquivo temporário privado, limitado em tamanho e removido ao terminar, inclusive em falhas. PDFs sem texto extraível exigem revisão/OCR externo; a primeira versão não realiza OCR.

## Comando de diagnóstico

O comando lê arquivos locais e apresenta o resultado. Não persiste importações nem executa faturamento, emissão ou baixa financeira.

### Notas de empenho

```bash
php artisan commitments:diagnose-pdf commitments "/caminho/primeiro empenho.pdf" "/caminho/segundo empenho.pdf"
```

Conferir também o cliente emissor e a empresa credora:

```bash
php artisan commitments:diagnose-pdf commitments "/caminho/empenho.pdf" \
  --issuer-document="83.021.808/0001-82" \
  --creditor-document="12.345.678/0001-95"
```

### Relatório de pagamentos

```bash
php artisan commitments:diagnose-pdf payments "/caminho/relatório.pdf" \
  --issuer-document="83.021.808/0001-82" \
  --creditor-name="EMPRESA EXEMPLO LTDA"
```

O layout do relatório fornecido identifica o credor por código e nome, sem seu CNPJ. Por isso `--creditor-document` aplica-se somente a notas de empenho e `--creditor-name` somente a relatórios.

Acrescentar `--json` para obter todos os campos estruturados, itens/documentos fiscais, páginas de origem, pendências e valores inteiros em centavos. A saída contém os dados do documento e deve ser tratada como informação do cliente.

### Resultado e códigos de saída

- `processed`: leitura reconhecida e conferências consistentes.
- `needs_review`: leitura disponível, mas há pendências de conferência.
- `failed`: arquivo ilegível, estrutura não reconhecida ou campos obrigatórios ausentes/ambíguos.

Códigos: `0` para todos os arquivos consistentes; `1` quando algum arquivo falha ou exige revisão; `2` para argumentos inválidos. Falha em um arquivo não impede diagnosticar os demais.

`processed` confirma a extração e as verificações solicitadas, não a existência de uma OS/fatura no banco. As opções de conferência de emissor/credor são opcionais no diagnóstico; a integração de importação deve fornecer o contexto da empresa e do cliente.

## Contratos para integração

- `PdfTextExtractor::extract($path)` retorna `ExtractedPdf`, preservando páginas e texto com layout.
- `ChapecoCommitmentParser::parse(...)` retorna `CommitmentData`.
- `ChapecoPaymentReportParser::parse(...)` retorna `PaymentReportData`, com `PaymentReportLineData` para cada ocorrência.
- Leitores possuem `VERSION`, a persistir em `parser_version` quando a importação for implementada.
- `toArray()` usa nomes como `amount_cents`, `net_amount_cents` e `declared_total_cents`: são **centavos**, não reais.
- Ao persistir via models que usam `MoneyCast`, converter centavos para a unidade esperada pelo cast; não atribuir `40000` ao atributo `amount` para representar R$ 400,00, pois isso multiplicaria o valor novamente.
- Problemas estruturais lançam `PdfReadingException`. Inconsistências revisáveis ficam em `issues`.
- No relatório, `issues` do documento indicam problemas gerais de integridade (totais, emissor, duplicidades). As linhas têm `issues` próprios, por exemplo múltiplas notas ou valor fiscal diferente do pagamento. `requiresReview()` sinaliza qualquer uma dessas pendências.
- Patrimônio ausente em uma linha do relatório não invalida a extração: o vínculo pode ser resolvido pelo empenho. Patrimônio ausente ou ambíguo na nota de empenho exige revisão antes de buscar a OS automaticamente.
- Não deduzir patrimônio por placa, valor ou outro número solto no objeto.

## Verificação automatizada

```bash
php artisan test tests/Unit/Services/Commitments tests/Feature/Commitments/PdfTextExtractorTest.php tests/Feature/Commitments/DiagnoseCommitmentPdfCommandTest.php
```

Fixtures de texto são sintéticas. Os testes de integração geram PDFs temporários e verificam Poppler, limites e limpeza dos arquivos. Cenários que dependem da extração real são marcados como ignorados se o executável não estiver instalado; os testes dos parsers e do comando continuam executáveis.

## Importação pelo painel administrativo — etapa 3

### Preparação do ambiente

Aplicar as migrations disponíveis:

```bash
php artisan migrate
```

O processamento utiliza a fila padrão. O worker precisa ter Poppler instalado e acesso ao mesmo armazenamento privado local usado pelos uploads:

```bash
php artisan queue:work --queue=default --tries=1 --timeout=90
```

O `composer dev` existente já escuta a fila padrão. Após implantar alterações de jobs/serviços, reiniciar os workers conforme o procedimento do ambiente.

### Como importar

1. Acessar a empresa no painel `/admin`.
2. Abrir **Financeiro → Empenhos** ou **Financeiro → Pagamentos de empenhos**.
3. Em **Configurar cliente**, selecionar o cliente e habilitar o fluxo. Os campos fiscais e financeiros podem ser preparados para as próximas etapas.
4. Clicar em **Importar empenhos** para enviar até 20 PDFs, ou **Importar relatório** para enviar um PDF de pagamentos.
5. Conferir o resumo de arquivos aceitos, duplicados e rejeitados no detalhe do lote.

Os acessos ficam no grupo Financeiro do menu principal, junto dos demais recursos financeiros. URLs diretas no painel administrativo:

- `/admin/{tenant}/commitment-imports`
- `/admin/{tenant}/commitment-payment-imports`

Substituir `{tenant}` pelo identificador da empresa presente na URL atual do painel. Os dois links exigem permissão de contas a receber na empresa.

O limite da aplicação é 20 MiB por PDF; limites de upload do PHP, servidor e Livewire também se aplicam. Os nomes originais são mantidos no histórico. O original fica em `storage/app/private` pelo disco `local`, acessível pelas rotas autorizadas de anexos.

### Histórico e consulta

As listas mostram cliente, situação do lote, quantidade de arquivos finalizados, duplicados, rejeitados, data e usuário. As tabelas atualizam a cada cinco segundos. No detalhe do lote, **Atualizar progresso** recarrega também o resumo da situação.

Em cada arquivo:

- **Dados extraídos**: informações do empenho ou tabela de pagamentos, pendências, páginas e texto original.
- **Visualizar PDF / Baixar PDF**: consulta do original, com autorização financeira da empresa.
- **Reprocessar**: nova leitura do mesmo original, quando permitida.

Reenvio de um arquivo idêntico não agenda outra execução: o novo lote registra a duplicidade e oferece link ao lote original. Um PDF diferente que declare um empenho já existente fica para revisão e não substitui o empenho anterior.

### Recuperação de falhas

Uma falha de leitura fica no arquivo, sem interromper os outros jobs. Após corrigir a causa, usar **Reprocessar**. Entradas que ficaram paradas na fila ou no processamento por mais de cinco minutos também podem ser recuperadas pela ação.

A execução utiliza um token por geração: um job antigo não pode assumir a nova execução nem sobrescrever seu resultado. O processamento é idempotente em reentregas. Dados com revisão manual, aprovação, faturamento ou recebimentos vinculados ficam protegidos contra reprocessamento.

Pendências extraídas ficam disponíveis para revisão nas etapas de conciliação. Nesta entrega, a importação persiste os documentos e resultados; o faturamento e os recebimentos serão acionados pelas etapas específicas do fluxo.

### Conferências de partes

Na importação de empenhos, o CNPJ do emissor é comparado ao documento do cliente e o CNPJ do credor ao documento da empresa. No relatório, o credor só está disponível por nome/código: o nome é comparado ao nome cadastrado da empresa. Diferenças permanecem como pendências para revisão.
