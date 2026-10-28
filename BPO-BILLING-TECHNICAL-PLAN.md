# Plano técnico de evolução do BPO

## Faturamento direto, NFS-e Nacional e operação financeira

**Tornedon · Versão 1.0 · 25 de outubro de 2026**

**Referência de código:** painel BPO entregue no commit `6c5066de`.

**Situação:** plano original de referência. A arquitetura de origem avulsa das fases 0–8 foi substituída pela revisão abaixo; as fases posteriores continuam como backlog e exigem desenvolvimento/homologação.

### Revisão de arquitetura — venda rápida com origem operacional

A decisão posterior é iniciar em **Nova venda**, usando uma OS simplificada para serviços ou uma requisição para produtos. Na finalização, o fluxo existente cria a fatura, prepara o documento e cria/vincula o financeiro conforme a política escolhida. A emissão pode ser solicitada imediatamente ou depois; estoque é opcional por modelo.

O contrato e a situação atual estão em **`BPO-QUICK-SALES.md`**. Não implementar `invoice_items`, resolvedor de origem direta ou documento fiscal como rascunho comercial com base nas seções originais a seguir. Os itens continuam exclusivamente nas entidades operacionais; os totais, PDFs e indicadores aproveitam essa origem. O texto restante preserva a proposta original para referência e o backlog de recuperação, cobrança, homologação e fases 9–13.

### Decisões confirmadas

- Um usuário pode acumular papéis e atuar em várias empresas.
- Atendimento BPO é autorizado por empresa, sem cadastro de escritório BPO.
- As empresas continuam podendo operar diretamente nos painéis existentes.
- Painel BPO com tema próprio e experiência desktop.
- Emissão avulsa de NFS-e Nacional, sem exigir ordem de serviço.
- Gerar conta a receber por padrão; oferecer também somente nota e vínculo com financeiro existente.
- Organização de pagamentos sem execução bancária; aprovação do cliente opcional por empresa.
- Prioridade bancária: Sicoob, Sicredi e Bradesco.

### Organização do plano

**Fases 0–8:** central de faturamento, emissão, financeiro, cobrança e implantação.

**Fases 9–13:** recorrências, documentos e tarefas, portal, fechamento e migração assistida.

Os nomes de classes, tabelas e campos novos são propostas de implementação. A fase 0 fecha seus contratos e a revisão fiscal vigente, preservando as convenções do projeto.

<!-- pagebreak -->

## 1. Escopo e decisões operacionais

### Objetivo da próxima entrega

Permitir que o operador abra uma empresa autorizada, prepare um faturamento avulso, emita NFS-e, acompanhe o retorno, gere ou vincule o financeiro e prepare uma cobrança sem repetir cadastros ou lançamentos.

### Fluxo recomendado

1. Selecionar empresa e cliente.
2. Selecionar serviço, competência, descrição e valor.
3. Escolher o tratamento financeiro e revisar vencimentos.
4. Salvar rascunho ou confirmar e solicitar emissão.
5. Acompanhar autorização, rejeição ou resultado ainda incerto.
6. Preparar cobrança, obter documentos e acompanhar recebimento.

| Modo financeiro | Comportamento proposto |
| --- | --- |
| Gerar recebível — padrão | Criar a obrigação na confirmação comercial, antes do envio fiscal, em transação local. |
| Vincular recebível existente | Associar obrigação compatível, sem criar outra ou alterar pagamentos já registrados. |
| Somente emitir nota | Manter documento fiscal e rastreabilidade comercial sem gerar título ou cobrança automática. |

**Decisão técnica recomendada:** o recebível representa a obrigação comercial, não o sucesso da transmissão fiscal. Por isso nasce na confirmação do faturamento. Uma nota rejeitada mantém o título e vira pendência fiscal. O cancelamento da obrigação é uma ação de negócio separada.

**Cobrança automática:** por padrão, aguardar a autorização fiscal. Um título em aberto não é confirmação de pagamento. Não registrar baixa só porque a nota autorizou, o boleto foi gerado ou o vencimento chegou.

### Primeiro cenário de emissão

Começar com um serviço/contexto tributário por NFS-e Nacional. A tabela de itens permite evolução, mas serviços com códigos ou tratamentos incompatíveis não serão consolidados silenciosamente no mesmo bloco nacional.

<!-- pagebreak -->

## 2. Diagnóstico do código e pontos críticos

| Evidência atual | Consequência para o plano |
| --- | --- |
| `Invoice::resolveAmounts()` usa OS e requisições. | Fatura avulsa precisa de itens próprios e resolvedor de origem. |
| `ConfirmInvoiceAction` rejeita fatura sem OS/requisição e já cria recebíveis. | Adaptar confirmação por origem; não criar OS fictícia. |
| `InvoiceService::createFiscalDocument()` monta itens a partir dos vínculos operacionais. | Acrescentar fonte direta para o item fiscal e a descrição. |
| Webhook, `ConsultNfseAction` e `ConsultNfeAction` chamam geração financeira. | Todos devem respeitar uma política financeira persistida e o mesmo coordenador idempotente. |
| `InvoiceObserver` pode agendar cancelamento de boleto; observers também programam comunicações. | Mapear efeitos antes de acrescentar novos jobs ou envios. |
| `financial_control_mode` já aparece em fluxo de entrada fiscal. | Não reutilizar o campo para faturamento de saída sem analisar sua semântica. |
| A NFS-e Nacional atual é V1, com restrições documentadas. | Definir e homologar uma matriz de cobertura real. |
| Worker de grupo possui timeout de 900 s e lock de 300 s. | Rever duração, renovação ou fencing para evitar sobreposição após expiração do lock. |

### Autoridade dos dados

O cabeçalho comercial define cliente, valor e política financeira. `FiscalDocument` define o documento fiscal. `AccountReceivable` define a obrigação. Boleto/Pix definem a cobrança. Pagamentos e movimentos representam liquidação efetiva.

Uma falha em uma dessas etapas não deve fabricar sucesso nas demais nem apagar evidências já confirmadas.

### Compatibilidade

Faturas existentes permanecem no caminho operacional atual. Os campos novos devem ter padrão compatível ou valor nulo equivalente ao comportamento anterior. A ativação da nova origem e das novas regras ocorre por fluxo explícito, sem reinterpretar o histórico automaticamente.

<!-- pagebreak -->

## 3. Arquitetura proposta

### Agregado comercial

Usar `Invoice` como cabeçalho de faturamento, acrescentando a origem `manual_bpo` e uma relação própria de itens. O rascunho é a própria fatura pendente; não criar outra entidade duplicando o mesmo cabeçalho.

