# Plano de Evolucao de Notificacoes de Documentos

## Objetivos

1. Permitir novo envio de e-mail quando uma OS for reaberta e encerrada novamente.
2. Permitir escolher se o cliente sera notificado quando um documento encerrado for reaberto.
3. Permitir escolher se o cliente sera notificado quando um documento for cancelado.
4. Permitir que um administrador exclua o registro de envio de e-mail para viabilizar novo envio manual.
5. Disponibilizar uma visualizacao detalhada do e-mail para qualquer usuario com acesso a esse modulo.

## Problema Atual

Hoje o fluxo de notificacao cria ou reutiliza um unico `EmailDispatch` por combinacao de:

- `company_id`
- `document_type`
- `document_id`
- `event`

Como o registro e obtido com `firstOrCreate`, um segundo encerramento da mesma OS reaproveita o mesmo dispatch. Se esse dispatch ja estiver como `SENT`, o job nao e reenfileirado e o e-mail nao e enviado novamente.

## Direcao Recomendada

Alterar o modelo de notificacao para registrar cada ocorrencia real do evento, em vez de manter um unico dispatch eterno por documento e evento.

Essa abordagem:

- preserva historico completo;
- permite auditoria de todos os disparos;
- resolve naturalmente o reenvio em novo encerramento;
- evita gambiarras no fluxo de reabertura.

## Escopo Funcional

### 1. Novo envio no segundo encerramento

Quando a OS seguir o fluxo:

- `aberta -> encerrada`
- `encerrada -> aberta`
- `aberta -> encerrada`

o sistema deve gerar um novo `EmailDispatch` no segundo encerramento e disparar novo e-mail, desde que as regras de notificacao continuem habilitadas.

### 2. Notificacao opcional de reabertura

Deve existir uma configuracao para decidir se o cliente sera notificado quando um documento antes encerrado for reaberto.

Eventos esperados:

- `service_order.reopened`
- `requisition.reopened`
- `production_order.reopened` se o dominio permitir

### 3. Notificacao opcional de cancelamento

Deve existir uma configuracao para decidir se o cliente sera notificado quando um documento for cancelado.

Eventos esperados:

- `service_order.cancelled`
- `requisition.cancelled`
- `production_order.cancelled` se aplicavel

### 4. Exclusao do registro de e-mail por administrador

Um usuario administrador deve poder excluir um `EmailDispatch`.

Objetivo pratico:

- limpar um envio antigo;
- permitir novo disparo funcional em cenarios onde o historico precise ser reiniciado manualmente;
- remover registros inconsistentes ou gerados indevidamente.

Regras:

- apenas administrador pode excluir;
- a exclusao deve ser explicitamente confirmada;
- a acao deve ser auditada em log;
- anexos locais associados ao dispatch devem ser removidos junto com o registro, se existirem.

Observacao:

A exclusao manual nao deve ser a estrategia principal para reenviar no novo encerramento. Ela e um recurso administrativo complementar.

### 5. Visualizacao detalhada do e-mail para qualquer usuario

Qualquer usuario com acesso ao recurso de listagem de e-mails deve poder abrir uma visualizacao detalhada do `EmailDispatch`.

Essa visualizacao deve exibir, no minimo:

- documento relacionado;
- evento;
- status do envio;
- destinatarios `to`, `cc` e `bcc`;
- assunto;
- provider;
- tentativas;
- data de criacao;
- data de envio;
- ultima falha;
- mensagem de erro;
- payload de retorno do provider, quando existir;
- manifesto de anexos;
- hash dos anexos;
- links ou informacoes de anexos persistidos.

Se o corpo do e-mail nao estiver persistido hoje, ha duas opcoes:

- exibir apenas os metadados e o assunto;
- ou evoluir o modelo para salvar `rendered_subject` e `rendered_body` para consulta posterior.

Minha recomendacao e salvar o conteudo renderizado, porque isso melhora auditoria e suporte.

## Mudancas Tecnicas Propostas

### 1. Evoluir a idempotencia do `EmailDispatch`

Hoje a unicidade pratica esta em:

- `company_id`
- `document_type`
- `document_id`
- `event`

Isso deve mudar para suportar multiplas ocorrencias do mesmo evento no mesmo documento.

Opcoes de implementacao:

1. Adicionar `event_sequence` por documento e evento.
2. Adicionar um identificador da ocorrencia, como `event_uuid`.
3. Adicionar um campo de referencia de transicao, como `source_status_changed_at`.

Recomendacao:

- usar `event_sequence` ou `event_uuid`;
- manter `idempotency_key` por ocorrencia;
- continuar protegendo contra duplicacao tecnica dentro da mesma transacao.

### 2. Expandir os eventos suportados

O enum de eventos deve incluir, alem de `closed`:

- `reopened`
- `cancelled`

Isso precisa ser refletido em:

- enums;
- politicas;
- observers;
- templates;
- filtros e telas de administracao.

### 3. Ajustar observers para mapear transicoes reais

Os observers devem reagir a mudanca de status e criar o evento correto.

Exemplos para OS:

- `OPEN -> CLOSED` gera `closed`
- `CLOSED -> OPEN` gera `reopened`
- `OPEN -> CANCELLED` gera `cancelled`
- `CANCELLED -> OPEN` pode ou nao gerar `reopened`, conforme regra de negocio desejada

Ponto importante:

- o observer deve olhar status atual e status original para identificar a transicao;
- nao basta olhar apenas o novo status.

### 4. Evoluir `CompanyEmailPolicy`

Cada tipo de documento deve poder ter politica distinta por evento.

