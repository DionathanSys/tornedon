# Plano Técnico - Conversão entre Unidades de Produto

## Objetivo

Permitir que um produto tenha uma unidade padrão de estoque e múltiplas unidades operacionais de compra, venda e movimentação, com conversão automática nos dois sentidos.

Exemplos:

- Produto com unidade padrão `JG`
- Compra em `CX`, onde `1 CX = 2 JG`
- Compra em `PC`, onde `8 PC = 1 JG`
- O sistema deve converter corretamente tanto de unidade maior para menor quanto de menor para maior

---

## Princípios da Solução

1. O estoque deve ser controlado sempre em uma unidade canônica por produto.
2. A unidade canônica será a `unidade padrão` do produto.
3. Toda operação pode ser feita em outra unidade, mas antes de afetar estoque deve ser convertida para a unidade padrão.
4. O histórico deve preservar:
   - unidade usada na operação
   - quantidade informada pelo usuário
   - quantidade convertida para unidade base
5. O preço da operação deve continuar vinculado à unidade usada na operação.

---

## Situação Atual no Projeto

### Estruturas já existentes

- `products.unit` já define a unidade padrão do produto
- existe a tabela `product_alternative_units`
- o estoque já é centralizado em `product_stocks.quantity_total`
- itens de requisição já possuem `unit_of_measure`
- movimentações de estoque hoje armazenam apenas `quantity`, sem distinguir unidade operacional de quantidade base

### Lacunas atuais

1. Não há serviço central de conversão de unidades.
2. O estoque usa quantidade única, mas os fluxos não convertem antes de movimentar.
3. A movimentação de estoque não guarda a unidade usada nem a quantidade convertida.
4. A relação `alternativeUnitConversions()` parece esperada no código, mas não está consolidada no modelo.
5. O cadastro de produto ainda não fecha todo o ciclo de persistência e uso das conversões.

---

## Regra de Modelagem Recomendada

### Regra semântica

Cada unidade alternativa deve representar:

- `1 unidade alternativa = X unidades base`

Exemplos com produto base `JG`:

- `1 CX = 2 JG` -> fator `2`
- `1 PC = 0,125 JG` -> fator `0.125`

### Fórmulas

#### Operação para estoque

`quantidade_base = quantidade_operacional * fator`

#### Exibição ou reconversão

`quantidade_operacional = quantidade_base / fator`

### Exemplos

- Compra de `3 CX` com fator `2` -> `6 JG`
- Compra de `16 PC` com fator `0,125` -> `2 JG`
- Venda de `1 CX` -> saída de `2 JG`
- Venda equivalente em peças -> `8 PC = 1 JG`

---

## Alternativa Mais Robusta de Modelagem

Para reduzir riscos de arredondamento, a modelagem ideal é salvar a relação de conversão em formato racional:

- `alt_quantity`
- `base_quantity`

Exemplos:

- `1 CX = 2 JG` -> `alt_quantity = 1`, `base_quantity = 2`
- `8 PC = 1 JG` -> `alt_quantity = 8`, `base_quantity = 1`

### Fórmula

`quantidade_base = quantidade_operacional * (base_quantity / alt_quantity)`

### Vantagens

- evita imprecisão em conversões fracionadas
- melhora a legibilidade
- preserva a regra operacional original
- facilita exibição ao usuário

### Recomendação

Se a mudança puder ser feita agora, esta é a melhor abordagem.

Se quiser menor impacto inicial, manter `conversion_factor` também é viável, desde que a semântica fique padronizada e documentada.

---

## Alterações de Banco de Dados

## 1. Produto e unidades alternativas

### Revisar tabela `product_alternative_units`

Garantir os campos:

- `id`
- `product_id`
- `unit`
- `conversion_factor` ou `alt_quantity` + `base_quantity`
- timestamps

### Regras

- `unit` não pode ser igual à unidade padrão do produto
- não pode repetir unidade alternativa no mesmo produto
- fator precisa ser maior que zero

### Recomendação extra

Adicionar índice único em:

- `product_id`
- `unit`

---

## 2. Movimentações de estoque

### Situação atual

A tabela de movimentação registra apenas:

- `quantity`
- `unit_price`
- `total_amount`

### Alteração proposta

Adicionar campos para rastrear a unidade da operação e a quantidade convertida:

- `operational_unit`
- `operational_quantity`
- `base_unit`
- `base_quantity`
- `conversion_factor_snapshot` ou `base_quantity_snapshot`/`alt_quantity_snapshot`

### Objetivo

Preservar o histórico exatamente como foi lançado, mesmo que a conversão do produto seja alterada no futuro.

---

## 3. Itens operacionais

Avaliar inclusão de quantidade convertida em base nas tabelas de itens que geram impacto em estoque, por exemplo:

- `requisition_items`
- `quote_items` se necessário para consistência futura
- itens de documentos fiscais, se forem origem de estoque
- ordens de produção, se consumirem ou gerarem estoque por unidade operacional

### Campos sugeridos

- `quantity`
- `unit_of_measure`
- `quantity_in_base_unit`
- `conversion_snapshot`

