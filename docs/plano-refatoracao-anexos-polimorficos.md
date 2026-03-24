# Plano de Refatoração — Anexos Polimórficos

## Objetivo
Evoluir a implementação atual de anexos (hoje orientada a `ServiceOrder`/`ProductionOrder`) para um modelo **genérico e reutilizável** por qualquer entidade, com:

- tabela central de anexos (polimórfica);
- relacionamento `HasMany` no Model aderente (via trait/contrato);
- controle de idempotência e cardinalidade por tipo de documento;
- serviço de domínio para upload/download/delete/versionamento;
- integração com Filament (`FileUpload`) sem acoplamento a um módulo específico;
- armazenamento em disco local por padrão, com troca simples para S3/R2.

---

## 1) Levantamento do que existe hoje (estado atual)

## 1.1 Banco de dados
- Existe a migration `create_order_attachments_table` criando a tabela **`order_attachments`** com:
  - `attachable_type`/`attachable_id` (`morphs`),
  - `disk`, `path`, `original_name`, `mime_type`, `size_bytes`, `uploaded_by`, timestamps.
- Não há hoje:
  - identificador público do anexo (ULID/UUID externo),
  - tipagem funcional do anexo (ex.: `fiscal_document`, `photo`, `contract`),
  - regra explícita de idempotência/cardinalidade,
  - versionamento lógico (atual x histórico).

## 1.2 Modelos e relacionamentos
- `ServiceOrder` e `ProductionOrder` possuem `attachments(): MorphMany`.
- Ambos possuem hook `deleting` para apagar anexos em cascata manualmente.
- `OrderAttachment` possui `attachable(): MorphTo` e hook `deleted` para remover arquivo físico via service.

## 1.3 Serviço
- Existe `OrderAttachmentStorageService` com operações `create`, `update`, `delete`, remoção física e geração de nome.
- O serviço está funcional, porém com semântica acoplada a “Order”:
  - nome da classe,
  - convenção de diretório com `typeDirectoryFor()` mapeando explicitamente `ServiceOrder` e `ProductionOrder`.

## 1.4 UI (Filament)
- Existe `OrderAttachmentsRelationManager` usando `FileUpload`.
- Está plugado diretamente:
  - no formulário de `ProductionOrder`;
  - no formulário de `ServiceOrder` (desktop/mobile).

## 1.5 Download fora do componente Filament
- Existe rota autenticada `/order-attachments/{orderAttachment}` com controller dedicado (`OrderAttachmentController`) e validação por empresa.
- Ou seja, já existe base de “download fora do componente”, porém também acoplada ao naming de “order”.

---

## 2) Problemas/GAPS vs. necessidade

1. **Acoplamento de domínio**: nomenclatura e pontos de integração centrados em “Order”.
2. **Escalabilidade de entidades**: falta um padrão simples para qualquer Model habilitar anexos.
3. **Idempotência e cardinalidade**: não existe regra para “apenas último por tipo” x “múltiplos por tipo”.
4. **Identificador externo**: falta `public_id` para uso em API/URL/log sem expor `id` incremental.
5. **Governança por tipo**: ausência de “catálogo de tipos” e políticas por tipo.
6. **Evolução de storage**: já existe `disk` dinâmico, mas falta padronização clara para alternar local/S3/R2 de forma sistêmica.

---

## 3) Plano de remoção/refatoração do que for necessário

## Fase R1 — Desacoplar naming e contratos (sem quebrar fluxo)
1. Criar nova camada genérica:
   - `Attachment` (novo model) e `AttachmentService` (novo service).
2. Marcar `OrderAttachment`/`OrderAttachmentStorageService` como legados (compatibilidade temporária).
3. Criar interfaces/traits:
   - `HasAttachments` (trait) para adicionar `attachments(): MorphMany` de forma padronizada.

> Meta: parar de introduzir novo código com naming de “order”.

