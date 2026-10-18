<?php

// Local, reviewed contract snapshot of https://app.integrabancos.com.br/api/docs (2026-10-16).
// Optional charge groups are validated only when supplied. No runtime dependency on the docs site.
return [
    '461' => ['name' => 'Asaas', 'boleto' => [], 'pix' => ['due_date' => true], 'update' => true, 'cancel' => true],
    '1' => ['name' => 'Banco do Brasil', 'boleto' => ['conta.convenio', 'conta.carteira', 'conta.variacao_carteira', 'pagador.endereco.logradouro', 'pagador.endereco.numero', 'pagador.endereco.bairro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'update' => true, 'cancel' => true],
    '403' => ['name' => 'Cora', 'boleto' => ['pagador.email'], 'pix' => ['due_date' => true, 'email' => true], 'update' => false, 'cancel' => true],
    '364' => ['name' => 'Efí', 'boleto' => ['pagador.endereco.logradouro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'pix' => ['key' => true], 'update' => true, 'cancel' => true],
    '77' => ['name' => 'Inter', 'boleto' => ['pagador.endereco.logradouro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'pix' => ['due_date' => true, 'key' => true], 'update' => true, 'cancel' => true],
    '341' => ['name' => 'Itaú', 'boleto' => ['conta.numero_agencia', 'conta.numero_conta_corrente', 'conta.carteira', 'pagador.endereco.logradouro', 'pagador.endereco.bairro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'pix' => ['key' => true], 'update' => true, 'cancel' => true],
    '33' => ['name' => 'Santander', 'boleto' => ['conta.convenio', 'conta.especie', 'pagador.endereco.logradouro', 'pagador.endereco.bairro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'pix' => ['due_date' => true, 'key' => true, 'key_type' => true], 'update' => false, 'cancel' => false],
    '756' => ['name' => 'Sicoob', 'boleto' => ['conta.numero_cliente', 'conta.numero_conta_corrente', 'conta.codigo_modalidade', 'pagador.endereco.logradouro', 'pagador.endereco.bairro', 'pagador.endereco.nome_municipio', 'pagador.endereco.uf', 'pagador.endereco.cep'], 'pix' => ['key' => true], 'update' => true, 'cancel' => true],
    '748' => ['name' => 'Sicredi', 'boleto' => ['conta.numero_agencia', 'conta.codigo_beneficiario', 'conta.numero_posto'], 'pix' => ['key' => true], 'update' => true, 'cancel' => true],
];
