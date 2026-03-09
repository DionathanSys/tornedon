#### Enum `DocumentModel` (novo):

```php
enum DocumentModel: string
{
    case NFE  = 'nfe';   // NF-e — Nota Fiscal Eletrônica (produtos)
    case NFSE = 'nfse';  // NFS-e — Nota Fiscal de Serviço Eletrônica
}
```

> O campo `document_type` do `FiscalDocument` passa a armazenar o modelo (nfe/nfse).

#### Validators por Modelo:

```
app/Services/FiscalDocument/Validators/
    ├── FiscalDocumentValidator.php          ← Regras comuns (cabeçalho)
    ├── NfeDocumentValidator.php             ← Regras específicas para NF-e
    └── Items/
        ├── NfeItemValidator.php             ← Valida item de NF-e (NCM, CFOP, impostos)
```

**`FiscalDocumentValidator` (regras comuns):**
```
- customer_id: required
- company_id: required
- document_type: required|in:nfe,nfse
- issued_at: required|date
- movement_at: required|date
- items: required|array|min:1
```

**`NfeDocumentValidator` (regras específicas NF-e):**
```
CABEÇALHO:
- operation_nature: required|string|max:60
- operation_type: required|in:0,1
- issue_purpose: required|in:1,2,3,4
- is_final_consumer: required|boolean
- buyer_presence_indicator: required|in:0,1,2,3,4,5,9
- freight_data: required|array
- freight_data.modalidade_frete: required|in:0,1,2,3,4,9

DESTINATÁRIO (via customer):
- customer.document_number: required (CPF ou CNPJ válido)
- customer.address: required (logradouro, bairro, cidade, UF, CEP)
- customer.state_tax_indicator: required|in:1,2,9
```

**`NfeItemValidator` (regras por item NF-e):**
```
- product_id: required|exists:products,id
- ncm_code: required|string|size:8
- cfop_code: required|string|size:4
- origin_code: required|in:0,1,2,3,4,5,6,7,8
- quantity: required|numeric|min:0.0001
- unit_price: required|numeric|min:0
- total_price: required|numeric|min:0
- unit_of_measure: required|string|max:6
- tax_data: required|array
- tax_data.imposto: required|array
- tax_data.imposto.icms: required|array
- tax_data.imposto.icms.situacao_tributaria: required
- tax_data.imposto.pis: required|array
- tax_data.imposto.pis.situacao_tributaria: required
- tax_data.imposto.cofins: required|array
- tax_data.imposto.cofins.situacao_tributaria: required
```

#### Resolução do Validator:

Adicionar um `ValidatorResolver` que seleciona os validators corretos com base no `document_type`:

```php
class FiscalDocumentValidatorResolver
{
    public static function resolve(string $documentType): array
    {
        return match ($documentType) {
            'nfe'  => [
                'document' => new NfeDocumentValidator(),
                'item'     => new NfeItemValidator(),
            ],
            'nfse' => [
                'document' => new NfseDocumentValidator(),
                'item'     => new NfseItemValidator(),
            ],
        };
    }
}
```


| Refatorar `CreateFiscalDocumentAction`        | Usar `ValidatorResolver` em vez do validator genérico            |
| --------------------------------------------- | ---------------------------------------------------------------- |
