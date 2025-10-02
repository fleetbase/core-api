<?php

namespace Fleetbase\Support\Reporting;

use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReportSchemaRegistry
{
    protected array $tables      = [];
    protected bool $cacheEnabled = true;
    protected int $cacheTtl      = 3600;

    /**
     * Register a table using the new Table class.
     */
    public function registerTable(Table $table): void
    {
        $this->tables[$table->getName()] = $table;

        // Clear cache when new table is registered
        if ($this->cacheEnabled) {
            $this->clearTableCache($table->getName());
        }
    }

    /**
     * Register multiple tables.
     */
    public function registerTables(array $tables): void
    {
        foreach ($tables as $table) {
            if ($table instanceof Table) {
                $this->registerTable($table);
            }
        }
    }

    /**
     * Get a table by name.
     */
    public function getTable(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * Check if a table is registered.
     */
    public function isTableRegistered(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    /**
     * Check if a table is registered.
     */
    public function hasTable(string $name): bool
    {
        return $this->isTableRegistered($name);
    }

    /**
     * Get all registered table names.
     */
    public function getRegisteredTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Get available tables for reporting.
     */
    public function getAvailableTables(string $extension, ?string $category = null): array
    {
        $cacheKey = 'report_tables_' . $extension . '_' . ($category ?? 'all');

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $tables = [];

        foreach ($this->tables as $table) {
            if ($extension !== $table->getExtension()) {
                continue;
            }
            if (!is_null($category) && $category !== $table->getCategory()) {
                continue;
            }
            $tables[] = [
                'name'                 => $table->getName(),
                'label'                => $table->getLabel(),
                'description'          => $table->getDescription(),
                'category'             => $table->getCategory(),
                'extension'            => $table->getExtension(),
                'columns'              => $this->getTableColumns($table->getName()),
                'relationships'        => $this->getTableRelationships($table->getName()),
                'auto_join_columns'    => $this->getAutoJoinColumns($table->getName()),
                'supports_aggregates'  => $table->getSupportsAggregates(),
                'max_rows'             => $table->getMaxRows(),
                'has_auto_joins'       => !empty($table->getAutoJoinRelationships()),
                'has_manual_joins'     => !empty($table->getManualJoinRelationships()),
            ];
        }

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $tables, $this->cacheTtl);
        }

        return $tables;
    }

    /**
     * Get columns for a specific table.
     */
    public function getTableColumns(string $tableName): array
    {
        $cacheKey = "report_columns_{$tableName}";

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $table = $this->getTable($tableName);
        if (!$table) {
            return [];
        }

        $columns = [];

        // Get visible columns (excludes foreign keys and hidden columns)
        foreach ($table->getVisibleColumns() as $column) {
            $columns[] = $column->toArray();
        }

        // Add auto-join columns with path information
        foreach ($table->getAutoJoinRelationships() as $relationship) {
            foreach ($relationship->getAllAvailableColumns() as $column) {
                $columnArray                       = $column->toArray();
                $columnArray['auto_join_path']     = $relationship->getName();
                $columnArray['relationship_label'] = $relationship->getLabel();
                $columns[]                         = $columnArray;
            }
        }

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $columns, $this->cacheTtl);
        }

        return $columns;
    }

    /**
     * Get relationships for a specific table.
     */
    public function getTableRelationships(string $tableName): array
    {
        $cacheKey = "report_relationships_{$tableName}";

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $table = $this->getTable($tableName);
        if (!$table) {
            return [];
        }

        $relationships = [];

        // Get all relationships (both auto-join and manual)
        foreach ($table->getRelationships() as $relationship) {
            $relationshipArray                              = $relationship->toArray();
            $relationshipArray['available_for_manual_join'] = true; // All relationships can be manually joined
            $relationships[]                                = $relationshipArray;
        }

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $relationships, $this->cacheTtl);
        }

        return $relationships;
    }

    /**
     * Get auto-join columns for a table.
     */
    public function getAutoJoinColumns(string $tableName): array
    {
        $table = $this->getTable($tableName);
        if (!$table) {
            return [];
        }

        $autoJoinColumns = [];

        foreach ($table->getAutoJoinRelationships() as $relationship) {
            foreach ($relationship->getAllAvailableColumns() as $column) {
                $autoJoinColumns[] = [
                    'name'           => $relationship->getName() . '.' . $column->getName(),
                    'label'          => $relationship->getLabel() . ' - ' . $column->getLabel(),
                    'type'           => $column->getType(),
                    'auto_join_path' => $relationship->getName(),
                    'relationship'   => $relationship->getName(),
                    'column'         => $column->getName(),
                ];
            }
        }

        return $autoJoinColumns;
    }

    /**
     * Check if a column is allowed for a table.
     */
    public function isColumnAllowed(string $tableName, string $columnName): bool
    {
        $table = $this->getTable($tableName);
        if (!$table) {
            return false;
        }

        // Check if it's a direct column
        if ($table->isColumnAllowed($columnName)) {
            return true;
        }

        // Check if it's an auto-join column (format: relationship.column)
        if (Str::contains($columnName, '.')) {
            $parts             = explode('.', $columnName, 2);
            $relationshipName  = $parts[0];
            $relatedColumnName = $parts[1];

            $relationship = $table->getRelationship($relationshipName);
            if ($relationship && $relationship->isAutoJoin()) {
                // Check if the column exists in the relationship
                foreach ($relationship->getAllAvailableColumns() as $column) {
                    if ($column->getName() === $relatedColumnName
                        || $column->getName() === $columnName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get table schema including columns and relationships.
     */
    public function getTableSchema(string $tableName): array
    {
        $table = $this->getTable($tableName);
        if (!$table) {
            return [];
        }

        return [
            'table'             => $table->toArray(),
            'columns'           => $this->getTableColumns($tableName),
            'relationships'     => $this->getTableRelationships($tableName),
            'auto_join_columns' => $this->getAutoJoinColumns($tableName),
        ];
    }

    /**
     * Resolve auto-join path for a column.
     */
    public function resolveAutoJoinPath(string $tableName, string $columnName): ?array
    {
        if (!Str::contains($columnName, '.')) {
            return null;
        }

        $table = $this->getTable($tableName);
        if (!$table) {
            return null;
        }

        $parts        = explode('.', $columnName);
        $currentTable = $table;
        $joinPath     = [];

        for ($i = 0; $i < count($parts) - 1; $i++) {
            $relationshipName = $parts[$i];
            $relationship     = $currentTable->getRelationship($relationshipName);

            if (!$relationship || !$relationship->isAutoJoin()) {
                return null;
            }

            $joinPath[] = [
                'relationship' => $relationship->getName(),
                'table'        => $relationship->getTable(),
                'type'         => $relationship->getType(),
                'local_key'    => $relationship->getLocalKey(),
                'foreign_key'  => $relationship->getForeignKey(),
            ];

            // For nested relationships, we would need to get the next table
            // This is a simplified version - you might need to extend this
            // based on your specific relationship structure
        }

        return $joinPath;
    }

    /**
     * Clear cache for a specific table.
     */
    public function clearTableCache(string $tableName): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $keys = [
            "report_columns_{$tableName}",
            "report_relationships_{$tableName}",
            'report_tables_all',
        ];

        // Also clear category-specific caches
        $table = $this->getTable($tableName);
        if ($table && $table->getCategory()) {
            $keys[] = "report_tables_{$table->getCategory()}";
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Clear all cache.
     */
    public function clearAllCache(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        foreach ($this->tables as $table) {
            $this->clearTableCache($table->getName());
        }
    }

    /**
     * Enable or disable caching.
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Set cache TTL.
     */
    public function setCacheTtl(int $ttl): void
    {
        $this->cacheTtl = $ttl;
    }
}
