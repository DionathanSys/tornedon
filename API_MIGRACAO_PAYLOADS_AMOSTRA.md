# API de Migracao - Payloads de Amostra

## Endpoints revisados

Os endpoints de coleta de dados via API estao definidos em `routes/api.php`:

1. `GET /api/migracao/parceiros`
2. `GET /api/migracao/contatos`
3. `GET /api/migracao/enderecos`
4. `GET /api/migracao/equipamentos`
5. `GET /api/migracao/ordens-servico`
6. `GET /api/migracao/servicos`

## Padrao geral das responses

Todos os endpoints seguem o mesmo envelope de resposta:

```json
{
  "data": [],
  "meta": {
    "resource": "nome_do_recurso",
    "count": 0,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {}
  }
}
```

## Autenticacao

A autenticacao pode ser enviada por:

- query string `key`
- header `X-Migration-Key`

Quando a chave for invalida, o retorno e:

```json
{
  "message": "Unauthorized"
}
```

Status HTTP: `401`

## Observacoes da revisao

1. O padrao `data` + `meta` esta consistente entre todos os endpoints.
2. A maior parte dos IDs exportados usa o prefixo `legacy_`.
3. Existem pequenas inconsistencias de nomenclatura:
4. Em `servicos`, o campo `imposto_servico_id` nao usa `legacy_`.
5. Em `ordens-servico`, `nota_entrada_id` e `nota_retorno_id` nao usam `legacy_`.
6. `ordens-servico` e o endpoint mais rico, com `itens` aninhados e dados do `servico` embutidos.

## Como a importacao esta sendo feita

Os imports atuais sao executados por comandos Artisan e seguem esta ordem de dependencia:

1. `php artisan migration:partners:import {company_id} {user_id}`
2. `php artisan migration:addresses:import {company_id} {user_id} --parceiro-id=...`
3. `php artisan migration:contacts:import {company_id} {user_id} --parceiro-id=...`
4. `php artisan migration:equipments:import {company_id} {user_id} --parceiro-id=...`
5. `php artisan migration:services:import {company_id} {user_id}`
6. `php artisan migration:service-orders:import {company_id} {user_id}`

Regras gerais do processo:

1. Cada import busca paginas da API usando `after_id`, `limit` e `meta.next_after_id`.
2. Cada registro importado grava um vinculo auxiliar em tabelas `temporary_*_migration_links`, preservando o `legacy_id`, o payload bruto e o timestamp da ultima importacao.
3. Quando possivel, o registro local e reconciliado por um mapa legado ja existente; sem mapa, o codigo tenta localizar um equivalente por chaves naturais, como documento do parceiro, endereco, email, `service_code` ou `number`.
4. `created_at` do legado nao e reaplicado nos modelos locais. Em geral ele e ignorado; `updated_at` e `deleted_at` do legado ficam registrados nas tabelas auxiliares, e alguns dados complementares vao para `additional_info`.
5. Enderecos, contatos, equipamentos e ordens dependem de parceiros ja importados. Itens de ordem dependem de servicos ja importados.
6. Campos sem equivalente direto no modelo atual sao armazenados em `additional_info`, em colunas auxiliares da tabela de mapeamento, ou apenas preservados no `payload` bruto da tabela temporaria.

---

## 1. Parceiros

