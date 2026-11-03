<?php

namespace App\Services\Financial\BankStatement;

use App\Services\Cnpj\CnpjDocument;
use Illuminate\Support\Str;

class BankStatementMatchText
{
    private const STOP_WORDS = [
        'pix', 'ted', 'doc', 'debito', 'credito', 'pagamento', 'pag', 'pgto', 'pagto', 'pgt',
        'recebimento', 'receb', 'transferencia', 'transf', 'enviado', 'enviada', 'recebido', 'recebida',
        'banco', 'conta', 'agencia', 'cpf', 'cnpj', 'ltda', 'eireli', 'sa', 'mei', 'para', 'por',
        'das', 'dos', 'com', 'uma', 'nao', 'informado', 'informada', 'sistema', 'lancamento',
        'companhia', 'comercial', 'servicos', 'industria', 'distribuidora', 'empresa', 'cliente', 'fornecedor', 'ofx',
    ];

    private const ALIASES = ['cia' => 'companhia', 'coml' => 'comercial', 'serv' => 'servicos', 'ind' => 'industria', 'dist' => 'distribuidora'];

    public function tokens(?string $text): array
    {
        $normalized = strtolower(Str::ascii($text ?? ''));
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(fn (string $token) => self::ALIASES[$token] ?? $token, $tokens);

        return array_values(array_unique(array_filter($tokens, fn (string $token) => strlen($token) >= 3
            && ! ctype_digit($token) && ! in_array($token, self::STOP_WORDS, true))));
    }

    public function documents(string $text): array
    {
        preg_match_all('/(?<![a-z0-9])(?:\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|\d{3}\.?\d{3}\.?\d{3}-?\d{2})(?![a-z0-9])/i', $text, $matches);
        $documents = array_map(fn (string $value) => preg_replace('/\D/', '', $value), $matches[0]);

        return array_values(array_unique(array_filter($documents, fn (string $document) => strlen($document) === 14
            ? app(CnpjDocument::class)->isValid($document)
            : $this->validCpf($document))));
    }

    public function reference(?string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/[^a-z0-9]/i', '', Str::ascii($value ?? '')) ?? '');
        if (ctype_digit($normalized)) {
            $normalized = ltrim($normalized, '0');
        }

        return strlen($normalized) >= 4 ? $normalized : null;
    }

    public function labeledReferences(string $text): array
    {
        $normalized = Str::ascii($text);
        preg_match_all('/\b(?:nf(?:-?e)?|nota(?:\s+fiscal)?|documento|ref(?:erencia)?|boleto)\s*[:#.-]?\s*([a-z0-9][a-z0-9.\/-]{3,})\b/i', $normalized, $matches);

        return array_values(array_unique(array_filter(array_map($this->reference(...), $matches[1]))));
    }

    private function validCpf(string $document): bool
    {
        if (strlen($document) !== 11 || preg_match('/^(\d)\1{10}$/', $document)) {
            return false;
        }

        for ($length = 9; $length < 11; $length++) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $document[$index] * ($length + 1 - $index);
            }
            $digit = (10 * $sum) % 11;
            if (($digit === 10 ? 0 : $digit) !== (int) $document[$length]) {
                return false;
            }
        }

        return true;
    }
}
