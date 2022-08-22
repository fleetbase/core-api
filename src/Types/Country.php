<?php

namespace Fleetbase\Types;

use Fleetbase\Exceptions\CountryException;
use Fleetbase\Support\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Countries\Package\Countries;
use JsonSerializable;

class Country implements JsonSerializable
{
    /**
     * ISO-3166-1 Alpha-3 Code.
     *
     * @var string
     */
    protected $code;

    /**
     * The country name.
     *
     * @var string
     */
    protected $name;

    /**
     * Country Data.
     *
     * @var array
     */
    protected $data = [];

    public function __construct($code)
    {
        if (is_string($code) && !static::has($code)) {
            throw new CountryException("Country not found: \"{$code}\"");
        }

        if (is_object($code) && method_exists($code, 'toArray')) {
            $code = $code->toArray();
        }

        if (is_array($code)) {
            $data = $code;
            $code = isset($data['cca3']) ? $data['cca3'] : null;
        } else {
            $data = static::all()->where('cca2', $code)->first();
        }

        $this->name = $data['name'] = Utils::get($data, 'name.common');
        $this->code = $code;
        $this->data = $data;

        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function __call($name, $args)
    {
        if ($name === 'getCurrency') {
            $currency = Arr::first($this->getCurrencies());

            if (is_array($currency) && isset($currency['name'])) {
                $currency = $currency['name'];
            }

            return $currency;
        }

        if (Str::startsWith($name, 'get')) {
            $property = Str::snake(Str::replaceFirst('get', '', $name));

            if (isset($this->{$property})) {
                return $this->{$property};
            }
        }

        return null;
    }

    /**
     * Check currency existence (within the class)
     *
     * @access public
     * @return bool
     */
    public static function has(?string $code): bool
    {
        if (!is_string($code)) {
            return false;
        }

        return static::all()->where('cca2', $code)->exists();
    }

    public static function all()
    {
        return new Collection(
            array_map(
                function ($countryData) {
                    return new static($countryData);
                },
                Countries::all()->toArray()
            )
        );
    }

    /**
     * Finds the first currency of which the callback returns true.
     * 
     * @param callable|null $callback
     * @return \Fleetbase\Support\Currency
     */
    public static function first(?callable $callback = null)
    {
        return static::all()->first($callback);
    }

    /**
     * Filter currencies by providing a callback.
     * 
     * @param callable|null $callback
     * @return \Illuminate\Support\Collection 
     */
    public static function filter(?callable $callback = null)
    {
        return static::all()->filter($callback)->values();
    }

    /**
     * Get the collection of items as JSON.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Converts data to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return (array) $this->data;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