| Componente proposto | Responsabilidade |
| --- | --- |
| `InvoiceSourceResolver` | Selecionar fonte operacional ou direta e devolver itens/totais normalizados. |
| `DirectInvoiceSource` | Ler itens próprios da fatura avulsa. |
| `OperationalInvoiceSource` | Adaptar OS/requisições preservando o comportamento existente. |
| `BpoBillingWorkflow` | Validar acesso, confirmar a operação local e solicitar processamento. |
| `InvoiceFinancialCoordinator` | Aplicar gerar, vincular, somente fiscal ou comportamento legado. |
| `BillingOperation` | Intenção durável, identidade da solicitação, revisão, ator e recuperação. |
| `FiscalResultProcessor` | Aplicar retornos normalizados sem duplicar efeitos. |
| `BillingChargeCoordinator` | Preparar cobranças do recebível correto após a condição fiscal configurada. |

### Limites transacionais

**Transação local:** bloquear fatura, validar revisão, persistir snapshot, documento, financeiro e intenção de envio. Retorno `null`/`false` de um serviço deve provocar falha e rollback do conjunto.

**Chamada externa:** executar fora da transação de banco, a partir de intenção já gravada. Não manter bloqueios de linhas durante uma requisição ao provedor.

**Retorno externo:** aplicar em nova transação curta, com verificação de identidade e estado. Processamento repetido deve produzir o mesmo resultado.

### Fluxo lógico

Rascunho → confirmação comercial → documento preparado + financeiro definido → intenção persistida → envio → consulta/webhook → finalização → cobrança/documentos.

Fiscal, financeiro, cobrança e entrega mantêm estados independentes, apresentados juntos pela interface.

<!-- pagebreak -->

## 4. Modelo de dados e migrations

### Alterações propostas em `invoices`

| Campo | Finalidade |
| --- | --- |
| `billing_origin` | Origem operacional legada ou `manual_bpo`. |
| `financial_policy` | `legacy`, `create_receivable`, `link_existing` ou `fiscal_only`. |
| `billing_revision` | Controle de concorrência e versão do rascunho. |
| `financial_target_id` | Referência ao recebível escolhido no modo de vínculo. |
| `billing_snapshot` | Snapshot versionado do que foi comercialmente confirmado. |
| `billing_confirmed_at` | Marco imutável da confirmação do novo fluxo. |

### Nova tabela `invoice_items`

`company_id`, `invoice_id`, `service_id`, posição, descrição, quantidade, preço, desconto, total e autoria. Acrescentar snapshots de descrição/código fiscal e dos valores utilizados. A relação é exclusiva da origem direta na primeira entrega.

Valores monetários usam centavos como autoridade. Quantidades podem ter escala decimal explícita; a multiplicação e o arredondamento precisam seguir um único contrato testado. O total financeiro deve separar valor bruto, descontos e retenções suportadas.

### Nova tabela `billing_operations`

`company_id`, `invoice_id`, tipo, revisão, UUID da solicitação, hash do conteúdo, ator solicitante, origem da autorização, ambiente, estado, identificação da tentativa, documento fiscal, recebível, erro sanitizado e timestamps de preparo/envio/conclusão.

Índices propostos: unicidade da solicitação por empresa; unicidade da operação de confirmação por fatura/revisão; busca por estado e idade para recuperação.

### Eventos e rastreabilidade

Criar `billing_integration_events` ou estrutura equivalente para retornos fiscais: provedor, ambiente, documento, identidade/hash do evento, payload mínimo necessário, situação de processamento e erro. A unicidade de evento complementa — não substitui — a idempotência dos efeitos.

Para vinculação financeira, guardar antes/depois e a origem da associação. Não remover vínculos ou documentos com exclusão em cascata de um rascunho já confirmado. Novas constraints devem tratar apenas a nova origem; não presumir unicidade histórica em toda a tabela de recebíveis.

<!-- pagebreak -->

## 5. Estados, invariantes e prevenção de duplicidade

### Estados separados

| Eixo | Estados de referência |
| --- | --- |
| Comercial | Rascunho, confirmado, cancelado. |
| Fiscal | Preparado, na fila, enviando, em processamento, autorizado, rejeitado, cancelamento pendente, cancelado, resultado incerto. |
| Financeiro | Não aplicável, pendente de geração, gerado, vinculado, falha de integração, resolução pendente. |
| Cobrança | Não solicitada, preparada, em processamento, registrada, rejeitada, incerta, cancelamento pendente, liquidada. |
| Entrega | Pendente, disponível, enviada, falhou. |

São estados de domínio propostos; mapear os enums atuais antes de criar novos valores persistidos. Não colocar todos esses estados em `Invoice.status`.

### Invariantes obrigatórios

1. Empresa e ator vêm do contexto autorizado do servidor.
2. Uma confirmação aceita possui uma identidade durável e um snapshot.
3. A mesma solicitação com conteúdo diferente é recusada; não atualiza silenciosamente uma intenção já aceita.
4. Uma nota autorizada não gera outro recebível por nova consulta ou webhook repetido.
5. `fiscal_only` permanece sem geração automática, inclusive nos callbacks.
6. Um título existente oculto pelo alcance do operador ainda conta na verificação de integridade.
7. Timeout após envio não autoriza criar novo documento, nova identificação ou nova numeração sem consulta.
8. Autorização fiscal não registra pagamento; cobrança registrada também não registra pagamento.
9. Fatos financeiros e fiscais confirmados não são desfeitos por eventos antigos ou falhas de e-mail.

### Identidade e bloqueios

Combinar UUID da solicitação, revisão/hash do conteúdo, índices únicos e `lockForUpdate()`. Definir ordem de bloqueio: fatura → operação → cabeçalho financeiro → parcelas, coordenada com a ordem já utilizada pelos serviços financeiros.

O coordenador de integridade faz consultas explícitas por empresa e fatura, mesmo se precisar ignorar somente o scope de visibilidade do usuário. Não retornar detalhes de um título ao qual o operador não tem acesso; apresentar uma pendência neutra para revisão autorizada.

<!-- pagebreak -->

## 6. Fase 0 — contratos, cobertura e linha de base

**Objetivo:** fechar as decisões que afetam emissão, valores, permissões e efeitos já existentes.

### Atividades

- Catalogar confirmação da fatura, geração manual de recebíveis, autorização por polling/webhook, cancelamento e comunicações dos observers.
- Registrar os campos realmente enviados pelo builder Nacional e as restrições do validador V1.
- Confrontar leiaute, regime, serviço, retenções, ambiente e regras do emitente com a documentação vigente e o provedor.
- Definir a regra de total bruto, líquido financeiro, competência, vencimento e desconto.
- Fechar o contrato das três políticas financeiras e o marco de geração na confirmação comercial.
- Confirmar as capacidades bancárias por banco, modalidade e contrato; separar OFX de cobrança e de importação automática de extrato.
- Documentar os pontos que hoje retornam erro sem lançar exceção e como o workflow assegura rollback.
- Identificar todos os consumidores de totais e descrições de `Invoice`, inclusive PDFs, relatórios e exportações.

### Arquivos de referência

