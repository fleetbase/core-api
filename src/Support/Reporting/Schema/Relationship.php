<?php

namespace Fleetbase\Support\Reporting\Schema;

use Illuminate\Support\Str;

class Relationship
{
    protected string $name;
    protected string $table;
    protected string $label;
    protected string $type;
    protected string $localKey;
    protected string $foreignKey;
    protected bool $enabled              = true;
    protected bool $autoJoin             = false; // Optional auto-join feature
    protected ?string $description       = null;
    protected array $columns             = [];
    protected array $nestedRelationships = [];
    protected array $meta                = [];

    public function __construct(string $name, string $table, string $type = 'left')
    {
        $this->name       = $name;
        $this->table      = $table;
        $this->type       = $type;
        $this->label      = $this->generateLabel($name);
        $this->localKey   = $this->generateLocalKey($name);
        $this->foreignKey = 'uuid'; // Default to uuid for Fleetbase
    }

    /**
     * Create a new relationship instance.
     */
    public static function make(string $name, string $table, string $type = 'left'): self
    {
        return new static($name, $table, $type);
    }

    /**
     * Create a belongsTo relationship.
     */
    public static function belongsTo(string $name, string $table): self
    {
        return static::make($name, $table, 'left');
    }

    /**
     * Create a hasMany relationship.
     */
    public static function hasMany(string $name, string $table): self
    {
        return static::make($name, $table, 'left');
    }

    /**
     * Create a hasOne relationship.
     */
    public static function hasOne(string $name, string $table): self
    {
        return static::make($name, $table, 'left');
    }

    /**
     * Create an auto-join relationship (optional feature).
     * This enables automatic joining when columns from this relationship are selected.
     */
    public static function hasAutoJoin(string $name, string $table, string $type = 'left'): self
    {
        return static::make($name, $table, $type)->setAutoJoin(true);
    }

    /**
     * Set the relationship label.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set the relationship description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the local key.
     */
    public function localKey(string $localKey): self
    {
        $this->localKey = $localKey;

        return $this;
    }

    /**
     * Set the foreign key.
     */
    public function foreignKey(string $foreignKey): self
    {
        $this->foreignKey = $foreignKey;

        return $this;
    }

    /**
     * Set the join type.
     */
    public function joinType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Enable or disable the relationship.
     */
    public function enabled(bool $enabled = true): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Set if this relationship should be auto-joined (optional feature).
     * When enabled, selecting columns from this relationship will automatically add the join.
     */
    public function autoJoin(bool $autoJoin = true): self
    {
        $this->autoJoin = $autoJoin;

        return $this;
    }

    /**
     * Add columns to the relationship.
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
     * Add a single column to the relationship.
     */
    public function addColumn(Column $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    /**
     * Add nested relationships.
     */
    public function with(array $relationships): self
    {
        foreach ($relationships as $relationship) {
            if ($relationship instanceof Relationship) {
                $this->nestedRelationships[] = $relationship;
            }
        }

        return $this;
    }

    /**
     * Add a nested relationship.
     */
    public function addNestedRelationship(Relationship $relationship): self
    {
        $this->nestedRelationships[] = $relationship;

        return $this;
    }

    /**
     * Add meta data to the relationship.
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

    public function getTable(): string
    {
        return $this->table;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isAutoJoin(): bool
    {
        return $this->autoJoin;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getNestedRelationships(): array
    {
        return $this->nestedRelationships;
    }

    public function getMeta(?string $key = null)
    {
        if ($key === null) {
            return $this->meta;
        }

        return $this->meta[$key] ?? null;
    }

    /**
     * Get all available columns including nested relationship columns.
     */
    public function getAllAvailableColumns(): array
    {
        $columns = $this->columns;

        // Add columns from nested relationships
        foreach ($this->nestedRelationships as $nestedRelationship) {
            $nestedColumns = $nestedRelationship->getAllAvailableColumns();
            foreach ($nestedColumns as $nestedColumn) {
                // Prefix the column name with the nested relationship path
                /** @var Column $nestedColumn */
                $prefixedColumn = $nestedColumn->copyWith([
                    'name'  => $this->name . '.' . $nestedColumn->getName(),
                    'label' => $this->label . ' - ' . $nestedColumn->getLabel(),
                ]);
                $columns[] = $prefixedColumn;
            }
        }

        return $columns;
    }

    /**
     * Get nested relationship by name.
     */
    public function getNestedRelationship(string $name): ?Relationship
    {
        foreach ($this->nestedRelationships as $relationship) {
            if ($relationship->getName() === $name) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Get auto-join relationships.
     */
    public function getAutoJoinRelationships(): array
    {
        return array_filter($this->nestedRelationships, function ($relationship) {
            return $relationship->isAutoJoin();
        });
    }

    /**
     * Get manual join relationships (non-auto-join).
     */
    public function getManualJoinRelationships(): array
    {
        return array_filter($this->nestedRelationships, function ($relationship) {
            return !$relationship->isAutoJoin();
        });
    }

    /**
     * Check if this relationship has nested relationships.
     */
    public function hasNestedRelationships(): bool
    {
        return !empty($this->nestedRelationships);
    }

    /**
     * Convert the relationship to an array representation.
     */
    public function toArray(): array
    {
        return [
            'name'                 => $this->name,
            'table'                => $this->table,
            'label'                => $this->label,
            'type'                 => $this->type,
            'local_key'            => $this->localKey,
            'foreign_key'          => $this->foreignKey,
            'enabled'              => $this->enabled,
            'auto_join'            => $this->autoJoin,
            'description'          => $this->description,
            'columns'              => array_map(fn ($column) => $column->toArray(), $this->columns),
            'nested_relationships' => array_map(fn ($rel) => $rel->toArray(), $this->nestedRelationships),
            'meta'                 => $this->meta,
        ];
    }

    // Protected methods
    protected function setAutoJoin(bool $autoJoin): self
    {
        $this->autoJoin = $autoJoin;

        return $this;
    }

    /**
     * Generate a human-readable label from relationship name.
     */
    protected function generateLabel(string $name): string
    {
        return Str::title(str_replace(['_', '-'], ' ', $name));
    }

    /**
     * Generate the local key based on relationship name.
     */
    protected function generateLocalKey(string $name): string
    {
        // For Fleetbase, most foreign keys follow the pattern {relationship}_uuid
        return $name . '_uuid';
    }
}
