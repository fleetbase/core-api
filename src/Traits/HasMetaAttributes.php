<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;
use Illuminate\Support\Arr;

trait HasMetaAttributes
{
    /**
     * Sets a meta-data property with a value.
     *
     * $resource->setMeta('id', '1846473');
     * $resource->setMeta('customer.name', 'John Doe');
     *
     * {
     *      "id": "1846473",
     *      "customer": {
     *          "name": "John Doe"
     *      }
     * }
     *
     * @return \Fleetbase\Models\Model
     */
    public function setMeta($keys, $value)
    {
        if (is_array($keys)) {
            foreach ($keys as $key => $value) {
                $this->setMeta($key, $value);
            }

            return $this;
        }

        $value = static::prepareValue($value);
        $meta  = $this->getAllMeta();
        $meta  = Utils::set($meta, $keys, $value);

        $this->setAttribute('meta', $meta);

        return $this;
    }

    /**
     * Get a meta-data property.
     *
     * @param string|array $key
     * @param [type] $defaultValue
     *
     * @return void
     */
    public function getMeta($key = null, $defaultValue = null)
    {
        $meta = $this->getAllMeta();

        if ($key === null) {
            return $meta;
        }

        return Utils::get($meta, $key, $defaultValue);
    }

    public function getMetaAttributes($properties = [])
    {
        $metaAttributes = [];

        foreach ($properties as $key) {
            Utils::set($metaAttributes, $key, $this->getMeta($key));
        }

        return $metaAttributes;
    }

    /**
     * Check if property exists in meta by key.
     *
     * @return bool
     */
    public function hasMeta($keys)
    {
        if (is_array($keys)) {
            return Arr::every($keys, function ($key) {
                return $this->hasMeta($key);
            });
        }

        return in_array($keys, array_keys($this->getAttribute('meta') ?? []));
    }

    /**
     * Update meta with database write.
     *
     * @param string|array $key
     * @param mixed|null   $value
     *
     * @return $this
     */
    public function updateMeta($key, $value = null)
    {
        $meta = $this->getAttribute('meta');

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->updateMeta($k, $v);
            }

            return $this;
        }

        $meta[$key] = static::prepareValue($value);

        $this->setAttribute('meta', $meta);

        return $this->update(['meta' => $meta]);
    }

    /**
     * Checks if key is missing from meta-data.
     *
     * @param [type] $key
     *
     * @return void
     */
    public function missingMeta($key)
    {
        return !$this->hasMeta($key);
    }

    /**
     * Checks if meta-data key value is true.
     *
     * @param string $key
     *
     * @return bool
     */
    public function isMeta($key)
    {
        return $this->getMeta($key) === true;
    }

    /**
     * Prepares value for meta-data insertion.
     *
     * @return void
     */
    private static function prepareValue($value)
    {
        if (Utils::isUnicodeString($value)) {
            $value = Utils::unicodeDecode($value);
        }

        return $value;
    }

    public function getAllMeta()
    {
        return $this->getAttribute('meta') ?? [];
    }
}
