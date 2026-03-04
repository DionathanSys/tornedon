# Objetivo
Controle emissão de documentos de venda de mercadoria/serviço e controle de documentos fiscais recebidos, compras de produtos;
Realizar a emissão de documentos fiscais referentes à venda de peças (Requisição) e venda de serviço (Ordem de serviço). Unir com controle de fatura.

# Requisitos
- Precisa ser algo rastreável, e reversível.
- Assíncrono, pois a comunicação com a API para emissão das notas trabalha dessa forma;
- Necessita de webhook para receber o retorno da API.


# API
## Documentação
https://integranotas.com.br/doc/nfe **NFE** Dentro desta existe a parte de *envia, busca, impressão do preview, impressão do DANFE*. Implemente todos.
