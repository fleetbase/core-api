<?php

namespace Fleetbase\Traits;

use Fleetbase\Services\FileResolverService;

/**
 * HasFileResolution Trait.
 *
 * Provides convenient file resolution methods for models with file relations.
 */
trait HasFileResolution
{
    /**
     * Resolve and set a file attribute on the model.
     *
     * @param string      $attribute The attribute name (e.g., 'photo_uuid')
     * @param mixed       $fileInput The file input to resolve
     * @param string|null $path      Optional custom path
     * @param string|null $disk      Optional storage disk
     *
     * @return bool True if successful, false otherwise
     */
    public function resolveAndSetFile(string $attribute, $fileInput, ?string $path = null, ?string $disk = null): bool
    {
        if (!$fileInput) {
            return false;
        }

        $path    = $path ?? $this->getDefaultFilePath();
        $service = app(FileResolverService::class);
        $file    = $service->resolve($fileInput, $path, $disk);

        if ($file) {
            $this->{$attribute} = $file->uuid;

            return true;
        }

        return false;
    }

    /**
     * Get the default file path for this model.
     */
    protected function getDefaultFilePath(): string
    {
        $company = session('company');
        $table   = $this->getTable();

        return "uploads/{$company}/{$table}";
    }

    /**
     * Resolve and immediately save a file attribute.
     *
     * @param string      $attribute The attribute name
     * @param mixed       $fileInput The file input to resolve
     * @param string|null $path      Optional custom path
     * @param string|null $disk      Optional storage disk
     *
     * @return bool True if successful and saved, false otherwise
     */
    public function resolveSetAndSaveFile(string $attribute, $fileInput, ?string $path = null, ?string $disk = null): bool
    {
        if ($this->resolveAndSetFile($attribute, $fileInput, $path, $disk)) {
            return $this->save();
        }

        return false;
    }
}
