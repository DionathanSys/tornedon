<?php

namespace App\Models;

use App\Enum\Equipment\Type;
use App\Services\DataReplication\ReplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'mark',
        'model',
        'serial_number',
        'created_by',
    ];

    protected $appends = ['identifier'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'type'       => Type::class,
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

    /* ==============================
     |  Helpers
     |==============================*/

    /**
     * Verifica se o equipamento é um veículo (carro ou caminhão).
     */
    public function isVehicle(): bool
    {
        return in_array($this->type, [Type::CAR, Type::TRUCK]);
    }

    /**
     * Retorna o identificador principal conforme o tipo:
     * - Veículos  → placa
     * - Demais    → serial_number
     */
    public function identifier(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->isVehicle() ? $this->placa : $this->serial_number,
        );
    }

    /**
     * Busca por placa (veículos) ou número de série (equipamentos).
     * Retorna registros onde qualquer um dos dois campos coincide com o termo.
     *
     * Uso:
     *   Equipment::searchByIdentifier('ABC1234')->get();
     *   Equipment::searchByIdentifier('ABC1234', type: Type::CAR)->get(); // restringe ao tipo
     */
    public function scopeSearchByIdentifier(Builder $query, string $term, ?Type $type = null): Builder
    {
        if ($type !== null) {
            // Se o tipo for informado, busca apenas no campo correto
            $field = in_array($type, [Type::CAR, Type::TRUCK]) ? 'placa' : 'serial_number';

            return $query->where($field, 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        }

        // Sem tipo definido: busca nos dois campos
        return $query->where(function (Builder $q) use ($term) {
            $q->where('placa', 'like', "%{$term}%")
              ->orWhere('serial_number', 'like', "%{$term}%")
              ->orWhere('name', 'like', "%{$term}%");
        });
    }

    /**
     * Requisições relacionadas ao equipamento
     */
    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }

}