`Invoice.php`, `InvoiceService.php`, `ConfirmInvoiceAction.php`, `GenerateInvoiceAccountReceivablesAction.php`, `AccountReceivableGenerationService.php`, `NfseDocumentService.php`, `NfeWebhookController.php`, `ConsultNfseAction.php`, `ConsultNfeAction.php` e observers de fatura/documento.

### Entregáveis

1. Diagrama dos fluxos atuais e dos novos pontos de extensão.
2. Matriz fiscal de cenários suportados, bloqueados e pendentes de homologação.
3. Matriz de permissões por comando.
4. Contrato de estados, chaves idempotentes e ordem de locks.
5. Lista de regressões existentes a preservar.

**Aceite:** nenhuma operação do fluxo novo fica com dois responsáveis independentes por criar o financeiro ou enviar a mesma comunicação. O primeiro conjunto fiscal liberado tem requisitos verificáveis, sem promessa genérica de cobertura nacional completa.

**Dependências:** acesso à documentação do provedor e, para homologação, emitente de teste válido. O plano e a estrutura não dependem de haver uma carteira comercial confirmada.

<!-- pagebreak -->

## 7. Fase 1 — dados, origem da fatura e autorização

**Objetivo:** permitir fatura avulsa real, sem OS artificial, mantendo faturas operacionais compatíveis.

### Alterações

- Criar migrations aditivas para os campos de fatura, `invoice_items`, operações e eventos.
- Criar `InvoiceItem`, relações por empresa e validação do vínculo do serviço selecionado.
- Introduzir DTO de itens e totais e as implementações do resolvedor de origem.
- Alterar `Invoice::resolveAmounts()` para reconhecer primeiro a origem direta; manter a fórmula operacional como caminho separado.
- Adaptar montagem do item fiscal e descrições ao resolvedor, sem somar simultaneamente itens diretos e itens operacionais.
- Registrar `InvoiceItem` como filho de `Invoice` no catálogo de permissões.
- Acrescentar as permissões específicas de vinculação financeira e emissão sem recebível, com checagem efetiva nos comandos.
- Guardar autoria e referência de atendimento usada na solicitação.

### Regras

Uma fatura não pode misturar as duas fontes na primeira entrega. Dados históricos sem a nova origem seguem o caminho legado. O vínculo do item com a fatura é imutável fora de um fluxo explícito. Quantidade, preço e desconto são recalculados no servidor.

### Testes significativos

- Totais de fatura direta com desconto e quantidade fracionada suportada.
- Ausência de OS/requisição não torna a fatura direta inválida.
- Valores de faturas operacionais permanecem iguais aos atuais.
- Fonte mista é recusada.
- Serviço ou item de outra empresa é recusado.
- Migração pode ser aplicada sem reinterpretar os registros anteriores.

**Aceite:** criar, consultar e editar um rascunho avulso com total correto; a suíte operacional continua passando. Ainda não liberar envio externo nesta fase.

**Dependência:** contratos da fase 0 aprovados tecnicamente.

<!-- pagebreak -->

## 8. Fase 2 — bancada de faturamento e rascunhos

**Objetivo:** entregar a experiência de preparação e revisão no tema desktop do BPO.

### Páginas e componentes propostos

- `BillingWorkspace`: lista de faturamentos, busca, abas e pendências.
- `CreateBilling` / `EditBilling`: cliente, serviço, valores, competência e financeiro.
- `ViewBilling`: linha do tempo comercial, fiscal, financeira e de entrega.
- `BillingWorkbench`: resumo da empresa/cliente ao lado do formulário e revisão final.
- `BillingReadinessService`: diagnóstico dos requisitos do emitente, serviço e tomador.

### Interface

1. Empresa identificada no cabeçalho e sem campo livre para trocar `company_id`.
2. Cliente pesquisável somente no contexto autorizado; cadastro rápido por serviço existente.
3. Um serviço/contexto fiscal no primeiro cenário, com favoritos por empresa/usuário.
4. Descrição editável, competência, quantidade e valores claros.
5. Financeiro em cartões: gerar, vincular existente ou somente nota.
6. Prévia de parcelas, valor líquido e opção de cobrança após autorização.
7. Salvar rascunho, revisar e confirmar em ações distintas.

### Alterações no painel

Incluir Faturamento em `BpoNavigation`, registrar as páginas no provider e ampliar o tema para a bancada. A página atual `FiscalDocuments` continua representando documentos; passa a oferecer vínculos para o faturamento de origem quando autorizado.

### Concorrência e validação

Usar revisão otimista do rascunho. Duas abas não podem sobrescrever mudanças sem detecção. Modelos restaurados pelo Livewire são relidos e autorizados antes de cada ação. A revisão final usa dados recalculados, não os totais enviados pelo navegador.

**Aceite:** operador prepara e retoma um rascunho sem navegar por OS; erros apontam para os campos relevantes; consulta, criação e edição obedecem aos alcances configurados.

<!-- pagebreak -->

## 9. Fase 3 — confirmação e geração financeira padrão

**Objetivo:** confirmar uma única operação local e preparar a emissão com financeiro rastreável.

### Sequência transacional

1. Resolver empresa, usuário e autorização atual; validar o atendimento quando a solicitação vier do BPO.
2. Bloquear a fatura e conferir origem, revisão, estado e hash esperado.
3. Reservar/encontrar `BillingOperation` pela identidade idempotente.
4. Recalcular e congelar itens, totais, cliente, configuração fiscal aplicável e política financeira.
5. Preparar exatamente um documento fiscal para a revisão aceita.
6. Executar `InvoiceFinancialCoordinator`.
7. Persistir vínculos e intenção de processamento e confirmar a transação.
8. Solicitar o processamento após commit; uma rotina de recuperação cobre a falha entre commit e dispatch.

### Modo padrão: gerar recebível

Usar `AccountReceivableService` com a empresa, cliente, principal líquido, parcelas, categorias e vencimentos confirmados. Guardar o identificador resultante na operação e o vínculo com a fatura/documento.

Se uma tentativa posterior encontrar o mesmo resultado, devolvê-lo sem criar outro. Se encontrar financeiro incompatível ou parcialmente produzido, parar em pendência de integridade; não interpretar apenas a ausência de registros visíveis ao operador como autorização para recriar.

### Mudanças no código existente

Adaptar `ConfirmInvoiceAction` à origem da fatura e delegar o tratamento financeiro ao coordenador. Preservar a política legada dos fluxos anteriores. Os jobs de cobrança hoje agendados na confirmação precisam respeitar o gatilho fiscal definido para a nova origem.

Os serviços podem manter seus contratos públicos de erro, mas o workflow deve lançar uma falha de domínio quando um passo obrigatório retornar `null`/`false`, garantindo rollback de toda a preparação.

**Aceite:** duplo clique e requisições concorrentes resultam em uma confirmação, um documento preparado e um conjunto financeiro. Falha após criar o cabeçalho não deixa itens ou títulos órfãos.

<!-- pagebreak -->

## 10. Fase 3 — vínculo existente e somente nota

### Vincular financeiro existente

