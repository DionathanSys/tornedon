# IntegraNotas NF-e — bloco `itens`

Baseado na documentação pública da NF-e da IntegraNotas na rota `/nfe`, com foco no exemplo de request exibido na página.

## Escopo

Este documento descreve o payload do campo `itens` e orientações práticas de integração.

> Observação importante
>
> A página pública deixa claramente visível o **exemplo completo** do array `itens`, mas não expõe de forma totalmente legível, no HTML acessível, a tabela completa de `required/type/min/max` para cada propriedade do item. Por isso:
>
> - **Confirmado pela documentação visível** = campos presentes no exemplo oficial.
> - **Provável obrigatório** = campos que devem existir para a NF-e ser montada corretamente e que aparecem no exemplo oficial.
> - **Condicional** = depende do regime tributário, CST/CSOSN, operação e tributação do item.

## Estrutura esperada

```json
{
  "itens": [
    {
      "numero_item": "1",
      "codigo_produto": "000297",
      "descricao": "SAL GROSSO 50KGS",
      "codigo_ncm": "55110011",
      "cfop": "5102",
      "unidade_comercial": "SC",
      "quantidade_comercial": 10,
      "valor_unitario_comercial": "22.45",
      "valor_bruto": "224.50",
      "unidade_tributavel": "SC",
      "quantidade_tributavel": "10.00",
      "valor_unitario_tributavel": "22.45",
      "origem": "0",
      "inclui_no_total": "1",
      "imposto": {
        "valor_aproximado_tributos": 9.43,
        "icms": {
          "situacao_tributaria": "101",
          "modalidade_base_calculo": "3",
          "valor_base_calculo": "0.00",
          "modalidade_base_calculo_st": "4",
          "aliquota_reducao_base_calculo": "0.00",
          "aliquota": "0.00",
          "aliquota_final": "0.00",
          "valor": "0.00",
          "aliquota_margem_valor_adicionado_st": "0.00",
          "aliquota_reducao_base_calculo_st": "0.00",
          "valor_base_calculo_st": "0.00",
          "aliquota_st": "0.00",
          "valor_st": "0.00"
        },
        "pis": {
          "situacao_tributaria": "01",
          "valor_base_calculo": 224.5,
          "aliquota": "1.65",
          "valor": "3.70"
        },
        "cofins": {
          "situacao_tributaria": "01",
          "valor_base_calculo": 224.5,
          "aliquota": "7.60",
          "valor": "17.06"
        }
      },
      "valor_desconto": 0,
      "valor_frete": 0,
      "valor_seguro": 0,
      "valor_outras_despesas": 0,
      "informacoes_adicionais": "Valor aproximado tributos R$: 9,43 (4,20%) Fonte: IBPT"
    }
  ]
}
```

## Campos do item

### Identificação e descrição

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `numero_item` | string numérica | Provável obrigatório | Número sequencial do item dentro da NF-e. Ex.: `1`, `2`, `3`. |
| `codigo_produto` | string | Provável obrigatório | Código interno/SKU do produto. |
| `descricao` | string | Provável obrigatório | Descrição do produto/mercadoria. |
| `informacoes_adicionais` | string | Opcional | Texto complementar do item. |

### Classificação fiscal

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `codigo_ncm` | string | Provável obrigatório | NCM do produto. Na prática, usar 8 dígitos. |
| `cfop` | string | Provável obrigatório | CFOP da operação. Na prática, usar 4 dígitos. |
| `origem` | string | Provável obrigatório | Origem da mercadoria. Ex.: `0` nacional. |

### Comercialização e tributação

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `unidade_comercial` | string | Provável obrigatório | Unidade comercial do item. |
| `quantidade_comercial` | número/string numérica | Provável obrigatório | Quantidade comercializada. |
| `valor_unitario_comercial` | número/string decimal | Provável obrigatório | Valor unitário comercial. |
| `valor_bruto` | número/string decimal | Provável obrigatório | Total bruto do item antes de descontos. |
| `unidade_tributavel` | string | Provável obrigatório | Unidade para tributação. |
| `quantidade_tributavel` | número/string decimal | Provável obrigatório | Quantidade tributável. |
| `valor_unitario_tributavel` | número/string decimal | Provável obrigatório | Valor unitário tributável. |
| `inclui_no_total` | string | Provável obrigatório | Normalmente `1` para somar no total da NF-e. |

### Composição monetária do item

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `valor_desconto` | número/string decimal | Opcional | Desconto do item. |
| `valor_frete` | número/string decimal | Opcional | Frete rateado no item, se houver. |
| `valor_seguro` | número/string decimal | Opcional | Seguro rateado no item, se houver. |
| `valor_outras_despesas` | número/string decimal | Opcional | Outras despesas acessórias do item. |

## Bloco `imposto`

O campo `imposto` é um objeto dentro de cada item. No exemplo oficial visível, ele contém `valor_aproximado_tributos`, `icms`, `pis` e `cofins`.

