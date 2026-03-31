# Plano de evolução: NFS-e intercambiável por cidade

## 1) Contexto atual

Hoje o fluxo de montagem de payload NFS-e está centralizado em um dispatcher que escolhe apenas entre dois modelos (`municipal` e `nacional`).

Esse desenho resolve o cenário atual, mas não contempla diferenças específicas por prefeitura quando o modelo continuar sendo municipal (por exemplo: Chapecó vs outra cidade com contrato/validação diferente na API).

## 2) Objetivo

Permitir que o sistema selecione dinamicamente a estratégia de emissão NFS-e com base na **cidade configurada da empresa**, sem quebrar código existente.

Resultado esperado:
- Para adicionar uma nova cidade, criar uma nova classe de estratégia;
- Registrar essa classe no catálogo/factory;
- Alterar somente a configuração da empresa para usar a nova cidade;
- Sem alterar fluxos centrais de envio/consulta.

## 3) Diretrizes de arquitetura

### 3.1 Contrato único para builders NFS-e
Criar uma interface para padronizar estratégias de payload:

- `App\Services\FiscalDocument\Contracts\NfsePayloadBuilder`
  - `supports(FiscalDocument $document): bool`
  - `build(FiscalDocument $document): ?array`
  - `identifier(): string` (ex.: `municipal:4204202`, `nacional:padrao`)

### 3.2 Resolver/Factory por configuração
Criar um resolvedor central:

- `App\Services\FiscalDocument\Resolvers\NfsePayloadBuilderResolver`

Responsabilidades:
- Ler `nfse_model` do documento;
- Ler cidade de emissão da empresa (ex.: `service_city_code` / `city_code` do fiscal profile);
- Compor uma chave de resolução, exemplo:
  - `nacional:*`
  - `municipal:4204202`
- Retornar o builder registrado para aquela chave;
- Se não houver match específico da cidade, aplicar fallback para `municipal:default`.

### 3.3 Registro declarativo
Criar arquivo de configuração para mapear estratégias:

- `config/nfse_builders.php`

Exemplo de mapa:
- `nacional:default` => `BuildNfseNacionalPayloadAction`
- `municipal:4204202` => `BuildNfseMunicipalChapecoPayloadAction`
- `municipal:default` => `BuildNfseMunicipalPayloadAction`

### 3.4 Compatibilidade retroativa
Manter as classes existentes funcionando como fallback:
- `BuildNfseMunicipalPayloadAction` vira “municipal genérico/default”;
- `BuildNfseNacionalPayloadAction` segue igual.

## 4) Mudanças planejadas por camada

## 4.1 Domínio e configuração de empresa
1. Garantir um campo de configuração da empresa/perfil fiscal para cidade de emissão (IBGE).
2. Definir precedência:
   - `fiscal_document.city_override` (se houver);
   - `fiscal_profile.default_service_city_code`;
   - `company.address.city_code` (fallback).
3. Expor helper único para resolver cidade efetiva de emissão.

## 4.2 Aplicação (orquestração)
1. Refatorar `BuildNfsePayloadAction` para usar `NfsePayloadBuilderResolver` em vez de `match` fixo.
2. Padronizar tratamento de erro quando nenhuma estratégia for encontrada (mensagem com chave de resolução).
3. Registrar logs com:
   - `builder_identifier`;
   - `resolution_key`;
   - `company_id`, `fiscal_document_id`.

## 4.3 Implementação por cidade
1. Criar primeira estratégia específica:
   - `BuildNfseMunicipalChapecoPayloadAction`.
2. Reaproveitar ao máximo a estratégia municipal atual por herança/composição para reduzir duplicação.
3. Para nova cidade futura:
   - criar `BuildNfseMunicipal{Cidade}PayloadAction`;
   - mapear no `config/nfse_builders.php`;
   - atualizar configuração da empresa.

## 4.4 Testes
1. **Unit** do resolver:
   - resolve nacional default;
   - resolve municipal por código IBGE;
   - aplica fallback municipal default.
2. **Feature** de emissão:
   - empresa Chapecó usa builder Chapecó;
   - empresa outra cidade sem builder específico usa municipal default.
3. **Contrato**:
   - garantir que todos os builders registrados implementam interface.

## 5) Plano de execução (fases)

### Fase 1 — Fundação (baixo risco)
- Criar interface + resolver + config map;
- Adaptar `BuildNfsePayloadAction` para novo resolvedor;
- Manter comportamento idêntico ao atual via mapeamento default.

### Fase 2 — Cidade específica
- Extrair comportamento de Chapecó para classe dedicada;
- Apontar `municipal:4204202` para a nova classe;
- Validar em homologação.

### Fase 3 — Governança e expansão
- Documentar “como adicionar uma nova cidade” em checklist técnico;
- Criar teste template reutilizável para novos builders;
- Opcional: painel administrativo para escolher estratégia por empresa sem deploy.

## 6) Critérios de aceite

1. Alterar cidade de emissão da empresa muda a estratégia sem alteração de código de fluxo.
2. Se não existir builder da cidade, emissão continua usando fallback municipal default.
3. Fluxo nacional não sofre regressão.
4. Logs permitem identificar claramente qual estratégia foi usada.
5. Adicionar nova cidade exige apenas:
   - nova classe;
   - entrada em config;
   - atualização da configuração da empresa.

## 7) Riscos e mitigação

- **Risco:** divergência de payload entre cidades.
  - **Mitigação:** testes de snapshot por cidade + homologação por município.

- **Risco:** proliferar if/else dentro dos builders.
  - **Mitigação:** regra de arquitetura: variação por cidade deve virar classe própria.

- **Risco:** erro de configuração da empresa.
  - **Mitigação:** validação de código IBGE e fallback explícito com log de warning.

## 8) Definição de pronto (DoD)

- Resolver ativo em produção;
- Builders atuais migrados para contrato;
- Pelo menos 1 builder específico por cidade (Chapecó) publicado;
- Testes de resolução e fallback passando;
- Documentação técnica para onboarding de nova cidade publicada.