Criar `LinkExistingReceivableAction` e uma busca de títulos autorizados. Na primeira entrega, selecionar um cabeçalho financeiro existente, com suas parcelas, sem agregação arbitrária de vários títulos.

Validar sob lock:

- Mesma empresa e cliente, título não cancelado e autorização de vinculação.
- Vínculo com fatura vazio ou já associado à mesma operação; rejeitar conflito com outro faturamento.
- Principal original compatível com o líquido comercial, separando juros, multa e descontos de liquidação.
- Composição de parcelas e situação das cobranças apresentadas para revisão autorizada.
- Ausência de criação financeira anterior pertencente à mesma operação.

Um título parcial ou totalmente recebido pode receber vínculo fiscal se sua obrigação original for compatível. Isso **não** reabre saldo, altera pagamento, muda datas históricas ou gera cobrança pelo valor original. Divergências exigem conciliação de negócio separada.

Guardar a associação anterior e a nova na auditoria. Não substituir cliente, principal ou forma de pagamento só para fazer o vínculo passar.

### Somente emitir a nota

Persistir `financial_policy = fiscal_only` na confirmação. O coordenador deve concluir o eixo financeiro como não aplicável, sem título e sem cobrança automática.

Webhook, consulta, retentativa, reprocessamento e obtenção de anexos precisam reconhecer esse modo. Não basta esconder o botão financeiro na interface.

### Mudança de decisão depois da confirmação

A política aceita não é editada silenciosamente. Gerar financeiro depois de uma emissão somente fiscal requer uma ação posterior identificada e autorizada, mantendo a história da decisão original. Pode ficar para uma ampliação após o primeiro fluxo estável.

**Aceite:** vincular um título existente não aumenta a quantidade de obrigações nem o dinheiro recebido; somente nota continua sem financeiro mesmo após múltiplos retornos autorizados.

<!-- pagebreak -->

## 11. Fase 4 — emissão assíncrona e resultado incerto

**Objetivo:** enviar ao provedor com identidade estável, autorização atual e recuperação segura.

### Reuso e alterações

Reutilizar `NfseDocumentService`, `FiscalEmissionPreflightService`, o builder Nacional e as actions fiscais. Acrescentar a operação comercial como contexto persistido, em vez de usar `updated_by` como identidade definitiva do solicitante.

Revisar `ProcessQueuedNfseEmissionJob` e as entradas alternativas, incluindo `SendNfseJob`, para compartilhar a mesma reserva de envio por documento. Um caminho antigo não pode contornar a proteção do novo fluxo.

### Antes do envio

- Validar usuário solicitante, papel, alcance e atendimento que sustentou a solicitação BPO.
- Verificar ambiente, emitente, revisão congelada, cancelamentos locais e estado atual.
- Reservar uma tentativa com identificador e posse verificável.
- Gerar/reservar numeração no ponto correto, segundo o contrato fiscal e o serviço já utilizado.
- Congelar a identificação externa e o payload válido antes de transmitir.

### Falhas e concorrência

Timeout ou queda depois de transmitir: marcar resultado incerto, consultar o documento pela identidade original e só então decidir o próximo passo. Não reservar outra numeração automaticamente.

**Correção de rejeição:** manter a identidade comercial e financeira; registrar uma versão fiscal do payload e quais campos foram corrigidos. Mudanças de cliente, principal ou vencimentos depois da confirmação exigem revisão comercial e os guards financeiros, especialmente quando há cobrança ou pagamento. No primeiro fluxo, bloquear alterações estruturais pelo editor fiscal. Documento autorizado não volta a ser rascunho por edição local.

O lock de grupo atual pode expirar antes do timeout do worker. Usar lease renovável com duração coerente e/ou fencing durável por tentativa. A resposta de um worker antigo não deve sobrescrever a tentativa atual. Uma autorização real recebida tardiamente precisa ser conciliada, não descartada apenas por ser antiga.

### Revogação de acesso

Antes de qualquer novo envio ou retentativa solicitada pelo operador, revalidar a autorização. Depois que uma transmissão já ocorreu, continuar processando seu retorno como fato da integração, mesmo se o usuário perdeu acesso.

**Aceite:** reentrega do job não emite outra nota; acesso revogado bloqueia novo envio; uma falha de rede não libera edição/reenvio cego de documento possivelmente autorizado.

<!-- pagebreak -->

## 12. Fase 5 — retornos, finalização e recuperação

**Objetivo:** unificar efeitos de webhook e polling, sem alterar o contrato público do webhook fiscal.

### Processamento de retorno

1. Validar o mecanismo de assinatura já utilizado e resolver emitente, ambiente e documento.
2. Registrar evento mínimo para processamento, quando o banco estiver disponível.
3. Normalizar a resposta e bloquear o documento/operação correspondente.
4. Aplicar transição compatível sem regressão de estado terminal.
5. Finalizar os efeitos por uma única rotina idempotente.
6. Programar documentos e cobranças aplicáveis, sem repetir o que já concluiu.

`NfeWebhookController`, `ConsultNfseAction` e `ConsultNfeAction` passam pelo mesmo processador/coordenador para o fluxo novo. Os fluxos legados mantêm tratamento compatível, sob regressão.

### Contrato HTTP 200

O webhook continua retornando HTTP 200 inclusive em erros internos. Por isso, não podemos depender de nova tentativa do provedor para toda falha. Criar recuperação local dos eventos gravados e polling dos documentos sem conclusão.

Se o banco estiver indisponível e o evento não puder ser persistido, manter registro operacional sanitizado e recuperar por consulta ao provedor. O retorno 200 não é garantia de que o evento entrou no inbox.

### Rotinas propostas

- `billing:recover-pending`: localizar intenções antigas sem dispatch/conclusão e reservar sua retomada.
- `billing:reconcile-financial`: identificar nota autorizada com financeiro pendente/incompatível.
- Reprocessamento de evento por identificador, com auditoria e efeito único.

### Finalização financeira

O recebível padrão já foi criado na confirmação. O retorno o reconhece e vincula, sem recriar. Falha financeira durável vira pendência rastreável. `fiscal_only` continua não aplicável. Um título existente mantém seu histórico.

**Aceite:** webhook e polling concorrentes produzem um mesmo resultado; autorização seguida de evento antigo não reabre a emissão; commit sem dispatch é recuperável.

<!-- pagebreak -->

## 13. Fase 6 — boleto, Pix e recebimento

**Objetivo:** preparar cobrança sobre a obrigação correta, com retomada independente da nota fiscal.

### Mudanças

- Criar `BillingChargeCoordinator` para selecionar parcelas, modalidade, conexão bancária e saldo atual.
- Reutilizar as entidades, providers, jobs, estados e recuperação bancária já existentes.
- Encaminhar o novo fluxo pela autorização fiscal por padrão, evitando os agendamentos antecipados da confirmação comercial.
- Guardar referência da cobrança e tentativa na operação de faturamento.
- Exibir ação Retomar cobrança quando a nota estiver correta e a cobrança não tiver concluído.