### `imposto.valor_aproximado_tributos`

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `valor_aproximado_tributos` | número/string decimal | Opcional | Valor estimado dos tributos, normalmente usado para transparência fiscal/IBPT. |

### `imposto.icms`

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `situacao_tributaria` | string | Condicional | CST ou CSOSN conforme CRT do emitente. |
| `modalidade_base_calculo` | string | Condicional | Modalidade de determinação da base do ICMS. |
| `valor_base_calculo` | número/string decimal | Condicional | Base de cálculo do ICMS. |
| `modalidade_base_calculo_st` | string | Condicional | Modalidade da base de cálculo do ICMS ST. |
| `aliquota_reducao_base_calculo` | número/string decimal | Condicional | Percentual de redução da BC do ICMS. |
| `aliquota` | número/string decimal | Condicional | Alíquota do ICMS. |
| `aliquota_final` | número/string decimal | Condicional | Alíquota final/FCP conforme cenário utilizado pela API. |
| `valor` | número/string decimal | Condicional | Valor do ICMS. |
| `aliquota_margem_valor_adicionado_st` | número/string decimal | Condicional | MVA do ICMS ST. |
| `aliquota_reducao_base_calculo_st` | número/string decimal | Condicional | Redução da BC ST. |
| `valor_base_calculo_st` | número/string decimal | Condicional | Base de cálculo do ICMS ST. |
| `aliquota_st` | número/string decimal | Condicional | Alíquota do ICMS ST. |
| `valor_st` | número/string decimal | Condicional | Valor do ICMS ST. |

### `imposto.pis`

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `situacao_tributaria` | string | Provável obrigatório | CST do PIS. |
| `valor_base_calculo` | número/string decimal | Condicional | Base do PIS. |
| `aliquota` | número/string decimal | Condicional | Alíquota percentual do PIS. |
| `valor` | número/string decimal | Condicional | Valor do PIS. |

### `imposto.cofins`

| Campo | Tipo prático | Status | Regra prática |
|---|---|---:|---|
| `situacao_tributaria` | string | Provável obrigatório | CST do COFINS. |
| `valor_base_calculo` | número/string decimal | Condicional | Base do COFINS. |
| `aliquota` | número/string decimal | Condicional | Alíquota percentual do COFINS. |
| `valor` | número/string decimal | Condicional | Valor do COFINS. |

## Regras práticas de integração

1. Monte `itens` sempre como array, mesmo quando houver apenas um item.
2. Trate valores monetários e quantidades como string decimal com ponto, para evitar problemas de serialização.
3. Use `numero_item` sequencial iniciando em `1`.
4. Valide `codigo_ncm` com 8 dígitos e `cfop` com 4 dígitos antes do envio.
5. Preencha `unidade_comercial` e `unidade_tributavel` de forma consistente com a operação.
6. Garanta coerência entre:
   - `quantidade_comercial` x `valor_unitario_comercial` x `valor_bruto`
   - `quantidade_tributavel` x `valor_unitario_tributavel`
7. O bloco `imposto.icms` muda conforme o regime tributário e a operação; não trate o exemplo de CSOSN/CST como universal.
8. Se a API rejeitar, ela tende a devolver os erros no padrão `5001` ou `5002`, informando campo, descrição e detalhes do problema.

## Exemplo mínimo prático

```json
{
  "itens": [
    {
      "numero_item": "1",
      "codigo_produto": "ABC123",
      "descricao": "PRODUTO EXEMPLO",
      "codigo_ncm": "84212345",
      "cfop": "5102",
      "unidade_comercial": "UN",
      "quantidade_comercial": "2.00",
      "valor_unitario_comercial": "15.50",
      "valor_bruto": "31.00",
      "unidade_tributavel": "UN",
      "quantidade_tributavel": "2.00",
      "valor_unitario_tributavel": "15.50",
      "origem": "0",
      "inclui_no_total": "1",
      "imposto": {
        "icms": {
          "situacao_tributaria": "101"
        },
        "pis": {
          "situacao_tributaria": "01",
          "valor_base_calculo": "31.00",
          "aliquota": "1.65",
          "valor": "0.51"
        },
        "cofins": {
          "situacao_tributaria": "01",
          "valor_base_calculo": "31.00",
          "aliquota": "7.60",
          "valor": "2.36"
        }
      },
      "valor_desconto": "0.00",
      "valor_frete": "0.00",
      "valor_seguro": "0.00",
      "valor_outras_despesas": "0.00"
    }
  ]
}
```

## Recomendação para Laravel

No backend, vale validar em três camadas:

1. **Request/Service**: presença e formato básico.
2. **DTO**: normalização e coerência estrutural do item.
3. **Action fiscal**: regra tributária por CST/CSOSN/CFOP/NCM.

## Fonte

Documentação pública da IntegraNotas — NF-e `/nfe`.
