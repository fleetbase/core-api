<?php

namespace Fleetbase\Traits;

trait ForcesCommands
{
    /**
     * Handles confirmation logic with support for the --force flag.
     *
     * @param string $message the confirmation message to display
     *
     * @return bool true if the action is confirmed, false otherwise
     */
    protected function confirmOrForce(string $message): bool
    {
        // If the --force flag is present, bypass confirmation
        if ($this->option('force')) {
            $this->warn('Force flag detected: Skipping confirmation.');

            return true;
        }

        // Otherwise, prompt for confirmation
        return $this->confirm($message);
    }
}
