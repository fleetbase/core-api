<?php

namespace Fleetbase\Services\Scheduling;

use Fleetbase\Models\ScheduleConstraint;
use Fleetbase\Models\ScheduleItem;

class ConstraintService
{
    /**
     * Registry of constraint handlers.
     *
     * @var array
     */
    protected $constraintHandlers = [];

    /**
     * Register a constraint handler for a specific entity type.
     */
    public function register(string $entityType, string $handlerClass): void
    {
        if (!isset($this->constraintHandlers[$entityType])) {
            $this->constraintHandlers[$entityType] = [];
        }

        $this->constraintHandlers[$entityType][] = $handlerClass;
    }

    /**
     * Validate a schedule item against all applicable constraints.
     */
    public function validate(ScheduleItem $item): array
    {
        $violations = [];

        // Get the assignee type to determine which constraint handlers to use
        $assigneeType = $item->assignee_type;

        if (!$assigneeType || !isset($this->constraintHandlers[$assigneeType])) {
            return $violations;
        }

        // Run all registered constraint handlers for this entity type
        foreach ($this->constraintHandlers[$assigneeType] as $handlerClass) {
            $handler = app($handlerClass);

            if (method_exists($handler, 'validate')) {
                $result = $handler->validate($item);

                if ($result && !$result->passed()) {
                    $violations = array_merge($violations, $result->getViolations());
                }
            }
        }

        // Log violations if any
        if (!empty($violations)) {
            activity()
                ->performedOn($item)
                ->causedBy(auth()->user())
                ->event('schedule.constraint_violated')
                ->withProperties(['violations' => $violations])
                ->log('Schedule constraint violated');

            event(new \Fleetbase\Events\ScheduleConstraintViolated($item, $violations));
        }

        return $violations;
    }

    /**
     * Get active constraints for a specific subject.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getConstraintsForSubject(string $subjectType, string $subjectUuid)
    {
        return ScheduleConstraint::forSubject($subjectType, $subjectUuid)
            ->active()
            ->orderByPriority()
            ->get();
    }

    /**
     * Get active constraints by type.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getConstraintsByType(string $type)
    {
        return ScheduleConstraint::byType($type)
            ->active()
            ->orderByPriority()
            ->get();
    }

    /**
     * Check if a specific constraint is satisfied.
     */
    public function checkConstraint(ScheduleItem $item, string $constraintKey): bool
    {
        $violations = $this->validate($item);

        foreach ($violations as $violation) {
            if (isset($violation['constraint_key']) && $violation['constraint_key'] === $constraintKey) {
                return false;
            }
        }

        return true;
    }
}
