# Fluxo Remessa → Ordem de Serviço → Retorno (NFe)

## Objetivo
Padronizar na Tornedon o cenário em que o cliente envia equipamentos via **NF-e de remessa para conserto**, a equipe executa o reparo via **ordem de serviço (OS)** e ao final emite uma **NF-e de retorno**.

Este desenho também cobre o caso em que **não existe NF de remessa** na entrada.

---

## Premissas aprovadas
1. **Uma OS trata apenas 1 equipamento**. Se houver mais equipamentos, devem ser criadas OS separadas.
2. A tabela principal de rastreio será chamada **`remittance_assets`**.
3. Pode existir tabela pivô **`service_order_received_assets`** para rastrear vínculo de ativos de remessa com a OS.
4. O vínculo entre **NF de entrada e NF de saída** deve ser em **pivot N:N**.
5. Em retorno parcial, deve haver consistência de **quantidade e valor** conforme política fiscal.
6. Deve guardar referência da **chave da NF de origem** no retorno para rastreabilidade.

---

## Situação atual (resumo)
- Já existem `fiscal_documents` e `fiscal_document_items`.
- A OS já possui vínculo de equipamento único (`equipment_id`).
- Falta modelagem de rastreio fiscal/quantitativo entre item recebido e item retornado.
- Falta campo para registrar itens recebidos na OS quando não há NF de remessa.

---

## Modelagem proposta

### 1) Service Orders: campo para entrada sem NF
Adicionar em `service_orders` o campo:
- `items_received` (TEXT ou JSON)

Objetivo:
- permitir que o usuário registre manualmente o que foi recebido (descrição, condição, observações), quando não existir NF de remessa;
- manter histórico operacional da OS com contexto de recebimento.

Exemplo de conteúdo:
- “1 impressora Zebra ZT230, sem cabo, tampa trincada.”
- “2 placas eletrônicas, cliente relata curto intermitente.”

### 2) Tabela `remittance_assets`
Representa os ativos/itens recebidos a partir da NF de remessa.

Campos sugeridos:
- `id`
- `company_id`
- `fiscal_document_id` (NF entrada)
- `fiscal_document_item_id` (item NF entrada)
- `product_id` (nullable)
- `equipment_id` (nullable)
- `serial_number` (nullable)
- `lot_number` (nullable)
- `received_quantity` (default 1)
- `returned_quantity` (default 0)
- `status` (`received`, `in_repair`, `ready_to_return`, `returned`, `canceled`)
- `metadata` (json)
- `created_by`, `updated_by`, `timestamps`

### 3) Tabela pivô `service_order_received_assets`
Mesmo com regra de 1 equipamento por OS, esta pivot é útil para:
- vincular formalmente o item da remessa tratado naquela OS;
- controlar quantidade alocada/retornada por OS em cenários de quantidade;
- manter rastreabilidade operacional e fiscal.

Campos:
- `service_order_id`
- `remittance_asset_id`
- `quantity_allocated`
- `notes`
- `created_at`, `updated_at`

### 4) Vínculo N:N entre item de entrada e item de retorno
Criar pivot fiscal (exemplo): `fiscal_document_item_origins`.

Campos sugeridos:
- `id`
- `origin_fiscal_document_id`
- `origin_fiscal_document_item_id`
- `return_fiscal_document_id`
- `return_fiscal_document_item_id`
- `linked_quantity`
- `linked_value`
- `origin_document_key` (chave da NF de origem)
- `metadata` (json)
- `created_at`, `updated_at`

Com isso, suporta:
- 1 item de entrada gerando vários itens de retorno (parciais);
- consolidação de vários itens de entrada em item(ns) de retorno;
- trilha fiscal completa entre origem e saída.

---

## Regras de negócio
1. Não permitir retorno com quantidade maior que saldo disponível:
   - `saldo = received_quantity - returned_quantity`.
2. Retorno parcial permitido.
3. Em retorno parcial, validar consistência de **quantidade e valor** conforme política fiscal da empresa.
4. Persistir a **chave da NF de origem** no vínculo de retorno.
5. Bloquear inconsistências (ex.: retorno sem origem quando operação exigir referência).
6. Manter auditoria de usuário/data/hora para alterações de vínculo e saldo.

---

## Fluxo operacional
1. Usuário importa/lança NF de remessa (quando houver).
2. Sistema gera registros em `remittance_assets` por item/quantidade.
3. Usuário cria OS (1 equipamento por OS).
4. Se não houver NF de remessa, usuário preenche `items_received` na OS.
5. Ao fechar OS, sistema prepara proposta de NF de retorno com base no saldo.
6. Na emissão/autorização do retorno:
   - grava vínculos N:N em pivot fiscal;
   - atualiza `returned_quantity`;
   - guarda `origin_document_key`.

---

## Entregas técnicas sugeridas

### Banco
- migration: add `items_received` em `service_orders`;
- migration: create `remittance_assets`;
- migration: create `service_order_received_assets`;
- migration: create `fiscal_document_item_origins` (pivot N:N entrada ↔ saída).

### Backend
- Model `RemittanceAsset`.
- Ajustar `ServiceOrder` (`items_received` em fillable/casts).
- Serviço de domínio para:
  - registrar ativos de remessa;
  - alocar vínculo com OS;
  - gerar draft de retorno por saldo;
  - confirmar retorno e baixar saldo.

### UI (Filament)
- Fiscal Document (entrada): ação “Gerar ativos de remessa”.
- OS: campo `items_received` visível quando não houver NF de remessa vinculada.
- Retorno: wizard com preview de saldo, quantidade e valor por origem.
