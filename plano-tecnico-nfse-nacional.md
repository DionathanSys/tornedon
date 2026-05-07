# Plano Tecnico de Implementacao NFS-e Nacional

## Objetivo

Implementar a emissao de NFS-e Nacional via Integra Notas com o menor risco de rejeicao da API e com zero regressao funcional no fluxo atual de NFS municipal.

## Premissas

- A NFS municipal deve continuar funcionando sem alteracao de comportamento.
- A NFS nacional deve entrar como fluxo paralelo, nunca como extensao improvisada do fluxo municipal.
- A primeira entrega deve cobrir apenas o payload minimo confiavel.
- Regras fiscais e condicionais da nacional devem ser validadas localmente antes de qualquer chamada externa.

## Objetivos da V1

- Emitir NFS-e Nacional com `tipo_emitente = 1`.
- Atender tomador nacional PF ou PJ.
- Emitir servico nacional sem exportacao.
- Utilizar payload minimo valido e controlado.
- Isolar arquitetura, validacao, payload e retorno da nacional.

## Fora do Escopo da V1

- `contingencia`
- `emissao_tomador`
- `intermediario`
- `obra`
- `evento`
- `locacao`
- `exterior`
- `pedagio`
- `deducoes`
- `servico.ibs_cbs`
- `servico.imovel`
- `servico.ressarcimento`
- `servico.tributos_municipais.imunidade`
- `servico.tributos_municipais.beneficio`
- `servico.tributos_municipais.suspensao`
- destinatario diferente do tomador

## Riscos a Evitar

- Tornar obrigatorios campos que so existem na nacional e quebrar a municipal.
- Misturar builder municipal e nacional no mesmo codigo com muitos `if`.
- Compartilhar DTO de payload entre provedores com regras divergentes.
- Alterar enums, validacoes ou respostas da municipal para acomodar a nacional.
- Descobrir regras condicionais apenas em homologacao.

## Estrategia Geral

1. Mapear o fluxo atual da NFS municipal.
2. Isolar o ponto de entrada da emissao.
3. Introduzir estrategia por tipo de emissao.
4. Encapsular o fluxo municipal atual sem mudar comportamento.
5. Implementar a nacional em provider proprio.
6. Liberar por feature flag.
7. Homologar a V1 com payload minimo.
8. Expandir cenarios especiais em fases posteriores.

## Fluxo Atual da Municipal

Antes de codar, levantar:

- ponto de entrada da emissao
- validacoes atuais
- builder de payload
- cliente HTTP / integracao
- persistencia de request e response
- tratamento de status e rejeicoes
- telas e formularios envolvidos
- jobs, filas ou processos assincronos

Entregavel desta etapa:

- lista de arquivos e servicos impactados
- separacao do que e municipal especifico e do que e reutilizavel

## Arquitetura Recomendada

### Orquestracao

Criar um orquestrador unico para decidir o tipo de emissao:

- `NfseEmissionOrchestrator`

Responsabilidades:

- receber o contexto da emissao
- decidir `municipal` ou `nacional`
- delegar ao provider correto

### Interface de Provider

Criar uma interface comum, por exemplo:

```php
interface NfseProviderInterface
{
    public function emitir(NfseEmissionInput $input): NfseEmissionResult;
}
```

Implementacoes:

- `MunicipalNfseProvider`
- `NacionalNfseProvider`

### Builders

- `MunicipalPayloadBuilder`
- `NacionalPayloadBuilder`

Regra:

- nao reutilizar o builder municipal para montar payload nacional
- nao colocar `if ($tipo === 'nacional')` dentro do builder municipal

### Validadores

- `MunicipalValidator`
- `NacionalValidator`

Compartilhar apenas utilitarios seguros:

- normalizacao de CPF/CNPJ
- validacao de email
- normalizacao de CEP
- parse/formatacao de datas
- sanitizacao de strings
- tratamento numerico

### Clientes de Integracao

- `MunicipalApiClient`
- `NacionalApiClient`

Responsabilidades:

- autenticacao
- envio HTTP
- tratamento basico de transporte
- retorno bruto padronizado

### DTOs e Contratos

Separar totalmente os contratos internos de emissao:

