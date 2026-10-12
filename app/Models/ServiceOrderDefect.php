<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderDefect extends Model
{
    public const STATUSES = [
        'pending' => 'Pendente',
        'in_analysis' => 'Em análise',
        'resolved' => 'Resolvido',
        'not_found' => 'Não constatado',
    ];

    protected $fillable = ['report', 'diagnosis', 'solution', 'status', 'position', 'created_by', 'updated_by'];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }
}
