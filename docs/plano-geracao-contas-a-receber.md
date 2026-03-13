# Plano de geração do Contas a Receber

## Objetivo
Padronizar o processo de geração do **Contas a Receber (AR)** com base na sequência:
1. Geração da fatura
2. Geração do documento fiscal
3. Geração do contas a receber

> Regra obrigatória: o AR deve respeitar o **método de pagamento definido na fatura**.

---

## Escopo funcional

### Entradas obrigatórias
- Fatura aprovada para faturamento.
- Método de pagamento definido na fatura (ex.: boleto, PIX, cartão, transferência, dinheiro).
- Dados financeiros da fatura:
  - Valor Total (totalAmount: Attribute)
  - Descontos (discauntAmount: Attribute)
  - Valor líquido (netValue: Attribute)
- Condição de pagamento (à vista, parcelado, prazo em dias).

### Saídas esperadas
- Documento fiscal emitido e vinculado à fatura.
- Título(s) de contas a receber criado(s) com:
  - Valor(es)
  - Data(s) de vencimento
  - Método de pagamento herdado da fatura
  - Status inicial (aberto/pendente)
  - Referência cruzada para fatura e documento fiscal

---

## Fluxo de processo (alto nível)

### Etapa 1 — Geração da fatura
1. Criar/confirmar fatura com todos os campos obrigatórios.
2. Validar se existe método de pagamento definido.
3. Validar consistência de valores e cliente.
4. Marcar fatura como “pronta para emissão fiscal”.

**Critérios de aceite:**
- Fatura sem erro de validação.
- Método de pagamento obrigatório preenchido.

### Etapa 2 — Geração do documento fiscal
1. Receber fatura pronta para emissão.
2. Emitir documento fiscal (NF-e/NFS-e conforme regra de negócio).
3. Persistir número, série, chave e status de autorização.
4. Vincular documento fiscal à fatura.

**Regras de bloqueio:**
- Se a emissão fiscal falhar, não gerar AR.
- Permitir retentativa de emissão fiscal.

**Critérios de aceite:**
- Documento fiscal autorizado (ou status permitido pela operação).

### Etapa 3 — Geração do Contas a Receber
1. Ler dados da fatura e documento fiscal autorizado.
2. Determinar plano financeiro:
   - À vista: 1 título
   - Parcelado: N títulos conforme condição
3. Gerar título(s) AR com valor e vencimento por parcela.
4. Aplicar **método de pagamento da fatura** em todos os títulos (ou conforme regra por parcela, se previsto).
5. Registrar histórico/auditoria da origem (fatura + documento fiscal).
6. Publicar evento de integração (quando existir): `ar.created`.

**Critérios de aceite:**
- Total dos títulos AR = valor líquido da fatura.
- Método de pagamento do AR = método da fatura.
- Vinculações entre entidades gravadas corretamente.

---

## Regras de negócio obrigatórias

1. **Herança de método de pagamento**
   - AR sempre nasce com o método da fatura.
   - Alteração posterior deve ser controlada por permissão e trilha de auditoria.

2. **Ordem transacional**
   - Não criar AR antes da confirmação do documento fiscal.
   - Em caso de falha na etapa 3, registrar erro e permitir reprocessamento idempotente.

3. **Idempotência**
   - Reprocessar sem duplicar títulos:
     - Chave idempotente recomendada: `invoice_id + fiscal_document_id + installment_number`.

4. **Integridade financeira**
   - Soma das parcelas deve bater com o valor líquido.
   - Diferenças de arredondamento devem seguir política definida (última parcela ajusta centavos).

5. **Status inicial padronizado**
   - Novo AR inicia em `aberto` (ou `pendente`) conforme padrão do sistema.

---

## Modelo sugerido de status

### Fatura
- `draft` → `ready_for_fiscal` → `fiscal_issued` → `ar_generated`

### Documento fiscal
- `pending` → `authorized` | `rejected`

### Contas a receber
- `open` → `partially_paid` → `paid` | `overdue` | `cancelled`

---

## Validações e tratamento de erros

### Antes de emitir fiscal
- Cliente com dados fiscais completos.
- Valores calculados corretamente.
- Método de pagamento informado.

### Antes de gerar AR
- Documento fiscal em status permitido.
- Fatura não cancelada.
- Ausência de AR prévio para a mesma combinação idempotente.

### Erros comuns
- Falha na SEFAZ/Prefeitura (emissão fiscal): fila de retentativa.
- Método de pagamento inválido/inativo: bloquear geração e solicitar correção da fatura.
- Inconsistência de parcelas: bloquear e registrar log de negócio.

---

## Observabilidade e auditoria

- Log estruturado por etapa com `correlation_id`.
- Eventos recomendados:
  - `invoice.ready_for_fiscal`
  - `fiscal_document.authorized`
  - `ar.created`
  - `ar.creation_failed`
- Auditoria mínima:
  - usuário/sistema que executou
  - data/hora
  - valores antes/depois
  - método de pagamento aplicado

---

## Plano de implementação por fases

### Fase 1 — Base do fluxo
- Garantir validação de método de pagamento na fatura.
- Bloqueio de geração AR sem documento fiscal válido.
- Criação de AR à vista (1 título).

### Fase 2 — Parcelamento e idempotência
- Regras de parcelamento completas.
- Chaves idempotentes para evitar duplicidade.
- Reprocessamento seguro.

### Fase 3 — Integrações e governança
- Publicação de eventos.
- Painel de monitoramento e métricas.
- Relatórios de rastreabilidade ponta a ponta.

---

## Checklist de aceite (UAT)

- [ ] Fatura sem método de pagamento não avança no fluxo.
- [ ] Documento fiscal rejeitado não gera AR.
- [ ] Documento fiscal autorizado gera AR automaticamente.
- [ ] AR criado com mesmo método de pagamento da fatura.
- [ ] Parcelas somam exatamente o valor líquido da fatura.
- [ ] Reprocessamento não duplica títulos.
- [ ] Entidades vinculadas (fatura ↔ fiscal ↔ AR).
- [ ] Logs e auditoria disponíveis para rastreamento.
