# Descrição
Consultar CNPJ utilizando API pública, buscar dados e retornar um Value Object para consumo da aplicação.

# Requisitos
- Respeitar rate limit de 5 consultas por minuto.

## Documentação API

Para realizar a consulta, basta acessar o endpoint abaixo com o número do CNPJ (sem pontuação):

`GET https://open.cnpja.com/office/:cnpj`

A resposta será um objeto JSON contendo os dados de cadastro e outras informações disponíveis para aquele CNPJ.

```php

curl \
--request GET \
--url 'https://open.cnpja.com/office/07526557011659'

```

```json

{
  "updated": "2025-01-03T20:40:19.000Z",
  "taxId": "07526557011659",
  "alias": "Filial Manaus",
  "founded": "2023-07-31",
  "head": false,
  "company": {
    "members": [
      {
        "since": "2022-12-26",
        "person": {
          "id": "04c3a1f8-359f-429d-9240-dad8c46451d9",
          "type": "NATURAL",
          "name": "Felipe Moreira Haddad Baruque",
          "taxId": "***787888**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2022-02-15",
        "person": {
          "id": "0563028c-26ce-4098-baeb-65e9781e2014",
          "type": "NATURAL",
          "name": "Valdecir Duarte",
          "taxId": "***748919**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2017-12-08",
        "person": {
          "id": "298afc92-361e-487d-9048-d3a329bb007f",
          "type": "NATURAL",
          "name": "Eduardo Braga Cavalcanti de Lacerda",
          "taxId": "***401457**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2019-01-01",
        "person": {
          "id": "53cd618f-c4a4-4e81-bb82-433e0c8acf0d",
          "type": "NATURAL",
          "name": "Jean Jereissati Neto",
          "taxId": "***224813**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2021-01-12",
        "person": {
          "id": "6be3aa9d-8c11-41f6-b21c-2967365819ae",
          "type": "NATURAL",
          "name": "Daniel Wakswaser Cordeiro",
          "taxId": "***638588**",
          "age": "31-40"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2022-02-15",
        "person": {
          "id": "82f76c94-71ef-4f31-8791-fbd133e1dec1",
          "type": "NATURAL",
          "name": "Daniela Gavranic Cachich",
          "taxId": "***189168**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2020-06-17",
        "person": {
          "id": "c642c541-24fb-4f9c-9fd3-a90b028d1ac3",
          "type": "NATURAL",
          "name": "Lucas Machado Lira",
          "taxId": "***585176**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2022-06-13",
        "person": {
          "id": "cc98f4e6-0d7d-429a-9c5d-219c83082e33",
          "type": "NATURAL",
          "name": "Carla Smith de Vasconcellos Crippa Prado",
          "taxId": "***485688**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2020-01-15",
        "person": {
          "id": "e0fdcab6-f081-48f6-b206-4d308802b0da",
          "type": "NATURAL",
          "name": "Eduardo Eiji Horai",
          "taxId": "***022918**",
          "age": "31-40"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2023-09-15",
        "person": {
          "id": "e2d73359-2d0a-4915-9c1c-af5b43f43088",
          "type": "NATURAL",
          "name": "Joao Coelho Rua Derbli de Carvalho",
          "taxId": "***035737**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2016-01-06",
        "person": {
          "id": "ea9dbeac-b5ec-4bca-bd5b-8f61f4b5a2f6",
          "type": "NATURAL",
          "name": "Ricardo Morais Pereira de Melo",
          "taxId": "***157884**",
          "age": "51-60"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2019-01-01",
        "person": {
          "id": "f4490d83-096c-4c86-8055-acd0782acf13",
          "type": "NATURAL",
          "name": "Paulo Andre Zagman",
          "taxId": "***343527**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      },
      {
        "since": "2019-01-01",
        "person": {
          "id": "f6329851-d940-414b-9ee6-5c55bb4e1943",
          "type": "NATURAL",
          "name": "Leticia Rudge Barbosa Kina",
          "taxId": "***726488**",
          "age": "41-50"
        },
        "role": {
          "id": 10,
          "text": "Diretor"
        }
      }
    ],
    "id": 7526557,
    "name": "AMBEV S.A.",
    "equity": 58226035176.01,
    "nature": {
      "id": 2046,
      "text": "Sociedade Anônima Aberta"
    },
    "size": {
      "id": 5,
      "acronym": "DEMAIS",
      "text": "Demais"
    },
    "simples": {
      "optant": false,
      "since": null
    },
    "simei": {
      "optant": false,
      "since": null
    }
  },
  "statusDate": "2023-07-31",
  "status": {
    "id": 2,
    "text": "Ativa"
  },
  "address": {
    "municipality": 1302603,
    "street": "Avenida Constantino Nery",
    "number": "2575",
    "district": "Flores",
    "city": "Manaus",
    "state": "AM",
    "details": null,
    "zip": "69058795",
    "country": {
      "id": 76,
      "name": "Brasil"
    }
  },
  "mainActivity": {
    "id": 1113502,
    "text": "Fabricação de cervejas e chopes"
  },
  "phones": [
    {
      "type": "LANDLINE",
      "area": "19",
      "number": "33135680"
    }
  ],
  "emails": [
    {
      "ownership": "CORPORATE",
      "address": "opobrigaces@ambev.com.br",
      "domain": "ambev.com.br"
    }
  ],
  "sideActivities": [
    {
      "id": 1122401,
      "text": "Fabricação de refrigerantes"
    }
  ],
  "registrations": [
    {
      "number": "054591406",
      "state": "AM",
      "enabled": true,
      "statusDate": "2024-04-03",
      "status": {
        "id": 1,
        "text": "Sem restrição"
      },
      "type": {
        "id": 1,
        "text": "IE Normal"
      }
    },
    {
      "number": "240519525",
      "state": "RR",
      "enabled": true,
      "statusDate": "2023-10-20",
      "status": {
        "id": 1,
        "text": "Sem restrição"
      },
      "type": {
        "id": 2,
        "text": "IE Substituto Tributário"
      }
    },
    {
      "number": "159195853",
      "state": "PA",
      "enabled": true,
      "statusDate": "2023-10-03",
      "status": {
        "id": 3,
        "text": "Vedada operação como Destinatário na UF"
      },
      "type": {
        "id": 2,
        "text": "IE Substituto Tributário"
      }
    },
    {
      "number": "0108826400193",
      "state": "AC",
      "enabled": true,
      "statusDate": "2024-05-29",
      "status": {
        "id": 2,
        "text": "Bloqueado como Destinatário na UF"
      },
      "type": {
        "id": 2,
        "text": "IE Substituto Tributário"
      }
    }
  ],
  "suframa": [
    {
      "number": "220120838",
      "since": "2024-04-12",
      "approved": true,
      "approvalDate": "1987-09-24",
      "status": {
        "id": 1,
        "text": "Ativa"
      },
      "incentives": [
        {
          "tribute": "IPI",
          "benefit": "Isenção",
          "purpose": "Consumo Interno, Industrialização e Utilização",
          "basis": "Decreto 7.212 de 2010 (Art. 81)"
        },
        {
          "tribute": "ICMS",
          "benefit": "Isenção",
          "purpose": "Industrialização e Comercialização",
          "basis": "Convênio ICMS n° 65 de 1988"
        }
      ]
    }
  ]
}
  
```