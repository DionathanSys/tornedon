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

## 3) Plano de remoção/refatoração do que for necessário (sem legado/depreciação)

## Fase R1 — Remoção direta do acoplamento atual
1. Criar nova camada genérica:
   - `Attachment` (novo model) e `AttachmentService` (novo service).
2. Criar interfaces/traits:
   - `HasAttachments` (trait) para adicionar `attachments(): MorphMany` de forma padronizada.
3. Remover imediatamente classes/rotas antigas de `OrderAttachment` e manager específico de ordem.

> Meta: eliminar o naming de “order” já no primeiro ciclo.

## Fase R2 — Substituir pontos de uso específicos
1. Criar `AttachmentsRelationManager` genérico (substitui `OrderAttachmentsRelationManager`).
2. Atualizar formulários Filament para usar o manager genérico.
3. Criar rota/controller genéricos de download (ex.: `/attachments/{attachment:public_id}/download`).
4. Remover rota antiga sem janela de depreciação (não há uso atual).

> Meta: remover dependência direta de ServiceOrder/ProductionOrder no fluxo de anexos.

## Fase R3 — Limpeza final e validação
1. Remover tabela `order_attachments` e qualquer artefato legado.
2. Consolidar código em `Attachment` como fonte única.
3. Validar permissões/download/upload no novo fluxo.
4. Adicionar testes de regressão para garantir estabilidade.

> Meta: manter apenas arquitetura nova, sem compatibilidade retroativa.

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

> Observação (MySQL): para garantir **exatamente 1 anexo atual** (`is_current = true`) por `(attachable_type, attachable_id, type)`, implementar no `AttachmentService` via transação:
> 1) `SELECT ... FOR UPDATE` no conjunto da entidade+tipo,
> 2) marcar anteriores como `is_current = false`,
> 3) inserir nova versão com `is_current = true`.
> Além disso, manter `unique(attachable_type, attachable_id, type, version)` para evitar colisão de versão.

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

## Fase I6 — Estratégia de implantação direta (MySQL)

1. **Release único**
   - criar tabela `attachments` + novos serviços/classes;
   - apontar UI/rotas/models diretamente para o novo fluxo;
   - remover tabela/código/rotas antigas no mesmo ciclo.

Checklist de implantação:
- remover `order_attachments` e classes relacionadas;
- garantir índices e transações MySQL para `single_latest`;
- validar permissões por `company_id`;
- validar upload/download/delete ponta a ponta no Filament e fora dele.

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
6. Aplicar `HasAttachments` e novo manager nas entidades alvo (`ServiceOrder`, `ProductionOrder` e demais necessárias).
7. Remover classes/rotas/tabela antigas no mesmo ciclo.
8. Executar validação final ponta a ponta (upload, download, delete, autorização por empresa).

---

## 8) Observações finais

- Padronizar nome da tabela para **`attachments`** (evitar `attachements`, typo).
- Se desejar trilha completa de auditoria, considerar eventos de domínio (`AttachmentUploaded`, `AttachmentDeleted`, `AttachmentReplaced`).
- Se houver necessidade legal (documento fiscal), definir política de retenção e versionamento imutável por tipo.
