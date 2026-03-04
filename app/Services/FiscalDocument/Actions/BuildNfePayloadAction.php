<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload completo para envio à API IntegraNotas.
 *
 * Lê dados do FiscalDocument (com relacionamentos carregados):
 *   - company         → dados do emitente
 *   - customer        → destinatário (com address e contacts)
 *   - items.product   → itens da NF-e
 *   - freight_data    → frete (já armazenado como array)
 *   - payment_data    → formas de pagamento
 *   - tax_data        → totais tributários
 *
 * Os dados tributários de cada item (ICMS, PIS, COFINS) vêm de
 * FiscalDocumentItem::tax_data — preenchidos no momento da criação do documento.
 */
class BuildNfePayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            $fiscalDocument->loadMissing([
                'company',
                'customer.address',
                'items.product',
            ]);

            $company  = $fiscalDocument->company;
            $customer = $fiscalDocument->customer;
            $address  = $customer->address->first();

            $issuedAt    = $fiscalDocument->issued_at->format('Y-m-d') . 'T00:00:00-03:00';
            $movementAt  = $fiscalDocument->movement_at->format('Y-m-d') . 'T00:00:00-03:00';

            // ------------------------------------------------------------------
            // Monta destinatário
            // ------------------------------------------------------------------
            $destinatario = [
                'nome'                          => $customer->name,
                'indicador_inscricao_estadual'  => $customer->state_tax_indicator?->value ?? '9',
                'inscricao_estadual'            => $customer->state_tax_id,
                'inscricao_municipal'           => $customer->municipal_tax_id,
            ];

            // CPF ou CNPJ
            $docNumber = preg_replace('/\D/', '', $customer->document_number ?? '');
            if (strlen($docNumber) === 14) {
                $destinatario['cnpj'] = $docNumber;
            } else {
                $destinatario['cpf'] = $docNumber;
            }

            if ($address) {
                $destinatario['endereco'] = [
                    'logradouro'       => $address->street ?? '',
                    'numero'           => $address->number ?? 'S/N',
                    'complemento'      => $address->complement ?? '',
                    'bairro'           => $address->neighborhood ?? '',
                    'codigo_municipio' => $address->city_code ?? '',
                    'nome_municipio'   => $address->city ?? '',
                    'uf'               => $address->state ?? '',
                    'cep'              => preg_replace('/\D/', '', $address->zip_code ?? ''),
                    'codigo_pais'      => '1058',
                    'nome_pais'        => 'BRASIL',
                    'telefone'         => preg_replace('/\D/', '', $address->phone ?? ''),
                ];
            }

            // ------------------------------------------------------------------
            // Monta itens
            // ------------------------------------------------------------------
            $itens = [];
            foreach ($fiscalDocument->items as $index => $item) {
                $taxData   = $item->tax_data ?? [];
                $itemPayload = [
                    'numero_item'              => $index + 1,
                    'codigo_produto'           => (string) $item->product_id,
                    'descricao'                => $item->product?->name ?? '',
                    'codigo_ncm'               => $item->ncm_code,
                    'cfop'                     => $item->cfop_code,
                    'unidade_comercial'        => $item->unit_of_measure,
                    'quantidade_comercial'     => (float) $item->quantity,
                    'valor_unitario_comercial' => number_format((float) $item->unit_price, 2, '.', ''),
                    'valor_bruto'              => number_format((float) $item->total_price, 2, '.', ''),
                    'unidade_tributavel'       => $item->unit_of_measure,
                    'quantidade_tributavel'    => number_format((float) $item->quantity, 4, '.', ''),
                    'valor_unitario_tributavel'=> number_format((float) $item->unit_price, 2, '.', ''),
                    'origem'                   => $item->origin_code,
                    'inclui_no_total'          => $item->included_in_total ? '1' : '0',
                    'imposto'                  => $taxData['imposto'] ?? [],
                    'valor_desconto'           => 0,
                    'valor_frete'              => 0,
                    'valor_seguro'             => 0,
                    'valor_outras_despesas'    => 0,
                ];

                if (! empty($taxData['informacoes_adicionais'])) {
                    $itemPayload['informacoes_adicionais'] = $taxData['informacoes_adicionais'];
                }

                $itens[] = $itemPayload;
            }

            // ------------------------------------------------------------------
            // Monta payload raiz
            // ------------------------------------------------------------------
            $payload = [
                'natureza_operacao'      => $fiscalDocument->operation_nature ?? 'VENDA',
                'serie'                  => $fiscalDocument->document_series ?? '1',
                'numero'                 => (int) $fiscalDocument->document_number,
                'data_emissao'           => $issuedAt,
                'data_entrada_saida'     => $movementAt,
                'tipo_operacao'          => $fiscalDocument->operation_type ?? 1,
                'finalidade_emissao'     => $fiscalDocument->issue_purpose ?? '1',
                'consumidor_final'       => $fiscalDocument->is_final_consumer ? '1' : '0',
                'presenca_comprador'     => $fiscalDocument->buyer_presence_indicator ? '1' : '9',
                'destinatario'           => $destinatario,
                'itens'                  => $itens,
                'frete'                  => $fiscalDocument->freight_data ?? ['modalidade_frete' => '9'],
                'pagamento'              => $fiscalDocument->payment_data ?? ['formas_pagamento' => [['meio_pagamento' => '99', 'valor' => '0.00']]],
            ];

            // Totais e cobrança (tax_data)
            if (! empty($fiscalDocument->tax_data)) {
                $payload['totais'] = $fiscalDocument->tax_data['totais'] ?? [];
                if (! empty($fiscalDocument->tax_data['cobranca'])) {
                    $payload['cobranca'] = $fiscalDocument->tax_data['cobranca'];
                }
            }

            if ($fiscalDocument->additional_tax_information) {
                $payload['informacoes_adicionais_fisco'] = $fiscalDocument->additional_tax_information;
            }

            if ($fiscalDocument->additional_taxpayer_information) {
                $payload['informacoes_adicionais_contribuinte'] = $fiscalDocument->additional_taxpayer_information;
            }

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $this->setError('Erro ao montar payload da NF-e: ' . $e->getMessage());

            Log::error('BuildNfePayloadAction: erro', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
