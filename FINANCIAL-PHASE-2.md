# Fase 2 — operação bancária com IntegraBancos

## 1. Objetivo e referências

Esta entrega consolida emissão, acompanhamento e recebimento de boletos e PIX, sobre as garantias financeiras da fase 1, documentadas em [FINANCIAL-INTEGRITY.md](FINANCIAL-INTEGRITY.md).

Fontes utilizadas:

- Documentação: <https://app.integrabancos.com.br/docs>.
- Schemas publicados para a própria documentação: <https://app.integrabancos.com.br/api/docs>.
- SDK PHP instalado: `integrabancos/sdk-php` **v1.0.3**, referência `1153e314f55e888303c041aa4f37cae4a9d0edf7`.
- Código e exemplos em `vendor/integrabancos/sdk-php/`.

O SDK foi utilizado por adaptadores da aplicação, sem alterações em `vendor/` nem atualização da dependência. A consulta de documentação e a execução dos testes não representam homologação de uma conta bancária real.

## 2. Desenvolvimento do plano

| Entrega planejada | Desenvolvimento realizado |
| --- | --- |
| 2.1 — contratos e normalização | Perfis locais por banco/modalidade, validação de pré-requisitos, configuração de payload, separação de códigos e autenticação compartilhada |
| 2.2 — processamento e recuperação | Estados assíncronos, snapshot da requisição, identificação da tentativa, consulta de eventos, polling de boleto/PIX e reenvio controlado |
| 2.3 — liquidação e valores | Registro durável de eventos, serviço financeiro compartilhado, datas bancárias, semântica de valores, resolução explícita e registro de devolução integral confirmada |
| 2.4 — operação e acompanhamento | Central de pendências/histórico, ações auditadas, isolamento de empresa, configuração de fila e regressões automatizadas |

### 2.1. Contratos por banco e modalidade

Arquivos principais:

- `config/integrabancos-contracts.php`.
- `app/Services/Financial/Banking/Providers/IntegraBancosPayloadBuilder.php`.
- `app/Filament/Management/Resources/BankAccountConnections/BankAccountConnectionResource.php`.

O snapshot local cobre Asaas, Banco do Brasil, Cora, Efí, Inter, Itaú, Santander, Sicoob e Sicredi. A modalidade PIX não é anunciada para Banco do Brasil, pois não consta naquele contrato consultado. Alteração/cancelamento respeitam as capacidades declaradas no snapshot: por exemplo, Cora não possui alteração e Santander não possui alteração/cancelamento no contrato consultado.

O builder:

1. Resolve o perfil pelo código do banco, normalizando zeros à esquerda para localizar o contrato.
2. Preenche identificação estável, valor e dados do pagador a partir da cobrança/parcela.
3. Consulta endereço e contato somente no vínculo do parceiro com a empresa atual; não usa endereço de outra empresa como fallback.
4. Aplica configurações de `settings.cobranca` ou `settings.pix`.
5. Valida pré-requisitos de conta, CPF/CNPJ, endereço, chave PIX, vencimento e campos condicionais de encargos.
6. Omite vencimento nas modalidades PIX imediatas cujo contrato não o utiliza.
7. Preserva o payload validado em `request_snapshot`, antes de enviá-lo ao SDK.

Campos centrais, como identificação e valor, não podem ser substituídos pelo JSON de configuração. `conta` e `beneficiario` são enviados no boleto quando configurados. Os encargos opcionais exigem seus campos condicionais quando utilizados.

A validação local é uma camada de pré-requisitos; não é um compilador integral de todos os JSON Schemas. Restrições adicionais de tamanho, enumeração e regras específicas continuam sujeitas à validação da API e à homologação do contrato bancário.

#### Configuração na interface

No painel de gerenciamento, em **Conexões bancárias → Contrato do banco**, existem campos JSON separados para boleto e PIX e um seletor para a semântica do valor no webhook.

Exemplo de estrutura para boleto Sicoob — substituir os números pelos dados do contrato real:

```json
{
  "conta": {
    "numero_cliente": "123456",
    "numero_conta_corrente": "1234567",
    "codigo_modalidade": "1"
  }
}
```

Exemplo de estrutura para PIX Sicoob:

```json
{
  "pagamento": {
    "chave_pix": "financeiro@empresa.com.br"
  }
}
```

As credenciais de autenticação no banco precisam estar cadastradas na IntegraBancos pelo canal correspondente. Esta entrega não automatiza o provisionamento de emitentes nem o cadastro de credenciais bancárias nos endpoints `/credenciais`.

### 2.2. Autenticação compartilhada e SDK

