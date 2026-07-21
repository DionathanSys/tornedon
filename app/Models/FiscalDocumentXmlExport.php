<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDocumentXmlExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'date_column',
        'starts_at',
        'ends_at',
        'total_documents',
        'successful_documents',
        'failed_documents',
        'zip_disk',
        'zip_path',
        'download_token',
        'download_expires_at',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'download_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_documents' => 'integer',
        'successful_documents' => 'integer',
        'failed_documents' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FiscalDocumentXmlExportItem::class);
    }

    public function isDownloadAvailable(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true)
            && filled($this->zip_path)
            && filled($this->download_token)
            && $this->download_expires_at !== null
            && $this->download_expires_at->isFuture();
    }
}
