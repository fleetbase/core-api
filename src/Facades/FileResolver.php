<?php

namespace Fleetbase\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * FileResolver Facade.
 *
 * Provides convenient static-like access to the FileResolverService.
 *
 * @method static \Fleetbase\Models\File|null resolve($file, ?string $path = 'uploads', ?string $disk = null)
 * @method static array                       resolveMany(array $files, ?string $path = 'uploads', ?string $disk = null)
 * @method static bool                        resolveAndAttach($file, $model, string $attribute, ?string $path = 'uploads', ?string $disk = null)
 *
 * @see \Fleetbase\Services\FileResolverService
 */
class FileResolver extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Fleetbase\Services\FileResolverService::class;
    }
}