Arquivos principais em `app/Services/Financial/Banking/Providers/`:

- `IntegraBancosOAuthClient.php`.
- `IntegraBancosTokenManager.php`.
- `IntegraBancosSdkTransport.php`.
- `IntegraBancosAuthenticationException.php`.

Boleto e PIX passaram a compartilhar a mesma gestão de tokens por conexão:

- Lock distribuído para impedir renovações simultâneas.
- Releitura das credenciais dentro do lock.
- Utilização de `expires_in`, com margem preventiva; fallback de 900 segundos quando o campo não existe.
- Renovação por refresh token.
- Uma tentativa por password grant quando o refresh retorna explicitamente `invalid_grant` e existem usuário/senha configurados.
- Proteção contra sobrescrever credenciais alteradas durante a renovação.
- Preservação das demais credenciais ao atualizar os tokens.
- Uma única renovação/repetição imediata da operação quando a API responde explicitamente código `23`, não autenticado.

O transporte valida `x_api_key` e chave de criptografia de exatamente 32 bytes antes da operação. A criptografia e o hash continuam sob responsabilidade do SDK. Credenciais permanecem criptografadas no model e foram ocultadas de sua serialização padrão.

Uma conexão já utilizada não permite trocar empresa, banco, ambiente ou provedor pelo model. Para outra identidade bancária/ambiente, deve ser cadastrada uma nova conexão. A conta financeira histórica da cobrança continua preservada pelo snapshot da fase 1.

### 2.3. Separação dos códigos e estados assíncronos

`IntegraBancosResponse.php` centraliza a interpretação dos campos:

| Campo/contexto | Interpretação |
| --- | --- |
| `codigo` na raiz | Resultado da operação da API, não o status da cobrança |
| `dados.status.codigo` ou `status.codigo` | Status da cobrança no contexto de consulta/webhook |
| Status retornado por consulta de evento | Situação da solicitação de alteração/cancelamento |
| `LOCAL_VALIDATION` | Código interno para rejeição local/autenticação anterior ao envio; não é um código bancário |

Exemplos implementados:

- `sucesso=true, codigo=4`: solicitação aceita e pendente, sem marcar cobrança como gerada.
- Código raiz `3`: recurso já existente; provoca acompanhamento, não uma baixa financeira.
- Código raiz `17` ou falha de comunicação durante emissão: resultado incerto; a recuperação começa por consulta.
- Status da cobrança `2`: gerada.
- Status da cobrança `3`: processamento financeiro pelo serviço de liquidação.
- Status da cobrança `4`: cancelada, sem apagar pagamentos existentes.
- Status `5` de evento consultado: solicitação processada; não equivale sozinho a cobrança cancelada.
- Status `6`: vencimento não é tratado automaticamente como cancelamento.
- Status `7`: devolução informada, encaminhada para resolução.
- Status `9`: rejeição bancária com detalhes para revisão.

Foram adicionados estados locais `processing` e `uncertain`. A solicitação possui metadados independentes: `operation_kind`, `operation_status`, `provider_operation_id` e `operation_attempt_id`.

Estados financeiros terminais são protegidos contra regressão por notificações antigas. Conflitos ficam no histórico e na central, sem desfazer dinheiro já registrado.

O SDK não entrega o HTTP status de respostas JSON válidas em sua interface atual. Os DTOs usam `httpStatus=0` quando essa informação não está disponível, em vez de fabricar HTTP 200/422 com base no conteúdo.

### 2.4. Emissão, alteração, cancelamento e recuperação

`BankingOperationService.php` reserva a operação no banco antes do I/O externo. Um worker interrompido deixa uma intenção persistida, permitindo recuperação por consulta.

Os jobs de registro, consulta e cancelamento foram refatorados. Foi criado `UpdateBankSlipJob.php`. As chamadas externas ocorrem fora das transações que aplicam efeitos financeiros.

#### Emissão

```text
Preparada → tentativa reservada → enviada → processamento no banco → gerada
                                      ↘ timeout → resultado incerto → consulta
```

A primeira tentativa válida congela o payload. Uma repetição após timeout não gera outra identificação nem dispara cegamente outro POST.

#### Alteração de boleto

Na listagem de boletos existe **Sincronizar alterações da parcela**. A ação exige justificativa, permissão e boleto gerado sem pagamentos.

O valor e vencimento desejados são lidos da parcela e guardados em `pending_update`. O valor/vencimento atuais da cobrança são preservados até uma consulta confirmar os dados pedidos. A parcela deve ser revisada pelo fluxo financeiro antes dessa sincronização.

#### Cancelamento