- `NfseEmissionInput`
- `NfseEmissionResult`
- `NfseNacionalRequest`
- `NfseNacionalResponse`
- `NfseMunicipalRequest`
- `NfseMunicipalResponse`

## Chave de Selecao do Tipo de Emissao

A decisao entre municipal e nacional deve ocorrer no inicio do fluxo.

Opcoes comuns:

- configuracao da empresa
- configuracao por municipio
- tipo de integracao habilitada
- feature flag por conta/tenant

Regra recomendada:

- uma vez escolhido o tipo de emissao, todo o restante do pipeline deve ser especifico daquele provider

## Feature Flag

Criar flag para ativacao controlada da nacional.

Exemplos:

- por empresa
- por ambiente
- por usuario interno

Objetivo:

- homologar a nacional sem qualquer impacto nos clientes da municipal
- permitir rollback rapido desabilitando a flag

## Especificacao Tecnica V1 da NFS Nacional

### Escopo da Emissao

- `tipo_emitente = 1`
- tomador nacional
- sem exportacao
- sem retencoes avancadas
- sem blocos especiais

### Campos Minimos do Payload

#### Raiz

- `data_emissao`
- `numero`
- `serie`
- `tomador`
- `servico`

#### Tomador

- `razao_social`
- `cnpj` ou `cpf`
- `endereco.logradouro`
- `endereco.numero`
- `endereco.bairro`
- `endereco.uf`

#### Servico

- `codigo`
- `discriminacao`
- `codigo_nbs`
- `valor_servicos`
- `tributos_nacionais.cst`

### Campos Opcionais Aceitos na V1

- `identificacao`
- `regime_apuracao`
- `regime_tributacao`
- `data_competencia`
- `tomador.im`
- `tomador.telefone`
- `tomador.email`
- `tomador.endereco.complemento`
- `tomador.endereco.codigo_municipio`
- `tomador.endereco.cep`
- `servico.descricao`
- `servico.codigo_tributacao_municipio`
- `servico.codigo_interno`
- `servico.valor_recebido`
- `servico.valor_desconto_condicionado`
- `servico.valor_desconto_incondicionado`
- `servico.tributos_municipais`
- `informacoes_complementares`

### Defaults da V1

- `data_competencia = data_emissao`
- `tipo_emitente = "1"`
- `regime_tributacao = "0"`, quando a regra fiscal da operacao permitir
- `tomador.tipo_destinatario = "0"`
- `servico.tributos_municipais.tipo_operacao = "1"`
- `servico.tributos_municipais.iss_retido = false`
- `servico.tributos_nacionais.tipo_retencao = "2"`

## Regras de Validacao da V1

### Datas

- `data_emissao` em ISO-8601 com timezone
- `data_competencia` em ISO-8601 com timezone

Exemplo:

- `2026-05-05T10:30:00-03:00`

### Numero e Serie

- `numero`: string numerica com 1 a 9 digitos
- `serie`: string com 1 a 5 caracteres

### Identificacao

- `identificacao`: 1 a 40 caracteres

### Tomador

- obrigatorio informar `cnpj` ou `cpf`
- `cnpj`: 14 digitos
- `cpf`: 11 digitos
- `razao_social`: 1 a 300 caracteres
- `telefone`: 6 a 20 digitos
- `email`: ate 60 caracteres

### Endereco do Tomador

- `logradouro`: 1 a 255 caracteres
- `numero`: 1 a 60 caracteres
- `complemento`: ate 256 caracteres
- `bairro`: 1 a 60 caracteres
- `uf`: 2 caracteres
- `codigo_municipio`: 7 digitos, quando informado
- `cep`: 8 digitos, quando informado

### Servico

- `codigo`: 6 digitos
- `descricao`: ate 200 caracteres
- `codigo_tributacao_municipio`: 3 digitos, quando informado
- `discriminacao`: 1 a 2000 caracteres
- `codigo_nbs`: 9 digitos
- `codigo_interno`: 1 a 20 caracteres
- `valor_servicos`: numero >= 0.01
- `valor_recebido`: numero >= 0
- `valor_desconto_condicionado`: numero >= 0
- `valor_desconto_incondicionado`: numero >= 0

### Tributos Municipais

