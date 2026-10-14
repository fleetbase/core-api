<?php

namespace Fleetbase\Services\DatabaseBackup;

/**
 * A backup run, or one database's dump within it, could not be completed.
 */
class DatabaseBackupException extends \RuntimeException
{
}
