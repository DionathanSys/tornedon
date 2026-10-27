<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\BankSlipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankSlip extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'operation_status',
        'operation_kind',
        'operation_attempt_id',
        'pending_update',
        'provider_operation_id',
        'provider_response_code',
        'request_snapshot',
        'request_sent_at',
        'account_receivable_installment_id',
        'bank_account_connection_id',
        'financial_account_id',
        'status',
        'provider_identification',
        'provider_charge_id',
        'amount',
        'due_date',
        'pdf_url',
        'digitable_line',
        'barcode',
        'provider_status_code',
        'provider_status_message',
        'registered_at',
        'paid_at',
        'cancel_requested_at',
        'canceled_at',
        'last_synchronized_at',
        'last_error',
        'provider_payload',
    ];

    protected $casts = [
        'request_snapshot' => 'array',
        'pending_update' => 'array',
        'request_sent_at' => 'datetime',
        'status' => BankSlipStatus::class,
        'amount' => MoneyCast::class,
        'due_date' => 'date',
        'registered_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'canceled_at' => 'datetime',
        'last_synchronized_at' => 'datetime',
        'provider_payload' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivableInstallment::class, 'account_receivable_installment_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankAccountConnection::class, 'bank_account_connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BankSlipEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountReceivableInstallmentPayment::class);
    }

    public function providerResponseFailed(): bool
    {
        if (in_array((string) data_get($this->provider_payload, 'codigo'), ['3', '4'], true)) {
            return false;
        }
        $success = data_get($this->provider_payload, 'sucesso');

        return $success === false
            || (is_string($success) && in_array(strtolower($success), ['false', '0', 'nao', 'não'], true));
    }

    public function getPdfUrlAttribute(?string $value): ?string
    {
        return $value
            ?: data_get($this->provider_payload, 'pdf')
            ?: data_get($this->provider_payload, 'dados.pdf')
            ?: data_get($this->provider_payload, 'data.pdf');
    }
}