### Regras financeiras

Cobrar apenas o saldo aplicável, considerando pagamentos parciais e cobranças existentes. Vincular título já quitado não gera boleto/Pix. Não registrar nova cobrança sem resolver resultado incerto anterior. Não cancelar títulos ou pagamentos porque um boleto foi rejeitado.

Confirmar recebimento usando os serviços bancários e financeiros compartilhados. Identificação, valor, conta e origem devem ser compatíveis para não repetir baixa já registrada por outro canal. Conflitos vão para revisão.

### Cobertura bancária

| Banco | Encaminhamento |
| --- | --- |
| Sicoob | Homologar boleto/Pix nos contratos e capacidades efetivamente disponíveis. |
| Sicredi | Homologar boleto/Pix, credenciais, identificações e callbacks. |
| Bradesco | OFX já é uma capacidade separada. Confirmar provedor/contrato de cobrança; não anunciar integração automática antes dessa validação. |

### Parcelas e escolhas

Não emitir duas modalidades para o mesmo saldo sem uma regra explícita de cobrança alternativa e cancelamento. Cada parcela tem sua identidade; falha em uma parcela não refaz cobranças já registradas nas demais.

**Aceite:** nota autorizada e cobrança falha podem ser retomadas separadamente; liquidação repetida não cria dois movimentos; capacidades indisponíveis ficam fora das opções selecionáveis.

<!-- pagebreak -->

## 14. Fase 7 — documentos, comunicação e cancelamentos

### Documentos e envio

Reutilizar as actions de PDF/prévia e `StoreFiscalDocumentAttachmentsAction`. A prévia deve ser identificada como rascunho. Documento autorizado usa o artefato fiscal disponibilizado pelo provedor, com identificação e protocolo corretos.

Disponibilizar PDF/XML quando o provedor e o tipo de documento permitirem. Falha de obtenção de arquivo vira pendência de documento; nunca causa nova emissão.

Integrar com `DocumentNotificationService` e `EmailDispatch`, preservando as rotas autenticadas/assinadas. Definir uma chave durável de entrega por documento, versão, canal, destinatário e finalidade. Observers e ações explícitas não podem enviar a mesma comunicação duas vezes sem intenção de reenvio.

### Cancelamento fiscal

Autorizar ação específica, verificar estado e requisitos do provedor e registrar justificativa. Aceite da solicitação não equivale a cancelamento confirmado. Guardar estado pendente e consultar quando necessário.

### Reflexo financeiro independente

| Situação | Tratamento |
| --- | --- |
| Título sem pagamento e sem cobrança ativa | Permitir cancelamento explícito pela regra financeira. |
| Cobrança ativa | Tratar/cancelar a cobrança e confirmar o resultado antes de concluir a resolução financeira correspondente. |
| Pagamento parcial ou integral | Preservar pagamentos; encaminhar revisão de crédito, devolução ou obrigação remanescente. |
| Nota cancelada e financeiro ainda pendente | Mostrar os dois estados e a ação necessária; não esconder a divergência. |

Não apagar o faturamento confirmado para resolver um cancelamento. Manter nota, eventos, títulos, pagamentos e decisões auditáveis. Adicionar testes sobre o `InvoiceObserver`, que já possui efeitos bancários no cancelamento da fatura.

**Aceite:** falha de e-mail não altera autorização fiscal; cancelamento fiscal não some com dinheiro recebido; reenvio de documento é separado de reemissão de nota.

<!-- pagebreak -->

## 15. Fase 8 — homologação e liberação controlada

**Objetivo:** tornar a central utilizável sem afetar a operação anterior.

### Homologação por capacidade

- NFS-e Nacional: cliente, serviço, regime, competência, descontos, retenções realmente suportadas, autorização, rejeição, consulta, cancelamento e documentos.
- Bancos: emissão, consulta, rejeição, liquidação, atraso de evento, resultado incerto e cancelamento por contrato habilitado.
- Concorrência: executar com o banco e o cache operacionais, múltiplos workers e reinício entre etapas.
- Permissões: responsável, auxiliar, somente consulta, emissão somente fiscal e escopo de registros próprios/atribuídos quando aplicável.

### Liberação

Criar uma capacidade/configuração empresarial para faturamento direto. Iniciar com dados de homologação, depois habilitar empresas piloto quando existirem e suas integrações estiverem verificadas. O número de empresas não bloqueia o desenvolvimento do produto.

Checklist empresarial: emitente, ambiente, serviço tributário, série/numeração, credenciais, destinatários, categoria financeira, conta e regras de cobrança. Não expor tokens/senhas na bancada do operador.

### Critérios para o primeiro uso real

1. Fluxo avulso completo sem OS artificial.
2. Três modos financeiros validados.
3. Retentativas e retornos concorrentes sem duplicação.
4. Recuperação de processamento e operação de pendências disponíveis.
5. Evidência de homologação do cenário fiscal e das cobranças liberadas.
6. Regressões dos painéis e faturamentos operacionais aprovadas.

**Aceite:** operador consegue emitir, acompanhar, cobrar quando disponível, obter documentos e resolver falhas específicas sem reiniciar todo o processo.

**Esforço relativo:** alto para integração e homologação; prazo depende das capacidades do provedor e da disponibilidade das credenciais. Não fixar calendário comercial antes da decomposição do backlog e da definição da equipe.

<!-- pagebreak -->

## 16. Permissões, escopos e processamento sem sessão

### Matriz mínima do novo fluxo

| Comando | Verificações de referência |
| --- | --- |
| Consultar faturamento | `invoices.view`, alcance e empresa. |
| Criar/editar rascunho | `invoices.create/update`, itens subordinados e vínculos válidos. |
| Confirmar comercialmente | Permissão de confirmação e manutenção necessária ao agregado. |
| Preparar/emitir NFS-e | `fiscal_documents.create/update/issue` e contexto empresarial. |
| Criar recebível | `account_receivables.create` e manutenção necessária ao fluxo. |
| Vincular existente | Nova `account_receivables.link_to_invoice`, alcance e compatibilidade. |
| Somente nota | Nova `fiscal_documents.issue_without_receivable`. |
| Preparar cobrança | Permissão específica ligada ao recebível e às capacidades bancárias. |
| Cancelar/reprocessar | Ação específica e validação do estado; não presumir a partir de consulta. |

Fechar a composição real na fase 0, considerando os eventos de gravação já implementados. Um botão com autorização não pode depender de uma permissão interna oculta que o papel sugerido não possui.

### Regras de implementação

- A UI mostra capacidades calculadas pelo servidor; não decide a autorização.
- Registrar os filhos no catálogo e estender `editorGroups()` somente com ações reais.
- Permissões novas têm uso efetivo nos serviços e Policies, não apenas rótulos.
- Atribuição de atendimentos e modelos de papéis continua respeitando a empresa.
- Seletores, totais, downloads e exportações obedecem ao alcance do registro.

