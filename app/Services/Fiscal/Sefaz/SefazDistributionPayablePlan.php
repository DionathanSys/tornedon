<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\Payment\Condition;
use App\Models\SefazDistributionDocument;
use App\Support\Financial\ExplicitPayableInstallments;
use App\Support\Financial\FiscalEntryAmount;
use App\Support\Financial\InstallmentSchedule;
use App\Support\Financial\PaymentTerms;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SefazDistributionPayablePlan
{
    public function source(SefazDistributionDocument $document): array
    {
        $xml = app(SefazDfeStorageService::class)->read($document->full_xml_path);
        if (! is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('O XML completo do DF-e não foi encontrado no storage.');
        }
        $parsed = app(SefazDistributionFiscalDocumentXmlParser::class)->parse($xml);
        $duplicates = data_get($parsed, 'billing.dup') ?? [];
        if ($duplicates && ! array_is_list($duplicates)) {
            $duplicates = [$duplicates];
        }
        $payments = data_get($parsed, 'payment.detPag') ?? [];
        if ($payments && ! array_is_list($payments)) {
            $payments = [$payments];
        }
        $methods = array_unique(array_column($payments, 'tPag'));
        $method = count($methods) === 1 ? match ((string) reset($methods)) {
            '01' => 'dinheiro', '02' => 'cheque', '15' => 'boleto', '16' => 'deposito', '17' => 'pix', '18' => 'transferencia',
            default => 'outros',
        } : 'outros';

        return [
            'fiscal_amount' => FiscalEntryAmount::fromParsedXml($parsed, (float) $document->total_amount),
            'billing_amount' => data_get($parsed, 'billing.fat.vLiq'),
            'payment_method' => $method,
            'installments' => array_map(fn (array $row): array => [
                'due_date' => $row['dVenc'] ?? null,
                'due_amount' => $row['vDup'] ?? null,
                'description' => isset($row['nDup']) ? 'Duplicata '.$row['nDup'] : null,
            ], $duplicates),
        ];
    }

    public function calculate(SefazDistributionDocument $document, array $data): array
    {
        $validated = Validator::make($data, [
            'due_amount' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date'],
            'payment_condition' => ['required', Rule::enum(Condition::class)],
            'payment_condition_details.installments' => ['required_if:payment_condition,personalizado', 'nullable', 'integer', 'min:1', 'max:999'],
            'payment_condition_details.first_due_days' => ['required_if:payment_condition,personalizado', 'nullable', 'integer', 'min:0', 'max:3650'],
            'payment_condition_details.interval_days' => ['required_if:payment_condition,personalizado', 'nullable', 'integer', 'min:1', 'max:365'],
            'installment_due_mode' => ['nullable', Rule::in([...array_keys(Condition::installmentIntervalOptions()), InstallmentSchedule::FIXED_DAY_OF_MONTH, InstallmentSchedule::CUSTOM_INTERVAL_DAYS])],
            'installment_fixed_day' => ['nullable', 'required_if:installment_due_mode,fixed_day_of_month', 'integer', 'min:1', 'max:31'],
        ])->validate();
        $condition = Condition::from($validated['payment_condition']);
        $details = $validated['payment_condition_details'] ?? null;
        $count = PaymentTerms::count($condition, $details);
        $first = $condition === Condition::CUSTOM
            ? PaymentTerms::firstDueDate($condition, Carbon::parse($document->issued_at ?? now()), $details)
            : Carbon::parse($validated['due_date'] ?? $document->issued_at ?? now());
        $config = [
            'mode' => $validated['installment_due_mode'] ?? ($condition->isTerm() ? $condition->value : Condition::DAYS_30->value),
            'fixed_day' => $validated['installment_fixed_day'] ?? null,
            'interval_days' => $condition === Condition::CUSTOM ? PaymentTerms::interval($condition, $details) : 30,
        ];
        if ($condition === Condition::CUSTOM && ! isset($validated['installment_due_mode'])) {
            $config['mode'] = InstallmentSchedule::CUSTOM_INTERVAL_DAYS;
        }
        $total = (int) round((float) $validated['due_amount'] * 100);
        if ($total < $count) {
            throw ValidationException::withMessages(['due_amount' => 'O valor não permite gerar parcelas de pelo menos R$ 0,01.']);
        }
        $base = intdiv($total, $count);

        return array_map(fn (int $index): array => [
            'due_date' => InstallmentSchedule::dueDate($first, $index, $config)->toDateString(),
            'due_amount' => ($base + ($index === $count - 1 ? $total - $base * $count : 0)) / 100,
        ], range(0, $count - 1));
    }

    public function resolve(SefazDistributionDocument $document, array $data): array
    {
        Validator::make($data, ['installments_source' => ['nullable', Rule::in(['xml', 'condition', 'manual'])]])->validate();
        $source = $this->source($document);
        $mode = $data['installments_source'] ?? 'condition';
        $rows = $data['installments'] ?? match ($mode) {
            'xml' => $source['installments'],
            'manual' => [],
            default => $this->calculate($document, ['due_amount' => $source['fiscal_amount'], ...$data]),
        };
        $amount = isset($data['due_amount']) ? (float) $data['due_amount'] : $source['fiscal_amount'];
        Validator::make(['due_amount' => $amount, 'amount_difference_reason' => $data['amount_difference_reason'] ?? null], [
            'due_amount' => ['required', 'numeric', 'gt:0'],
            'amount_difference_reason' => [Rule::requiredIf((int) round($amount * 100) !== (int) round($source['fiscal_amount'] * 100)), 'nullable', 'string', 'max:1000'],
        ], ['amount_difference_reason.required' => 'Justifique a diferença entre o valor da conta e o total fiscal.'])->validate();
        $rows = ExplicitPayableInstallments::build(['due_amount' => $amount, 'installments' => $rows]);

        return ['amount' => $amount, 'installments' => $rows, 'source' => $source];
    }
}