---

## Camada de Domínio

## 1. Criar serviço central de conversão

### Nome sugerido

`ProductUnitConversionService`

### Responsabilidades

1. Resolver a unidade base do produto
2. Resolver conversão cadastrada
3. Validar se a unidade informada é permitida para o produto
4. Converter:
   - unidade operacional -> unidade base
   - unidade base -> unidade operacional
5. Retornar estrutura padronizada com:
   - unidade informada
   - quantidade informada
   - unidade base
   - quantidade base
   - fator aplicado

### Métodos sugeridos

- `convertToBase(Product $product, string $unit, float $quantity): ConversionResultDTO`
- `convertFromBase(Product $product, string $unit, float $quantity): ConversionResultDTO`
- `isAllowedUnit(Product $product, string $unit): bool`
- `getAvailableUnits(Product $product): array`

---

## 2. DTO de resultado

### Nome sugerido

`ConversionResultDTO`

### Campos

- `product_id`
- `operational_unit`
- `operational_quantity`
- `base_unit`
- `base_quantity`
- `factor`
- `display_rule`

---

## Regras de Negócio

## 1. Cadastro de produto

O produto deve ter:

- uma unidade padrão obrigatória
- zero ou mais unidades alternativas
- cada unidade alternativa com sua regra de conversão

### Exemplo de cadastro

Produto: Parafuso kit

- unidade padrão: `JG`
- unidades alternativas:
  - `CX -> 1 CX = 2 JG`
  - `PC -> 8 PC = 1 JG`

---

## 2. Compra

Ao lançar compra:

1. usuário informa unidade da compra
2. usuário informa quantidade
3. sistema converte para unidade base
4. estoque recebe a quantidade convertida

### Exemplo

- compra `10 CX`
- conversão `1 CX = 2 JG`
- entrada em estoque = `20 JG`

---

## 3. Venda

Ao lançar venda:

1. usuário escolhe unidade de venda
2. informa quantidade naquela unidade
3. sistema converte para unidade base
4. reserva/baixa do estoque usa quantidade base
5. faturamento preserva a unidade da venda

### Exemplo

- venda `3 PC`
- conversão `8 PC = 1 JG`
- saída de estoque = `0,375 JG`

---

## 4. Reserva de estoque

Toda reserva deve usar a quantidade convertida para base.

### Impacto

- reserva
- liberação
- baixa definitiva
- validação de saldo

---

## 5. Movimentação manual

Ao fazer movimentação manual:

- o usuário deve poder escolher a unidade operacional
- o sistema calcula a quantidade base antes de aplicar no estoque
- o histórico deve mostrar as duas quantidades

---

## 6. Produção

Se ordem de produção consumir ou produzir itens com unidade diferente da base:

- converter insumos para base antes do consumo
- converter produção para base antes da entrada no estoque

---

## 7. Fiscal

Os documentos fiscais devem manter a unidade usada na operação comercial.

### Avaliar por fluxo

- unidade comercial
- unidade tributável
- quantidade comercial
- quantidade tributável

### Diretriz

A unidade do documento não deve necessariamente ser a unidade base do estoque.

O estoque usa base.
O fiscal usa a unidade da operação ou a unidade tributável conforme a regra aplicável.

---

## Ajustes na Interface

## 1. Cadastro de produto

Trocar o modelo atual para um formulário com:

- unidade padrão
- repeater de unidades alternativas
- fator ou relação de conversão
- descrição legível da regra

### Exemplo visual

- Unidade padrão: `JG`
- Conversões:
  - `1 CX = 2 JG`
  - `8 PC = 1 JG`

---

## 2. Requisição, venda e compra

O campo `unit_of_measure` deve deixar de ser texto livre e passar a ser seleção baseada no produto.

### Ao selecionar unidade

o sistema deve:

- recalcular quantidade base
- recalcular validação de saldo
- exibir conversão aplicada

### Ajuda visual sugerida

- `1 CX = 2 JG`
- `8 PC = 1 JG`
- `Estoque disponível: 14 JG`
- `Esta operação consumirá 4 JG`

---

## 3. Movimentações de estoque

No formulário de movimentação:

- selecionar produto
- selecionar unidade operacional
- informar quantidade
- exibir quantidade equivalente em unidade base
- aplicar estoque usando base

---

## Pontos de Código a Ajustar

## 1. Produto

- `app/Models/Product.php`
- adicionar relação `alternativeUnitConversions()`

## 2. Unidades alternativas

- `app/Models/ProductAlternativeUnit.php`
- consolidar cast e semântica da conversão

## 3. Cadastro/edição de produto

- `app/Services/Product/Actions/CreateProductAction.php`
- `app/Services/Product/Actions/UpdateProductAction.php`
- `app/Services/Product/Validators/ProductValidator.php`
- `app/Filament/Clusters/Inventory/Resources/Products/Schemas/ProductForm.php`

## 4. Requisições e vendas

