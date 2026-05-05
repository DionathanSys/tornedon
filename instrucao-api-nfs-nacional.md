https://integranotas.com.br/doc/nfse

```php
{

- [contingencia](https://integranotas.com.br/doc/nfse): {
    - [data](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Data e Hora da entrada em contingência",
        - options: "ex. 2020-05-16T12:33:20-03:00"},
    - [motivo](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Motivo da entrada em contingência",
        - options: "15 a 256 caracteres"}},
- [identificacao](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Identificador unico enviado pelo sistema emissor para controle próprio.",
    - options: "1 a 40 carateres"},
- [regime_apuracao](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Regime de Apuração. (regApTribSN)",
    - [options](https://integranotas.com.br/doc/nfse): [
        1. "1 - Regime de apuração dos tributos federais e municipais pelo SN",
        2. "2 - Regime de apuração dos tributos federais pelo SN e ISSQN  por fora do SN conforme respectiva legislação municipal do tributo",
        3. "3 - Regime de apuração dos tributos federais e municipais por fora do SN conforme respectivas legilações federal e municipal de cada tributo"]},
- [regime_tributacao](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Regime Especial de Tributação. (regEspTrib)",
    - [options](https://integranotas.com.br/doc/nfse): [
        1. "0 - Nenhum",
        2. "1 - Ato Cooperado (Cooperativa)",
        3. "2 - Estimativa",
        4. "3 - Microempresa Municipal",
        5. "4 - Notário ou Registrador",
        6. "5 - Profissional Autônomo",
        7. "6 - Sociedade de Profissionais"],
    - default: "0 - Nenhum"},
- [data_emissao](https://integranotas.com.br/doc/nfse): {
    - required: true,
    - type: "string",
    - description: "Data de emissão do RPS. (dhEmi)",
    - options: "ex. 2020-05-16T12:33:20-03:00"},
- [data_competencia](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Data da prestação do serviço. (dCompet)",
    - options: "ex. 2020-05-16T12:33:20-03:00",
    - default: "data_emissao"},
- [numero](https://integranotas.com.br/doc/nfse): {
    - required: true,
    - type: "string",
    - description: "Número do RPS. (nDPS)",
    - options: "1 a 9 digitos"},
- [serie](https://integranotas.com.br/doc/nfse): {
    - required: true,
    - type: "string",
    - description: "Série do RPS. (serie)",
    - options: "1 a 5 digitos"},
- [tipo_emitente](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Tipo do emitente. (tpEmit)",
    - [options](https://integranotas.com.br/doc/nfse): [
        1. "1 - Prestador",
        2. "2 - Tomador",
        3. "3 - Intermediário"],
    - default: "1 - Prestador"},
- [emissao_tomador](https://integranotas.com.br/doc/nfse): {
    - [tipo](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Motivo da Emissão da DPS pelo Tomador/Intermediário. (cMotivoEmisTI)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "1 - Importação de Serviço",
            2. "2 - Tomador/Intermediário obrigado a emitir NFS-e por legislação municipal",
            3. "3 - Tomador/Intermediário emitindo NFS-e por recusa de emissão pelo prestador",
            4. "4 - Tomador/Intermediário emitindo por rejeitar a NFS-e emitida pelo prestador"]},
    - [chave](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Chave de Acesso da NFS-e rejeitada pelo Tomador/Intermediário. (chNFSeRej)",
        - options: "50 digitos"}},
- [informacoes](https://integranotas.com.br/doc/nfse): {
    - [documento_tecnico](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Identificador de Documento de Responsabilidade Técnica: ART, RRT, DRT, Outros. (idDocTec)",
        - options: "1 a 40 caracteres"},
    - [documento_ref](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Chave da nota, número identificador da nota, número do contrato ou outro identificador de documento emitido pelo prestador de serviços, que subsidia a emissão dessa nota pelo tomador do serviço ou intermediário (preenchimento obrigatório caso a nota esteja sendo emitida pelo Tomador ou intermediário do serviço). (docRef)",
        - options: "1 a 255 caracteres"},
    - [numero_pedido](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do pedido/ordem de compra/ordem de serviço/projeto que autorize a prestação do serviço em operações B2B - Informação de interesse do tomador do serviço para controle e gestão da Negociação. (xPed)",
        - options: "1 a 60 caracteres"},
    - [itens_pedido_compra](https://integranotas.com.br/doc/nfse): [
        1. [](https://integranotas.com.br/doc/nfse){
            - [item](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Número do item do  pedido/ordem de compra/ordem de serviço/projeto - Identificação do número do item do pedido ou ordem de compra destacado e xPed. (xItemPed)",
                - options: "1 a 60 caracteres"}}]},
- [tomador](https://integranotas.com.br/doc/nfse): {
    - [cnpj](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "CNPJ. (CNPJ)",
        - options: "14 digitos",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Informar cnpj ou cpf"]},
    - [cpf](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "CPF. (CPF)",
        - options: "11 digitos",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Informar cnpj ou cpf"]},
    - [nif](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número de Identificação Fiscal fornecido por órgão de administração tributária no exterior. (NIF)",
        - options: "1 a 40 caracteres"},
    - [dispensa_nif](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Tipo de dispença da exigência do NIF. (cNaoNIF)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Não informado na nota de origem",
            2. "1 - Dispensado",
            3. "2 - Não exigência"]},
    - [caepf](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do Cadastro de Atividade Econômica da Pessoa Física. (CAEPF)",
        - options: "14 digitos"},
    - [im](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Inscrição Municipal. (IM)",
        - options: "1 a 15 digitos"},
    - [razao_social](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Razão Social. (xNome)",
        - options: "1 a 300 caracteres"},
    - [telefone](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do telefone com código de área. (fone)",
        - options: "6 a 20 digitos"},
    - [email](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - maxLength: 60,
        - description: "Email. (email)",
        - options: "1 a 60 caracteres"},
    - [tipo_ente_governamental](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Tipo de ente governamental. (tpEnteGov)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "1 - União",
            2. "2 - Estado",
            3. "3 - Distrito Federal",
            4. "4 - Município"]},
    - [tipo_destinatario](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "A respeito do Destinatário dos serviços. (indDest)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - o destinatário é o próprio tomador/adquirente identificado na NFS-e (tomador = adquirente = destinatário)",
            2. "1 - o destinatário não é o próprio adquirente, podendo ser outra pessoa, física ou jurídica (ou equiparada), ou um estabelecimento diferente do indicado como tomador (tomador = adquirente ≠ destinatário)"],
        - default: "0 – Destinatário é o próprio tomador/adquirente identificado na NFS-e (tomador=adquirente=destinatário)"},
    - [endereco](https://integranotas.com.br/doc/nfse): {
        - [logradouro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Rua (Logradouro). (xLgr)",
            - options: "1 a 255 caracteres"},
        - [numero](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Número do Endereço. (nro)",
            - options: "1 a 60 caracteres"},
        - [complemento](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Complemento do Endereço. (xCpl)",
            - options: "1 a 256 caracteres"},
        - [bairro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Bairro do Endereço. (xBairro)",
            - options: "1 a 60 caracteres"},
        - [uf](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Sigla da UF",
            - options: "2 caracteres",
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Em caso de exportação informar EX"]},
        - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código IBGE do Municipio. (cMun)",
            - options: "7 digitos"},
        - [cep](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. (CEP)",
            - options: "8 digitos"},
        - [codigo_pais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código do país onde o serviço foi prestado. Declarar somente quando for fora do Brasil (Exportação). (cPais)",
            - options: "2 caracteres"},
        - [codigo_postal](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
            - options: "1 a 11 caracteres"},
        - [nome_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
            - options: "1 a 60 caracteres"},
        - [nome_provincia](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
            - options: "1 a 60 caracteres"}}},
- [servico](https://integranotas.com.br/doc/nfse): {
    - [endereco_local_prestacao](https://integranotas.com.br/doc/nfse): {
        - [codigo_municipio_prestacao](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código IBGE do município onde o serviço foi prestado. (cLocPrestacao)",
            - options: "7 digitos",
            - default: "Municipio do prestador"},
        - [codigo_pais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código do país onde o serviço foi prestado. Declarar somente quando for fora do Brasil (Exportação). (cPaisPrestacao)",
            - options: "2 caracteres"}},
    - [codigo](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Código do serviço prestado Item da LC 116/2003 (https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/anexo_b-nbs2-lista_servico_nacional-snnfse.xlsx/view). (cTribNac)",
        - options: "6 digitos"},
    - [descricao](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - maxLength: 200,
        - description: "Descrição do serviço",
        - options: "1 a 200 caracteres",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Usado apenas para mostrar no PDF a descrição do código do serviço"]},
    - [codigo_tributacao_municipio](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Código do serviço prestado próprio do município. (cTribMun)",
        - options: "3 digitos"},
    - [discriminacao](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - maxLength: 2000,
        - description: "Discriminação dos serviços. (xDescServ)",
        - options: "1 a 2000 caracteres",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Para quebra de linha use **\n**"]},
    - [codigo_nbs](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Código NBS. (cNBS)",
        - options: "9 digitos"},
    - [codigo_interno](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Código interno. (cIntContrib)",
        - options: "1 a 20 caractedres"},
    - [valor_servicos](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "number",
        - minimum: 0.01,
        - description: "Valor dos serviços em R$. (vServ)",
        - options: "ex. 100.00"},
    - [valor_recebido](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "number",
            2. "null"],
        - minimum: 0,
        - description: "Valor total recebido pelo serviço. (vReceb)",
        - options: "ex. 100.00"},
    - [valor_desconto_condicionado](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "number",
            2. "null"],
        - minimum: 0,
        - description: "Valor do desconto condicionado. (vDescCond)",
        - options: "ex. 100.00"},
    - [valor_desconto_incondicionado](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "number",
            2. "null"],
        - minimum: 0,
        - description: "Valor do desconto incondicionado. (vDescIncond)",
        - options: "ex. 100.00"},
    - [tributos_municipais](https://integranotas.com.br/doc/nfse): {
        - [tipo_operacao](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Tipo da operação. (tribISSQN)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "1 - Operação tributável",
                2. "2 - Imunidade",
                3. "3 - Exportação de serviço",
                4. "4 - Não Incidência"],
            - default: "1 - Operação tributável"},
        - [codigo_pais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Sigla do pais na tabela ISO. Declarar somente quando for fora do Brasil (Exportação). (cPaisResult)",
            - options: "2 caracteres"},
        - [aliquota_iss](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - maximum: 100,
            - description: "Alíquota do serviço prestado. (pAliq)",
            - options: "ex. 5.00",
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Deve ser sempre enviado esse valor em percentual, 5% seria enviado como 5.00 e nao 0.05"]},
        - [iss_retido](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "boolean",
                2. "null"],
            - description: "ISS foi retido",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "true - SIM",
                2. "false - Não"],
            - default: "false"},
        - [responsavel_retencao](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Responsável pela Retençao o ISS. (tpRetISSQN)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "2 - Retido pelo Tomador",
                2. "3 - Retido pelo Intermediario"],
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Quando não informado sera usado '2 - Retido pelo Tomador' quando o campo iss_retido = true"]},
        - [imunidade](https://integranotas.com.br/doc/nfse): {
            - [tipo](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Identificação da Imunidade do ISSQN. (tpImunidade)",
                - [options](https://integranotas.com.br/doc/nfse): [
                    1. "0 - Imunidade (tipo não informado na nota de origem)",
                    2. "1 - Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)",
                    3. "2 - Templos de qualquer culto (CF88, Art 150, VI, b)",
                    4. "3 - Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos, atendidos os requisitos da lei (CF88, Art 150, VI, c)",
                    5. "4 - Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)",
                    6. "5 - Fonogramas e videofonogramas musicais produzidos no Brasil contendo obras musicais ou literomusicais de autores brasileiros e/ou obras em geral interpretadas por artistas brasileiros bem como os suportes materiais ou arquivos digitais que os contenham, salvo na etapa de replicação industrial de mídias ópticas de leitura a laser.   (CF88, Art 150, VI, e)"]}},
        - [beneficio](https://integranotas.com.br/doc/nfse): {
            - [numero](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Identificador do benefício municipal parametrizado pelo município. (nBM)",
                - options: "14 digitos"},
            - [valor_reducao](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "number",
                    2. "null"],
                - description: "Valor monetário informado pelo emitente para redução da base de cálculo (BC) do ISSQN devido a um Benefício Municipal. (vRedBCBM)",
                - options: "ex. 100.00"},
            - [percentual_reducao](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "number",
                    2. "null"],
                - minimum: 0,
                - maximum: 100,
                - description: "Valor percentual informado pelo emitente para redução da base de cálculo (BC) do ISSQN devido a um Benefício Municipal. (pRedBCBM)",
                - [options](https://integranotas.com.br/doc/nfse): [
                    1. "ex. 5.00",
                    2. "Deve ser sempre enviado esse valor em percentual, 5% seria enviado como 5.00 e nao 0.05"]}},
        - [suspensao](https://integranotas.com.br/doc/nfse): {
            - [tipo](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Identificação da Imunidade da suspensão. (tpSusp)",
                - [options](https://integranotas.com.br/doc/nfse): [
                    1. "6 - Exigibilidade Suspensa por Decisão Judicial",
                    2. "7 - Exigibilidade Suspensa por Processo Administrativo"]},
            - [numero](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Número do processo judicial ou administrativo de suspensão da exigibilidade. (nProcesso)",
                - options: "30 digitos"}}},
    - [tributos_nacionais](https://integranotas.com.br/doc/nfse): {
        - [cst](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Situação tributária do PIS e COFINS. (CST)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "00 - Nenhum",
                2. "01 - Operação Tributável com Alíquota Básica",
                3. "02 - Operação Tributável com Alíquota Diferenciada",
                4. "03 - Operação Tributável com Alíquota por Unidade de Medida de Produto",
                5. "04 - Operação Tributável monofásica - Revenda a Alíquota Zero",
                6. "05 - Operação Tributável por Substituição Tributária",
                7. "06 - Operação Tributável a Alíquota Zero",
                8. "07 - Operação Tributável da Contribuição",
                9. "08 - Operação sem Incidência da Contribuição",
                10. "09 - Operação com Suspensão da Contribuição"]},
        - [tipo_retencao](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Informa se o PIS/COFINS foi retido. (tpRetPisCofins)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "1 - PIS/COFINS Retido",
                2. "2 - PIS/COFINS Não Retido"],
            - default: "2 - PIS/COFINS Não Retido"},
        - [valor_base_calculo](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor de base de cálculo PIS e COFINS. (vBCPisCofins)",
            - options: "ex. 100.00"},
        - [aliquota_pis](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Percentual PIS. (pAliqPis)",
            - options: "ex. 10.00"},
        - [valor_pis](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor PIS. (vPis)",
            - options: "ex. 10.00"},
        - [aliquota_cofins](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Percentual COFINS. (pAliqCofins)",
            - options: "ex. 10.00"},
        - [valor_cofins](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor COFINS. (vCofins)",
            - options: "ex. 10.00"},
        - [valor_inss](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor de retenção INSS. (vRetCP)",
            - options: "ex. 100.00"},
        - [valor_ir](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor de retenção IR. (vRetIRRF)",
            - options: "ex. 100.00"},
        - [valor_csll](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor retenção CSLL. (vRetCSLL)",
            - options: "ex. 100.00"}},
    - [tributos_totais](https://integranotas.com.br/doc/nfse): {
        - [percentual_tributos_federais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor em percentual dos tributos federais. (pTotTribFed)",
            - options: "ex. 10.00"},
        - [valor_tributos_federais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor total dos tributos federais. (vTotTribFed)",
            - options: "ex. 100.00"},
        - [percentual_tributos_estaduais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor em percentual dos tributos estaduais. (pTotTribEst)",
            - options: "ex. 10.00"},
        - [valor_tributos_estaduais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor total dos tributos estaduais. (vTotTribEst)",
            - options: "ex. 100.00"},
        - [percentual_tributos_municipais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor em percentual dos tributos municipais. (pTotTribMun)",
            - options: "ex. 10.00"},
        - [valor_tributos_municipais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor total dos tributos municipais. (vTotTribMun)",
            - options: "ex. 100.00"},
        - [percentual_tributos_simples_nacional](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "number",
                2. "null"],
            - minimum: 0,
            - description: "Valor em percentual dos tributos do simples nacional. (pTotTribSN)",
            - options: "ex. 10.00"}},
    - [ibs_cbs](https://integranotas.com.br/doc/nfse): {
        - [consumidor_final](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Indica operação de uso ou consumo pessoal (art. 57). (indFinal)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "0 - Não",
                2. "1 - Sim"]},
        - [codigo_fornecimento](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Código indicador da operação de fornecimento, conforme tabela "código indicador de operação". (cIndOp)",
            - options: "6 digitos"},
        - [tipo_operacao](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Tipo de Operação com Entes Governamentais ou outros serviços sobre bens imóveis. (tpOper)",
            - [options](https://integranotas.com.br/doc/nfse): [
                1. "1 - Fornecimento com pagamento posterior",
                2. "2 - Recebimento do pagamento com fornecimento já realizado",
                3. "3 - Fornecimento com pagamento já realizado",
                4. "4 - Recebimento do pagamento com fornecimento posterior",
                5. "5 - Fornecimento e recebimento do pagamento concomitantes"]},
        - [nfse_referenciadas](https://integranotas.com.br/doc/nfse): [
            1. [](https://integranotas.com.br/doc/nfse){
                - [chave](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Tipo Chave da Nota Fiscal de Serviço Eletrônica. (refNFSe)",
                    - options: "50 digitos"}}],
        - [destinatario](https://integranotas.com.br/doc/nfse): {
            - [cnpj](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "CNPJ. (CNPJ)",
                - options: "14 digitos",
                - [rules](https://integranotas.com.br/doc/nfse): [
                    1. "Informar cnpj ou cpf"]},
            - [cpf](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "CPF. (CPF)",
                - options: "11 digitos",
                - [rules](https://integranotas.com.br/doc/nfse): [
                    1. "Informar cnpj ou cpf"]},
            - [nif](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Número de Identificação Fiscal fornecido por órgão de administração tributária no exterior. (NIF)",
                - options: "1 a 40 caracteres"},
            - [dispensa_nif](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Tipo de dispença da exigência do NIF. (cNaoNIF)",
                - [options](https://integranotas.com.br/doc/nfse): [
                    1. "0 - Não informado na nota de origem",
                    2. "1 - Dispensado",
                    3. "2 - Não exigência"]},
            - [caepf](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Número do Cadastro de Atividade Econômica da Pessoa Física. (CAEPF)",
                - options: "14 digitos"},
            - [razao_social](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Razão Social. (xNome)",
                - options: "1 a 300 caracteres"},
            - [telefone](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Número do telefone com código de área. (fone)",
                - options: "6 a 20 digitos"},
            - [email](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - maxLength: 60,
                - description: "Email. (email)",
                - options: "1 a 60 caracteres"},
            - [endereco](https://integranotas.com.br/doc/nfse): {
                - [logradouro](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Rua (Logradouro). (xLgr)",
                    - options: "1 a 255 caracteres"},
                - [numero](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Número do Endereço. (nro)",
                    - options: "1 a 60 caracteres"},
                - [complemento](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Complemento do Endereço. (xCpl)",
                    - options: "1 a 256 caracteres"},
                - [bairro](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Bairro do Endereço. (xBairro)",
                    - options: "1 a 60 caracteres"},
                - [uf](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Sigla da UF",
                    - options: "2 caracteres",
                    - [rules](https://integranotas.com.br/doc/nfse): [
                        1. "Em caso de exportação informar EX"]},
                - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Código IBGE do Municipio. (cMun)",
                    - options: "7 digitos"},
                - [cep](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Codigo Postal. (CEP)",
                    - options: "8 digitos"},
                - [codigo_pais](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Código do país onde o serviço foi prestado. Declarar somente quando for fora do Brasil (Exportação). (cPais)",
                    - options: "2 caracteres"},
                - [codigo_postal](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
                    - options: "1 a 11 caracteres"},
                - [nome_municipio](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
                    - options: "1 a 60 caracteres"},
                - [nome_provincia](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
                    - options: "1 a 60 caracteres"}}},
        - [imovel](https://integranotas.com.br/doc/nfse): {
            - [numero_matricula](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Inscrição imobiliária fiscal (código fornecido pela Prefeitura Municipal para a identificação da obra ou para fins de recolhimento do IPTU). (inscImobFisc)",
                - options: "1 a 30 caracteres"},
            - [codigo_cadastro_imobiliario](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Código do Cadastro Imobiliário Brasileiro - CIB. (cCIB)",
                - options: "8 caracteres"},
            - [endereco](https://integranotas.com.br/doc/nfse): {
                - [logradouro](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Rua (Logradouro). (xLgr)",
                    - options: "1 a 255 caracteres"},
                - [numero](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Número do Endereço. (nro)",
                    - options: "1 a 60 caracteres"},
                - [complemento](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Complemento do Endereço. (xCpl)",
                    - options: "1 a 256 caracteres"},
                - [bairro](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Bairro do Endereço. (xBairro)",
                    - options: "1 a 60 caracteres"},
                - [uf](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Sigla da UF",
                    - options: "2 caracteres",
                    - [rules](https://integranotas.com.br/doc/nfse): [
                        1. "Em caso de exportação informar EX"]},
                - [cep](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Codigo Postal. (CEP)",
                    - options: "8 digitos"},
                - [codigo_postal](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
                    - options: "1 a 11 caracteres"},
                - [nome_municipio](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
                    - options: "1 a 60 caracteres"},
                - [nome_provincia](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
                    - options: "1 a 60 caracteres"}}},
        - [ressarcimento](https://integranotas.com.br/doc/nfse): {
            - [documentos](https://integranotas.com.br/doc/nfse): [
                1. [](https://integranotas.com.br/doc/nfse){
                    - [dfe_nacional](https://integranotas.com.br/doc/nfse): {
                        - [tipo](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - description: "Documento fiscal a que se refere a chaveDfe que seja um dos documentos do Repositório Nacional. (tipoChaveDFe)",
                            - [options](https://integranotas.com.br/doc/nfse): [
                                1. "1 - NFS-e",
                                2. "2 - NF-e",
                                3. "3 - CT-e",
                                4. "9 - Outro"]},
                        - [descricao](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - minLength: 1,
                            - maxLength: 255,
                            - description: "Descrição da DF-e a que se refere a chaveDfe que seja um dos documentos do Repositório Nacional. (xTipoChaveDFe)",
                            - options: "1 a 255 caracteres",
                            - [rules](https://integranotas.com.br/doc/nfse): [
                                1. "Deve ser preenchido apenas quando "tipoChaveDFe = 9 (Outro)""]},
                        - [chave](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - minLength: 1,
                            - maxLength: 50,
                            - description: "Chave do Documento Fiscal eletrônico do repositório nacional referenciado para os casos de operações já tributadas. (chaveDFe)",
                            - options: "1 a 50 caracteres"}},
                    - [dfe_outro](https://integranotas.com.br/doc/nfse): {
                        - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - description: "Código do município emissor do documento fiscal que não se encontra no repositório nacional. (cMunDocFiscal)",
                            - options: "7 digitos"},
                        - [numero](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - minLength: 1,
                            - maxLength: 255,
                            - description: "Número do documento fiscal que não se encontra no repositório nacional. (nDocFiscal)",
                            - options: "1 a 255 caracteres"},
                        - [descricao](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - minLength: 1,
                            - maxLength: 255,
                            - description: "Descrição do documento fiscal. (xDocFiscal)",
                            - options: "1 a 255 caracteres"}},
                    - [outro](https://integranotas.com.br/doc/nfse): {
                        - [numero](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - minLength: 1,
                            - maxLength: 255,
                            - description: "Número do documento não fiscal. (nDoc)",
                            - options: "1 a 255 caracteres"},
                        - [descricao](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - minLength: 1,
                            - maxLength: 255,
                            - description: "Descrição do documento não fiscal. (xDoc)",
                            - options: "1 a 255 caracteres"}},
                    - [fornecedor](https://integranotas.com.br/doc/nfse): {
                        - [cnpj](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - description: "CNPJ. (CNPJ)",
                            - options: "14 digitos",
                            - [rules](https://integranotas.com.br/doc/nfse): [
                                1. "Informar cnpj ou cpf"]},
                        - [cpf](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - description: "CPF. (CPF)",
                            - options: "11 digitos",
                            - [rules](https://integranotas.com.br/doc/nfse): [
                                1. "Informar cnpj ou cpf"]},
                        - [nif](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - description: "Número de Identificação Fiscal fornecido por órgão de administração tributária no exterior. (NIF)",
                            - options: "1 a 40 caracteres"},
                        - [dispensa_nif](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - description: "Tipo de dispença da exigência do NIF. (cNaoNIF)",
                            - [options](https://integranotas.com.br/doc/nfse): [
                                1. "0 - Não informado na nota de origem",
                                2. "1 - Dispensado",
                                3. "2 - Não exigência"]},
                        - [caepf](https://integranotas.com.br/doc/nfse): {
                            - required: false,
                            - [type](https://integranotas.com.br/doc/nfse): [
                                1. "string",
                                2. "null"],
                            - description: "Número do Cadastro de Atividade Econômica da Pessoa Física. (CAEPF)",
                            - options: "14 digitos"},
                        - [razao_social](https://integranotas.com.br/doc/nfse): {
                            - required: true,
                            - type: "string",
                            - description: "Razão Social. (xNome)",
                            - options: "1 a 300 caracteres"}},
                    - [data_emissao](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Data da emissão do documento dedutível. (dtEmiDoc)",
                        - options: "ex. 2025-12-31"},
                    - [data_competencia](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Data da competência do documento dedutível. (dtCompDoc)",
                        - options: "ex. 2025-12-31"},
                    - [tipo](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Tipo de valor incluído neste documento, recebido por motivo de estarem relacionadas a operações de terceiros, objeto de reembolso, repasse ou ressarcimento pelo recebedor, já tributados e aqui referenciados. (tpReeRepRes)",
                        - [options](https://integranotas.com.br/doc/nfse): [
                            1. "01 - Repasse de remuneração por intermediação de imóveis a demais corretores envolvidos na operação",
                            2. "02 - Repasse de valores a fornecedor relativo a fornecimento intermediado por agência de turismo",
                            3. "03 - Reembolso ou ressarcimento recebido por agência de propaganda e publicidade por valores pagos relativos a serviços de produção externa por conta e ordem de terceiro",
                            4. "04 - Reembolso ou ressarcimento recebido por agência de propaganda e publicidade por valores pagos relativos a serviços de mídia por conta e ordem de terceiro",
                            5. "99 - Outros reembolsos ou ressarcimentos recebidos por valores pagos relativos a operações por conta e ordem de terceiro"]},
                    - [descricao](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - minLength: 1,
                        - maxLength: 150,
                        - description: "Descrição do reembolso ou ressarcimento. (xTpReeRepRes)",
                        - options: "1 a 150 caracteres",
                        - [rules](https://integranotas.com.br/doc/nfse): [
                            1. "99 - Outros reembolsos ou ressarcimentos recebidos por valores pagos relativos a operações por conta e ordem de terceiro"]},
                    - [valor](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "number",
                        - description: "Valor monetário (total ou parcial, conforme documento informado) utilizado para não inclusão na base de cálculo do ISS e do IBS e da CBS da NFS-e que está sendo emitida (R$). (vlrReeRepRes)",
                        - options: "ex. 10.00"}}]},
        - [situacao_tributaria](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Código de Situação Tributária do IBS e da CBS. (CST)",
            - options: "3 digitos"},
        - [classificacao_tributaria](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Código de Classificação Tributária do IBS e da CBS. (cClassTrib)",
            - options: "6 digitos"},
        - [codigo_credito_presumido](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código e Classificação do Crédito Presumido: IBS e CBS. (cCredPres)",
            - options: "2 digitos"},
        - [regular](https://integranotas.com.br/doc/nfse): {
            - [situacao_tributaria](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Código de Situação Tributária do IBS e da CBS de tributação regular. (CSTReg)",
                - options: "3 digitos"},
            - [classificacao_tributaria](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Código da Classificação Tributária do IBS e da CBS de tributação regular. (cClassTribReg)",
                - options: "6 digitos"}},
        - [diferimento](https://integranotas.com.br/doc/nfse): {
            - [percentual_ibs_uf](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "number",
                - description: "Percentual de diferimento para o IBS estadual. (pDifUF)",
                - options: "ex. 8.00"},
            - [percentual_ibs_municipio](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "number",
                - description: "Percentual de diferimento para o IBS municipal. (pDifMun)",
                - options: "ex. 8.00"},
            - [percentual_cbs](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "number",
                - description: "Percentual de diferimento para a CBS. (pDifCBS)",
                - options: "ex. 8.00"}}}},
- [deducoes](https://integranotas.com.br/doc/nfse): {
    - [percentual](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "number",
            2. "null"],
        - minimum: 0,
        - maximum: 100,
        - description: "Percentual da dedução. (pDR)",
        - options: "ex. 5.00",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Deve ser sempre enviado esse valor em percentual, 5% seria enviado como 5.00 e nao 0.05"]},
    - [valor](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "number",
            2. "null"],
        - minimum: 0,
        - description: "Valor da dedução. (vDR)",
        - options: "ex. 100.00"},
    - [itens](https://integranotas.com.br/doc/nfse): [
        1. [](https://integranotas.com.br/doc/nfse){
            - [nfse_nacional](https://integranotas.com.br/doc/nfse): {
                - [chave](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Chave de Acesso da NFS-e (Padrão Nacional). (chNFSe)",
                    - options: "50 caracteres"}},
            - [nfe](https://integranotas.com.br/doc/nfse): {
                - [chave](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Chave de Acesso da NF-e. (chNFe)",
                    - options: "44 caracteres"}},
            - [nfse_municipal](https://integranotas.com.br/doc/nfse): {
                - [numero](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Número da nota eletrônica municipal. (nNFSeMun)",
                    - options: "15 digitos"},
                - [codigo_verificacao](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Código de Verificação da nota eletrônica municipal. (cVerifNFSeMun)",
                    - options: "1 a 9 caracteres"},
                - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Código Município emissor da nota eletrônica municipal (Tabela do IBGE). (cMunNFSeMun)",
                    - options: "7 digitos"}},
            - [nf](https://integranotas.com.br/doc/nfse): {
                - [numero](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Número da Nota Fiscal NF ou NFS. (nNFS)",
                    - options: "7 digitos"},
                - [serie](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Modelo da Nota Fiscal NF ou NFS. (modNFS)",
                    - options: "1 a 15 caracteres"},
                - [modelo](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Série Nota Fiscal NF ou NFS. (serieNFS)",
                    - options: "1 a 15 caracteres"}},
            - [outro_documento_fiscal](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - minLength: 1,
                - maxLength: 255,
                - description: "Número de documento fiscal. (nDocFisc)",
                - options: "1 a 255 caracteres"},
            - [outro_documento](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - minLength: 1,
                - maxLength: 255,
                - description: "Número de documento não fiscal. (nDoc)",
                - options: "1 a 255 caracteres"},
            - [tipo_deducao](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Identificação da Dedução/Redução. (tpDedRed)",
                - [options](https://integranotas.com.br/doc/nfse): [
                    1. "1 - Alimentação e bebidas/frigobar",
                    2. "2 - Materiais",
                    3. "3 - Produção externa",
                    4. "4 - Reembolso de despesas",
                    5. "5 - Repasse consorciado",
                    6. "6 - Repasse plano de saúde",
                    7. "7 - Serviços",
                    8. "8 - Subempreitada de mão de obra",
                    9. "99 - Outras deduções"]},
            - [descricao](https://integranotas.com.br/doc/nfse): {
                - required: false,
                - [type](https://integranotas.com.br/doc/nfse): [
                    1. "string",
                    2. "null"],
                - description: "Descrição da Dedução/Redução. (xDescOutDed)",
                - options: "1 a 150 caracteres",
                - [rules](https://integranotas.com.br/doc/nfse): [
                    1. "99 - Outras Deduções"]},
            - [data_emissao](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "string",
                - description: "Data da emissão do documento dedutível. (dtEmiDoc)",
                - options: "ex. 2025-12-31"},
            - [valor_dedutivel](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "number",
                - minimum: 0,
                - description: "Valor monetário total dedutível/redutível no documento informado. (vDedutivelRedutivel)",
                - options: "ex. 100.00"},
            - [valor_deducao](https://integranotas.com.br/doc/nfse): {
                - required: true,
                - type: "number",
                - minimum: 0,
                - description: "Valor monetário utilizado para dedução/redução do valor do serviço da NFS-e que está sendo emitida. (vDeducaoReducao)",
                - options: "ex. 1.00"},
            - [emitente](https://integranotas.com.br/doc/nfse): {
                - [cnpj](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "CNPJ. (CNPJ)",
                    - options: "14 digitos",
                    - [rules](https://integranotas.com.br/doc/nfse): [
                        1. "Informar cnpj ou cpf"]},
                - [cpf](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "CPF. (CPF)",
                    - options: "11 digitos",
                    - [rules](https://integranotas.com.br/doc/nfse): [
                        1. "Informar cnpj ou cpf"]},
                - [nif](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Número de Identificação Fiscal fornecido por órgão de administração tributária no exterior. (NIF)",
                    - options: "1 a 40 caracteres"},
                - [dispensa_nif](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Tipo de dispença da exigência do NIF. (cNaoNIF)",
                    - [options](https://integranotas.com.br/doc/nfse): [
                        1. "0 - Não informado na nota de origem",
                        2. "1 - Dispensado",
                        3. "2 - Não exigência"]},
                - [caepf](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Número do Cadastro de Atividade Econômica da Pessoa Física. (CAEPF)",
                    - options: "14 digitos"},
                - [im](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Inscrição Municipal. (IM)",
                    - options: "1 a 15 digitos"},
                - [razao_social](https://integranotas.com.br/doc/nfse): {
                    - required: true,
                    - type: "string",
                    - description: "Razão Social. (xNome)",
                    - options: "1 a 300 caracteres"},
                - [telefone](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - description: "Número do telefone com código de área. (fone)",
                    - options: "6 a 20 digitos"},
                - [email](https://integranotas.com.br/doc/nfse): {
                    - required: false,
                    - [type](https://integranotas.com.br/doc/nfse): [
                        1. "string",
                        2. "null"],
                    - maxLength: 60,
                    - description: "Email. (email)",
                    - options: "1 a 60 caracteres"},
                - [endereco](https://integranotas.com.br/doc/nfse): {
                    - [logradouro](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Rua (Logradouro). (xLgr)",
                        - options: "1 a 255 caracteres"},
                    - [numero](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Número do Endereço. (nro)",
                        - options: "1 a 60 caracteres"},
                    - [complemento](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Complemento do Endereço. (xCpl)",
                        - options: "1 a 256 caracteres"},
                    - [bairro](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Bairro do Endereço. (xBairro)",
                        - options: "1 a 60 caracteres"},
                    - [uf](https://integranotas.com.br/doc/nfse): {
                        - required: true,
                        - type: "string",
                        - description: "Sigla da UF",
                        - options: "2 caracteres",
                        - [rules](https://integranotas.com.br/doc/nfse): [
                            1. "Em caso de exportação informar EX"]},
                    - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Código IBGE do Municipio. (cMun)",
                        - options: "7 digitos"},
                    - [cep](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Codigo Postal. (CEP)",
                        - options: "8 digitos"},
                    - [codigo_pais](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Código do país onde o serviço foi prestado. Declarar somente quando for fora do Brasil (Exportação). (cPais)",
                        - options: "2 caracteres"},
                    - [codigo_postal](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
                        - options: "1 a 11 caracteres"},
                    - [nome_municipio](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
                        - options: "1 a 60 caracteres"},
                    - [nome_provincia](https://integranotas.com.br/doc/nfse): {
                        - required: false,
                        - [type](https://integranotas.com.br/doc/nfse): [
                            1. "string",
                            2. "null"],
                        - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
                        - options: "1 a 60 caracteres"}}}}]},
- [intermediario](https://integranotas.com.br/doc/nfse): {
    - [cnpj](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "CNPJ. (CNPJ)",
        - options: "14 digitos",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Informar cnpj ou cpf"]},
    - [cpf](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "CPF. (CPF)",
        - options: "11 digitos",
        - [rules](https://integranotas.com.br/doc/nfse): [
            1. "Informar cnpj ou cpf"]},
    - [nif](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número de Identificação Fiscal fornecido por órgão de administração tributária no exterior. (NIF)",
        - options: "1 a 40 caracteres"},
    - [dispensa_nif](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Tipo de dispença da exigência do NIF. (cNaoNIF)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Não informado na nota de origem",
            2. "1 - Dispensado",
            3. "2 - Não exigência"]},
    - [caepf](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do Cadastro de Atividade Econômica da Pessoa Física. (CAEPF)",
        - options: "14 digitos"},
    - [im](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Inscrição Municipal. (IM)",
        - options: "1 a 15 digitos"},
    - [razao_social](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Razão Social. (xNome)",
        - options: "1 a 300 caracteres"},
    - [telefone](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do telefone com código de área. (fone)",
        - options: "6 a 20 digitos"},
    - [email](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - maxLength: 60,
        - description: "Email. (email)",
        - options: "1 a 60 caracteres"},
    - [endereco](https://integranotas.com.br/doc/nfse): {
        - [logradouro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Rua (Logradouro). (xLgr)",
            - options: "1 a 255 caracteres"},
        - [numero](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Número do Endereço. (nro)",
            - options: "1 a 60 caracteres"},
        - [complemento](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Complemento do Endereço. (xCpl)",
            - options: "1 a 256 caracteres"},
        - [bairro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Bairro do Endereço. (xBairro)",
            - options: "1 a 60 caracteres"},
        - [uf](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Sigla da UF",
            - options: "2 caracteres",
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Em caso de exportação informar EX"]},
        - [codigo_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código IBGE do Municipio. (cMun)",
            - options: "7 digitos"},
        - [cep](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. (CEP)",
            - options: "8 digitos"},
        - [codigo_pais](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Código do país onde o serviço foi prestado. Declarar somente quando for fora do Brasil (Exportação). (cPais)",
            - options: "2 caracteres"},
        - [codigo_postal](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
            - options: "1 a 11 caracteres"},
        - [nome_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
            - options: "1 a 60 caracteres"},
        - [nome_provincia](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
            - options: "1 a 60 caracteres"}}},
- [obra](https://integranotas.com.br/doc/nfse): {
    - [numero_matricula](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número de matrícula. (inscImobFisc)",
        - options: "1 a 30 caracteres"},
    - [codigo](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do projeto. (cObra)",
        - options: "1 a 30 caracteres"},
    - [endereco](https://integranotas.com.br/doc/nfse): {
        - [logradouro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Rua (Logradouro). (xLgr)",
            - options: "1 a 255 caracteres"},
        - [numero](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Número do Endereço. (nro)",
            - options: "1 a 60 caracteres"},
        - [complemento](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Complemento do Endereço. (xCpl)",
            - options: "1 a 256 caracteres"},
        - [bairro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Bairro do Endereço. (xBairro)",
            - options: "1 a 60 caracteres"},
        - [uf](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Sigla da UF",
            - options: "2 caracteres",
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Em caso de exportação informar EX"]},
        - [cep](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. (CEP)",
            - options: "8 digitos"},
        - [codigo_postal](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
            - options: "1 a 11 caracteres"},
        - [nome_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
            - options: "1 a 60 caracteres"},
        - [nome_provincia](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
            - options: "1 a 60 caracteres"}}},
- [evento](https://integranotas.com.br/doc/nfse): {
    - [descricao](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Descrição do evento Artístico, Cultural, Esportivo, etc. (desc)",
        - options: "1 a 255 caracteres"},
    - [data_inicio](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Data de início da atividade de evento. (dtIni)",
        - options: "ex. 2020-05-16"},
    - [data_fim](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Data de fim da atividade de evento. (dtFim)",
        - options: "ex. 2020-05-16"},
    - [codigo_atividade](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Identificação da Atividade de Evento (código identificador de evento determinado pela Administração Tributária Municipal). (idAtvEvt)",
        - options: "1 a 30 caracteres"},
    - [endereco](https://integranotas.com.br/doc/nfse): {
        - [logradouro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Rua (Logradouro). (xLgr)",
            - options: "1 a 255 caracteres"},
        - [numero](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Número do Endereço. (nro)",
            - options: "1 a 60 caracteres"},
        - [complemento](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Complemento do Endereço. (xCpl)",
            - options: "1 a 256 caracteres"},
        - [bairro](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Bairro do Endereço. (xBairro)",
            - options: "1 a 60 caracteres"},
        - [uf](https://integranotas.com.br/doc/nfse): {
            - required: true,
            - type: "string",
            - description: "Sigla da UF",
            - options: "2 caracteres",
            - [rules](https://integranotas.com.br/doc/nfse): [
                1. "Em caso de exportação informar EX"]},
        - [cep](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. (CEP)",
            - options: "8 digitos"},
        - [codigo_postal](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Codigo Postal. Declarar somente quando for fora do Brasil (Exportação). (cEndPost)",
            - options: "1 a 11 caracteres"},
        - [nome_municipio](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome do municipio. Declarar somente quando for fora do Brasil (Exportação). (xCidade)",
            - options: "1 a 60 caracteres"},
        - [nome_provincia](https://integranotas.com.br/doc/nfse): {
            - required: false,
            - [type](https://integranotas.com.br/doc/nfse): [
                1. "string",
                2. "null"],
            - description: "Nome da região/provincia. Declarar somente quando for fora do Brasil (Exportação). (xEstProvReg)",
            - options: "1 a 60 caracteres"}}},
- [locacao](https://integranotas.com.br/doc/nfse): {
    - [categoria](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Categoria do serviço. (categ)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "1 - Locação",
            2. "2 - Sublocação",
            3. "3 - Arrendamento",
            4. "4- Direito de passagem",
            5. "5 - Permissão de uso"]},
    - [objeto](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Tipo de objetos da locação, sublocação, arrendamento, direito de passagem ou permissão de uso. (objeto)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "1 - Ferrovia",
            2. "2 - Rodovia",
            3. "3 - Postes",
            4. "4 - Cabos",
            5. "5- Dutos",
            6. "6 - Condutos de qualquer natureza"]},
    - [extensao](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Extensão total da ferrovia, rodovia, cabos, dutos ou condutos. (extensao)",
        - options: "1 a 5 digitos"},
    - [postes](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Número total de postes. (nPostes)",
        - options: "1 a 6 digitos"}},
- [exterior](https://integranotas.com.br/doc/nfse): {
    - [modo_prestacao](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Modo de Prestação. (mdPrestacao)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Desconhecido (tipo não informado na nota de origem)",
            2. "1 - Transfronteiriço",
            3. "2 - Consumo no Brasil",
            4. "3- Movimento Temporário de Pessoas Físicas",
            5. "4 - Consumo no Exterior"]},
    - [vinculo_prestador](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Vínculo entre as partes no negócio. (vincPrest)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Sem vínculo com o tomador/Prestador",
            2. "1 - Controlada",
            3. "2 - Controladora",
            4. "3 - Coligada",
            5. "4 - Matriz",
            6. "5 - Filial ou sucursal",
            7. "6 - Outro vínculo"]},
    - [moeda](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Identifica a moeda da transação comercial. (tpMoeda)",
        - options: "1 a 3 digitos"},
    - [valor](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "number",
        - minimum: 0,
        - description: "Valor do serviço prestado expresso em moeda estrangeira especificada em tpmoeda. (vServMoeda)",
        - options: "ex. 10.00"},
    - [codigo_fomento_prestador](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Mecanismo de apoio/fomento ao Comércio Exterior utilizado pelo prestador do serviço. (mecAFComexP)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "00 - Desconhecido (tipo não informado na nota de origem)",
            2. "01 - Nenhum",
            3. "02 - ACC - Adiantamento sobre Contrato de Câmbio - Redução a Zero do IR e do IOF",
            4. "03 - ACE - Adiantamento sobre Cambiais Entregues - Redução a Zero do IR e do IOF",
            5. "04 - BNDES-Exim Pós-Embarque - Serviços",
            6. "05 - BNDES-Exim Pré-Embarque - Serviços",
            7. "06 - FGE - Fundo de Garantia à Exportação",
            8. "07 - PROEX - EQUALIZAÇÃO",
            9. "08 - PROEX - Financiamento"]},
    - [codigo_fomento_tomador](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Mecanismo de apoio/fomento ao Comércio Exterior utilizado pelo tomador do serviço. (mecAFComexT)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "00 - Desconhecido (tipo não informado na nota de origem)",
            2. "01 - Nenhum",
            3. "02 - Adm. Pública e Repr. Internacional",
            4. "03 - Alugueis e Arrend. Mercantil de maquinas, equip., embarc. e aeronaves",
            5. "04 - Arrendamento Mercantil de aeronave para empresa de transporte aéreo público",
            6. "05 - Comissão a agentes externos na exportação",
            7. "06 - Despesas de armazenagem, mov. e transporte de carga no exterior",
            8. "07 - Eventos FIFA (subsidiária)",
            9. "08 - Eventos FIFA",
            10. "09 - Fretes, arrendamentos de embarcações ou aeronaves e outros",
            11. "10 - Material Aeronáutico",
            12. "11 - Promoção de Bens no Exterior",
            13. "12 - Promoção de Dest. Turísticos Brasileiros",
            14. "13 - Promoção do Brasil no Exterior",
            15. "14 - Promoção Serviços no Exterior",
            16. "15 - RECINE",
            17. "16 - RECOPA",
            18. "17 - Registro e Manutenção de marcas, patentes e cultivares",
            19. "18 - REICOMP",
            20. "19 - REIDI",
            21. "20 - REPENEC",
            22. "21 - REPES",
            23. "22 - RETAERO",
            24. "23 - RETID",
            25. "24 - Royalties, Assistência Técnica, Científica e Assemelhados",
            26. "25 - Serviços de avaliação da conformidade vinculados aos Acordos da OMC",
            27. "26 - ZPE"]},
    - [movimentacao_bens](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Operação está vinculada à Movimentação Temporária de Bens. (movTempBens)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Desconhecido (tipo não informado na nota de origem)",
            2. "1 - Não",
            3. "2 - Vinculada - Declaração de Importação",
            4. "3 - Vinculada - Declaração de Exportação"]},
    - [numero_di](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número da Declaração de Importação (DI/DSI/DA/DRI-E) averbado. (nDI)",
        - options: "1 a 12 caracteres"},
    - [numero_re](https://integranotas.com.br/doc/nfse): {
        - required: false,
        - [type](https://integranotas.com.br/doc/nfse): [
            1. "string",
            2. "null"],
        - description: "Número do Registro de Exportação (RE) averbado. (nRE)",
        - options: "1 a 12 caracteres"},
    - [compartilha_comercio](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Compartilhar as informações da NFS-e gerada a partir desta DPS com a Secretaria de Comércio Exterior. (mdic)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "0 - Não enviar para o MDIC",
            2. "1 - Enviar para o MDIC"]}},
- [pedagio](https://integranotas.com.br/doc/nfse): {
    - [categoria_veiculo](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Categorias de veículos para cobrança. (categVeic)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "00 - Categoria de veículos (tipo não informado na nota de origem)",
            2. "01 - Automóvel, caminhonete e furgão",
            3. "02 - Caminhão leve, ônibus, caminhão trator e furgão",
            4. "03 - Automóvel e caminhonete com semireboque",
            5. "04 - Caminhão, caminhão-trator, caminhão-trator com semi-reboque e ônibus",
            6. "05 - Automóvel e caminhonete com reboque",
            7. "06 - Caminhão com reboque",
            8. "07 - Caminhão trator com semi-reboque",
            9. "08 - Motocicletas, motonetas e bicicletas motorizadas",
            10. "09 - Veículo especial",
            11. "10 - Veículo Isento"]},
    - [numero_eixos](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Número de eixos para fins de cobrança. (nEixos)",
        - options: "1 a 2 digitos"},
    - [tipo_rodagem](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Tipo de rodagem. (rodagem)",
        - [options](https://integranotas.com.br/doc/nfse): [
            1. "1 - Simples",
            2. "2 - Dupla"]},
    - [sentido](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Orientação de passagem do veículo: ângulo em graus a partir do norte geográfico em sentido horário, número inteiro de 0 a 359, onde 0º seria o norte, 90º o leste, 180º o sul, 270º o oeste. Precisão mínima de 10. (sentido)",
        - options: "1 a 3 digitos"},
    - [placa](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Placa do veículo. (placa)",
        - options: "7 caracteres"},
    - [codigo_acesso](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Código de acesso gerado automaticamente pelo sistema emissor da concessionária. (codAcessoPed)",
        - options: "10 caracteres"},
    - [codigo_contrato](https://integranotas.com.br/doc/nfse): {
        - required: true,
        - type: "string",
        - description: "Código de contrato gerado automaticamente pelo sistema nacional no cadastro da concessionária. (codContrato)",
        - options: "4 caracteres"}},
- [informacoes_complementares](https://integranotas.com.br/doc/nfse): {
    - required: false,
    - [type](https://integranotas.com.br/doc/nfse): [
        1. "string",
        2. "null"],
    - description: "Informações complementares. (descricao)",
    - options: "1 à 2000 caracteres"}

}
```