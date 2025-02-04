<?php

namespace Fleetbase\Traits;

trait HasSessionAttributes
{
    /**
     * An array of column names that are not affected or automatically updated
     * based on the current user's session. These columns remain consistent
     * regardless of the user's session context.
     *
     * @var array
     */
    protected $sessionAgnosticColumns = [];

    /**
     * Get the session-agnostic columns for the model.
     */
    public function getSessionAgnosticColumns(): array
    {
        return $this->sessionAgnosticColumns;
    }

    /**
     * Set the session-agnostic columns for the model.
     *
     * @return $this
     */
    public function setSessionAgnosticColumns(array $columns): self
    {
        $this->sessionAgnosticColumns = $columns;

        return $this;
    }

    /**
     * Determine if a column is session-agnostic.
     */
    public function isSessionAgnosticColumn(string $column): bool
    {
        return in_array($column, $this->sessionAgnosticColumns, true);
    }
}
