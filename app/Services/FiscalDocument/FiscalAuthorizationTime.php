<?php

namespace App\Services\FiscalDocument;

use App\Models\FiscalDocument;
use Carbon\Carbon;
use Throwable;

class FiscalAuthorizationTime
{
    /** Never invent an authorization time from the consultation/webhook arrival time. */
    public function resolve(FiscalDocument $document, array|object $response): ?Carbon
    {
        $times = $document->authorized_at ? [$document->authorized_at->copy()] : [];
        foreach (['data_hora_autorizacao', 'data_autorizacao', 'autorizado_em'] as $key) {
            $value = data_get($response, $key);
            if (is_string($value) && trim($value) !== '') {
                try {
                    $times[] = Carbon::parse($value);
                } catch (Throwable) {
                    // Malformed external dates do not replace a known timestamp.
                }
            }
        }
        $xmlValue = data_get($response, 'xml') ?? data_get($document->nfe_payload, 'xml_base64');
        if (is_string($xmlValue) && $xmlValue !== '') {
            $xmlText = str_starts_with(ltrim($xmlValue), '<') ? $xmlValue : base64_decode($xmlValue, true);
            if (is_string($xmlText) && ! preg_match('/<!DOCTYPE|<!ENTITY/i', $xmlText)) {
                $previous = libxml_use_internal_errors(true);
                try {
                    $xml = simplexml_load_string($xmlText, \SimpleXMLElement::class, LIBXML_NONET);
                    $values = $xml ? $xml->xpath('//*[local-name()="protNFe"]/*[local-name()="infProt"]/*[local-name()="dhRecbto"]') : [];
                    if ($values && trim((string) $values[0]) !== '') {
                        $times[] = Carbon::parse((string) $values[0]);
                    }
                } catch (Throwable) {
                    // Missing or unreadable authorization evidence requires fiscal treatment.
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }
        }
        usort($times, fn (Carbon $left, Carbon $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        return $times[0] ?? null;
    }
}