Referencia: `app/Http/Controllers/Api/Migracao/ParceirosMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 101,
      "nome": "Gama Eletronica Ltda",
      "tipo_vinculo": "cliente",
      "tipo_documento": "cnpj",
      "nro_documento": "12345678000199",
      "ativo": true,
      "inscricao_estadual": "123456789",
      "created_at": "2026-06-01T10:00:00Z",
      "updated_at": "2026-06-15T14:30:00Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "resource": "parceiros",
    "count": 1,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "include_deleted": false
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `temporary_partner_migration_links.legacy_id` | Chave de reconciliacao do parceiro legado. |
| `nome` | `partners.name` | Importado diretamente. |
| `tipo_vinculo` | `company_partner.type` | Convertido para array de tipos do parceiro na empresa (`customer`, `supplier`, etc.). |
| `tipo_documento` | `partners.document_type` | Normalizado para `cpf` ou `cnpj`. |
| `nro_documento` | `partners.document_number` | Formatado e usado tambem para localizar parceiro ja existente. O valor bruto vai para `temporary_partner_migration_links.legacy_document_number`. |
| `ativo` | `company_partner.is_active` | Considera tambem `deleted_at`; se estiver deletado no legado, o vinculo da empresa fica inativo. |
| `inscricao_estadual` | `partners.state_tax_id` e `partners.state_tax_indicator` | `ISENTO` vira indicador `2`; vazio vira indicador `9`; demais valores viram inscricao + indicador `1`. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. Fica apenas no payload bruto salvo na tabela auxiliar. |
| `updated_at` | `temporary_partner_migration_links.legacy_updated_at` | Usado para rastreabilidade. |
| `deleted_at` | `partners.deleted_at` e `temporary_partner_migration_links.legacy_deleted_at` | Se vier preenchido, aplica soft delete no parceiro local. |

## 2. Contatos

Referencia: `app/Http/Controllers/Api/Migracao/ContatosMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 55,
      "legacy_parceiro_id": 101,
      "nome_contato": "Maria Souza",
      "email": "maria@gama.com.br",
      "telefone_fixo": "1133334444",
      "telefone_cel": "11999998888",
      "envio_ordem": true,
      "created_at": "2026-06-01T10:10:00Z",
      "updated_at": "2026-06-15T14:35:00Z"
    }
  ],
  "meta": {
    "resource": "contatos",
    "count": 1,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "parceiro_id": 101
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `temporary_contact_migration_links.legacy_id` | Chave de reconciliacao do contato legado. |
| `legacy_parceiro_id` | `temporary_contact_migration_links.legacy_partner_id` | Resolve o `company_partner_id` a partir do parceiro ja importado. |
| `nome_contato` | `temporary_contact_migration_links.legacy_contact_name` | Nao existe coluna `name` em `contacts`; o nome fica apenas no mapa temporario e no payload bruto. |
| `email` | `contacts.email` | Normalizado para minusculas e usado para reconciliar contato existente. |
| `telefone_fixo` | `contacts.phone` | Mantem apenas digitos. |
| `telefone_cel` | `contacts.mobile` | Mantem apenas digitos. |
| `envio_ordem` | `contacts.notify` | Importado como booleano. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. |
| `updated_at` | `temporary_contact_migration_links.legacy_updated_at` | Usado para rastreabilidade. |

## 3. Enderecos

Referencia: `app/Http/Controllers/Api/Migracao/EnderecosMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 88,
      "legacy_parceiro_id": 101,
      "rua": "Rua das Flores",
      "numero": "123",
      "complemento": "Sala 4",
      "bairro": "Centro",
      "codigo_municipio": "3550308",
      "cidade": "Sao Paulo",
      "estado": "SP",
      "cep": "01001000",
      "pais": "Brasil",
      "created_at": "2026-06-01T10:20:00Z",
      "updated_at": "2026-06-15T14:40:00Z"
    }
  ],
  "meta": {
    "resource": "enderecos",
    "count": 1,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "parceiro_id": 101
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `temporary_address_migration_links.legacy_id` | Chave de reconciliacao do endereco legado. |
| `legacy_parceiro_id` | `temporary_address_migration_links.legacy_partner_id` | Resolve o `company_partner_id` do endereco a partir do parceiro importado. |
| `rua` | `addresses.street` | Importado diretamente. |
| `numero` | `addresses.number` | Se vier vazio, vira `S/N`. |
| `complemento` | `addresses.complement` | Importado diretamente. |
| `bairro` | `addresses.neighborhood` | Importado diretamente. |
| `codigo_municipio` | `addresses.city_code` | Mantem apenas digitos. |
| `cidade` | `addresses.city` | Importado diretamente. |
| `estado` | `addresses.state` | Normalizado para maiusculas. |
| `cep` | `addresses.postal_code` | Normalizado para CEP com 8 digitos. |
| `pais` | `addresses.country` | Se vier vazio, assume `Brasil`. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. |
| `updated_at` | `temporary_address_migration_links.legacy_updated_at` | Usado para rastreabilidade. |

## 4. Equipamentos

Referencia: `app/Http/Controllers/Api/Migracao/EquipamentosMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 203,
      "legacy_parceiro_id": 101,
      "descricao": "Inversor de frequencia",
      "nro_serie": "INV-2026-0001",
      "modelo": "CFW500",
      "marca": "WEG",
      "created_at": "2026-06-01T10:30:00Z",
      "updated_at": "2026-06-15T14:45:00Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "resource": "equipamentos",
    "count": 1,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "include_deleted": false,
      "parceiro_id": 101
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `temporary_equipment_migration_links.legacy_id` | Chave de reconciliacao do equipamento legado. |
| `legacy_parceiro_id` | `temporary_equipment_migration_links.legacy_partner_id` e `equipments.owner_id` | O proprietario e resolvido pelo parceiro previamente importado. |
| `descricao` | `equipments.name` | Importado diretamente. |
| `nro_serie` | `equipments.serial_number` | Usado tambem para reconciliar equipamento existente. |
| `modelo` | `equipments.model` | Importado diretamente. |
| `marca` | `equipments.mark` | Importado diretamente. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. |
| `updated_at` | `temporary_equipment_migration_links.legacy_updated_at` | Usado para rastreabilidade. |
| `deleted_at` | `equipments.deleted_at` e `temporary_equipment_migration_links.legacy_deleted_at` | Se vier preenchido, aplica soft delete no equipamento local. |

Observacao: `equipments.type` nao vem pronto do payload. Ele e inferido a partir de descricao, marca, modelo e numero de serie.

## 5. Ordens de Servico

Referencia: `app/Http/Controllers/Api/Migracao/OrdensServicoMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 9001,
      "legacy_parceiro_id": 101,
      "legacy_equipamento_id": 203,
      "legacy_fatura_id": 77,
      "placa": "ABC1D23",
      "data_ordem": "2026-06-01",
      "data_encerrado": "2026-06-03",
      "valor_total": 1250.5,
      "desconto": 50,
      "prioridade": "alta",
      "tipo_manutencao": "corretiva",
      "status": "fechada",
      "status_processo": "finalizado",
      "relato_cliente": "Equipamento nao liga",
      "itens_recebidos": "fonte, cabos e painel",
      "path_pdf": "ordens-servico/9001.pdf",
      "img_equipamento": "equipamentos/203.jpg",
      "nota_entrada_id": 12,
      "nota_retorno_id": 19,
      "observacao_geral": "Cliente solicitou urgencia",
      "observacao_interna": "Troca de componente realizada",
      "created_at": "2026-06-01T11:00:00Z",
      "updated_at": "2026-06-15T15:00:00Z",
      "itens": [
        {
          "legacy_id": 501,
          "legacy_ordem_servico_id": 9001,
          "legacy_servico_id": 301,
          "servico": {
            "legacy_id": 301,
            "nome": "Troca de componente",
            "descricao": "Substituicao de capacitor",
            "valor_unitario": 350,
            "ativo": true
          },
          "quantidade": 2,
          "valor_unitario": 350,
          "valor_total": 700,
          "desconto": 0,
          "observacao": "Aplicado em bancada",
          "garantia": true
        }
      ]
    }
  ],
  "meta": {
    "resource": "ordens_servico",
    "count": 1,
    "limit": 200,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "parceiro_id": 101,
      "equipamento_id": 203,
      "fatura_id": 77,
      "status": "fechada"
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `service_orders.number`, `temporary_service_order_migration_links.legacy_id` e `service_orders.additional_info.migration.legacy_id` | O numero da OS local vira o `legacy_id` em texto. |
| `legacy_parceiro_id` | `temporary_service_order_migration_links.legacy_partner_id` e `service_orders.customer_id` | O cliente e resolvido pelo parceiro previamente importado. |
| `legacy_equipamento_id` | `temporary_service_order_migration_links.legacy_equipment_id` e `service_orders.equipment_id` | O equipamento e resolvido pelo mapa temporario de equipamentos. |
| `legacy_fatura_id` | `temporary_service_order_migration_links.legacy_invoice_id` e `service_orders.additional_info.migration.legacy_invoice_id` | Nao popula `service_orders.invoice_id`; fica apenas como referencia legada. |
| `placa` | `service_orders.location` | Importado como localizacao/texto livre. |
| `data_ordem` | `service_orders.order_date` | Importado diretamente. |
| `data_encerrado` | `service_orders.completion_date` | Importado diretamente. |
| `valor_total` | sem equivalente direto | Nao e gravado. O total local e derivado dos itens da OS. |
| `desconto` | `service_orders.additional_info.migration.legacy_discount_amount` | O desconto da OS fica apenas como referencia legada; o desconto efetivo local e controlado por item. |
| `prioridade` | `service_orders.priority` | Convertido para enum local (`low`, `normal`, `high`, `urgent`). |
| `tipo_manutencao` | `service_orders.type` | Convertido para enum local (`maintenance`, `repair`, etc.). |
| `status` | `service_orders.status` | Convertido para enum local (`open`, `closed`, `invoiced`, `cancelled`). |
| `status_processo` | `service_orders.solution` | Copiado como texto livre. |
| `relato_cliente` | `service_orders.customer_observations` | Importado diretamente. |
| `itens_recebidos` | `service_orders.items_received` | Importado diretamente. |
| `path_pdf` | `service_orders.additional_info.migration.legacy_path_pdf` | Guardado apenas em `additional_info`. |
| `img_equipamento` | `service_orders.additional_info.migration.legacy_img_equipment` | Guardado apenas em `additional_info`. |
| `nota_entrada_id` | `service_orders.additional_info.migration.legacy_note_entry_id` | Guardado apenas em `additional_info`. |
| `nota_retorno_id` | `service_orders.additional_info.migration.legacy_note_return_id` | Guardado apenas em `additional_info`. |
| `observacao_geral` | `service_orders.general_observations` | Importado diretamente. |
| `observacao_interna` | `service_orders.internal_observations` | Importado diretamente. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. |
| `updated_at` | `temporary_service_order_migration_links.legacy_updated_at` | Usado para rastreabilidade. |
| `itens` | `service_order_items` + `temporary_service_order_item_migration_links` | Cada item gera ou atualiza um item local da OS. |

### Equivalencia dos itens aninhados

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `temporary_service_order_item_migration_links.legacy_id` e `service_order_items.additional_info.migration.legacy_id` | Chave de reconciliacao do item legado. |
| `legacy_ordem_servico_id` | `temporary_service_order_item_migration_links.legacy_service_order_id` | Rastreabilidade do vinculo com a OS legada. |
| `legacy_servico_id` | `temporary_service_order_item_migration_links.legacy_service_id`, `service_order_items.additional_info.migration.legacy_service_id` e `service_order_items.service_id` | O `service_id` local e resolvido a partir do servico previamente importado. |
| `servico` | sem uso direto na gravacao principal | O import da OS nao cria servico por esse bloco embutido; ele exige que `migration:services:import` ja tenha sido executado. O objeto bruto segue preservado no payload salvo da OS/item. |
| `quantidade` | `service_order_items.quantity` | Importado diretamente. |
| `valor_unitario` | `service_order_items.unit_price` | Importado diretamente. |
| `valor_total` | `service_order_items.additional_info.migration.legacy_total_amount` | Guardado como referencia; o total local e recalculado. |
| `desconto` | `service_order_items.discount_amount` e `service_order_items.discount_percentage` | O percentual e recalculado a partir de quantidade x valor unitario. |
| `observacao` | `service_order_items.observations` | Importado diretamente. |
| `garantia` | `service_order_items.additional_info.migration.legacy_warranty` | Guardado apenas em `additional_info`. |

## 6. Servicos

Referencia: `app/Http/Controllers/Api/Migracao/ServicosMigrationController.php`

```json
{
  "data": [
    {
      "legacy_id": 301,
      "nome": "Troca de componente",
      "descricao": "Substituicao de capacitor e testes",
      "valor_unitario": 350,
      "ativo": true,
      "imposto_servico_id": 5,
      "created_at": "2026-06-01T09:00:00Z",
      "updated_at": "2026-06-15T13:00:00Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "resource": "servicos",
    "count": 1,
    "limit": 500,
    "has_more": false,
    "next_after_id": null,
    "filters": {
      "after_id": 0,
      "updated_from": null,
      "include_deleted": false,
      "ativo": true
    }
  }
}
```

### Equivalencia de campos

| Chave legado | Campo equivalente no sistema atual | Como entra na importacao |
| --- | --- | --- |
| `legacy_id` | `services.service_code`, `temporary_service_migration_links.legacy_id` e `services.additional_info.migration.legacy_id` | O codigo do servico local vira o `legacy_id` com padding a esquerda para 4 digitos. |
| `nome` | `services.name` | Importado diretamente. |
| `descricao` | `services.description` | Importado diretamente. |
| `valor_unitario` | `services.price` e `services.min_sale_price` | O valor minimo de venda recebe o mesmo valor importado. |
| `ativo` | `services.is_active` | Importado diretamente. |
| `imposto_servico_id` | sem equivalente usado na importacao atual | O campo e ignorado no cadastro local do servico. |
| `created_at` | sem equivalente direto | Nao e reaplicado no modelo local. |
| `updated_at` | `temporary_service_migration_links.legacy_updated_at` | Usado para rastreabilidade. |
| `deleted_at` | `services.deleted_at` e `temporary_service_migration_links.legacy_deleted_at` | Se vier preenchido, aplica soft delete no servico local. |

Observacoes:

1. `services.cost` e fixado em `0` na importacao atual.
2. `services.category` recebe sempre `migrado`.
3. `services.requires_approval` recebe `false` e `services.accept_customer_discount` recebe `true`.