Exemplos:

- `service_order.closed`
- `service_order.reopened`
- `service_order.cancelled`

Cada politica deve controlar:

- `enabled`;
- templates de assunto e corpo;
- anexos obrigatorios e opcionais;
- fallback de anexos;
- restricoes de mime e tamanho.

### 5. Evoluir `CompanyPartner`

Hoje ha flags de notificacao por tipo e evento principal. O modelo deve suportar flags especificas por evento.

Exemplo recomendado:

- `notify_service_order_closed`
- `notify_service_order_reopened`
- `notify_service_order_cancelled`
- `notify_requisition_closed`
- `notify_requisition_reopened`
- `notify_requisition_cancelled`

Se o projeto quiser reduzir colunas, isso pode migrar para uma configuracao estruturada. Mas, para consistencia com o estado atual, novas flags booleanas tendem a ser a evolucao mais simples.

### 6. Persistir conteudo renderizado do e-mail

Para suportar a visualizacao detalhada, recomendo adicionar no `EmailDispatch`:

- `rendered_subject`
- `rendered_html`
- `rendered_text`

Beneficios:

- auditoria completa;
- suporte operacional;
- comparacao entre envios do mesmo documento em eventos diferentes;
- melhor analise de falhas.

### 7. Politica de anexos por evento

Recomendacao inicial:

- `closed`: manter PDF como hoje;
- `reopened`: sem anexo por padrao;
- `cancelled`: sem anexo por padrao;
- permitir configuracao futura por politica.

## Mudancas de Interface

### 1. Tela de configuracao de politicas

Adicionar configuracoes por evento para cada documento relevante.

Exemplo para OS:

- enviar ao encerrar;
- enviar ao reabrir;
- enviar ao cancelar;
- editar assunto e corpo por evento.

### 2. Tela de parceiro/cliente

Adicionar toggles por evento no relacionamento com a empresa.

Exemplo:

- notificar encerramento de OS;
- notificar reabertura de OS;
- notificar cancelamento de OS.

### 3. Tela de `EmailDispatch`

A lista atual deve evoluir para:

- acao de visualizar detalhes para qualquer usuario autorizado;
- acao de reenfileirar quando aplicavel;
- acao de excluir apenas para administrador.

### 4. Modal ou pagina de visualizacao detalhada

Pode ser implementada como:

- `ViewAction` em tabela Filament;
- ou pagina dedicada de detalhes.

Minha recomendacao:

- usar `ViewAction` primeiro, pela menor complexidade;
- migrar para pagina propria apenas se o volume de informacoes crescer muito.

## Permissoes

### Exclusao de `EmailDispatch`

- apenas administrador;
- protegido por policy;
- com confirmacao obrigatoria.

### Visualizacao detalhada

- qualquer usuario que hoje possa acessar a listagem de `EmailDispatch` deve poder abrir os detalhes;
- se a listagem hoje for muito restrita, vale revisar se esse recurso deve ser movido para um modulo mais amplo de auditoria.

## Logging e Auditoria

Registrar em log:

- criacao de novo dispatch por ocorrencia;
- reenvio manual;
- exclusao manual por administrador;
- visualizacao detalhada, apenas se houver exigencia de auditoria operacional.

## Estrategia de Implementacao

### Fase 1. Reenvio no novo encerramento

1. Alterar a estrategia de criacao do `EmailDispatch`.
2. Garantir novo dispatch a cada novo encerramento real.
3. Cobrir com testes de fluxo de reabertura e novo encerramento.

### Fase 2. Novos eventos

1. Adicionar `reopened` e `cancelled` aos enums.
2. Ajustar observers para detectar transicoes reais.
3. Criar politicas e templates por evento.

### Fase 3. Configuracoes de negocio

1. Adicionar flags por evento em `CompanyPartner`.
2. Adicionar controles por evento na interface.

### Fase 4. Operacao e suporte

1. Adicionar visualizacao detalhada do `EmailDispatch`.
2. Persistir conteudo renderizado do e-mail.
3. Adicionar exclusao por administrador.

## Testes Recomendados

### Reenvio em novo encerramento

- cria OS aberta;
- encerra com envio habilitado;
- valida 1 dispatch;
- reabre;
- encerra novamente;
- valida 2 dispatches.

### Reabertura

- encerra;
- reabre;
- valida dispatch de `reopened` apenas quando a politica estiver habilitada.

### Cancelamento

- cancela documento;
- valida dispatch de `cancelled` apenas quando a politica estiver habilitada.

### Exclusao administrativa

- admin exclui dispatch com sucesso;
- usuario comum nao pode excluir;
- anexos vinculados sao removidos.

### Visualizacao detalhada

- usuario autorizado consegue abrir detalhes;
- dados renderizados e metadados aparecem corretamente;
- usuario sem permissao nao acessa.

## Decisoes em Aberto

1. Reabrir um documento cancelado deve gerar evento `reopened` ou apenas voltar a `open` sem notificacao?
2. O corpo renderizado do e-mail deve ser persistido para todos os envios antigos apenas daqui para frente?
3. A exclusao administrativa deve ser fisica ou soft delete?

## Recomendacao Final

Implementar primeiro o reenvio por nova ocorrencia de encerramento e, na mesma linha evolutiva, preparar a estrutura para novos eventos (`reopened` e `cancelled`).

Para operacao:

- visualizacao detalhada deve ficar disponivel para qualquer usuario autorizado a consultar o modulo;
- exclusao do registro deve ficar restrita ao administrador;
- o reenvio por novo encerramento deve funcionar sem depender da exclusao manual.
