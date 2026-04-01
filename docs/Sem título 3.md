# Fluxo Remessa → Ordem de Serviço → Retorno (NFe)

## Objetivo
Padronizar na Tornedon o cenário em que o cliente envia um ou mais equipamentos via **NF-e de remessa para conserto**, a equipe executa o reparo via **ordem de serviço (OS)** e ao final emite uma **NF-e de retorno de mercadoria**.

Esse fluxo cobre:
- múltiplos itens na mesma NF de remessa;
- múltiplas unidades do mesmo item (quantidade > 1);
- rastreabilidade entre NF de entrada, OS e NF de retorno.

---

## Situação atual (base)
Hoje já existe:
- cadastro de documentos fiscais em `fiscal_documents` e itens em `fiscal_document_items`;
- confirmação de documento de entrada (ação de confirmação na resource de fiscal document);
- OS com vínculo de `equipment_id` único por ordem de serviço;
- emissão fiscal a partir de faturamento/integrações já existentes no módulo financeiro.

Gap principal para esse caso de negócio:
- não há modelagem explícita para relacionar **cada unidade recebida na remessa** com o ciclo de reparo e retorno;
- `service_orders.equipment_id` permite 1 equipamento por OS, mas a remessa pode trazer N equipamentos e/ou quantidade N do mesmo item.

---

## Estratégia recomendada

### 1) Criar uma entidade de rastreio por unidade recebida
Criar tabela `received_assets` (ou `remittance_assets`) para representar cada unidade física recebida da remessa.

Campos sugeridos:
- `id`
- `company_id`
- `fiscal_document_id` (NF de remessa origem)
- `fiscal_document_item_id` (item da NF origem)
- `product_id` (se houver)
- `equipment_id` (nullable: quando existir cadastro de equipamento)
- `serial_number` (nullable)
- `lot_number` (nullable)
- `received_quantity` (default 1)
- `returned_quantity` (default 0)
- `status` (`received`, `in_repair`, `ready_to_return`, `returned`, `canceled`)
- `metadata` (json para marca/modelo/defeito informado na NF)
- auditoria (`created_by`, `updated_by`, timestamps)

> Para item com quantidade 5 sem número de série, você pode:
> - criar 5 registros de unidade (`received_quantity = 1`) para rastreio fino; ou
> - criar 1 registro com `received_quantity = 5` quando não houver controle unitário.
>
> Recomendação: permitir os dois modos por configuração da operação.

### 2) Permitir OS com múltiplos ativos recebidos
Adicionar tabela pivô `service_order_received_assets`:
- `service_order_id`
- `received_asset_id`
- `quantity_allocated`
- `notes`

Com isso, uma OS pode tratar:
- um único ativo;
- vários ativos da mesma remessa;
- ativos de remessas diferentes (se a regra comercial permitir).

### 3) Gerar itens de retorno com saldo pendente
No fechamento da OS, montar prévia da NF de retorno somente com saldo pendente:

`saldo_para_retorno = received_quantity - returned_quantity`

Na emissão/autorização da NF de retorno:
- registrar vínculo do item de retorno com `received_asset_id` (tabela auxiliar `fiscal_document_item_links` ou campo direto);
- incrementar `returned_quantity`;
- quando saldo zerar, marcar `received_asset.status = returned`.

### 4) Vínculo fiscal explícito entre nota origem e retorno
Para auditoria e SPED, criar referência de origem:
- `origin_fiscal_document_id`
- `origin_fiscal_document_item_id`

Isso pode ficar:
- em `fiscal_document_items` (campos nullable), ou
- em tabela de relacionamento (`fiscal_document_item_origins`) para suportar N:N.

Se você precisa consolidar vários itens de entrada em 1 item de retorno, prefira tabela N:N.

---

## Regras de negócio essenciais
1. **Não retornar além do recebido** por item/unidade.
2. **Permitir retorno parcial** (ex.: recebeu 10, retornou 4, ficou saldo 6).
3. **Bloquear cancelamento da NF de remessa** se já houver retorno autorizado vinculado.
4. **Bloquear exclusão de OS fechada** quando houver vínculo com retorno fiscal.
5. **Manter trilha** de usuário, data/hora e chave da NF de origem/retorno.
6. **CFOP de retorno parametrizável** por perfil fiscal/operação.

---

## Ajustes sugeridos no Tornedon

### Banco de dados
- migration `create_received_assets_table`
- migration `create_service_order_received_assets_table`
- migration para origem/destino fiscal entre itens (`fiscal_document_item_origins`)

### Backend (Laravel)
- Model `ReceivedAsset`
- Relacionamentos:
  - `FiscalDocument hasMany ReceivedAsset`
  - `FiscalDocumentItem hasMany ReceivedAsset`
  - `ServiceOrder belongsToMany ReceivedAsset` (pivot)
- Serviço de domínio:
  - `App\Services\Fiscal\RemittanceReturnService`
  - métodos:
    - `registerFromIncomingRemittance(FiscalDocument $document)`
    - `allocateToServiceOrder(ServiceOrder $order, array $assets)`
    - `buildReturnDraftFromServiceOrder(ServiceOrder $order)`
    - `confirmReturnEmission(FiscalDocument $returnDocument)`

### Filament/UI
- Em **FiscalDocument (entrada)**:
  - ação “Gerar ativos recebidos” após confirmação da NF.
- Em **ServiceOrder**:
  - trocar seleção única de equipamento por seletor multi-itens de ativos recebidos;
  - exibir saldo pendente por ativo.
- Em **FiscalDocument (retorno)**:
  - wizard “Gerar retorno por OS” com preview de quantidades disponíveis.

---

## Fluxo operacional (passo a passo)
1. Usuário lança/importa NF de remessa de entrada.
2. Sistema confirma a NF e gera `received_assets` por item/quantidade.
3. Usuário abre OS e aloca um ou mais ativos recebidos.
4. Técnicos executam reparo e encerram OS.
5. Usuário aciona “Gerar NF de retorno” na OS.
6. Sistema calcula saldos, monta itens e exige validação fiscal.
7. Após autorização da NF de retorno, sistema baixa `returned_quantity` e atualiza status dos ativos.

---

## Cenários de múltiplos equipamentos

### A) Mesmo item, quantidade maior que 1
- NF de remessa: Item X quantidade 3.
- OS 1 repara 2 unidades.
- NF de retorno 1 retorna 2 unidades.
- Fica saldo de 1 unidade para retorno futuro.

### B) Vários itens diferentes
- NF de remessa com Item A (qtd 1) + Item B (qtd 2).
- OS pode tratar só Item A e 1 unidade do Item B.
- Retorno parcial respeita saldo por item/unidade.

---

## Plano de entrega (incremental)

### Fase 1 (MVP)
- modelagem `received_assets`;
- vínculo de ativos na OS;
- geração manual da NF de retorno com validação de saldo.

### Fase 2
- wizard automático OS → NF retorno;
- vínculos fiscais N:N entre itens origem/destino;
- relatórios de saldo pendente por cliente/remessa.

### Fase 3
- automações fiscais avançadas (CFOP/CSOSN/CST por operação);
- validações anti-duplicidade por chave+item+série/serial.

---

## Observações fiscais (funcional)
- Validar com contador quais CFOPs serão usados para:
  - entrada de remessa para conserto;
  - retorno de mercadoria consertada;
  - retorno sem conserto e/ou troca.
- Em retorno parcial, garantir consistência de quantidade e valor conforme política fiscal da empresa.
- Guardar referência da chave da NF de origem no documento de retorno para rastreabilidade.
