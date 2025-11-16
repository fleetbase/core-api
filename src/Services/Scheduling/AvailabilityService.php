<?php

namespace Fleetbase\Services\Scheduling;

use Fleetbase\Models\ScheduleAvailability;
use Illuminate\Support\Facades\DB;

class AvailabilityService
{
    /**
     * Set availability for a subject.
     */
    public function setAvailability(array $data): ScheduleAvailability
    {
        return DB::transaction(function () use ($data) {
            $availability = ScheduleAvailability::create($data);

            activity()
                ->performedOn($availability)
                ->causedBy(auth()->user())
                ->event('availability.set')
                ->withProperties($data)
                ->log('Availability set');

            return $availability;
        });
    }

    /**
     * Check if a subject is available during a time range.
     */
    public function checkAvailability(string $subjectType, string $subjectUuid, string $startAt, string $endAt): bool
    {
        // Check for any unavailability periods that overlap with the requested time range
        $unavailability = ScheduleAvailability::forSubject($subjectType, $subjectUuid)
            ->unavailable()
            ->withinTimeRange($startAt, $endAt)
            ->exists();

        return !$unavailability;
    }

    /**
     * Get availability for a subject within a time range.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailability(string $subjectType, string $subjectUuid, string $startAt, string $endAt)
    {
        return ScheduleAvailability::forSubject($subjectType, $subjectUuid)
            ->withinTimeRange($startAt, $endAt)
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Get available resources of a specific type within a time range.
     */
    public function getAvailableResources(string $subjectType, string $startAt, string $endAt, array $filters = []): array
    {
        // Get all subjects of the specified type that are unavailable during the time range
        $unavailableSubjects = ScheduleAvailability::where('subject_type', $subjectType)
            ->unavailable()
            ->withinTimeRange($startAt, $endAt)
            ->pluck('subject_uuid')
            ->unique()
            ->toArray();

        // This would need to be extended based on the actual subject model
        // For now, return the list of unavailable subject UUIDs
        return [
            'unavailable_subjects' => $unavailableSubjects,
        ];
    }

    /**
     * Delete availability.
     */
    public function deleteAvailability(ScheduleAvailability $availability): bool
    {
        return DB::transaction(function () use ($availability) {
            activity()
                ->performedOn($availability)
                ->causedBy(auth()->user())
                ->event('availability.deleted')
                ->log('Availability deleted');

            return $availability->delete();
        });
    }
}