### Workers e retornos

Jobs recebem IDs e contexto persistido, relendo registros por empresa. Revalidar autorização antes de nova ação externa dirigida pelo usuário. Não usar `Auth::login()` global ou fingir superadministrador para concluir efeitos.

Retornos de uma transmissão já aceita são fatos da integração: registrar e reconciliar mesmo após revogação do solicitante. Diferenciar essa finalização de um novo envio ou nova cobrança, que exige autorização vigente.

<!-- pagebreak -->

## 17. Matriz de arquivos existentes a alterar

| Arquivo / área | Alteração prevista |
| --- | --- |
| `app/Models/Invoice.php` | Origem direta, relações, totais por resolvedor e snapshots. |
| `InvoiceService.php` | Montar item/descrição fiscal a partir da fonte correta. |
| `ConfirmInvoiceAction.php` | Validar fonte, coordenar política financeira e evitar agendamento bancário antecipado na nova origem. |
| `GenerateInvoiceAccountReceivablesAction.php` | Respeitar o coordenador, os vínculos e as decisões explícitas posteriores. |
| `AccountReceivableGenerationService.php` | Unificar deduplicação, política e recuperação; preservar comportamento legado. |
| `NfseDocumentService.php` | Relacionar operação e solicitante; bloquear retentativa incerta. |
| `ProcessQueuedNfseEmissionJob.php` | Reserva durável, autorização pré-envio e lease/fencing. |
| `SendNfseJob.php` | Compartilhar o guard de envio e não contornar o worker principal. |
| `ConsultNfseAction.php` / `ConsultNfeAction.php` | Encaminhar retorno ao processador comum. |
| `NfeWebhookController.php` | Processamento unificado e recuperável; manter HTTP 200. |
| `InvoiceObserver.php` / `FiscalDocumentObserver.php` | Coordenar e deduplicar efeitos de comunicação/cancelamento. |
| `FiscalEmissionPreflightService.php` e builders | Validar a nova origem e a cobertura explicitamente homologada. |
| `BpoNavigation.php` / `BpoPanelProvider.php` | Registrar Faturamento e suas capacidades. |
| `FiscalDocuments.php` do BPO | Relacionar documentos com faturamentos e ações autorizadas. |
| `PermissionCatalog.php` | Itens subordinados e ações específicas de vínculo/somente fiscal. |
| `routes/console.php` | Agendar recuperação e reconciliação de pendências. |

**Diretórios principais:** `app/Services/Invoice`, `app/Services/FiscalDocument`, `app/Services/AccountReceivable`, `app/Jobs`, `app/Observers` e `app/Filament/Bpo`.

As alterações devem ser pequenas e testáveis por fase. Evitar uma reescrita geral dos fluxos operacionais em paralelo ao lançamento do faturamento avulso.

<!-- pagebreak -->

## 18. Matriz de componentes novos

| Componente proposto | Papel técnico |
| --- | --- |
| `InvoiceItem` | Item próprio do faturamento direto, subordinado à fatura. |
| `BillingOperation` | Intenção, identidade, ator e evolução de cada operação. |
| `BillingIntegrationEvent` | Evento fiscal recebido e situação de processamento. |
| `InvoiceSourceResolver` | Escolha determinística da origem dos itens. |
| `DirectInvoiceSource` / `OperationalInvoiceSource` | Adaptadores para o mesmo contrato de itens/totais. |
| `BillingAmountsDTO` / `BillingSnapshotDTO` | Dados monetários e snapshot validados, com versão. |
| `BillingReadinessService` | Diagnóstico de pré-requisitos apresentados à interface. |
| `BpoBillingWorkflow` | Coordenação de rascunho, confirmação e preparo da intenção. |
| `InvoiceFinancialCoordinator` | Política única de geração/vínculo/ausência de financeiro. |
| `LinkExistingReceivableAction` | Vinculação validada, sem movimentação indevida. |
| `FiscalResultProcessor` | Aplicação de fatos fiscais com transições válidas. |
| `BillingChargeCoordinator` | Cobrança por saldo, parcela e condição fiscal. |
| `RecoverPendingBillingCommand` | Recuperar intenções e operações paradas. |
| `ReconcileBillingFinancialCommand` | Detectar e encaminhar inconsistências entre eixos. |
| `BillingWorkspace` / `CreateBilling` / `ViewBilling` | Páginas da central no BPO. |
| `BillingWorkbench` | Componente de revisão e operação desktop. |

### Contratos e nomes

Consolidar esses nomes na fase 0. Uma classe não precisa ser criada se a responsabilidade couber de forma clara em um serviço existente; o importante é manter um único dono de cada efeito.

### Organização sugerida

Coordenação comercial em `app/Services/Invoice/Billing`; contratos de origem em `app/Services/Invoice/Sources`; integração fiscal nos diretórios atuais; páginas em `app/Filament/Bpo/Pages/Billing`; componentes em `app/Forms/Components`; views e estilos no tema BPO existente.

As referências a documento, recebível e cobrança devem ser persistidas. Não usar descrições, números de tela ou logs como único vínculo entre essas entidades.

<!-- pagebreak -->

## 19. Plano de testes e evidências

| Cenário | Evidência exigida |
| --- | --- |
| Fatura direta e operacional | Totais corretos e ausência de dupla contagem de fontes. |
| Falha após gravação parcial | Rollback de cabeçalho, itens e preparação financeira. |
| Duplo clique / mesma UUID | Uma única confirmação e resultado reaproveitado. |
| Duas requisições concorrentes | Índice/lock impedem duplicação econômica. |
| Recebível oculto ao operador | Integridade reconhece o vínculo sem revelar seus detalhes. |
| Somente nota | Zero recebível/cobrança mesmo após webhook e consulta repetidos. |
| Vínculo de título já recebido | Sem nova baixa, sem reabertura e sem cobrança indevida. |
| Timeout de envio | Consulta antes de qualquer nova transmissão/numeração. |
| Worker antigo / lock expirado | Fencing ou lease impede sobrescrita e envio paralelo indevido. |
| Webhook + polling | Efeitos convergem para uma única autorização e finalização. |
| Revogação | Novo envio bloqueado; retorno já ocorrido continua conciliável. |
| Falha de cobrança/anexo/e-mail | Nota preservada e retomada somente da etapa falha. |
| Cancelamento com pagamento | Histórico financeiro preservado e resolução explícita. |

### Camadas

Testes unitários para cálculo, política e transições; feature para serviços/transactions; Livewire para rascunho, revisão, autorização e rejeições; integração com mocks contratuais do provedor; homologação real para capacidades fiscais/bancárias.

Usar SQLite para regressões rápidas, mas validar concorrência e particularidades de índice/lock também no banco operacional com múltiplos workers.

### UI e regressão

