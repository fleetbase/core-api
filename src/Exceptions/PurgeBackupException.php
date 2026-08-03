<?php

namespace Fleetbase\Exceptions;

/**
 * Thrown when a purge backup cannot be verified, aborting before any rows are deleted.
 */
class PurgeBackupException extends \RuntimeException
{
    protected ?string $disk;
    protected ?string $remotePath;

    public function __construct(string $reason, ?string $disk = null, ?string $remotePath = null)
    {
        $this->disk       = $disk;
        $this->remotePath = $remotePath;

        $target = $disk && $remotePath ? " [{$disk}:{$remotePath}]" : '';

        parent::__construct("Backup{$target} {$reason}; aborting purge without deleting rows.");
    }

    public function getDisk(): ?string
    {
        return $this->disk;
    }

    public function getRemotePath(): ?string
    {
        return $this->remotePath;
    }
}
