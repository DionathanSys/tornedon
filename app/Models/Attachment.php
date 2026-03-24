<?php

namespace App\Models;

use App\Enums\AttachmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id',
        'attachable_type',
        'attachable_id',
        'company_id',
        'type',
        'idempotency_key',
        'version',
        'is_current',
        'disk',
        'path',
        'original_name',
        'stored_name',
        'extension',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'metadata',
        'uploaded_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'metadata'   => 'array',
        'type'       => AttachmentType::class,
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
    
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
    
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
    
    public function getUrlAttribute(): string
    {
        return url("/attachments/{$this->public_id}/download");
    }
}