Conferir em resoluções desktop, teclado, estados de carregamento, erro recuperável e manutenção do contexto da empresa. Executar a suíte existente, testes de frontend pertinentes, Pint nos arquivos alterados e build Vite. Não confundir teste automatizado com homologação de emitente ou banco real.

<!-- pagebreak -->

## 20. Implantação, observabilidade e rollback

### Ordem de publicação

1. Aplicar migrations aditivas e compatíveis.
2. Publicar código compatível com a origem legada e o novo build.
3. Reiniciar workers conforme o processo normal do projeto.
4. Ativar rotinas de recuperação com limites e métricas.
5. Habilitar a capacidade por empresa/cenário homologado.

### Indicadores operacionais

Tempo para preparar emissão; autorização/rejeição por cenário; idade das filas; operações em resultado incerto; pendências financeiras; cobranças sem confirmação; anexos/entregas falhos; tentativas reaproveitadas e duplicidades bloqueadas.

Logs e auditorias usam correlação por empresa, fatura, operação, documento e tentativa. Não registrar certificado, senha ou token. Registrar versões dos snapshots e decisões de retomada.

### Recuperação

As rotinas devem separar: intenção não despachada, job parado antes do envio, envio externo incerto, retorno recebido não processado e etapa secundária falha. Cada situação tem retomada própria, sem um botão genérico que repete todo o faturamento.

### Rollback funcional

Desabilitar novas confirmações diretas por configuração, preservando consulta e recuperação dos processos já aceitos. Interromper disparos novos de cobrança quando necessário, mantendo recepção de eventos.

Não desfazer migrations destrutivamente, apagar faturas, remover títulos ou invalidar notas externas para “voltar a versão”. Operações em andamento precisam ser drenadas ou tratadas por procedimento compatível com seus estados.

### Aceite de operação

Equipe consegue identificar o ponto da falha, consultar o histórico e retomar a etapa correta. Uma divergência não fica apenas em log: aparece como pendência com responsável e ação disponível.

<!-- pagebreak -->

## 21. Fase 9 — contratos e recorrências

**Objetivo:** automatizar faturamentos e despesas periódicas usando o fluxo avulso já estabilizado.

### Dados propostos

`billing_contracts`: empresa, cliente, itens/modelo, vigência, periodicidade, calendário, política financeira, regras de reajuste e situação.

`billing_occurrences`: contrato, competência, sequência, versão, fatura gerada, operação e situação. Unicidade por contrato/competência/ocorrência evita geração repetida.

### Serviços e alterações

- `ContractScheduleService`: calcular próximas ocorrências e datas, incluindo meses com dias diferentes.
- `GenerateContractOccurrenceJob`: reservar ocorrência e chamar o mesmo workflow de faturamento.
- `ContractAdjustmentService`: vigência do reajuste, versão e impacto somente nas competências aplicáveis.
- Páginas BPO de contratos, agenda de geração e prévia dos próximos lançamentos.
- Catálogo de permissões para consultar, criar, alterar, pausar, reajustar e gerar ocorrências.

### Regras

Parcelamento não é recorrência. Pausar um contrato impede novas ocorrências, sem apagar obrigações já geradas. Alterar o contrato não modifica uma fatura confirmada silenciosamente. Na primeira liberação, permitir revisão das ocorrências antes de ativar emissão automática por empresa.

Para despesas recorrentes, reutilizar a geração de contas a pagar com identidade de ocorrência própria, sem criar um contrato de venda artificial.

### Testes e aceite

Calendários mensais, fim de mês, vigência, pausa, encerramento, reajuste, reentrega do job e falha no meio da geração. A mesma competência gera uma ocorrência e uma fatura; reprocessar não emite outra nota.

**Dependência:** fases 1–8, especialmente idempotência, snapshots, recuperação e autorização em workers. O cronograma de automação deve respeitar os cenários fiscais e bancários homologados.

<!-- pagebreak -->

## 22. Fase 10 — documentos, tarefas e pendências

**Objetivo:** estruturar a rotina diária da equipe BPO e a entrada de informações de cada cliente.

### Dados propostos

- `bpo_tasks`: empresa, atendimento, responsável, tipo, prazo, prioridade, estado e referências do processo.
- `document_inbox_entries`: remetente, empresa, arquivo privado, hash, classificação, situação e vínculo financeiro/fiscal.
- `document_requests`: solicitação ao cliente, prazo, resposta e documentos associados.
- Comentários com visibilidade interna ou compartilhada.

### Serviços e interface

Criar triagem de documentos, atribuição de tarefas e solicitação de complementos. Integrar pendências da central de faturamento com tarefas, sem copiar o documento financeiro para outra base.

A carteira passa a mostrar pendências atribuídas e prazos do atendimento. Uma tarefa pode apontar para a nota rejeitada, cobrança incerta ou documento faltante e abrir a tela de resolução correspondente.

### Regras

Hash detecta possíveis arquivos duplicados, sem presumir que todo arquivo repetido represente a mesma obrigação. Um documento pode justificar vários registros; vinculação não é geração financeira automática. Arquivos permanecem protegidos por empresa e autorização do registro associado.

Comentários internos não aparecem no portal do cliente. Encerrar atendimento bloqueia o acesso externo BPO, preservando os documentos e o histórico da empresa.

### Evolução de captura

E-mail, OCR e WhatsApp são canais adicionais após estabilizar a caixa de entrada. Exigem contrato de recebimento, associação segura à empresa, deduplicação e revisão dos dados extraídos antes de gravar efeitos financeiros. Não presumir que já exista uma integração de WhatsApp disponível.

**Aceite:** operador sabe o que precisa fazer, quem responde pela pendência e qual documento sustenta cada lançamento. Upload, triagem e vínculo não geram obrigações duplicadas.

<!-- pagebreak -->

## 23. Fase 11 — portal e aprovação opcional

**Objetivo:** permitir colaboração do empresário sem exigir o uso da interface operacional completa do BPO.

### Experiência do cliente

Posição financeira autorizada, envio de documentos, respostas a pendências, consulta de notas/cobranças e relatórios. Utilizar a identidade única existente e papéis por empresa; não criar um segundo cadastro da mesma pessoa.

### Aprovação configurável

Por empresa: desabilitada, todas as despesas ou regras por valor/categoria. A aprovação não executa pagamento bancário e não cria baixa.

Dados sugeridos: `company_approval_policies`, `approval_requests` e `approval_decisions`, com versão da política e snapshot dos dados aprovados.

### Alterações no backend

- `ApprovalPolicyResolver`: determinar quando há necessidade de aprovação.
- `SubmitPayableForApprovalAction`: congelar os campos relevantes e definir aprovador autorizado.
- `DecideApprovalAction`: aceitar/rejeitar com auditoria e prevenção de decisão duplicada.
- Guards de alteração: valor, favorecido e vencimento relevantes invalidam a aprovação anterior.
- Notificações e páginas de pendências compartilhadas, com comentários internos separados.

### Fluxo

Preparado → aprovação pendente → aprovado/rejeitado → pagamento realizado fora do sistema → baixa e conciliação.

