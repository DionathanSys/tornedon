<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SkipsGeneratedColumns
{
    protected static array $generatedColumnsCache = [];

    protected static function bootSkipsGeneratedColumns(): void
    {
        static::saving(function (Model $model): void {
            foreach ($model->getGeneratedColumnsForTable() as $column) {
                if (! $model->exists || $model->isDirty($column)) {
                    unset($model->attributes[$column]);
                }
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function getGeneratedColumnsForTable(): array
    {
        $connection = $this->getConnection();
        $database = $connection->getDatabaseName();

        if (! $database) {
            return [];
        }

        $cacheKey = sprintf(
            '%s:%s:%s',
            $connection->getName() ?? 'default',
            $database,
            $this->getTable()
        );

        if (array_key_exists($cacheKey, static::$generatedColumnsCache)) {
            return static::$generatedColumnsCache[$cacheKey];
        }

        try {
            $rows = $connection->select(
                'select COLUMN_NAME as column_name from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? and EXTRA like ?',
                [$database, $this->getTable(), '%GENERATED%']
            );

            static::$generatedColumnsCache[$cacheKey] = array_values(array_filter(array_map(
                static fn (object $row): string => (string) ($row->column_name ?? ''),
                $rows
            )));
        } catch (\Throwable) {
            static::$generatedColumnsCache[$cacheKey] = [];
        }

        return static::$generatedColumnsCache[$cacheKey];
    }
}