Uma resposta de aceite mantém `cancel_pending`. Quando existe `evento_id`, o sistema usa o método real do SDK `consultarEventoBoleto()`. O cancelamento da cobrança é confirmado pelo retorno correspondente; aceite ou evento processado isoladamente não encerram a cobrança.

#### Reenvio controlado

As ações de reenvio exigem justificativa e releitura do estado:

- São permitidas falhas locais/validação prévia e rejeições explicitamente anteriores ao processamento, conforme os códigos tratados.
- Para emissão incerta, exige-se código de ausência `12`, ao menos duas observações da mesma consulta espaçadas por um minuto e cinco minutos desde o envio.
- Não é permitido reemitir quando existem pagamentos na parcela.
- A identificação é preservada. No caso incerto, o snapshot também é preservado.

Cada tentativa externa tem UUID próprio. Assim, dois erros de validação idênticos em tentativas diferentes não são confundidos com o mesmo evento.

Esses controles reduzem reenvios indevidos; a janela real de consistência da IntegraBancos ainda deve ser validada em homologação. Uma resposta de ausência isolada não autoriza reenvio.

### 2.5. Eventos duráveis e processamento compartilhado

Componentes:

- `App\Models\BankingEvent`.
- `BankingEventService.php`.
- `BankSettlementService.php`.
- `ProcessBankingEventJob.php`.

Fluxo:

```text
Consulta / resposta de operação / webhook
                  ↓
        observação durável e identificada
                  ↓
       validação de estado, empresa e conexão
                  ↓
        confirmação / nova baixa / revisão
                  ↓
             auditoria correlacionada
```

`banking_events` guarda conteúdo normalizado, fonte, operação, recebimento, tentativas, repetições, erro e resultado. O fingerprint é estável e isolado por conexão; a assinatura variável do webhook não participa da identidade. A repetição de uma consulta incrementa `deliveries`/`last_received_at`, sem repetir seu efeito financeiro.

Eventos antigos de boleto continuam em `bank_slip_events`, com processamento adaptado ao serviço compartilhado. PIX utiliza o novo registro. Eventos sem cobrança correspondente permanecem rastreáveis, inclusive após reinício operacional.

O processamento utiliza transações e locks sobre evento, cobrança e estruturas financeiras. Um erro no movimento desfaz a baixa e mantém o evento com falha para recuperação. Novos recebimentos têm vínculo único opcional com `banking_event_id`.

### 2.6. Valores, datas e recebimentos

Novos recebimentos automáticos utilizam uma data válida informada pelo provedor: `data_pagamento` da consulta ou `data_hora` do webhook. A origem fica explícita em `financial_date_source`: `provider_payment_date` ou `provider_event_time`. Formatos suportados incluem datas brasileiras, ISO e data/hora com offset. Ausência/invalidade não é substituída silenciosamente por `now()`.

`data_hora` é documentada como horário do evento, não como garantia universal da data efetiva do pagamento. Essa correspondência deve ser validada por conexão. Ao confirmar ou consultar uma baixa já existente, sua data original é preservada; datas posteriores informadas ficam no evento e na cobrança. Uma divergência de data deve ser tratada na revisão/conciliação, sem reescrita retroativa silenciosa.

A consulta pode informar valor nominal, não a composição efetivamente recebida. Por isso:

- Cobranças com encargos/desconto configurados exigem revisão quando a consulta não comprova a composição.
- A primeira liquidação nominal simples pode ser recuperada por consulta quando valor e data são suficientes e não há efeito financeiro conflitante.
- Não se deduz um novo pagamento apenas subtraindo baixas anteriores de um valor de consulta sem semântica comprovada.
- Juros, multa e desconto da baixa são valores absolutos em reais; não são automaticamente copiados de percentuais configurados no contrato bancário.

#### Semântica dos webhooks por conexão

| Configuração | Comportamento |
| --- | --- |
| `unknown` — padrão | Recebimentos parciais/adicionais ambíguos vão para revisão |
| `incremental` | Após homologação, cada valor representa um recebimento incremental |
| `cumulative` | Após homologação, aplica somente a diferença positiva frente ao total bancário já representado |

No modo acumulado, repetir o mesmo total não gera caixa. Um total inferior ao registrado exige revisão, em vez de produzir um estorno presumido.

O status da parcela e o status da cobrança são distintos. A parcela pode ter sido quitada por outros meios sem que o boleto original esteja integralmente pago. A cobertura da cobrança considera o principal representado por suas próprias baixas, com juros/multa/desconto identificados.

