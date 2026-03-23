<?php

namespace App\Models;

use App\Services\Attachments\OrderAttachmentStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderAttachment extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            app(OrderAttachmentStorageService::class)->removeStoredFile($attachment);
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