Quando a política estiver desabilitada, a rotina financeira deve funcionar integralmente sem criar solicitações de aprovação.

### Testes e aceite

Política ligada/desligada, troca de aprovador, alteração após aprovação, concorrência de decisões, suspensão do usuário e acesso de outra empresa. O cliente aprova somente o que pode consultar e o BPO não transforma aprovação em pagamento.

**Dependência:** estrutura de documentos/tarefas e base financeira estável. O portal é uma experiência adicional; empresas continuam podendo operar diretamente conforme seus papéis.

<!-- pagebreak -->

## 24. Fase 12 — fechamento mensal e entrega gerencial

**Objetivo:** transformar a operação do BPO em uma entrega periódica verificável ao empresário e à contabilidade.

### Dados propostos

`financial_period_closings`: empresa, período, estado, responsável, revisões, pendências justificadas, snapshot do fechamento e identificação dos artefatos entregues.

`closing_checklist_items`: conferências, evidências, responsável e conclusão. Templates por empresa podem ser copiados de modelos do sistema.

### Processo

1. Conferir documentos, classificações, contas e conciliações.
2. Revisar emissões/cobranças incertas e pendências financeiras.
3. Resolver ou justificar exceções.
4. Revisar o fechamento e bloquear o período.
5. Gerar pacote com DRE, fluxo de caixa, posição de contas e comentários gerenciais.
6. Disponibilizar ao cliente e exportar o conjunto contábil pertinente.

### Alterações técnicas

Criar `FinancialPeriodGuard` e integrar aos serviços de baixa, estorno, classificação e movimentos; o bloqueio não pode existir só na página. Reabertura exige permissão específica, justificativa e nova versão do fechamento.

Reutilizar os relatórios existentes, validando caixa/competência, estornos e equivalência das classificações. Documentar os filtros usados no pacote para permitir reprodução. Uma DRE gerencial não deve ser apresentada como escrituração contábil completa.

### Contabilidade

Definir sistema de destino, layout, mapa de contas e validação dos arquivos antes de desenvolver adaptadores. A exportação genérica pode vir primeiro; integração específica depende do contrato técnico do destino.

**Aceite:** fechamento tem evidências e responsável; período fechado não sofre alteração silenciosa; reabertura gera histórico; pacote pode ser reproduzido e consultado pelo cliente autorizado.

<!-- pagebreak -->

## 25. Fase 13 — migração assistida do Conta Azul

**Objetivo:** trazer empresas com dados conferidos e data de corte definida, quando houver adesão comercial.

### Estratégias por empresa

- Cadastros, saldos e títulos abertos, com histórico preservado em acervo consultável.
- Histórico ampliado, quando a extração realmente disponibilizar os dados necessários.

Não prometer histórico completo sem verificar os endpoints e as exportações disponíveis.

### Estruturas e serviços

Criar lotes de importação, staging de registros, mapeamento de IDs externos, categorias/contas e relatórios de divergência. A identificação externa deve ser única por empresa, origem e tipo de registro.

`ContaAzulImportAdapter` faz extração autorizada por empresa; `ImportMappingService` resolve cadastros/classificações; `FinancialImportValidator` confere saldos e obrigações; `CommitImportBatchAction` aplica o lote de forma retomável.

### Sequência

1. Diagnóstico das rotinas usadas e das capacidades fiscais/bancárias necessárias.
2. Extração e preservação dos arquivos originais.
3. Importação em staging, sem efeitos financeiros imediatos.
4. Mapeamento e detecção de duplicidades.
5. Prévia de títulos, parcelas, pagamentos e saldos.
6. Conferência e aplicação rastreável.
7. Data de corte e validação da operação no novo sistema.

### Cuidados de negócio

Importar uma nota autorizada não deve reemitir a nota nem gerar outro recebível automaticamente. Importar um pagamento não deve repeti-lo por callback ou conciliação posterior. Guardar a origem e o marco histórico da importação.

**Aceite:** saldos e títulos conciliados por conta/empresa; reprocessamento do lote não duplica registros; documentação de exceções disponível; saída do sistema anterior somente após validar as rotinas efetivamente necessárias.

**Dependência:** cobertura funcional correspondente à empresa, incluindo NFC-e/NF-e ou integrações específicas quando exigidas por sua operação.

<!-- pagebreak -->

## 26. Backlog, sequência de entrega e referências

### Ordem recomendada

| Pacote | Fases | Resultado |
| --- | --- | --- |
| A — Fundação do faturamento | 0–1 | Contratos, origem direta, dados e autorização. |
| B — Preparação comercial | 2–3 | Rascunhos e três modos financeiros com confirmação única. |
| C — Ciclo fiscal | 4–5 | Envio, consulta, retornos e recuperação de falhas. |
| D — Cobrança e entrega | 6–7 | Cobranças suportadas, documentos, comunicações e cancelamentos. |
| E — Uso real | 8 | Homologação e ativação por capacidade/empresa. |
| F — Escala operacional | 9–10 | Recorrências, caixa de documentos e tarefas. |
| G — Colaboração e gestão | 11–12 | Portal, aprovação opcional e fechamento. |
| H — Entrada da carteira | 13 | Migração assistida quando as empresas aderirem. |

### Primeiro backlog executável

Fechar a matriz fiscal e os efeitos atuais; definir os DTOs/estados; criar migrations; implementar o resolvedor de origem; validar totais diretos; registrar permissões; entregar rascunho e revisão; implementar o coordenador financeiro; criar intenção durável; integrar o envio e os retornos; concluir cobrança/documentos; homologar e liberar.

Estimar cada pacote após decompor as tarefas com a equipe disponível. O esforço de provedor, credenciais e homologação deve aparecer separado do desenvolvimento de interface.

### Decisões a fechar na fase 0

Cenários tributários iniciais, serviços/retencões suportados, gatilho de cobrança, capacidades bancárias por contrato e critérios de compatibilidade do título existente. Para fases posteriores: sistema contábil de destino, canais de captura e profundidade histórica da migração.

### Referências

Código inspecionado: `Invoice`, `InvoiceService`, actions de confirmação/geração, `AccountReceivableGenerationService`, `NfseDocumentService`, builders/validadores nacionais, workers fiscais, webhook, consultas, observers, serviços bancários e painel BPO.

Documentos do projeto: `BPO-PANEL.md`, `USERS-ACCESS.md`, `FINANCIAL-INTEGRITY.md`, `FINANCIAL-PHASE-2.md`.

Fontes para validação na execução: [NFS-e Nacional — documentação técnica](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica), [Filament 4 — tenancy](https://filamentphp.com/docs/4.x/users/tenancy), [API Conta Azul](https://developers.contaazul.com/), documentação contratual do provedor fiscal e da integração bancária utilizada.

**Entrega documental:** este plano e seu PDF são disponibilizados na conversa para encaminhamento pelo WhatsApp, conforme a opção escolhida.