Baixas bancárias confirmadas são protegidas contra exclusão ou alteração financeira pelos serviços. A confirmação de baixa por vencimento continua automática quando o vínculo é inequívoco. Quando há ambiguidade, um operador pode vincular explicitamente uma baixa existente ou reconhecer um evento já representado, preservando seu movimento e evento original.

### 2.7. Devolução já realizada pelo banco

Status `7` não dispara saída de dinheiro automaticamente. A central permite **Registrar devolução já confirmada**, para um recebimento confirmado, vinculado à cobrança, devolvido integralmente.

O fluxo:

1. Identifica recebimento e movimento originais.
2. Exige data e justificativa.
3. Cria contrapartida de saída vinculada ao movimento original.
4. Preserva a baixa e registra `returned_amount`.
5. Recalcula o recebido líquido e reabre o saldo correspondente.
6. Impede repetição do mesmo estorno.

Juros/descontos já registrados não são renegociados implicitamente pela devolução. A obrigação é recalculada com a composição financeira existente.

Não há chamada para solicitar devolução PIX ao banco. O contrato/SDK consultado não oferece esse método. Devolução parcial, múltiplas parcelas de devolução e reconstrução de recebimentos antigos sem vínculo comprovado exigem evolução específica.

### 2.8. Webhooks

O endpoint existente `/webhook/bank-slips/{connection}` recebe também PIX quando o vínculo local identifica a modalidade.

- Mantém validação de CNPJ/CPF e assinatura documentada.
- Aceita o evento `TESTE`, sem criar cobrança ou evento financeiro.
- Permite autenticação adicional pelo header `X-Webhook-Token`, configurado nas credenciais criptografadas da conexão e no painel IntegraBancos.
- Persiste o evento antes de despachar o processamento pós-commit.
- Responde 503 se a persistência falha, permitindo o reenvio pelo provedor. Assinaturas inválidas não produzem efeitos.

O endpoint NFe conserva seu contrato próprio de HTTP 200; seus testes fazem parte da suíte.

### 2.9. Central de pendências e auditoria

Navegação: **Financeiro → Pendências bancárias**.

Arquivos em `app/Filament/Clusters/Financial/Resources/BankingEvents/`.

Existem abas de pendências e histórico completo. A página mostra cobrança, banco, fonte, operação, status do evento/cobrança, erro e tentativas.

Ações:

- Consultar cobrança/operação novamente.
- Reprocessar evento com justificativa.
- Solicitar reenvio sujeito às regras de recuperação.
- Resolver recebimento com composição ou vínculo explícito.
- Registrar devolução integral já confirmada.
- Encerrar evento sem efeito financeiro quando elegível, preservando justificativa. Pendências financeiras vinculadas não podem ser descartadas por esse atalho.
- Consultar retorno e histórico recente; os demais eventos ficam na aba de histórico.

Listagens e opções são filtradas pela empresa. Os serviços revalidam acesso e pertencimento, inclusive nas ações de componentes já montados. Utiliza-se a permissão existente `account_receivables.access`; não foi criada uma nova matriz de alçadas nesta fase.

Operações/resoluções são correlacionadas com a auditoria existente. O reinício operacional também reconhece o novo ledger e sua ordem de exclusão por FKs; não desabilita constraints.

## 3. Banco de dados

Migration: `database/migrations/2026_10_16_000002_create_banking_operations.php`.

Adiciona:

- `banking_events`, com vínculos à empresa, conexão e cobrança, chaves de deduplicação e índices de acompanhamento.
- Metadados de solicitação/tentativa e snapshots em `bank_slips`/`pix_charges`.
- `banking_event_id` único opcional e `returned_amount` nos recebimentos.

As somas de recebimentos e o diagnóstico `financial:audit-integrity` foram adequados ao valor líquido após devolução. Registros anteriores não recebem confirmação bancária nem identidade reconstruída por inferência.

## 4. Agendamento e filas

As consultas à IntegraBancos são solicitadas exclusivamente pelas actions manuais do financeiro. Os comandos de consulta em lote e seus agendamentos foram removidos. A atualização local da tela PIX e as retentativas de emissão, alteração e cancelamento não disparam consultas bancárias. Webhooks continuam atualizando as cobranças pelo processamento existente.

Os jobs bancários de I/O utilizam timeout de 300 segundos e lock de cobrança de 330 segundos. O supervisor bancário do Horizon foi ajustado para 300 segundos. Defaults de `retry_after` em Redis/database passaram para 360 segundos.

