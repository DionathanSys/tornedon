<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipment extends Model
{
    use SoftDeletes;

    protected $table = 'equipments';

    protected $fillable = [
        'name',
        'owner_id',
        'company_id',
        'type',
        'placa',
        'model',
        'serial_number',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Proprietário do equipamento (Parceiro)
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'owner_id');
    }

    /**
     * Empresa proprietária
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Usuário que criou o registro
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Ordens de serviço relacionadas ao equipamento
     */
    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /**
     * Requisições relacionadas ao equipamento
     */
    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }
}
