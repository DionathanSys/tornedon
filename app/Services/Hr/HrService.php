<?php

namespace App\Services\Hr;

use App\Enum\AccountPayable\Status;
use App\Enum\Hr\PaymentNature;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Models\HrReceipt;
use App\Models\HrReceiptSignature;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\FinancialClassificationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrService
{
    public const CLASSIFICATIONS = ['financial_category_id' => 'financial_categories', 'chart_account_id' => 'chart_accounts', 'cost_center_id' => 'cost_centers', 'result_center_id' => 'result_centers'];

    public function __construct(private HrAccess $access) {}

    public function savePerson(User $actor, Company $company, array $data, ?HrPerson $person = null): HrPerson
    {
        return DB::transaction(function () use ($actor, $company, $data, $person) {
            if ($person) {
                $person = HrPerson::withoutGlobalScopes()->lockForUpdate()->findOrFail($person->id);
            }
            $this->access->authorize($actor, $company, 'hr_people.'.($person ? 'update' : 'create'), $person);
            $validated = Validator::make($data, [
                'company_partner_id' => ['required', Rule::exists('company_partner', 'id')->where('company_id', $company->id)->where('is_active', true), Rule::unique('hr_people')->where('company_id', $company->id)->ignore($person?->id)],
                'roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in(['employee', 'shareholder'])],
                'position' => ['nullable', 'string', 'max:255'], 'started_at' => ['nullable', 'date'],
                'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
                'base_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
                'is_active' => ['required', 'boolean'], ...$this->classificationRules($company),
            ])->validate();
            $validated['chart_account_id'] = app(FinancialClassificationService::class)->resolveChartAccountIdFromCategoryId($validated['financial_category_id'] ?? null, $company->id, 'payable');
            if ($person && $person->entries()->withoutGlobalScopes()->exists() && (int) $person->company_partner_id !== (int) $validated['company_partner_id']) {
                $this->fail('company_partner_id', 'Não é possível trocar a pessoa de um vínculo com lançamentos.');
            }
            $link = CompanyPartner::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($validated['company_partner_id']);
            $partner = $link->partner;
            if (! $partner || ! $partner->is_active) {
                $this->fail('company_partner_id', 'A pessoa está inativa.');
            }
            $before = $person?->attributesToArray();
            $person ??= new HrPerson(['company_id' => $company->id, 'created_by' => $actor->id]);
            $person->fill([...$validated, 'base_amount' => $validated['base_amount'] ?? 0])->save();
            $this->audit($actor, $person, 'hr.person.saved', $before);

            return $person;
        }, 3);
    }

    public function saveEntry(User $actor, Company $company, array $data, ?HrEntry $entry = null): HrEntry
    {
        return DB::transaction(function () use ($actor, $company, $data, $entry) {
            if ($entry) {
                $entry = HrEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            }
            $this->access->authorize($actor, $company, 'hr_entries.'.($entry ? 'update' : 'create'), $entry);
            if ($entry && $entry->status !== 'draft') {
                $this->fail('status', 'Somente rascunhos podem ser alterados.');
            }
            $validated = Validator::make($data, [
                'hr_person_id' => ['required', Rule::exists('hr_people', 'id')->where('company_id', $company->id)->where('is_active', true)],
                'competence_date' => ['required', 'date'], 'due_date' => ['required', 'date'],
                'nature' => ['required', Rule::enum(PaymentNature::class)],
                'items' => ['required', 'array', 'min:1', 'max:200'],
                'items.*.description' => ['required', 'string', 'max:255'],
                'items.*.type' => ['required', Rule::in(['earning', 'deduction'])],
                'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
                'advance_deductions' => ['nullable', 'array', 'max:100'],
                'advance_deductions.*.entry_id' => ['required', 'integer', 'distinct'],
                'advance_deductions.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
                'notes' => ['nullable', 'string', 'max:10000'], ...$this->classificationRules($company),
            ])->validate();
            $person = HrPerson::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($validated['hr_person_id']);
            $this->access->authorize($actor, $company, 'hr_people.view', $person);
            $this->validateNature($person, $validated['nature']);
            $items = array_map(fn ($item) => Arr::only($item, ['description', 'type', 'amount']), array_values($validated['items']));
            $advances = array_map(fn ($item) => Arr::only($item, ['entry_id', 'amount']), array_values($validated['advance_deductions'] ?? []));
            if ($advances && ! in_array($validated['nature'], ['salary', 'pro_labore'], true)) {
                $this->fail('advance_deductions', 'Adiantamentos só podem ser abatidos em salário ou pró-labore.');
            }
            $gross = $deductions = 0;
            foreach ($items as $item) {
                if ($item['type'] === 'earning') {
                    $gross += self::cents($item['amount']);
                } else {
                    $deductions += self::cents($item['amount']);
                }
            }
            foreach ($advances as $advance) {
                $this->validateAdvance($company, $person, $advance, $entry?->id);
                $deductions += self::cents($advance['amount']);
            }
            if ($gross <= 0 || $gross > self::cents('999999999999.99') || $deductions > $gross) {
                $this->fail('items', 'Informe proventos positivos e descontos que não ultrapassem o total bruto.');
            }
            $before = $entry?->attributesToArray();
            $entry ??= new HrEntry(['company_id' => $company->id, 'created_by' => $actor->id, 'status' => 'draft']);
            $entry->fill([
                ...$validated, 'items' => $items, 'advance_deductions' => $advances,
                'competence_date' => Carbon::parse($validated['competence_date'])->startOfMonth()->toDateString(),
                'gross_amount' => $gross / 100, 'deduction_amount' => $deductions / 100, 'net_amount' => ($gross - $deductions) / 100,
            ])->save();
            $this->audit($actor, $entry, 'hr.entry.saved', $before);

            return $entry;
        }, 3);
    }

    public function confirm(User $actor, Company $company, HrEntry $entry): HrEntry
    {
        return DB::transaction(function () use ($actor, $company, $entry) {
            $entry = HrEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            $this->access->authorize($actor, $company, 'hr_entries.approve', $entry);
            if ($entry->status === 'confirmed') {
                return $entry;
            }
            if ($entry->status !== 'draft') {
                $this->fail('status', 'Lançamento cancelado.');
            }
            $person = HrPerson::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($entry->hr_person_id);
            if (! $person->is_active) {
                $this->fail('hr_person_id', 'Vínculo de RH inativo.');
            }
            $this->validateNature($person, $entry->nature);
            $link = CompanyPartner::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($person->company_partner_id);
            if (! $link->is_active || ! $link->partner?->is_active) {
                $this->fail('hr_person_id', 'Pessoa ou vínculo com a empresa inativo.');
            }
            foreach ($entry->advance_deductions ?? [] as $advance) {
                $this->validateAdvance($company, $person, $advance, $entry->id);
                DB::table('hr_advance_allocations')->insert(['hr_entry_id' => $entry->id, 'advance_entry_id' => $advance['entry_id'], 'amount' => $advance['amount'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $classification = [];
            foreach (self::CLASSIFICATIONS as $field => $table) {
                $classification[$field] = $entry->$field ?? $person->$field;
            }
            Validator::make($classification, $this->classificationRules($company))->validate();
            $classification['chart_account_id'] = app(FinancialClassificationService::class)->resolveChartAccountIdFromCategoryId($classification['financial_category_id'], $company->id, 'payable');
            $payable = null;
            if (self::cents($entry->net_amount) > 0) {
                $this->authorizeFinancial($actor, $company, 'create');
                $finance = app(AccountPayableService::class);
                $payable = $finance->create([
                    ...$classification, 'company_id' => $company->id, 'supplier_id' => $link->partner_id,
                    'description' => 'RH #'.$entry->id.' — '.PaymentNature::options()[$entry->nature].' '.$entry->competence_date->format('m/Y'),
                    'document_number' => 'RH-'.$entry->id, 'type' => 'hr', 'due_date' => $entry->due_date->toDateString(),
                    'competence_date' => $entry->competence_date->toDateString(), 'due_amount' => $entry->net_amount, 'is_effective' => true,
                ], $actor->id);
                if (! $payable || $finance->hasError()) {
                    throw ValidationException::withMessages($finance->getErrors() ?: ['account_payable' => $finance->getMessage()]);
                }
            }
            $entry->fill([
                ...$classification, 'status' => 'confirmed', 'confirmed_at' => now(), 'account_payable_id' => $payable?->id,
                'snapshot' => ['company_name' => $company->name, 'company_document' => $company->document_number,
                    'company_city' => $company->address['city'] ?? null, 'company_state' => $company->address['state'] ?? null,
                    'person_name' => $link->partner->name, 'person_document' => $link->partner->document_number,
                    'position' => $person->position],
            ])->save();
            $this->audit($actor, $entry, 'hr.entry.confirmed');

            return $entry;
        }, 3);
    }

    public function cancel(User $actor, Company $company, HrEntry $entry): void
    {
        DB::transaction(function () use ($actor, $company, $entry) {
            $entry = HrEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            $this->access->authorize($actor, $company, 'hr_entries.cancel', $entry);
            HrPerson::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->hr_person_id);
            if ($entry->status === 'cancelled') {
                return;
            }
            if (DB::table('hr_advance_allocations')->where('advance_entry_id', $entry->id)->exists()) {
                $this->fail('advance_deductions', 'Cancele os lançamentos que utilizaram este adiantamento antes de cancelá-lo.');
            }
            if ($entry->account_payable_id) {
                $payable = AccountPayable::withoutGlobalScopes()->findOrFail($entry->account_payable_id);
                $this->authorizeFinancial($actor, $company, 'cancel', $payable);
                $finance = app(AccountPayableService::class);
                if (! $finance->cancel($payable, 'Cancelamento do lançamento de RH #'.$entry->id, $actor->id)) {
                    throw ValidationException::withMessages($finance->getErrors() ?: ['account_payable' => $finance->getMessage()]);
                }
            }
            DB::table('hr_advance_allocations')->where('hr_entry_id', $entry->id)->delete();
            $entry->update(['status' => 'cancelled']);
            $this->audit($actor, $entry, 'hr.entry.cancelled');
        }, 3);
    }

    public function issueReceipt(User $actor, Company $company, HrEntry $entry, int $paymentId): HrReceipt
    {
        return DB::transaction(function () use ($actor, $company, $entry, $paymentId) {
            $entry = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($entry->id);
            $this->access->authorize($actor, $company, 'hr_entries.view', $entry);
            $this->access->authorize($actor, $company, 'hr_receipts.issue');
            $payment = $this->payments($entry)->whereKey($paymentId)->lockForUpdate()->first();
            if ($entry->status !== 'confirmed' || ! $payment || $payment->amount <= 0 || $this->payableCancelled($entry)) {
                $this->fail('payment_id', 'Recibos exigem uma baixa válida deste lançamento.');
            }
            $fingerprint = self::fingerprint($payment);
            $receipt = HrReceipt::withoutGlobalScopes()->where('payment_id', $paymentId)->where('fingerprint', $fingerprint)->whereNull('voided_at')->first();
            if ($receipt) {
                return $receipt;
            }
            $paid = $this->paidAmount($entry);
            $receipt = HrReceipt::create([
                'company_id' => $company->id, 'hr_entry_id' => $entry->id, 'payment_id' => $payment->id,
                'fingerprint' => $fingerprint, 'created_by' => $actor->id,
                'snapshot' => [...$this->documentData($entry), 'payment_date' => $payment->payment_date->format('d/m/Y'),
                    'payment_amount' => $payment->amount, 'interest_amount' => $payment->interest_amount,
                    'fine_amount' => $payment->fine_amount, 'payment_discount' => $payment->discount_amount,
                    'paid_total' => $paid, 'balance_amount' => max(0, round((float) $entry->net_amount + $this->paymentAdjustments($entry) - $paid, 2))],
            ]);
            $this->audit($actor, $receipt, 'hr.receipt.issued');

            return $receipt;
        }, 3);
    }

    public function registerPayment(User $actor, Company $company, HrEntry $entry, array $data): AccountPayableInstallmentPayment
    {
        return DB::transaction(function () use ($actor, $company, $entry, $data) {
            $entry = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($entry->id);
            $this->access->authorize($actor, $company, 'hr_entries.view', $entry);
            if ($entry->status !== 'confirmed' || ! $entry->account_payable_id) {
                $this->fail('payment', 'Confirme um lançamento com valor líquido a pagar antes de registrar a baixa.');
            }
            $payable = AccountPayable::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($entry->account_payable_id);
            $this->authorizeFinancial($actor, $company, 'settle', $payable);
            $validated = Validator::make($data, [
                'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                'interest_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'fine_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $company->id)->where('is_active', true)],
                'description' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:10000'],
            ])->validate();
            $installment = $payable->installments()->firstOrFail();
            $finance = app(AccountPayableService::class);
            // The HR competence remains the competence of the obligation, even when paid in another month.
            $payment = $finance->registerInstallmentPayment($installment, (float) $validated['amount'], $validated['payment_date'], [
                ...Arr::except($validated, ['amount', 'payment_date']), 'user_id' => $actor->id,
            ]);
            if (! $payment || $finance->hasError()) {
                throw ValidationException::withMessages($finance->getErrors() ?: ['payment' => $finance->getMessage()]);
            }

            return $payment;
        }, 3);
    }

    public function reversePayment(User $actor, Company $company, HrEntry $entry, int $paymentId): void
    {
        DB::transaction(function () use ($actor, $company, $entry, $paymentId): void {
            $entry = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($entry->id);
            $this->access->authorize($actor, $company, 'hr_entries.view', $entry);
            $payable = AccountPayable::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($entry->account_payable_id);
            $this->authorizeFinancial($actor, $company, 'reverse', $payable);
            $payment = $this->payments($entry)->whereKey($paymentId)->firstOrFail();
            $finance = app(AccountPayableService::class);
            if (! $finance->deleteInstallmentPayment($payment)) {
                throw ValidationException::withMessages($finance->getErrors() ?: ['payment' => $finance->getMessage()]);
            }
        }, 3);
    }

    public function signReceipt(User $actor, Company $company, HrReceipt $receipt, array $data): HrReceiptSignature
    {
        return DB::transaction(function () use ($actor, $company, $receipt, $data) {
            $receipt = HrReceipt::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($receipt->id);
            $entry = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($receipt->hr_entry_id);
            $this->access->authorize($actor, $company, 'hr_entries.view', $entry);
            $this->access->authorize($actor, $company, 'hr_receipts.view', $receipt);
            $this->access->authorize($actor, $company, 'hr_receipts.sign', $receipt);
            $this->payments($entry)->whereKey($receipt->payment_id)->lockForUpdate()->first();
            $receipt = HrReceipt::withoutGlobalScopes()->lockForUpdate()->findOrFail($receipt->id);
            if (! $this->receiptIsValid($receipt)) {
                $this->fail('signature', 'Não é possível assinar um recibo invalidado.');
            }
            if (HrReceiptSignature::withoutGlobalScopes()->where('hr_receipt_id', $receipt->id)->exists()) {
                $this->fail('signature', 'Este recibo já possui assinatura.');
            }
            $validated = Validator::make($data, [
                'signed_by_name' => ['required', 'string', 'max:255'],
                'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2500000'],
            ])->validate();
            $binary = base64_decode(substr($validated['signature'], strlen('data:image/png;base64,')), true);
            $image = $binary !== false ? @getimagesizefromstring($binary) : false;
            if (! $image || $image[2] !== IMAGETYPE_PNG || $image[0] > 4096 || $image[1] > 4096) {
                $this->fail('signature', 'Envie uma assinatura PNG válida, com dimensões de até 4096 pixels.');
            }
            $signature = HrReceiptSignature::create([
                'company_id' => $company->id, 'hr_receipt_id' => $receipt->id,
                'signed_by_name' => trim($validated['signed_by_name']), 'signature' => $validated['signature'],
                'signed_at' => now(), 'recorded_by' => $actor->id,
                'document_hash' => hash('sha256', json_encode(['id' => $receipt->id, 'snapshot' => $receipt->snapshot, 'fingerprint' => $receipt->fingerprint], JSON_THROW_ON_ERROR)),
                'metadata' => ['ip' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000), 'channel' => 'hr_panel'],
            ]);
            app(AuditRecorder::class)->recordModelEvent($signature, 'hr.receipt.signed', 'Recibo de RH #'.$receipt->id.' assinado em tela', null,
                ['signed_by_name' => $signature->signed_by_name, 'signed_at' => $signature->signed_at->toIso8601String(), 'document_hash' => $signature->document_hash, 'signature_hash' => hash('sha256', $signature->signature)], $actor->id);

            return $signature;
        }, 3);
    }

    public function canPerformFinancialAction(User $actor, Company $company, HrEntry $entry, string $action): bool
    {
        if (! in_array($action, ['settle', 'reverse'], true) || ! $this->access->allows($actor, $company, 'hr_entries.view', $entry) || ! $entry->account_payable_id || $entry->status !== 'confirmed') {
            return false;
        }
        $payable = AccountPayable::withoutGlobalScopes()->where('company_id', $company->id)->find($entry->account_payable_id);
        if (! $payable || $payable->status === Status::CANCELLED || ($action === 'settle' && ! $payable->is_effective)) {
            return false;
        }
        try {
            $this->authorizeFinancial($actor, $company, $action, $payable);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function receiptIsValid(HrReceipt $receipt): bool
    {
        $entry = HrEntry::withoutGlobalScopes()->find($receipt->hr_entry_id);
        $payment = $entry ? $this->payments($entry)->whereKey($receipt->payment_id)->first() : null;

        return ! $receipt->voided_at && $entry && $entry->status === 'confirmed' && ! $this->payableCancelled($entry)
            && $payment && hash_equals($receipt->fingerprint, self::fingerprint($payment));
    }

    public function documentData(HrEntry $entry): array
    {
        $identity = $entry->snapshot;
        if (! $identity) {
            $company = Company::findOrFail($entry->company_id);
            $person = HrPerson::withoutGlobalScopes()->findOrFail($entry->hr_person_id);
            $partner = CompanyPartner::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($person->company_partner_id)->partner;
            $identity = ['company_name' => $company->name, 'company_document' => $company->document_number, 'company_city' => $company->address['city'] ?? null, 'company_state' => $company->address['state'] ?? null, 'person_name' => $partner->name, 'person_document' => $partner->document_number, 'position' => $person->position];
        }

        return [...$identity, 'entry_id' => $entry->id, 'nature' => PaymentNature::options()[$entry->nature],
            'competence' => $entry->competence_date->format('m/Y'), 'due_date' => $entry->due_date->format('d/m/Y'), 'items' => $entry->items,
            'advances' => $entry->advance_deductions ?? [], 'gross_amount' => $entry->gross_amount,
            'deduction_amount' => $entry->deduction_amount, 'net_amount' => $entry->net_amount, 'notes' => $entry->notes];
    }

    public function payments(HrEntry $entry)
    {
        return AccountPayableInstallmentPayment::withoutGlobalScopes()->where('company_id', $entry->company_id)
            ->whereIn('account_payable_installment_id', DB::table('account_payable_installments')->where('company_id', $entry->company_id)->where('account_payable_id', $entry->account_payable_id)->select('id'));
    }

    public function paidAmount(HrEntry $entry): float
    {
        return round((float) $this->payments($entry)->sum('amount'), 2);
    }

    public function advancePaidAmount(HrEntry $entry): float
    {
        $principal = $this->payments($entry)->get()->sum(fn ($payment) => max(0, self::cents($payment->amount) - self::cents($payment->interest_amount) - self::cents($payment->fine_amount)));

        return min(self::cents($entry->net_amount), $principal) / 100;
    }

    public static function fingerprint(AccountPayableInstallmentPayment $payment): string
    {
        return hash('sha256', json_encode(Arr::only($payment->getRawOriginal(), ['id', 'company_id', 'account_payable_installment_id', 'payment_date', 'amount', 'interest_amount', 'fine_amount', 'discount_amount', 'financial_account_id', 'bank_account_id', 'updated_at']), JSON_THROW_ON_ERROR));
    }

    public static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function validateAdvance(Company $company, HrPerson $person, array $deduction, ?int $entryId): void
    {
        $advance = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('hr_person_id', $person->id)->where('nature', 'advance')->where('status', 'confirmed')->find($deduction['entry_id']);
        if (! $advance || $advance->id === $entryId || $this->payableCancelled($advance)) {
            $this->fail('advance_deductions', 'Adiantamento inválido para esta pessoa/empresa.');
        }
        $used = DB::table('hr_advance_allocations')->where('advance_entry_id', $advance->id)->when($entryId, fn ($q) => $q->where('hr_entry_id', '!=', $entryId))->sum('amount');
        $available = self::cents($this->advancePaidAmount($advance)) - self::cents($used);
        if (self::cents($deduction['amount']) > $available) {
            $this->fail('advance_deductions', 'O abatimento ultrapassa o saldo pago e disponível do adiantamento #'.$advance->id.'.');
        }
    }

    private function validateNature(HrPerson $person, string $nature): void
    {
        if (($nature === 'salary' && ! in_array('employee', $person->roles, true)) || (in_array($nature, ['pro_labore', 'profit'], true) && ! in_array('shareholder', $person->roles, true))) {
            $this->fail('nature', 'A natureza do pagamento não corresponde ao vínculo da pessoa.');
        }
    }

    private function classificationRules(Company $company): array
    {
        $rules = [];
        foreach (self::CLASSIFICATIONS as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')->where('company_id', $company->id)];
        }

        return $rules;
    }

    private function authorizeFinancial(User $actor, Company $company, string $action, ?AccountPayable $payable = null): void
    {
        $authorization = app(CompanyAuthorization::class);
        $allowed = $authorization->isManaged($actor, $company)
            ? ($payable ? $authorization->allowsRecord($actor, $company, 'account_payables.'.$action, $payable) : $authorization->allows($actor, $company, 'account_payables.'.$action))
            : app(UserAccessService::class)->allows($actor, $company, 'account_payables.'.$action);
        throw_unless($allowed, AuthorizationException::class);
    }

    private function payableCancelled(HrEntry $entry): bool
    {
        return $entry->account_payable_id && DB::table('account_payables')->where('id', $entry->account_payable_id)->where('status', 'cancelled')->exists();
    }

    private function paymentAdjustments(HrEntry $entry): float
    {
        return (float) $this->payments($entry)->get()->sum(fn ($p) => $p->interest_amount + $p->fine_amount - $p->discount_amount);
    }

    private function audit(User $actor, Model $record, string $event, ?array $before = null): void
    {
        app(AuditRecorder::class)->recordModelEvent($record, $event, 'RH: '.str_replace('.', ' / ', $event), $before, $record->attributesToArray(), $actor->id);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
