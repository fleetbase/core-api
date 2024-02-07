<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class Setting extends EloquentModel
{
    use HasApiModelBehavior;
    use Searchable;
    use Filterable;

    /**
     * Create a new instance of the model.
     *
     * @param array $attributes the attributes to set on the model
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->connection = config('fleetbase.db.connection');
    }

    /**
     * No timestamp columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['key'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'value' => Json::class,
    ];

    /**
     * Finds a system setting.
     *
     * @param string $key
     */
    public static function system($key, $defaultValue = null)
    {
        $segments   = explode('.', $key);
        $settingKey = 'system.' . $key;
        $queryKey   = 'system.' . $segments[0] . '.' . $segments[1];

        if (count($segments) >= 3) {
            // Remove first two segments, join remaining ones
            $subKey = implode('.', array_slice($segments, 2));

            // Query the database for the main setting
            $setting = static::where('key', $queryKey)->first();

            if ($setting) {
                // Get the sub value from the setting value
                return data_get($setting->value, $subKey);
            }
        } else {
            $setting = static::where('key', $settingKey)->first();

            if ($setting) {
                return $setting->value;
            }
        }

        return $defaultValue;
    }

    /**
     * Updates a system setting.
     *
     * @param string $key
     */
    public static function configureSystem($key, $value = null): ?Setting
    {
        return static::configure('system.' . $key, $value);
    }

    /**
     * Updates a system setting.
     *
     * @param string $key
     */
    public static function configure($key, $value = null): ?Setting
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'key'   => $key,
                'value' => $value,
            ]
        );
    }

    /**
     * lookup a setting and return the value.
     *
     * @return mixed|null
     */
    public static function lookup(string $key, $defaultValue = null)
    {
        $setting = static::where('key', $key)->first();

        if (!$setting) {
            return $defaultValue;
        }

        return data_get($setting, 'value', $defaultValue);
    }

    /**
     * Get a settting record by key.
     *
     * @return Setting|null
     */
    public static function getByKey(string $key)
    {
        return static::where('key', $key)->first();
    }

    public static function getBranding()
    {
        $brandingSettings = [
            'id'       => 1,
            'uuid'     => 1,
            'icon_url' => config('fleetbase.branding.icon_url'),
            'logo_url' => config('fleetbase.branding.logo_url'),
        ];
        $iconUuid         = static::where('key', 'branding.icon_uuid')->value('value');
        $logoUuid         = static::where('key', 'branding.logo_uuid')->value('value');
        $defaultTheme     = static::where('key', 'branding.default_theme')->value('value');

        // get icon file record
        if (\Illuminate\Support\Str::isUuid($iconUuid)) {
            $icon = File::where('uuid', $iconUuid)->first();

            if ($icon && $icon instanceof File) {
                $brandingSettings['icon_url'] = $icon->url;
            }
        }

        // getlogo file record
        if (\Illuminate\Support\Str::isUuid($logoUuid)) {
            $logo = File::where('uuid', $logoUuid)->first();

            if ($logo && $logo instanceof File) {
                $brandingSettings['logo_url'] = $logo->url;
            }
        }

        // set branding settings
        $brandingSettings['icon_uuid']     = $iconUuid;
        $brandingSettings['logo_uuid']     = $logoUuid;
        $brandingSettings['default_theme'] = $defaultTheme ?? 'dark';

        return $brandingSettings;
    }

    public static function getBrandingLogoUrl()
    {
        $logoUuid         = static::where('key', 'branding.logo_uuid')->value('value');

        if (\Illuminate\Support\Str::isUuid($logoUuid)) {
            $logo = File::where('uuid', $logoUuid)->first();

            if ($logo && $logo instanceof File) {
                return $logo->url;
            }
        }

        return config('fleetbase.branding.logo_url');
    }

    public static function getBrandingIconUrl()
    {
        $iconUuid         = static::where('key', 'branding.icon_uuid')->value('value');

        if (\Illuminate\Support\Str::isUuid($iconUuid)) {
            $icon = File::where('uuid', $iconUuid)->first();

            if ($icon && $icon instanceof File) {
                return $icon->url;
            }
        }

        return config('fleetbase.branding.icon_url');
    }

    public function getValue(string $key, $defaultValue = null)
    {
        return data_get($this->value, $key, $defaultValue);
    }

    public function getBoolean(string $key)
    {
        return Utils::castBoolean($this->getValue($key, false));
    }
}
