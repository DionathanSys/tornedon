## 1. Cadastro fiscal por empresa (tenant fiscal profile)

Cada tenant precisa ter um conjunto de parâmetros fiscais próprios, por exemplo:

- dados cadastrais/fiscais da empresa emitente
- regime tributário
- IE, CRT, CNAE, município, UF
- ambientes por tipo de documento e credenciais da API/SEFAZ
- séries, numeração, CSC/certificado/contingência
- parametrização de CFOP padrão por operação
- parametrização de CST/CSOSN, NCM, CEST, origem da mercadoria
- regras de cálculo de ICMS, ICMS-ST, IPI, PIS, COFINS, DIFAL, FCP
- regras por UF, operação, produto, finalidade e destinatário
- regras para devolução, remessa, bonificação, transferência, industrialização
- regras de serviço, caso você emita NFS-e também

Eu criaria algo como:

- `company_fiscal_profiles`
- `company_fiscal_certificates`
- `company_fiscal_series`
- `company_tax_regimes`
- `company_tax_api_credentials`

A ideia é: **empresa** é quem opera; **fiscal profile** é a fotografia da configuração fiscal ativa daquela empresa.

## 2. Motor de regras fiscais versionado

Em vez de gravar “o CFOP do produto” ou “o CST do cliente” de forma fixa, crie uma camada de decisão fiscal baseada em contexto.

### Entidades principais

- `fiscal_operations`
- `fiscal_rules`
- `fiscal_rule_conditions`
- `fiscal_rule_results`
- `fiscal_rule_versions`

### Contexto de decisão

A regra precisa receber um contexto como este:

[  
    'tenant_id' => 1,  
    'company_id' => 10,  
    'document_type' => 'nfe',  
    'operation_type' => 'sale', // sale, transfer, return, remittance...  
    'movement_direction' => 'out',  
    'issuer_uf' => 'SC',  
    'recipient_uf' => 'PR',  
    'recipient_taxpayer_type' => 'contributor',  
    'recipient_final_consumer' => false,  
    'product_id' => 123,  
    'product_ncm' => '87082999',  
    'product_cest' => '...',  
    'product_origin' => 0,  
    'product_tax_group_id' => 5,  
    'purpose' => 'resale', // resale, consumption, asset, industrialization  
    'service_order_id' => 99,  
    'has_st' => true,  
    'issued_at' => '2026-03-09'  
]

### Resultado da regra

O resultado da regra deveria devolver algo assim:

[  
    'cfop' => '6102',  
    'cst_icms' => '00',  
    'csosn' => null,  
    'mod_bc' => '3',  
    'aliquota_icms' => 12.00,  
    'mod_bc_st' => '4',  
    'aliquota_mva_st' => 40.00,  
    'cst_pis' => '01',  
    'aliquota_pis' => 1.65,  
    'cst_cofins' => '01',  
    'aliquota_cofins' => 7.60,  
    'ipi_cst' => '99',  
    'mensagem_fiscal_id' => 8  
]

### Regra com vigência

Toda regra fiscal precisa ter:

- `valid_from`
- `valid_to`
- `priority`
- `enabled`
- `rule_version`

Isso é fundamental porque leiaute e obrigações fiscais mudam com frequência, e 2025/2026 já trouxeram mudanças de leiaute e adequações para IBS/CBS na NF-e.

## 3. Snapshot fiscal no documento

Nunca confie em recalcular tudo da nota antiga olhando a regra atual.  
No momento da emissão, grave um **snapshot completo** da decisão fiscal usada.

### Exemplo

- `fiscal_documents`
- `fiscal_document_items`
- `fiscal_document_taxes`
- `fiscal_document_rule_snapshots`

Cada item da nota deve guardar:

- NCM, CEST, CFOP usado
- CST/CSOSN usado
- bases e alíquotas
- valores calculados
- ID da regra aplicada
- versão da regra
- payload enviado
- resposta da SEFAZ/API
- XML autorizado
- hash/assinatura do snapshot

Assim, mesmo que você altere o cadastro do produto, o regime da empresa ou uma regra fiscal, a nota histórica continua íntegra.

## 4. Escrituração / “livro fiscal” como camada derivada

Aqui está o ponto mais importante da modelagem: eu **não faria um “livro fiscal” digitado manualmente** como fonte primária.  
Eu faria um **razão fiscal derivado dos documentos e eventos fiscais**.

### Fonte primária

- NF-e emitida/recebida
    
- NFC-e
    
- CT-e, MDF-e, NFS-e, se aplicável
    
- carta de correção
    
- cancelamento
    
- inutilização
    
- devolução
    
- ajustes
    
- inventário
    
- movimentações com reflexo fiscal
    

### Camada derivada

Crie tabelas como:

- `fiscal_ledger_entries`
    
- `fiscal_apportionments`
    
- `fiscal_period_closings`
    
- `fiscal_inventories`
    
- `fiscal_adjustments`
    

Cada lançamento do ledger pode representar:

- entrada
    
- saída
    
- estorno
    
- crédito
    
- débito
    
- ajuste
    
- observação legal
    
- vínculo com documento/origem
    

Exemplo de lançamento:

[  
    'tenant_id' => 1,  
    'company_id' => 10,  
    'reference_date' => '2026-03-09',  
    'period' => '2026-03',  
    'entry_type' => 'saida',  
    'document_type' => 'nfe',  
    'document_id' => 501,  
    'item_id' => 2,  
    'cfop' => '6102',  
    'cst_icms' => '00',  
    'base_icms' => 1000.00,  
    'valor_icms' => 120.00,  
    'base_st' => 0.00,  
    'valor_st' => 0.00,  
    'valor_ipi' => 0.00,  
    'valor_pis' => 16.50,  
    'valor_cofins' => 76.00,  
    'ledger_origin' => 'document_authorized'  
]