## Fase R2 — Substituir pontos de uso específicos
1. Criar `AttachmentsRelationManager` genérico (substitui `OrderAttachmentsRelationManager`).
2. Atualizar formulários Filament para usar o manager genérico.
3. Criar rota/controller genéricos de download (ex.: `/attachments/{attachment:public_id}/download`).
4. Manter rota antiga com redirect/depreciação por uma janela curta.

> Meta: remover dependência direta de ServiceOrder/ProductionOrder no fluxo de anexos.

## Fase R3 — Migração de dados e limpeza
1. Migrar tabela `order_attachments` → `attachments` (rename ou nova + backfill).
2. Migrar código para `Attachment` como fonte única.
3. Remover classes/rotas legadas após validação.
4. Adicionar testes de regressão para garantir equivalência funcional.

> Meta: eliminar definitivamente o legado “order_*”.

---

## 4) Plano de implementação da solução desejada

## Fase I1 — Modelo de dados alvo

## 4.1 Tabela `attachments` (proposta)
Campos base:
- `id` (bigint interno)
- `public_id` (ULID/UUID, único, indexado) ✅ identificador do anexo
- `attachable_type`, `attachable_id` (morph)
- `company_id` (FK/index para tenancy e autorização)
- `type` (string curta; ex.: `fiscal_document`, `photo`, `contract`)
- `idempotency_key` (nullable; para retries seguros)
- `version` (int, default 1)
- `is_current` (bool, default true)
- `disk`, `path`, `original_name`, `stored_name`, `extension`, `mime_type`, `size_bytes`, `checksum_sha256`
- `metadata` (json)
- `uploaded_by`, `deleted_by` (nullable)
- `created_at`, `updated_at`, `deleted_at` (soft delete)

Índices recomendados:
- `unique(public_id)`
- `index(attachable_type, attachable_id, type, is_current)`
- `unique(attachable_type, attachable_id, type, version)`
- `unique(attachable_type, attachable_id, type, idempotency_key)` (quando `idempotency_key` preenchida)
- `index(company_id, created_at)`

> Observação: dependendo do banco, regra “somente um current por tipo” pode exigir garantia transacional no service (além de índice).

## 4.2 Configuração por tipo (cardinalidade/idempotência)
Criar catálogo em config (`config/attachments.php`):
- `types.fiscal_document`:
  - `mode: single_latest`
  - `allowed_mimes`, `max_size`, `retention`
- `types.service_photo`:
  - `mode: multiple`
- `types.contract`:
  - `mode: single_latest`

`mode`:
- `single_latest`: apenas versão atual por tipo/entidade (anteriores ficam histórico `is_current=false` ou são removidos).
- `multiple`: múltiplos anexos ativos por tipo.

---

## Fase I2 — Serviço de domínio de anexos

Criar `App\Services\Attachments\AttachmentService` com responsabilidades:
1. `upload(Model $owner, UploadedFile|TemporaryUploadedFile $file, string $type, UploadOptions $options)`
2. `replaceCurrent(...)` (atalho para `single_latest`)
3. `delete(Attachment $attachment, DeleteOptions $options)`
4. `downloadResponse(Attachment $attachment, ?string $asName = null)`
5. `existsByIdempotency(...)`
6. `listFor(Model $owner, AttachmentFilters $filters)`

Regras internas do service:
- Resolver disco via config (`attachments.default_disk`) e permitir override por tipo.
- Gerar path por padrão: `attachments/{company_id}/{attachable}/{attachable_id}/{type}/{YYYY}/{MM}/`.
- Calcular `checksum_sha256` para deduplicação opcional.
- Aplicar política de cardinalidade (`single_latest`/`multiple`) em transação.
- Aplicar idempotência por `idempotency_key` (retornar anexo existente em retry).

---

## Fase I3 — Integração com Filament FileUpload

1. Criar componente/relation manager genérico:
   - `AttachmentsRelationManager`.
