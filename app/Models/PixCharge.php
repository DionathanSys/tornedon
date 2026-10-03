<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\PixChargeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixCharge extends Model
{
    protected $fillable = [
        'company_id',
        'account_receivable_installment_id',
        'bank_account_connection_id',
        'status',
        'provider_identification',
        'provider_charge_id',
        'amount',
        'due_date',
        'qr_code',
        'pix_copy_paste',
        'provider_status_code',
        'provider_status_message',
        'registered_at',
        'paid_at',
        'last_synchronized_at',
        'last_error',
        'provider_payload',
    ];

    protected $casts = [
        'status' => PixChargeStatus::class,
        'amount' => MoneyCast::class,
        'due_date' => 'date',
        'registered_at' => 'datetime',
        'paid_at' => 'datetime',
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

    public function providerResponseFailed(): bool
    {
        $success = data_get($this->provider_payload, 'sucesso');

        return $success === false
            || (is_string($success) && in_array(strtolower($success), ['false', '0', 'nao', 'não'], true));
    }
}
