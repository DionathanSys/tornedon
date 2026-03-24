<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Enums\AttachmentType;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;

trait HasAttachments
{
    /**
     * Get all of the models attachments.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get current attachments.
     */
    public function currentAttachments(): MorphMany
    {
        return $this->attachments()->where('is_current', true);
    }

    /**
     * Get current attachments by type.
     */
    public function currentAttachmentsOfType(AttachmentType|string $type): MorphMany
    {
        $typeValue = $type instanceof AttachmentType ? $type->value : $type;
        return $this->currentAttachments()->where('type', $typeValue);
    }

    /**
     * Get all attachments by type (including history).
     */
    public function attachmentsOfType(AttachmentType|string $type): MorphMany
    {
        $typeValue = $type instanceof AttachmentType ? $type->value : $type;
        return $this->attachments()->where('type', $typeValue);
    }
    
    /**
     * Define the allowed attachment types for this model.
     * Override this method in the model to restrict the types.
     * @return array<string>
     */
    public function allowedAttachmentTypes(): array
    {
        return array_column(AttachmentType::cases(), 'value');
    }
}