- `tipo_operacao`: usar `1` na V1
- `aliquota_iss`: percentual entre 0 e 100
- enviar percentual como `5.00`, nunca `0.05`
- `iss_retido`: boolean

### Tributos Nacionais

- `cst`: string conforme tabela da API
- `tipo_retencao`: `1` ou `2`

### Informacoes Complementares

- ate 2000 caracteres

## Regras de Negocio da V1

- `tipo_emitente` deve ser sempre `1`
- `tomador.tipo_destinatario` deve ser sempre `0`
- nao permitir `uf = EX`
- nao permitir exportacao na V1
- nao permitir blocos fora do escopo
- nao permitir destinatario diferente do tomador
- preferir omitir campos nulos ao montar o payload final

## Matriz de Regras Condicionais Futuras

Essas regras devem ficar fora da V1, mas ja documentadas para expansao futura:

- `emissao_tomador` quando `tipo_emitente = 2` ou `3`
- `informacoes.documento_ref` quando a nota for emitida por tomador/intermediario
- `servico.tributos_municipais.codigo_pais` quando `tipo_operacao = 3`
- `servico.tributos_municipais.imunidade.tipo` quando `tipo_operacao = 2`
- `servico.tributos_municipais.responsavel_retencao` quando `iss_retido = true`
- `ibs_cbs.destinatario` quando `tomador.tipo_destinatario = 1`
- endereco exterior quando `uf = EX`
- `ressarcimento.documentos[].dfe_nacional.descricao` quando `tipo = 9`
- `deducoes.itens[].descricao` quando `tipo_deducao = 99`

## Payload Exemplo da V1

```json
{
  "identificacao": "NFSE-000123",
  "regime_apuracao": "1",
  "regime_tributacao": "0",
  "data_emissao": "2026-05-05T10:30:00-03:00",
  "data_competencia": "2026-05-05T10:30:00-03:00",
  "numero": "123",
  "serie": "1",
  "tipo_emitente": "1",
  "tomador": {
    "cnpj": "12345678000199",
    "cpf": null,
    "im": null,
    "razao_social": "EMPRESA EXEMPLO LTDA",
    "telefone": "1133334444",
    "email": "financeiro@exemplo.com.br",
    "tipo_destinatario": "0",
    "endereco": {
      "logradouro": "Rua Exemplo",
      "numero": "100",
      "complemento": "Sala 1",
      "bairro": "Centro",
      "uf": "SP",
      "codigo_municipio": "3550308",
      "cep": "01001000"
    }
  },
  "servico": {
    "codigo": "010101",
    "descricao": "Descricao curta do servico",
    "codigo_tributacao_municipio": "123",
    "discriminacao": "Prestacao de servico conforme contrato 123.",
    "codigo_nbs": "123456789",
    "codigo_interno": "SERV001",
    "valor_servicos": 100.0,
    "valor_recebido": 100.0,
    "valor_desconto_condicionado": 0,
    "valor_desconto_incondicionado": 0,
    "tributos_municipais": {
      "tipo_operacao": "1",
      "aliquota_iss": 5.0,
      "iss_retido": false
    },
    "tributos_nacionais": {
      "cst": "06",
      "tipo_retencao": "2"
    }
  },
  "informacoes_complementares": "Informacoes complementares opcionais."
}
```

## Modelagem de Dados Recomendada

Criar modelos internos separados para nacional:

- `NfseNacionalRequest`
- `NfseNacionalTomador`
- `NfseNacionalEndereco`
- `NfseNacionalServico`
- `NfseNacionalTributosMunicipais`
- `NfseNacionalTributosNacionais`

Criar enums internos para codigos fechados:

- `TipoEmitenteEnum`
- `RegimeTributacaoEnum`
- `TomadorTipoDestinatarioEnum`
- `TributosMunicipaisTipoOperacaoEnum`
- `TributosNacionaisTipoRetencaoEnum`
- `TributosNacionaisCstEnum`

## Impacto em Banco de Dados

Levantar os campos que a nacional exige e a municipal nao usa ou usa com outra semantica.

Campos provaveis:

- codigo nacional do servico LC 116
- codigo NBS
- CST de PIS/COFINS
- configuracoes futuras de IBS/CBS
- informacoes fiscais adicionais do tomador

