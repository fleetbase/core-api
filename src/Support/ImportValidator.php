<?php

namespace Fleetbase\Support;

use Fleetbase\Models\File;
use Illuminate\Support\Str;

class ImportValidator
{
    protected array $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];

    public function getValidFileTypes(): array
    {
        return $this->validFileTypes;
    }

    public function validateFile(File $file): bool
    {
        return !Str::endsWith($file->path, $this->validFileTypes);
    }

    public function validateFilePath(string $path): bool
    {
        return !Str::endsWith($path, $this->validFileTypes);
    }

    public static function isValid(File $file): bool
    {
        return (new static())->validateFile($file);
    }
}
