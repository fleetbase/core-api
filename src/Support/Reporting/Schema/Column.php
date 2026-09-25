<?php

namespace Fleetbase\Support\Reporting\Schema;

use Illuminate\Support\Str;

class Column
{
    /**
     * Identifier columns that end in `_id` but are not foreign keys.
     */
    public const IDENTIFIER_COLUMNS = ['public_id', 'internal_id'];

    /**
     * Internal bookkeeping columns that are never reportable, whatever a schema declares.
     */
    public const SYSTEM_COLUMNS = ['_key', '_import_id'];

    /**
     * Whether a column name is internal bookkeeping that reports must never expose.
     */
    public static function isSystemColumnName(string $name): bool
    {
        return in_array($name, static::SYSTEM_COLUMNS, true);
    }

    protected string $name;
    protected string $label;
    protected string $type;
    protected ?string $description   = null;
    protected ?string $format        = null;
    protected bool $nullable         = true;
    protected bool $searchable       = true;
    protected bool $sortable         = true;
    protected bool $filterable       = true;
    protected bool $aggregatable     = false;
    protected bool $hidden           = false;
    protected bool $computed         = false;
    protected bool $aggregate        = false;
    protected ?string $computation   = null;
    protected ?\Closure $transformer = null;
    protected array $meta            = [];

    public function __construct(string $name, string $type = 'string')
    {
        $this->name         = $name;
        $this->type         = $type;
        $this->label        = $this->generateLabel($name);
        $this->aggregatable = $this->determineAggregatable($type);
    }

    /**
     * Create a new column instance.
     */
    public static function make(string $name, string $type = 'string'): self
    {
        return new static($name, $type);
    }

    /**
     * Create a computed column.
     *
     * Whether the computation is an aggregate (COUNT/SUM/AVG/MIN/MAX/GROUP_CONCAT) is detected
     * from the expression unless `$options['aggregate']` says otherwise.
     */
    public static function computed(string $name, string $computation, string $type = 'string', array $options = []): self
    {
        return static::make($name, $type)
            ->setComputed(true)
            ->setComputation($computation)
            ->setAggregate(isset($options['aggregate']) ? (bool) $options['aggregate'] : static::isAggregateExpression($computation))
            ->setAggregatable(isset($options['aggregatable']) ? (bool) $options['aggregatable'] : false)
            ->setSortable(isset($options['sortable']) ? (bool) $options['sortable'] : false)
            ->setSearchable(isset($options['searchable']) ? (bool) $options['searchable'] : false);
    }

    /**
     * Create a row-level expression column, such as a value read out of a JSON column.
     *
     * Bare column names in the expression resolve against the table or relationship that
     * declares the column, so `JSON_EXTRACT(meta, '$.total')` declared on a nested
     * `payload.entities` relationship reads the joined entity's meta. Unlike an aggregate
     * computed column, an expression column behaves like a regular column: it can be
     * selected, filtered, sorted, grouped by and aggregated.
     */
    public static function expression(string $name, string $expression, string $type = 'string'): self
    {
        $column = static::make($name, $type)
            ->setComputed(true)
            ->setComputation($expression)
            ->setAggregate(false);

        $column->aggregatable = $column->determineAggregatable($type);

        return $column;
    }

    /**
     * Whether an SQL expression is an aggregate, i.e. it starts with an aggregate function.
     */
    public static function isAggregateExpression(string $expression): bool
    {
        return (bool) preg_match('/^\s*\(?\s*(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i', $expression);
    }

    /**
     * Create a count column.
     */
    public static function count(string $name, string $countField = '*'): self
    {
        return static::computed($name, "COUNT({$countField})", 'integer')
            ->setAggregatable(true);
    }

    /**
     * Create a sum column.
     */
    public static function sum(string $name, string $sumField): self
    {
        return static::computed($name, "SUM({$sumField})", 'decimal')
            ->setAggregatable(true);
    }

    /**
     * Create an average column.
     */
    public static function avg(string $name, string $avgField): self
    {
        return static::computed($name, "AVG({$avgField})", 'decimal')
            ->setAggregatable(true);
    }

    /**
     * Create a max column.
     */
    public static function max(string $name, string $maxField): self
    {
        return static::computed($name, "MAX({$maxField})", 'string')
            ->setAggregatable(true);
    }

    /**
     * Create a min column.
     */
    public static function min(string $name, string $minField): self
    {
        return static::computed($name, "MIN({$minField})", 'string')
            ->setAggregatable(true);
    }

    /**
     * Set the column label.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set the column description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the column format.
     */
    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Set if the column is nullable.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Set if the column is searchable.
     */
    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Set if the column is sortable.
     */
    public function sortable(bool $sortable = true): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    /**
     * Set if the column is filterable.
     */
    public function filterable(bool $filterable = true): self
    {
        $this->filterable = $filterable;

        return $this;
    }