Com isso, você consegue depois:

- fechamento mensal
    
- conferência fiscal
    
- geração de relatórios internos
    
- conciliação com SPED
    
- base para exportações futuras
    

Isso conversa bem com a realidade da **EFD ICMS/IPI**, que é justamente a escrituração digital dos documentos e da apuração, em vez do antigo livro isolado preenchido à mão.

---

# Modelagem prática recomendada

## Núcleo cadastral

- `companies`
    
- `establishments` ou `company_branches`
    
- `partners`
    
- `products`
    
- `product_tax_profiles`
    
- `product_tax_profile_histories`
    

## Núcleo fiscal

- `fiscal_profiles`
    
- `fiscal_profile_versions`
    
- `fiscal_operations`
    
- `fiscal_rules`
    
- `fiscal_rule_conditions`
    
- `fiscal_rule_results`
    
- `fiscal_messages`
    
- `fiscal_benefits`
    
- `fiscal_cfop_maps`
    
- `fiscal_tax_situations`
    

## Núcleo documental

- `fiscal_documents`
    
- `fiscal_document_items`
    
- `fiscal_document_references`
    
- `fiscal_document_events`
    
- `fiscal_document_payloads`
    
- `fiscal_document_responses`
    
- `fiscal_document_xmls`
    

## Núcleo de escrituração

- `fiscal_ledger_entries`
    
- `fiscal_periods`
    
- `fiscal_period_closings`
    
- `fiscal_apportionments`
    
- `fiscal_inventories`
    
- `fiscal_reconciliations`
    

---

# Como decidir a regra na emissão

Eu usaria um fluxo assim:

1. Usuário cria a operação comercial.
    
2. O sistema identifica o contexto fiscal do item.
    
3. Um `FiscalDecisionService` resolve a regra aplicável.
    
4. O resultado volta como um `FiscalDecisionDTO`.
    
5. O item da nota recebe esse snapshot.
    
6. O `NfePayloadBuilder` monta o payload já com o snapshot.
    
7. Após autorização/cancelamento/carta de correção, gera-se evento fiscal e lançamento no ledger.
    

### Exemplo de serviços

- `ResolveFiscalContextAction`
    
- `ResolveFiscalRuleAction`
    
- `CalculateItemTaxesAction`
    
- `BuildNfePayloadAction`
    
- `PersistFiscalSnapshotAction`
    
- `PostFiscalLedgerEntriesAction`
    

Isso encaixa muito bem no padrão que você já vem usando de **Service -> Action**.

---

# O que eu não faria

Eu evitaria estes desenhos:

### 1. Guardar tudo diretamente no produto

Ex.: `products.cfop`, `products.cst`, `products.aliquota_icms`

Isso quebra porque o mesmo produto pode ter tratamento diferente conforme:

- UF
    
- operação
    
- cliente contribuinte ou não
    
- consumo final
    
- devolução
    
- transferência
    
- vigência da regra
    

### 2. Recalcular nota antiga usando regra atual

Isso gera inconsistência histórica e problemas de auditoria.

### 3. Misturar escrituração com emissão

A emissão gera o fato fiscal; a escrituração deriva dele.  
São camadas relacionadas, mas não iguais.

---

# Como eu modelaria a vigência

Este ponto vale ouro.

## Perfil fiscal versionado

Tabela `fiscal_profile_versions`:

- `id`
    
- `tenant_id`
    
- `company_id`
    
- `version`
    
- `valid_from`
    
- `valid_to`
    
- `status`
    
- `ruleset_checksum`
    
- `created_by`
    

Cada emissão busca a versão vigente na data da operação.

## Regra versionada

Tabela `fiscal_rules`:

- `fiscal_profile_version_id`
    
- `operation_type`
    
- `priority`
    
- `conditions_json`
    
- `result_json`
    
- `valid_from`
    
- `valid_to`
    

Você pode até usar JSON para condição/resultado no começo, e normalizar depois o que ficar crítico.

---
# Pensando adiante: IBS/CBS

Mesmo que hoje você esteja mais focado em ICMS/IPI/PIS/COFINS, eu já deixaria a modelagem preparada para novos tributos e novos grupos no documento, porque a NF-e já está recebendo adequações de leiaute ligadas à Reforma Tributária do Consumo, com inclusão de campos e regras para IBS/CBS.

Então, em vez de fixar estrutura só para ICMS/PIS/COFINS, prefira algo extensível como:

- `tax_type`
    
- `tax_subtype`
    
- `calculation_method`
    
- `base_amount`
    
- `rate`
    
- `amount`
    
- `metadata_json`
    

Assim você suporta tributos atuais e futuros sem quebrar o modelo.

---

# Minha recomendação objetiva

Se eu fosse desenhar isso para sua aplicação, faria assim:

**1. Produto**

- somente dados fiscais estruturais do item:
    
    - NCM
        
    - CEST
        
    - origem
        
    - unidade
        
    - classificação fiscal base
        
- sem CFOP fixo
    

**2. Empresa**

- possui perfil fiscal próprio
    
- possui versões de regra por vigência
    

**3. Motor fiscal**

- decide CFOP/CST/CSOSN/alíquotas por contexto
    
- devolve snapshot pronto para o documento
    

**4. Documento fiscal**

- salva snapshot fiscal imutável
    
- armazena payload, XML, retorno, eventos
