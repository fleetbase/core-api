<?php

namespace Fleetbase\Exceptions;

/**
 * Thrown when a purge backup cannot be verified, aborting before any rows are deleted.
 */
class PurgeBackupException extends \RuntimeException
{
    public function __construct(string $reason, ?string $disk = null, ?string $remotePath = null)
    {
        $target = $disk && $remotePath ? " [{$disk}:{$remotePath}]" : '';

        parent::__construct("Backup{$target} {$reason}; aborting purge without deleting rows.");
    }
}