2. Form com `FileUpload`:
   - `disk()` e `directory()` vindos do service/config,
   - `acceptedFileTypes`, `maxSize` por `type`,
   - suporte a múltiplos quando `mode=multiple`,
   - modo substituição quando `single_latest`.
3. Incluir campo seletor de `type` quando necessário.
4. Em entidades onde o tipo é fixo, esconder seletor e setar default.

---

## Fase I4 — Download fora do Filament

Criar endpoint dedicado (exemplo):
- `GET /attachments/{attachment:public_id}/download`

Controller:
- valida autenticação/autorização por `company_id` e políticas de acesso;
- usa `AttachmentService::downloadResponse()`;
- opcional: endpoint assinado e temporário para compartilhamento externo.

Também considerar:
- `GET /attachments/{public_id}` (metadados);
- `DELETE /attachments/{public_id}` (API/ações externas).

---

## Fase I5 — Adoção nos Models

Estratégia padrão para qualquer Model:
1. adicionar trait `HasAttachments`;
2. garantir presença de `company_id` (ou resolver contexto de tenant por outro caminho);
3. opcionalmente definir tipos permitidos por Model:
   - método `allowedAttachmentTypes(): array`.

Entidades iniciais sugeridas:
- `ServiceOrder` (manter)
- `ProductionOrder` (se ainda fizer sentido)
- futuros: `FiscalDocument`, `Requisition`, etc.

---

## Fase I6 — Estratégia de migração incremental (sem downtime)

1. **Release A**
   - criar nova tabela `attachments` + novos serviços/classes;
   - manter escrita antiga ativa.
2. **Release B**
   - dual-write (opcional) ou backfill de `order_attachments` para `attachments`;
   - trocar leitura para nova tabela.
3. **Release C**
   - remover legado (`order_attachments`, classes e rotas antigas).

Checklist de backfill:
- mapear `order_attachments.id` -> `attachments.legacy_id` (temporário);
- gerar `public_id` para registros antigos;
- definir `type` default inicial (ex.: `generic`), depois refinar;
- recalcular `is_current/version` por agrupamento.

---

## 5) Critérios de aceite (DoD)

1. Um novo Model habilita anexos adicionando apenas trait + policy.
2. Upload via Filament funcionando para `single_latest` e `multiple`.
3. Download funcionando via endpoint externo ao Filament.
4. Storage default local e troca para S3/R2 apenas por config/env.
5. Idempotência garantida para retries (sem duplicação indevida).
6. Testes cobrindo service, autorização, cardinalidade e idempotência.

---

## 6) Plano de testes

## Unitários (service)
- upload simples;
- upload com `idempotency_key` repetida;
- `single_latest` substituindo versão anterior;
- `multiple` aceitando vários anexos;
- delete lógico/físico;
- fallback/erros de storage.

## Integração (HTTP)
- download autorizado (200);
- download sem vínculo com empresa (403);
- attachment inexistente (404);
- link assinado expirado (403/401).

## Filament
- create/edit/delete pelo relation manager;
- validações de MIME/tamanho;
- comportamento correto por `mode`.

---

## 7) Ordem sugerida de execução (prática)

1. Criar schema novo (`attachments`) + model `Attachment`.
2. Implementar `AttachmentService` com testes unitários.
3. Criar trait `HasAttachments`.
4. Criar relation manager genérico Filament.
5. Expor endpoint de download por `public_id`.
6. Migrar `ServiceOrder` primeiro (piloto).
7. Migrar `ProductionOrder` e demais entidades.
8. Remover legado quando estabilizar.

---

## 8) Observações finais

- Padronizar nome da tabela para **`attachments`** (evitar `attachements`, typo).
- Se desejar trilha completa de auditoria, considerar eventos de domínio (`AttachmentUploaded`, `AttachmentDeleted`, `AttachmentReplaced`).
- Se houver necessidade legal (documento fiscal), definir política de retenção e versionamento imutável por tipo.