Variáveis de ambiente existentes prevalecem sobre defaults: conferir `REDIS_QUEUE_RETRY_AFTER` e `DB_QUEUE_RETRY_AFTER` no ambiente de implantação. O timeout do worker/supervisor precisa ser inferior ao `retry_after`. O SDK utiliza 60 segundos por chamada; autenticação, consulta de evento e consulta da cobrança podem ocorrer em sequência. Se o job for interrompido, suas operações já persistidas devem ser retomadas por consulta/reprocessamento.

## 5. Verificações realizadas

- Suíte completa via `composer test`: **713 testes aprovados**.
- Nova cobertura em `tests/Feature/Services/Financial/BankingOperationsTest.php`: **32 cenários**.
- Testes anteriores de boleto/PIX ajustados para retornos documentados: `sucesso`, datas bancárias e distinção entre aceite e conclusão.
- Verificação de formatação com Laravel Pint e de whitespace com Git.

Entre as regressões estão: timeout sem novo POST, consulta de evento, pagamento por consulta, notificações antigas, datas ausentes, encargos, vínculo manual, devolução com histórico, rollback financeiro, PIX por webhook, teste de URL, falha de persistência, OAuth, reenvio condicionado, valores acumulados, acesso entre empresas e reinício operacional.

As integrações nos testes utilizam mocks/fakes. SQLite em memória e fila síncrona não comprovam isolamento/concorrência de MySQL/PostgreSQL, locks distribuídos ou comportamento real de múltiplos workers.

## 6. Passos seguintes

### 6.1. Implantar a estrutura

1. Aplicar a nova migration no procedimento normal de release:

   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   ```

2. Conferir os valores efetivos de timeout/retry e o cache compartilhado usado pelos workers.
3. Reiniciar os processos conforme a instalação: `php artisan horizon:terminate` no Horizon ou `php artisan queue:restart` para workers tradicionais.
4. Garantir consumo da fila `banking` e execução do scheduler; conferir com `php artisan schedule:list`.
5. Executar o diagnóstico financeiro por empresa e revisar inconsistências históricas:

   ```bash
   php artisan financial:audit-integrity --company=1
   ```

Nenhuma migration ou correção foi aplicada a um banco operacional nesta implementação; as migrations foram exercitadas pelo banco de testes.

### 6.2. Homologar cada conexão utilizada

1. Conferir emitente, ambiente, chaves e credenciais bancárias cadastradas na IntegraBancos.
2. Preencher contrato de conta/PIX e dados cadastrais exigidos pelo banco.
3. Configurar a URL de webhook e, se utilizado, seu header adicional; validar `TESTE`.
4. Emitir e acompanhar geração, documentos e consulta.
5. Validar alteração/cancelamento assíncronos com `evento_id`.
6. Validar pagamento simples, pagamento após baixa automática e recuperação sem webhook.
7. Capturar exemplos reais de parcial, encargos e desconto, preservando dados sensíveis fora de fixtures públicas.
8. Somente então selecionar `incremental` ou `cumulative` para aquela conexão.
9. Confirmar se `valor` da consulta é nominal/recebido, o formato/fuso das datas e a existência de identificador bancário estável de liquidação.
10. Exercitar devolução integral e sua conciliação com o extrato.

A documentação possui uma divergência entre um exemplo e a rota apresentada de consulta PIX. O adaptador utiliza o método do SDK v1.0.3, que envia banco e identificação. Essa rota precisa integrar a homologação real.

### 6.3. Validar concorrência na infraestrutura operacional

Exercitar com múltiplos workers e banco/cache compartilhados: webhook versus consulta; duas renovações de token; interrupção após aceite; repetição de evento; resolução simultânea; consulta de cancelamento durante pagamento. Comparar recebimentos, movimentos, eventos e extrato após cada caso.

### 6.4. Evoluções posteriores

| Prioridade | Evolução |
| --- | --- |
| Alta | Ampliar fixtures contratuais por banco e a validação local de padrões/limites específicos |
| Alta | Utilizar identificador bancário de liquidação quando disponibilizado, reduzindo revisões ambíguas entre canais |
| Alta | Alçadas e permissões específicas para resolver recebimento, devolver e reenviar |
| Média | Devoluções parciais e alocações detalhadas de encargos/tarifas |
| Média | Alertas de pendências antigas e métricas de tempo de geração, liquidação e recuperação |
| Média | Política de retenção/arquivamento do ledger e observabilidade de filas |
| Conforme contrato | Novas operações do provedor, inclusive eventual solicitação de devolução PIX, somente se passarem a existir no contrato/SDK |

O desenvolvimento automatizado desta entrega está verificado pela suíte. O próximo marco operacional é homologar as conexões efetivamente utilizadas e incorporar os retornos comprovados como fixtures e regras adicionais.