Recomendacoes:

- preferir colunas novas e opcionais
- nao reutilizar coluna municipal com semantica diferente
- se houver muita especificidade, usar tabela/configuracao separada por tipo de emissao

## Cadastro e UI

### Cadastro de Servico

Garantir disponibilidade de:

- `servico.codigo`
- `servico.codigo_nbs`
- `servico.codigo_tributacao_municipio`, se usado
- CST aplicavel

### Cadastro de Tomador

Garantir disponibilidade de:

- CPF/CNPJ
- razao social
- endereco minimo
- email/telefone opcionais

### Interface

- nao exigir campos da nacional em fluxo municipal
- exibir campos extras apenas quando o tipo de emissao for nacional
- se a mesma tela atender os dois fluxos, renderizar condicionalmente por contexto

## Pipeline de Implementacao

1. Receber dados internos da nota.
2. Resolver o tipo de emissao.
3. Passar pelo provider correspondente.
4. Validar obrigatorios e contexto.
5. Aplicar defaults.
6. Montar payload.
7. Omitir nulos e objetos vazios.
8. Enviar para API.
9. Registrar request/response.
10. Persistir resultado padronizado.

## Tratamento de Persistencia e Auditoria

Salvar por emissao:

- tipo de emissao: `municipal` ou `nacional`
- payload final enviado
- resposta da API
- erros de validacao local
- codigo interno da nota
- identificador externo, numero, protocolo, chave, quando houver

Regra:

- manter municipal e nacional rastreaveis de forma independente

## Observabilidade

Separar logs e metricas por tipo:

- `emission_type=municipal`
- `emission_type=nacional`

Monitorar:

- taxa de rejeicao
- erros de validacao local
- tempo medio de emissao
- falhas de transporte
- falhas por campo obrigatorio ausente

## Testes Necessarios

### Regressao Municipal

Antes da entrada da nacional, congelar o comportamento atual com testes para:

- emissao municipal simples
- validacao municipal
- montagem de payload municipal
- leitura da resposta municipal

### Nacional V1

Casos de sucesso:

- emissao com tomador PJ
- emissao com tomador PF

Casos de falha local:

- ausencia de `numero`
- ausencia de `serie`
- ausencia de `data_emissao`
- ausencia de `cnpj` e `cpf`
- `cnpj` invalido
- `cpf` invalido
- ausencia de `razao_social`
- ausencia de `logradouro`, `numero`, `bairro` ou `uf`
- ausencia de `servico.codigo`
- ausencia de `servico.codigo_nbs`
- ausencia de `servico.discriminacao`
- `valor_servicos <= 0`
- `aliquota_iss` fora de faixa
- `uf = EX`
- envio de bloco fora de escopo da V1

## Ordem Ideal de Implementacao

### Fase 1

- mapear fluxo atual municipal
- identificar ponto unico de entrada da emissao
- criar interface `NfseProviderInterface`
- encapsular o fluxo municipal atual em `MunicipalNfseProvider`
- manter testes municipais verdes

### Fase 2

- criar feature flag para nacional
- criar DTOs, validator e builder nacional
- criar `NacionalApiClient`
- criar `NacionalNfseProvider`

### Fase 3

- implementar payload minimo V1
- criar testes unitarios e de integracao da nacional
- registrar logs e auditoria separados

### Fase 4

- homologar com exemplos reais
- corrigir rejeicoes de contrato
- liberar gradualmente por feature flag

### Fase 5

- expandir retencoes, exportacao e blocos especiais
- introduzir `ibs_cbs`, `deducoes`, `ressarcimento` e demais cenarios

## Checklist de Pronto para Desenvolvimento

- fluxo municipal atual mapeado
- ponto de entrada identificado
- estrategia por provider definida
- feature flag definida
- campos necessarios da V1 mapeados no banco
- payload minimo revisado
- regras de validacao local definidas
- testes de regressao municipal planejados

## Resultado Esperado

- a NFS municipal permanece estavel
- a NFS nacional entra como trilha paralela
- a V1 emite com payload minimo e baixo risco de rejeicao
- a arquitetura fica preparada para evoluir blocos fiscais mais complexos sem contaminar o legado municipal
