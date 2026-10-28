<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tracks the workbench; commercial items remain exclusively in OS/requisitions. */
class BpoSale extends Model
{
    protected $fillable = ['company_id', 'request_id', 'sale_template_id', 'template_snapshot', 'service_order_id', 'requisition_id', 'invoice_id', 'payment_settings', 'revision', 'finalized_at', 'created_by'];

    protected $casts = ['template_snapshot' => 'array', 'payment_settings' => 'array', 'revision' => 'integer', 'finalized_at' => 'datetime'];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function source(): ServiceOrder|Requisition
    {
        return $this->service_order_id ? $this->serviceOrder()->firstOrFail() : $this->requisition()->firstOrFail();
    }
}
