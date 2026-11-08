<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentIssue extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['history' => 'array', 'resolved_at' => 'datetime'];

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function evidenceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'evidence_attachment_id')->withTrashed();
    }
}