    /**
     * Set if the column is aggregatable.
     */
    public function aggregatable(bool $aggregatable = true): self
    {
        $this->aggregatable = $aggregatable;

        return $this;
    }

    /**
     * Set if the column is hidden.
     */
    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Set the column transformer.
     */
    public function transformer(\Closure|callable $transformer): self
    {
        $this->transformer = $transformer instanceof \Closure
            ? $transformer
            : \Closure::fromCallable($transformer);

        return $this;
    }

    /**
     * Add meta data to the column.
     */
    public function meta(string $key, $value): self
    {
        $this->meta[$key] = $value;

        return $this;
    }

    /**
     * Set multiple meta data at once.
     */
    public function setMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

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

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function isAggregatable(): bool
    {
        return $this->aggregatable;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function isComputed(): bool
    {
        return $this->computed;
    }

    /**
     * Whether this is a computed column whose computation aggregates rows (COUNT, SUM, ...).
     */
    public function isAggregate(): bool
    {
        return $this->computed && $this->aggregate;
    }

    /**
     * Whether this is a computed column that yields a value per row (an expression column).
     */
    public function isExpression(): bool
    {
        return $this->computed && !$this->aggregate && $this->computation !== null;
    }

    public function getComputation(): ?string
    {
        return $this->computation;
    }

    public function getTransformer(): ?\Closure
    {
        return $this->transformer;
    }

    public function hasTransformer(): bool
    {
        return $this->transformer !== null;
    }

    public function getMeta(?string $key = null)
    {
        if ($key === null) {
            return $this->meta;
        }

        return $this->meta[$key] ?? null;
    }

    /**
     * Check if this is a foreign key column.
     */
    public function isForeignKey(): bool
    {
        return static::isForeignKeyName($this->name);
    }

    /**
     * Whether a column name looks like a foreign key (`*_uuid` / `*_id`).
     *
     * A record's own identifiers (`public_id`, `internal_id`) end in `_id` but are not
     * foreign keys; they are usually the first thing a report needs.
     */
    public static function isForeignKeyName(string $name): bool
    {
        if (in_array($name, static::IDENTIFIER_COLUMNS, true)) {
            return false;
        }

        return Str::endsWith($name, '_uuid') || Str::endsWith($name, '_id');
    }

    /**
     * Transform a value using the registered transformer.
     */
    public function transformValue($value)
    {
        if ($this->transformer) {
            return ($this->transformer)($value);
        }

        return $value;
    }

    /**
     * Convert the column to an array representation.
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'label'        => $this->label,
            'type'         => $this->type,
            'description'  => $this->description,
            'format'       => $this->format,
            'nullable'     => $this->nullable,
            'searchable'   => $this->searchable,
            'sortable'     => $this->sortable,
            'filterable'   => $this->filterable,
            'aggregatable' => $this->aggregatable,
            'hidden'       => $this->hidden,
            'computed'     => $this->computed,
            'aggregate'    => $this->isAggregate(),
            'computation'  => $this->computation,
            'transformer'  => $this->hasTransformer(),
            'meta'         => $this->meta,
        ];
    }

    /**
     * Convert the column to a JSON representation.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    // Protected methods
    protected function setComputed(bool $computed): self
    {
        $this->computed = $computed;

        return $this;
    }

    protected function setComputation(string $computation): self
    {
        $this->computation = $computation;

        return $this;
    }

    protected function setAggregate(bool $aggregate): self
    {
        $this->aggregate = $aggregate;

        return $this;
    }

    protected function setAggregatable(bool $aggregatable): self
    {
        $this->aggregatable = $aggregatable;

        return $this;
    }

    protected function setSortable(bool $sortable): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    protected function setSearchable(bool $searchable): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Generate a human-readable label from column name.
     */
    protected function generateLabel(string $name): string
    {
        return Str::title(str_replace(['_', '-'], ' ', $name));
    }

    /**
     * Determine if a column type is aggregatable by default.
     */
    protected function determineAggregatable(string $type): bool
    {
        return in_array($type, [
            'integer', 'int', 'bigint', 'smallint', 'tinyint',
            'decimal', 'float', 'double', 'numeric',
            'date', 'datetime', 'timestamp', 'time',
        ]);
    }

    /**
     * Create a copy of this column with overrides.
     */
    public function copyWith(array $overrides): self
    {
        $clone = clone $this;

        foreach ($overrides as $key => $value) {
            switch ($key) {
                case 'name':
                    $clone->name = (string) $value;
                    break;
                case 'label':
                    $clone->label = (string) $value;
                    break;
                case 'type':
                    $clone->type         = (string) $value;
                    $clone->aggregatable = $clone->determineAggregatable($clone->type);
                    break;
                case 'description':
                    $clone->description = $value !== null ? (string) $value : null;
                    break;
                    // add more allowed keys as needed
                default:
                    throw new \InvalidArgumentException("Cannot set property: {$key}");
            }
        }

        return $clone;
    }
}
