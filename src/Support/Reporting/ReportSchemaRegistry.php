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

        if ($this->cacheEnabled && !empty($tables)) {
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
            $columns = array_merge(
                $columns,
                $this->flattenRelationshipColumns($relationship, $relationship->getName(), [$relationship->getLabel()])
            );
        }

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $columns, $this->cacheTtl);
        }

        return $columns;
    }

    /**
     * Recursively flatten relationship columns with full path and contextual labels.
     *
     * Example:
     *   payload.pickup.street1 → label "Pickup Street 1"
     *   payload.dropoff.city   → label "Dropoff City"
     */
    private function flattenRelationshipColumns($relationship, string $path, array $labelTrail): array
    {
        $out = [];

        $shortPrefix = $this->shortRelationshipLabel(end($labelTrail)); // "Pickup Location" → "Pickup"

        // Columns directly on this relationship
        foreach ($relationship->getColumns() as $column) {
            $arr = $column->toArray();

            // Ensure the machine name carries the full path (so filters/queries work unambiguously)
            $arr['name'] = "{$path}.{$column->getName()}";

            // Human label with context
            // e.g., "Pickup Street 1" or "Dropoff City"
            $arr['label'] = trim($shortPrefix . ' ' . $arr['label']);

            // Useful metadata
            $arr['auto_join_path']      = $path;            // e.g., "payload.pickup"
            $arr['relationship_labels'] = $labelTrail;      // e.g., ["Order Payload", "Pickup Location"]

            $out[] = $arr;
        }

        // Recurse into nested auto-join relationships (payload -> pickup/dropoff)
        foreach ($relationship->getAutoJoinRelationships() as $child) {
            $childPath   = "{$path}.{$child->getName()}";
            $childLabels = array_merge($labelTrail, [$child->getLabel()]);

            $out = array_merge($out, $this->flattenRelationshipColumns($child, $childPath, $childLabels));
        }

        return $out;
    }

    /**
     * Normalize relationship label into a short prefix for columns.
     * Examples:
     *   "Pickup Location"  → "Pickup"
     *   "Dropoff Location" → "Dropoff"
     *   "Order Payload"    → "Payload".
     */
    private function shortRelationshipLabel(string $label): string
    {
        // Remove common suffixes like "Location"
        $label = preg_replace('/\s+Location$/i', '', $label);

        // If the label has multiple words, prefer the first ("Pickup Location" → "Pickup")
        // But for "Order Payload" we prefer the last ("Payload") so nested pickup/dropoff can still prepend naturally
        $parts = preg_split('/\s+/', trim($label));
        if (!$parts || count($parts) === 0) {
            return trim($label);
        }

        // Special case: if it contains "Payload", keep "Payload"
        if (stripos($label, 'payload') !== false) {
            return 'Payload';
        }

        // Default: first word
        return $parts[0];
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

        if ($this->cacheEnabled && !empty($relationships)) {
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
    // In ReportSchemaRegistry

    public function isColumnAllowed(string $tableName, string $columnPath): bool
    {
        // no dot → direct column on the main table
        if (strpos($columnPath, '.') === false) {
            $table = $this->getTable($tableName);

            return $table ? $table->isColumnAllowed($columnPath) : false;
        }

        $segments = explode('.', $columnPath);
        $finalCol = array_pop($segments); // e.g. "street1"
        $table    = $this->getTable($tableName);

        if (!$table) {
            return false;
        }

        // Walk relationships: e.g. ["payload","pickup"]
        $relationship = null;

        foreach ($segments as $idx => $seg) {
            // First hop: find relationship on the table
            if ($idx === 0) {
                $relationship = $table->getRelationship($seg);
            } else {
                // Next hops: find on the previous relationship
                $relationship = $this->getChildRelationship($relationship, $seg);
            }

            if (!$relationship || !$relationship->isAutoJoin()) {
                return false;
            }
        }

        // At this point $relationship is the LAST relationship in the chain (e.g., "pickup")
        // Check if the final column exists on that relationship.
        foreach ($relationship->getColumns() as $col) {
            if ($col->getName() === $finalCol) {
                return true;
            }
        }

        // If your Relationship exposes all nested columns via getAllAvailableColumns(), keep as a fallback:
        if (method_exists($relationship, 'getAllAvailableColumns')) {
            foreach ($relationship->getAllAvailableColumns() as $col) {
                if ($col->getName() === $finalCol) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find a nested child relationship by name on a parent Relationship.
     */
    private function getChildRelationship($parentRelationship, string $name)
    {
        if (!method_exists($parentRelationship, 'getAutoJoinRelationships')) {
            return null;
        }
        foreach ($parentRelationship->getAutoJoinRelationships() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
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

        $table = $this->getTable($tableName);
        if (!$table) {
            return;
        }

        $extension = $table->getExtension();
        $category  = $table->getCategory();

        $keys = [
            "report_columns_{$tableName}",
            "report_relationships_{$tableName}",
            // IMPORTANT: these match getAvailableTables()
            "report_tables_{$extension}_all",
        ];

        if ($category) {
            $keys[] = "report_tables_{$extension}_{$category}";
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
