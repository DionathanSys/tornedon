<?php

namespace App\Models;

use App\Enum\Financial\DreDisplaySign;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class DreLine extends Model
{
    protected $fillable = [
        'dre_model_id',
        'parent_id',
        'name',
        'code',
        'line_type',
        'operation',
        'display_sign',
        'display_depth',
        'sort_order',
        'is_bold',
        'is_visible',
    ];

    protected $casts = [
        'line_type' => DreLineType::class,
        'operation' => DreOperation::class,
        'display_sign' => DreDisplaySign::class,
        'display_depth' => 'integer',
        'sort_order' => 'integer',
        'is_bold' => 'boolean',
        'is_visible' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            $line->validateParent();
        });

        static::saved(fn (self $line): string => $line->dreModel->refreshStructureHash());
        static::deleted(fn (self $line): string => $line->dreModel->refreshStructureHash());
    }

    public function dreModel(): BelongsTo
    {
        return $this->belongsTo(DreModel::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function chartAccounts(): BelongsToMany
    {
        return $this->belongsToMany(ChartAccount::class, 'dre_line_chart_account')
            ->withPivot('include_descendants')
            ->withTimestamps();
    }

    private function validateParent(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['Uma linha da DRE nao pode ser pai dela mesma.'],
            ]);
        }

        $parent = static::query()->find($this->parent_id);

        if (! $parent || (int) $parent->dre_model_id !== (int) $this->dre_model_id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A linha pai deve pertencer ao mesmo modelo de DRE.'],
            ]);
        }

        $current = $parent;
        $visited = [];

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A hierarquia de linhas da DRE contem um ciclo.'],
                ]);
            }

            if ($this->exists && $currentId === (int) $this->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A linha pai nao pode ser descendente da linha atual.'],
                ]);
            }

            $visited[$currentId] = true;
            $current = $current->parent()->first();
        }
    }
}
