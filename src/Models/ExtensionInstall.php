<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\TracksApiCredential;
use Fleetbase\Traits\HasUuid;

class ExtensionInstall extends Model
{
    use HasUuid, TracksApiCredential, HasApiModelBehavior;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'extension_installs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'extension_uuid',
        'company_uuid',
        'meta',
        'config',
        'overwrite'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
        'config' => Json::class,
        'overwrite' => Json::class,
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function extension()
    {
        return $this->belongsTo(Extension::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Fleetbase\Models\Extension
     */
    public function asExtension()
    {
        $data = $this->extension->toArray();
        $extension = new Extension($data);

        $extension->setAttribute('meta', $this->meta);
        $extension->setAttribute('uuid', $this->uuid);
        $extension->setAttribute('install_uuid', $this->uuid);
        $extension->setAttribute('installed', true);
        $extension->setAttribute('is_installed', true);

        if (is_array($this->overwrite)) {
            foreach ($this->overwrite as $key => $value) {
                $extension->setAttribute($key, $value);
            }
        }

        return $extension;
    }
}
