<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Financial\BankStatement\Parsers\GenericOfxStatementParser;

$path = '\\\\Mfs\\usuarios\\dionathan.silva\\Downloads\\extrato sicredi 01 a 04-03-2026.ofx';
$contents = file_get_contents($path);

if ($contents === false) {
    fwrite(STDERR, "Nao foi possivel ler o arquivo.\n");
    exit(1);
}

$parser = new GenericOfxStatementParser();
$parsed = $parser->parse($contents);

echo json_encode([
    'header' => $parsed['header']->toArray(),
    'transactions' => count($parsed['transactions']),
    'first_transaction' => $parsed['transactions'][0]->toArray() ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
