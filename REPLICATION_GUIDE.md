# Guia de Uso: Sistema de Replicação de Partners e Equipments

## Visão Geral
Sistema multitenancy que permite replicar Partners (parceiros) e Equipments (equipamentos) entre múltiplas empresas de forma independente (sem sincronização).

---

## 3 Mecanismos de Replicação

### 1️⃣ **Replicação Manual (Botão no Filament)**

#### Replicar um Partner existente:
1. Navegue até **Parceiros** → editar um parceiro
2. Clique no botão **"Replicar para outras empresas"** (ícone de seta roxa no header)
3. Modal abrirá solicitando as empresas de destino
4. Selecione as empresas e clique em **"Replicar"**
5. Receberá notificação de sucesso/erro

#### Replicar um Equipment existente:
1. Navegue até **Equipamentos** → editar um equipamento
2. Clique no botão **"Replicar para outras empresas"**
3. Selecione as empresas de destino
4. Confirme a replicação

---

### 2️⃣ **Replicação Automática (Checkbox ao Criar)**

#### Ao criar um Partner:
1. Navegue até **Parceiros** → **Novo Parceiro**
2. Preencha os dados normalmente
3. Na seção **"Replicar para outras Empresas"**, selecione as empresas destinatárias
4. Clique em **"Salvar"**
5. O Partner será criado NA EMPRESA ATUAL + replicado para as demais automaticamente

#### Ao criar um Equipment:
1. Navegue até **Equipamentos** → **Novo Equipamento**
2. Preencha os dados normalmente
3. Na seção **"Replicar para outras Empresas"**, selecione as empresas destinatárias
4. Clique em **"Salvar"**
5. O Equipment será criado + replicado automaticamente

---

### 3️⃣ **Replicação em Lote (CLI/Command)**

#### Syntax do comando:
```bash
php artisan app:replicate-data {type} --source-id={id} --target-companies={ids} [--confirm]
```

#### Exemplos:

**Replicar um Partner com confirmação:**
```bash
php artisan app:replicate-data partner --source-id=5 --target-companies=2,3,4 --confirm
```

**Replicar um Equipment (sem --confirm, irá perguntar):**
```bash
php artisan app:replicate-data equipment --source-id=12 --target-companies=1,2
```

**Interativo (sem especificar ID):**
```bash
php artisan app:replicate-data partner --target-companies=2,3
# Irá pedir o ID do registro
```

#### Output do comando:
- Tabela mostrando empresas com sucesso ✓
- Tabela mostrando falhas com mensagens de erro ✗
- Código de saída 0 (sucesso) ou 1 (falha)

---

## Regras de Negócio

### Partners
- ✓ Copia o Partner base + tódos os dados relacionados:
  - Endereços (via CompanyPartner)
  - Contatos (via CompanyPartner)
  - Configurações específicas da empresa (tipo, limite de fatura, status)
- ✗ Não pode replicar se o Partner já está vinculado à empresa alvo
- ✓ Dados copiados são **independentes** (editar em uma empresa não afeta outras)

### Equipments
- ✓ Copia o Equipment com todos os campos:
  - Nome, tipo, placa, série, marca, modelo
  - Proprietário (Partner owner_id)
- ✗ Falha se um Equipment com a mesma placa/série já existe na empresa alvo
- ✗ Falha se o Partner proprietário não existe na empresa alvo
- ✓ Dados copiados são **independentes**

---

## Tratamento de Erros

### Erros comuns e soluções:

| Erro | Causa | Solução |
|------|-------|--------|
| "Partner já está vinculado a esta empresa" | Partner já replicado | Desvin cule ou use empresa diferente |
| "Um equipamento com a mesma placa/serial já existe" | Equipment duplicado | Remova o duplicate ou use empresa diferente |
| "O Partner dono não está vinculado a esta empresa" | Equipment owner não existe lá | Repl ique o Partner ANTES do Equipment |
| "Empresa não existe" | ID de empresa inválido | Verifique o ID da empresa |

---

## Arquitetura Interna

```
ReplicationService (app/Services/DataReplication/)
├── PartnerReplicationHandler
│   ├── Copia Partner base
│   ├── Copia CompanyPartner (com dados específicos)
│   ├── Copia Addresses
│   └── Copia Contacts
│
└── EquipmentReplicationHandler
    └── Copia Equipment com validações de owner

Mecanismos:
├── Botão Filament (ReplicateToCompaniesAction)
├── Listeners de Eloquent (ReplicatePartnerOnCreate, ReplicateEquipmentOnCreate)
└── Command CLI (ReplicateDataCommand)

Helpers:
└── Models: Partner::replicateTo() e Equipment::replicateTo()
```

---

## Permissões

- ✓ Qualquer usuário multi-tenant pode replicar
- ✓ Limitado às empresas do usuário
- ✗ Não há necessidade de permissões especiais

---

## Logs

Replicações automáticas (via listeners) são logadas em:
```
storage/logs/laravel.log
```

Busque por:
- `"Failed to replicate Partner after creation"`
- `"Failed to replicate Equipment after creation"`

---

## Dicas e Best Practices

1. **Ao replicar Equipment**: Sempre replique o Partner owner ANTES
2. **Dados sincronizados**: Após replicação, os dados são independentes — edições em uma empresa não afetam outras
3. **Validações**: O sistema valida automaticamente conflitos e dados faltantes
4. **UI**: Use o botão no Filament para replicações individuais; use CLI para lotes

---

## Próximas Melhorias (Opcional)

- [ ] Adicionar campo de "data de replicação" nos registros
- [ ] Implementar sincronização bidirecional (opcional)
- [ ] Dashboard de histórico de replicações
- [ ] Função de desfazer replicação última
- [ ] API endpoint para replicação externa
