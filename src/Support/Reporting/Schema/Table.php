<?php

namespace Fleetbase\Support\Reporting\Schema;

use Illuminate\Support\Str;

class Table
{
    protected string $name;
    protected string $label;
    protected ?string $description      = null;
    protected ?string $category         = null;
    protected ?string $extension        = null;
    protected array $columns            = [];
    protected array $computedColumns    = [];
    protected array $relationships      = [];
    protected array $excludedColumns    = [];
    protected bool $supportsAggregates  = true;
    protected ?int $maxRows             = null;
    protected bool $cacheable           = true;
    protected int $cacheTtl             = 3600;
    protected array $permissions        = [];
    protected array $meta               = [];

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->label = $this->generateLabel($name);
    }

    /**
     * Create a new table instance.
     */
    public static function make(string $name): self
    {
        return new static($name);
    }

    /**
     * Set the table label.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set the table description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the table category.
     */
    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Set the table extension.
     */
    public function extension(string $extension): self
    {
        $this->extension = $extension;

        return $this;
    }

    /**
     * Add columns to the table.
     */
    public function columns(array $columns): self
    {
        foreach ($columns as $column) {
            if ($column instanceof Column) {
                $this->columns[] = $column;
            } elseif (is_array($column)) {
                $this->columns[] = Column::make($column['name'], $column['type'] ?? 'string')
                    ->label($column['label'] ?? null)
                    ->description($column['description'] ?? null);
            } elseif (is_string($column)) {
                $this->columns[] = Column::make($column);
            }
        }

        return $this;
    }

    /**
     * Add a single column to the table.
     */
    public function addColumn(Column $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    /**
     * Add computed columns to the table.
     */
    public function computedColumns(array $columns): self
    {
        foreach ($columns as $column) {
            if ($column instanceof Column) {
                $this->computedColumns[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a computed column to the table.
     */
    public function addComputedColumn(Column $column): self
    {
        $this->computedColumns[] = $column;

        return $this;
    }

    /**
     * Add relationships to the table.
     */
    public function relationships(array $relationships): self
    {
        foreach ($relationships as $relationship) {
            if ($relationship instanceof Relationship) {
                $this->relationships[] = $relationship;
            }
        }

        return $this;
    }

    /**
     * Add a single relationship to the table.
     */
    public function addRelationship(Relationship $relationship): self
    {
        $this->relationships[] = $relationship;

        return $this;
    }

    /**
     * Exclude columns from being displayed in the UI.
     * These columns will still be available for joins and queries.
     */
    public function excludeColumns(array $columnNames): self
    {
        $this->excludedColumns = array_merge($this->excludedColumns, $columnNames);

        return $this;
    }

    /**
     * Set if the table supports aggregate functions.
     */
    public function supportsAggregates(bool $supports = true): self
    {
        $this->supportsAggregates = $supports;

        return $this;
    }

    /**
     * Set the maximum number of rows that can be returned.
     */
    public function maxRows(int $maxRows): self
    {
        $this->maxRows = $maxRows;

        return $this;
    }

    /**
     * Set if the table results are cacheable.
     */
    public function cacheable(bool $cacheable = true): self
    {
        $this->cacheable = $cacheable;

        return $this;
    }

    /**
     * Set the cache TTL in seconds.
     */
    public function cacheTtl(int $ttl): self
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * Set permissions for the table.
     */
    public function permissions(array $permissions): self
    {
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Add meta data to the table.
     */
    public function meta(string $key, $value): self
    {
        $this->meta[$key] = $value;

        return $this;
    }

    // Getters
    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getComputedColumns(): array
    {
        return $this->computedColumns;
    }

    public function getAllColumns(): array
    {
        return array_merge($this->columns, $this->computedColumns);
    }

    public function getRelationships(): array
    {
        return $this->relationships;
    }

    public function getExcludedColumns(): array
    {
        return $this->excludedColumns;
    }

    public function getSupportsAggregates(): bool
    {
        return $this->supportsAggregates;
    }

    public function getMaxRows(): ?int
    {
        return $this->maxRows;
    }

    public function isCacheable(): bool
    {
        return $this->cacheable;
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function getMeta(?string $key = null)
    {
        if ($key === null) {
            return $this->meta;
        }

        return $this->meta[$key] ?? null;
    }

    /**
     * Get visible columns (excluding hidden and excluded columns).
     */
    public function getVisibleColumns(): array
    {
        return array_filter($this->getAllColumns(), function ($column) {
            return !$column->isHidden()
                   && !in_array($column->getName(), $this->excludedColumns)
                   && !$this->isForeignKeyColumn($column->getName());
        });
    }

    /**
     * Get auto-join relationships.
     */
    public function getAutoJoinRelationships(): array
    {
        return array_filter($this->relationships, function ($relationship) {
            return $relationship->isAutoJoin();
        });
    }

    /**
     * Get manual join relationships (non-auto-join).
     */
    public function getManualJoinRelationships(): array
    {
        return array_filter($this->relationships, function ($relationship) {
            return !$relationship->isAutoJoin();
        });
    }

    /**
     * Get relationship by name.
     */
    public function getRelationship(string $name): ?Relationship
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->getName() === $name) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Get column by name.
     */
    public function getColumn(string $name): ?Column
    {
        foreach ($this->getAllColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Check if a column exists.
     */
    public function hasColumn(string $name): bool
    {
        return $this->getColumn($name) !== null;
    }

    /**
     * Check if a relationship exists.
     */
    public function hasRelationship(string $name): bool
    {
        return $this->getRelationship($name) !== null;
    }

    /**
     * Check if a column is allowed (not excluded and not hidden).
     */
    public function isColumnAllowed(string $name): bool
    {
        $column = $this->getColumn($name);

        if (!$column) {
            return false;
        }

        return !$column->isHidden()
               && !in_array($name, $this->excludedColumns);
    }

    /**
     * Get all available columns including relationship columns.
     */
    public function getAllAvailableColumns(): array
    {
        $columns = $this->getVisibleColumns();

        // Add columns from auto-join relationships
        foreach ($this->getAutoJoinRelationships() as $relationship) {
            $relationshipColumns = $relationship->getAllAvailableColumns();
            foreach ($relationshipColumns as $column) {
                // Add auto_join_path metadata
                $column->meta('auto_join_path', $relationship->getName());
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Convert the table to an array representation.
     */
    public function toArray(): array
    {
        return [
            'name'                       => $this->name,
            'label'                      => $this->label,
            'description'                => $this->description,
            'category'                   => $this->category,
            'extension'                  => $this->extension,
            'columns'                    => array_map(fn ($column) => $column->toArray(), $this->getVisibleColumns()),
            'computed_columns'           => array_map(fn ($column) => $column->toArray(), $this->computedColumns),
            'relationships'              => array_map(fn ($rel) => $rel->toArray(), $this->relationships),
            'auto_join_relationships'    => array_map(fn ($rel) => $rel->toArray(), $this->getAutoJoinRelationships()),
            'manual_join_relationships'  => array_map(fn ($rel) => $rel->toArray(), $this->getManualJoinRelationships()),
            'excluded_columns'           => $this->excludedColumns,
            'supports_aggregates'        => $this->supportsAggregates,
            'max_rows'                   => $this->maxRows,
            'cacheable'                  => $this->cacheable,
            'cache_ttl'                  => $this->cacheTtl,
            'permissions'                => $this->permissions,
            'meta'                       => $this->meta,
        ];
    }

    /**
     * Check if a column name is a foreign key.
     */
    protected function isForeignKeyColumn(string $name): bool
    {
        return Str::endsWith($name, '_uuid') || Str::endsWith($name, '_id');
    }

    /**
     * Generate a human-readable label from table name.
     */
    protected function generateLabel(string $name): string
    {
        return Str::title(str_replace(['_', '-'], ' ', $name));
    }
}
