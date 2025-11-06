<?php

namespace Fleetbase\Traits;

use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Support\Http;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trait HasCustomFields.
 *
 * Intended for Fleetbase models that can have CustomField definitions and CustomFieldValue records
 * attached via a morph relationship (subject_type + subject_uuid).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasCustomFields
{
    /** @var array<string,CustomField|null> */
    protected array $customFieldCache = [];

    /** @var array<string,mixed> */
    protected array $customFieldValuesCache = [];

    /** @var array<string> preserved keys */
    private const RESERVED_CF_KEYS = ['custom_fields', 'custom_field_values'];

    /**
     * Custom field definitions attached directly to this subject.
     *
     * @return HasMany<CustomField>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class, 'subject_uuid')->orderBy('order');
    }

    /**
     * Custom field values for this subject.
     *
     * @return HasMany<CustomFieldValue>
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'subject_uuid');
    }

    /**
     * Normalize a user-provided key into canonical "name" format.
     */
    protected function normalizeCustomFieldKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace('_', ' ', $key);

        return Str::slug($key);
    }

    /**
     * Produce a label-variant for lookups (e.g. "Order Number").
     */
    protected function labelFromKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace(['_', '-'], ' ', $key);

        return Str::title($key);
    }

    /**
     * Retrieve a CustomField by key (matches by normalized name OR label).
     * Uses relation if loaded; falls back to DB query with proper grouping.
     */
    public function getCustomField(string $key): ?CustomField
    {
        $name     = $this->normalizeCustomFieldKey($key);
        $label    = $this->labelFromKey($key);
        $cacheKey = "field:{$name}|{$label}";

        if (array_key_exists($cacheKey, $this->customFieldCache)) {
            return $this->customFieldCache[$cacheKey];
        }

        $this->loadMissing('customFields');

        $field = $this->customFields
            ->first(fn (CustomField $f) => $f->name === $name || $f->label === $label);

        if (!$field) {
            $field = $this->customFields()
                ->where(fn ($q) => $q->where('name', $name)->orWhere('label', $label))
                ->first();
        }

        return $this->customFieldCache[$cacheKey] = $field;
    }

    /**
     * Check if a custom field exists (by name or label).
     */
    public function hasCustomField(string $key): bool
    {
        return $this->getCustomField($key) !== null;
    }

    /**
     * Alias for hasCustomField.
     */
    public function isCustomField(string $key): bool
    {
        return $this->hasCustomField($key);
    }

    /**
     * Returns true if ANY of the given keys exist.
     *
     * @param array<int,string> $keys
     */
    public function hasAnyCustomFields(array $keys): bool
    {
        foreach ($keys as $k) {
            if ($this->hasCustomField($k)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if ALL of the given keys exist.
     *
     * @param array<int,string> $keys
     */
    public function hasAllCustomFields(array $keys): bool
    {
        foreach ($keys as $k) {
            if (!$this->hasCustomField($k)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the CustomFieldValue model for a given CustomField.
     */
    public function getCustomFieldValue(CustomField $customField): ?CustomFieldValue
    {
        $this->loadMissing('customFieldValues');

        return $this->customFieldValues
            ->firstWhere('custom_field_uuid', $customField->uuid)
            ?? $this->customFieldValues()
                    ->where('custom_field_uuid', $customField->uuid)
                    ->first();
    }

    /**
     * Get a custom field's cast value by key, with optional default.
     * (Casting is handled by CustomFieldValue::$casts['value' => CustomValue::class]).
     */
    public function getCustomFieldValueByKey(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'value:' . strtolower($key);

        if (array_key_exists($cacheKey, $this->customFieldValuesCache)) {
            return $this->customFieldValuesCache[$cacheKey];
        }

        $field = $this->getCustomField($key);
        if (!$field) {
            return $this->customFieldValuesCache[$cacheKey] = $default;
        }

        $cfv   = $this->getCustomFieldValue($field);
        $value = $cfv?->value;

        return $this->customFieldValuesCache[$cacheKey] = ($value ?? $default);
    }

    /**
     * Get a custom field's RAW (un-cast) value by key, with default.
     */
    public function getRawCustomFieldValueByKey(string $key, mixed $default = null): mixed
    {
        $field = $this->getCustomField($key);
        if (!$field) {
            return $default;
        }

        $cfv = $this->getCustomFieldValue($field);
        if (!$cfv) {
            return $default;
        }

        // getRawOriginal respects the underlying stored representation before casts
        return $cfv->getRawOriginal('value') ?? $default;
    }

    /**
     * Return all custom field values keyed by normalized snake label (e.g., "order_number").
     * Uses CustomFieldValue::getCustomFieldLabelAttribute() to derive the label.
     *
     * @return array<string,mixed>
     */
    public function getCustomFieldValues(bool $snakeKeys = true): array
    {
        $this->loadMissing('customFieldValues.customField');

        $out = [];
        foreach ($this->customFieldValues as $cfv) {
            /** @var CustomFieldValue $cfv */
            $label = $cfv->custom_field_label; // accessor on model; may be null if relation missing
            $key   = $label ? ($snakeKeys ? Str::snake(Str::lower($label)) : $label) : null;

            if ($key !== null) {
                // $cfv->value is already cast via CustomValue
                $out[$key] = $cfv->value;
            }
        }

        return $out;
    }

    /**
     * Return the list of keys (normalized snake case) for all available custom field values.
     *
     * @return array<int,string>
     */
    public function getCustomFieldKeys(): array
    {
        return array_keys($this->getCustomFieldValues(true));
    }

    /**
     * Insert custom field values into an attributes array at a chosen position.
     * - 'start'  — prepend
     * - 'end'    — append
     * - 'middle' — insert between left/right halves (default)
     * - integer  — explicit position (0-based).
     *
     * Values are inserted as a flattened associative map.
     */
    public function withCustomFields(array $attributes = [], int|string $position = 'middle'): array
    {
        $inserts = $this->getCustomFieldValues(true);

        // Check for collisions and rename them with a leading underscore
        foreach (self::RESERVED_CF_KEYS as $reserved) {
            if (array_key_exists($reserved, $inserts)) {
                $inserts['_' . $reserved] = $inserts[$reserved];
                unset($inserts[$reserved]);
            }
        }

        // Context: internal/public
        $isInternal = Http::isInternalRequest();
        $isPublic   = Http::isPublicRequest();

        if ($isInternal) {
            $this->loadMissing('customFieldValues.customField');
            $inserts['custom_field_values'] = $this->customFieldValues;
        }
        if ($isPublic) {
            $inserts['custom_fields'] = $this->getCustomFieldKeys();
        }

        if ($position === 'start') {
            return $inserts + $attributes; // preserve keys
        }

        if ($position === 'end') {
            return $attributes + $inserts;
        }

        if (is_int($position)) {
            return $this->insertAssocAt($attributes, $inserts, max(0, $position));
        }

        // middle
        $middle = (int) floor(count($attributes) / 2);

        return $this->insertAssocAt($attributes, $inserts, $middle);
    }

    /**
     * Create or update a custom field VALUE by field object or key (name/label).
     * Does NOT create the CustomField definition unless $createMissingField=true.
     * Sets subject type/uuid and company_uuid if available on the subject.
     */
    public function setCustomFieldValue(string|CustomField $fieldOrKey, mixed $value, bool $createMissingField = false): ?CustomFieldValue
    {
        /** @var Model $this */
        $field = $fieldOrKey instanceof CustomField ? $fieldOrKey : $this->getCustomField($fieldOrKey);

        if (!$field && $createMissingField) {
            // Minimal safe definition; caller can extend if needed.
            $name  = $this->normalizeCustomFieldKey((string) $fieldOrKey);
            $label = $this->labelFromKey((string) $fieldOrKey);

            $field = $this->customFields()->create([
                'name'         => $name,
                'label'        => $label,
                'type'         => 'text',
                'component'    => 'text',
                'required'     => false,
                'editable'     => true,
                'order'        => 0,
                'subject_type' => $this->getMorphClass(),
                'subject_uuid' => $this->getAttribute('uuid'),
                'company_uuid' => $this->getAttribute('company_uuid') ?? session('company'),
            ]);
            // bust definition cache for subsequent lookups
            $this->customFieldCache = [];
        }

        if (!$field) {
            return null;
        }

        $this->loadMissing('customFieldValues');

        /** @var CustomFieldValue $cfv */
        $cfv = $this->customFieldValues()
            ->firstOrNew([
                'custom_field_uuid' => $field->uuid,
                'subject_uuid'      => $this->getAttribute('uuid'),
            ]);

        $cfv->subject_type   = $this->getMorphClass();
        $cfv->value          = $value; // will be cast via CustomValue
        $cfv->company_uuid   = $this->getAttribute('company_uuid') ?? $cfv->company_uuid;

        method_exists($cfv, 'saveQuietly') ? $cfv->saveQuietly() : $cfv->save();

        // invalidate cached values (definition cache remains valid)
        $this->customFieldValuesCache = [];

        return $cfv;
    }

    /**
     * Bulk set custom field values from an associative array (label/name => value).
     * Skips non-existent fields unless $createMissingFields = true.
     *
     * @param array<string,mixed> $keyValueMap
     *
     * @return int number of values written
     */
    public function setCustomFields(array $keyValueMap, bool $createMissingFields = false): int
    {
        $written = 0;

        foreach ($keyValueMap as $key => $val) {
            $cfv = $this->setCustomFieldValue((string) $key, $val, $createMissingFields);
            if ($cfv) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Sync values so only keys in the given map remain; others are removed.
     *
     * @param array<string,mixed> $keyValueMap
     *
     * @return array{written:int,deleted:int}
     */
    public function syncCustomFields(array $keyValueMap, bool $createMissingFields = false): array
    {
        $this->loadMissing('customFieldValues.customField');

        $existing     = $this->getCustomFieldValues(true);
        $target       = array_map(fn ($k) => Str::snake(Str::lower($this->labelFromKey($k))), array_keys($keyValueMap));
        $existingKeys = array_keys($existing);

        $written = $this->setCustomFields($keyValueMap, $createMissingFields);

        // delete values not in target
        $toDelete = array_diff($existingKeys, $target);
        $deleted  = 0;

        foreach ($toDelete as $snakeKey) {
            // map back to actual field by comparing labels
            $this->loadMissing('customFieldValues.customField');

            $victims = $this->customFieldValues->filter(function (CustomFieldValue $cfv) use ($snakeKey) {
                $label = $cfv->custom_field_label;
                $key   = $label ? Str::snake(Str::lower($label)) : null;

                return $key === $snakeKey;
            });

            foreach ($victims as $cfv) {
                $deleted += (int) $cfv->delete();
            }
        }

        if ($deleted > 0) {
            $this->customFieldValuesCache = [];
        }

        return ['written' => $written, 'deleted' => $deleted];
    }

    /**
     * Delete a single value by field object or key (does not remove the definition).
     */
    public function forgetCustomField(string|CustomField $fieldOrKey): bool
    {
        $field = $fieldOrKey instanceof CustomField ? $fieldOrKey : $this->getCustomField($fieldOrKey);
        if (!$field) {
            return false;
        }

        $deleted = $this->customFieldValues()
            ->where('custom_field_uuid', $field->uuid)
            ->delete() > 0;

        if ($deleted) {
            $this->customFieldValuesCache = [];
        }

        return $deleted;
    }

    /**
     * Remove all custom field values for this subject.
     *
     * @return int number of rows deleted
     */
    public function clearCustomFields(): int
    {
        $count                        = $this->customFieldValues()->delete();
        $this->customFieldValuesCache = [];

        return $count;
    }

    /**
     * Insert an associative array into another associative array at position $pos.
     * Keys preserved. Helper for withCustomFields().
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $inserts
     *
     * @return array<string,mixed>
     */
    protected function insertAssocAt(array $base, array $inserts, int $pos): array
    {
        $left  = array_slice($base, 0, $pos, true);
        $right = array_slice($base, $pos, null, true);

        return $left + $inserts + $right;
    }

    /**
     * Synchronize custom field values for the current model instance.
     *
     * Behavior:
     * - If a payload row contains a `uuid`, that exact CustomFieldValue record is updated.
     * - If no `uuid` is provided, the record is updated or created based on
     *   (`custom_field_uuid`, `subject_uuid`) uniqueness.
     * - If `treat_null_as_delete` is true and a payload value is null, the corresponding
     *   record is deleted.
     * - If `delete_missing` is true, any existing custom field values not present in
     *   the incoming payload are deleted.
     * - Ensures the model’s `customFieldValues` relation is refreshed at the end so
     *   subsequent API responses return updated values.
     *
     * Options:
     * - `persist` (bool, default true): if false, no DB writes are performed and only a dry-run
     *   summary of intended changes is returned.
     * - `treat_null_as_delete` (bool, default false): if true, null values in the payload delete
     *   the corresponding record.
     * - `delete_missing` (bool, default false): if true, values not present in the payload are deleted.
     *
     * @param array $payload Array of field values in the shape:
     *                       [
     *                       'uuid'             => (string|null) existing value UUID,
     *                       'custom_field_uuid'=> (string) required,
     *                       'value'            => (mixed) value to store,
     *                       'value_type'       => (string|null),
     *                       ]
     * @param array $options options to control persistence and delete behavior
     *
     * @return array Summary of operations performed:
     *               [
     *               'created' => (int) number of new values inserted,
     *               'updated' => (int) number of existing values updated,
     *               'deleted' => (int) number of values deleted,
     *               'skipped' => (int) number of rows skipped,
     *               ]
     *
     * @throws \Throwable if a database transaction fails
     */
    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        /** @var Model $this */
        $opts = array_merge([
            'delete_missing'       => false,
            'treat_null_as_delete' => false,
            'persist'              => true,
        ], $options);

        $subjectUuid = (string) $this->getAttribute('uuid');
        $companyUuid = $this->getAttribute('company_uuid') ?? session('company');
        $subjectType = (string) $this->getMorphClass();

        $created = $updated = $deleted = $skipped = 0;

        // normalize incoming; keep track of which field UUIDs we touched
        $incoming = [];
        foreach ($payload as $row) {
            $fieldUuid = Arr::get($row, 'custom_field_uuid');
            if (!is_string($fieldUuid) || $fieldUuid === '') {
                $skipped++;
                continue;
            }
            $incoming[] = $fieldUuid;
        }

        if (!$opts['persist']) {
            // dry-run: just report intent (very lightweight)
            return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => $skipped];
        }

        DB::transaction(function () use (
            $payload, $opts, $subjectUuid, $companyUuid, $subjectType,
            &$created, &$updated, &$deleted, &$skipped
        ) {
            // cache existing values for this subject, keyed by both uuid and custom_field_uuid
            $existing = $this->customFieldValues()->get();
            $byUuid   = $existing->keyBy('uuid');
            $byField  = $existing->keyBy('custom_field_uuid');

            foreach ($payload as $row) {
                $valUuid   = Arr::get($row, 'uuid');
                $fieldUuid = Arr::get($row, 'custom_field_uuid');
                $value     = Arr::get($row, 'value');
                $valueType = Arr::get($row, 'value_type');

                if (!is_string($fieldUuid) || $fieldUuid === '') {
                    $skipped++;
                    continue;
                }

                // delete if requested and value is null
                if ($opts['treat_null_as_delete'] && $value === null) {
                    $target = $valUuid ? $byUuid->get($valUuid) : $byField->get($fieldUuid);
                    if ($target) {
                        $target->delete();
                        $deleted++;
                        $byUuid->forget($target->uuid);
                        $byField->forget($fieldUuid);
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                if ($valUuid && ($target = $byUuid->get($valUuid))) {
                    // UPDATE by row UUID
                    $changed = ($target->value !== $value) || ($target->value_type !== $valueType);
                    if ($changed) {
                        $target->fill([
                            'value'      => $value,
                            'value_type' => $valueType,
                        ]);
                        $target->company_uuid = $companyUuid ?? $target->company_uuid;
                        $target->subject_type = $subjectType;
                        method_exists($target, 'saveQuietly') ? $target->saveQuietly() : $target->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    // keep maps fresh
                    $byField->put($fieldUuid, $target);
                    continue;
                }

                // No UUID match: UPDATE/CREATE by (custom_field_uuid, subject_uuid)
                $target = $byField->get($fieldUuid);
                if ($target) {
                    $changed = ($target->value !== $value) || ($target->value_type !== $valueType);
                    if ($changed) {
                        $target->fill([
                            'value'      => $value,
                            'value_type' => $valueType,
                        ]);
                        $target->company_uuid = $companyUuid ?? $target->company_uuid;
                        $target->subject_type = $subjectType;
                        method_exists($target, 'saveQuietly') ? $target->saveQuietly() : $target->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // CREATE
                    $new = $this->customFieldValues()->make([
                        'custom_field_uuid' => $fieldUuid,
                        'subject_uuid'      => $subjectUuid,
                        'subject_type'      => $subjectType,
                        'company_uuid'      => $companyUuid,
                        'value'             => $value,
                        'value_type'        => $valueType,
                    ]);
                    $new->forceFill(['uuid' => CustomFieldValue::generateUuid()]);
                    method_exists($new, 'saveQuietly') ? $new->saveQuietly() : $new->save();
                    $created++;
                    $byUuid->put($new->uuid, $new);
                    $byField->put($fieldUuid, $new);
                }
            }

            if ($opts['delete_missing']) {
                $incomingFields = collect($payload)->pluck('custom_field_uuid')->filter()->unique();
                $toDelete       = $byField->keys()->diff($incomingFields);
                foreach ($toDelete as $fieldUuid) {
                    if ($victim = $byField->get($fieldUuid)) {
                        $victim->delete();
                        $deleted++;
                    }
                }
            }
        });

        // always refresh relation so responses reflect latest DB values
        $this->unsetRelation('customFieldValues');
        $this->load('customFieldValues');
        $this->customFieldValuesCache = [];

        return compact('created', 'updated', 'deleted', 'skipped');
    }
}
