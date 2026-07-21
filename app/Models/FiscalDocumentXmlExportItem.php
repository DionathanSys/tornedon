<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentXmlExportItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'fiscal_document_xml_export_id',
        'fiscal_document_id',
        'document_key',
        'document_number',
        'status',
        'xml_disk',
        'xml_path',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function export(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentXmlExport::class, 'fiscal_document_xml_export_id');
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