- `app/Services/RequisitionItem/Actions/CreateRequisitionItemAction.php`
- `app/Services/RequisitionItem/Actions/UpdateRequisitionItemAction.php`
- `app/Services/FiscalDocument/Actions/ProcessAuthorizedNfeStockMovementsAction.php`
- `app/Filament/Clusters/Sales/Resources/Requisitions/Schemas/ItemsForm.php`

## 5. Estoque

- `app/Models/StockMovement.php`
- `app/Services/StockMovement/Actions/ApplyMovementToProductStockAction.php`
- `app/Filament/Clusters/Inventory/Resources/StockMovements/Schemas/StockMovementForm.php`

## 6. Fiscal

- `app/Services/Invoice/InvoiceService.php`
- fluxos de item fiscal que dependam de `unit_of_measure`

---

## Estratégia de Implementação

## Fase 1 - Base de conversão

1. consolidar modelagem de unidade alternativa
2. criar relação no produto
3. criar serviço central de conversão
4. cobrir com testes unitários do serviço

## Fase 2 - Cadastro de produto

1. ajustar validação
2. ajustar formulário
3. ajustar create/update para persistência correta
4. validar consistência da conversão cadastrada

## Fase 3 - Estoque

1. adaptar movimentações para gravar unidade operacional e base
2. converter toda entrada/saída antes de afetar `quantity_total`
3. ajustar reservas e liberações

## Fase 4 - Vendas/Requisições

1. substituir texto livre de unidade por select controlado
2. converter quantidade antes de validar saldo
3. salvar quantidade base junto ao item
4. usar quantidade base nas baixas

## Fase 5 - Fiscal

1. revisar unidade comercial e tributável
2. garantir que XML continue correto
3. alinhar estoque com unidade base e fiscal com unidade operacional

## Fase 6 - Histórico e migração

1. backfill dos registros compatíveis
2. marcar campos históricos de conversão
3. tratar produtos com unidades alternativas já cadastradas sem fator

---

## Estratégia de Migração de Dados

## Produtos já existentes

### Caso 1: produto sem unidade alternativa
- nenhuma ação extra

### Caso 2: produto com unidade alternativa sem fator confiável
- manter cadastro
- exigir ajuste manual antes de usar conversão

### Caso 3: produto com fator conhecido
- migrar normalmente

## Regra importante

Nunca inferir automaticamente fator sem confirmação do usuário, salvo quando houver origem confiável.

---

## Regras de Arredondamento

## Recomendação

1. conversão interna com precisão alta
2. estoque persistido com precisão compatível com a coluna atual
3. exibição arredondada apenas na interface

### Sugestão

- cálculo interno: 8 casas decimais
- estoque persistido: conforme limite da tabela
- exibição: 3 ou 4 casas conforme contexto

---

## Testes Necessários

## 1. Conversão

- converter `CX -> JG`
- converter `PC -> JG`
- converter `JG -> PC`
- validar unidade inválida
- validar fator zero ou negativo

## 2. Produto

- cadastrar produto com múltiplas unidades
- editar conversões
- impedir unidade alternativa igual à base
- impedir duplicidade de unidade alternativa

## 3. Estoque

- entrada em unidade maior
- entrada em unidade menor
- saída em unidade maior
- saída em unidade menor
- reserva usando quantidade convertida
- liberação usando quantidade convertida

## 4. Saldo

- validar saldo com conversão
- bloquear saída insuficiente
- permitir quando houver saldo convertido suficiente

## 5. Histórico

- gravar unidade operacional
- gravar quantidade operacional
- gravar quantidade base
- preservar snapshot da conversão

## 6. Fiscal

- gerar item fiscal com unidade correta
- manter consistência entre quantidade comercial e valor total
- validar impacto em XML

---

## Riscos

1. divergência entre unidade da operação e unidade do estoque
2. arredondamento em conversões fracionadas
3. quebra de histórico se a conversão do produto for alterada depois
4. documentos fiscais com unidade incorreta
5. saldo incorreto se algum fluxo continuar usando `quantity` sem conversão

---

## Mitigações

1. centralizar toda conversão em um único serviço
2. gravar snapshot da conversão no histórico
3. usar sempre quantidade base para estoque
4. manter unidade operacional para interface e fiscal
5. cobrir fluxos críticos com testes automatizados

---

## Recomendação Final

A implementação deve seguir esta regra central:

- estoque sempre em unidade padrão do produto
- operação pode acontecer em qualquer unidade cadastrada
- toda movimentação converte antes de afetar estoque
- histórico preserva unidade original e quantidade convertida

Essa abordagem atende:

- compra em unidade maior
- compra em unidade menor
- venda em unidade maior
- venda em unidade menor
- reservas
- movimentações manuais
- rastreabilidade fiscal e operacional

---

## Decisões Funcionais a Confirmar Antes da Implementação

1. O preço informado deve sempre ser o preço da unidade escolhida na operação?
2. O sistema deve permitir quantidades fracionadas em qualquer unidade?
3. A unidade tributável da NF-e deve seguir:
   - a unidade da operação
   - ou a unidade base do estoque
4. Deseja modelagem simples com `conversion_factor` ou modelagem robusta com `alt_quantity/base_quantity`?
