<?php

namespace Fleetbase\Services\Scheduling;

use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Support\Facades\DB;

class ScheduleService
{
    /**
     * Create a new schedule.
     */
    public function createSchedule(array $data): Schedule
    {
        return DB::transaction(function () use ($data) {
            $schedule = Schedule::create($data);

            activity()
                ->performedOn($schedule)
                ->causedBy(auth()->user())
                ->event('schedule.created')
                ->withProperties($data)
                ->log('Schedule created');

            event(new \Fleetbase\Events\ScheduleCreated($schedule));

            return $schedule;
        });
    }

    /**
     * Update an existing schedule.
     */
    public function updateSchedule(Schedule $schedule, array $data): Schedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update($data);

            activity()
                ->performedOn($schedule)
                ->causedBy(auth()->user())
                ->event('schedule.updated')
                ->withProperties($data)
                ->log('Schedule updated');

            event(new \Fleetbase\Events\ScheduleUpdated($schedule));

            return $schedule->fresh();
        });
    }

    /**
     * Delete a schedule.
     */
    public function deleteSchedule(Schedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            activity()
                ->performedOn($schedule)
                ->causedBy(auth()->user())
                ->event('schedule.deleted')
                ->log('Schedule deleted');

            event(new \Fleetbase\Events\ScheduleDeleted($schedule));

            return $schedule->delete();
        });
    }

    /**
     * Create a new schedule item.
     */
    public function createScheduleItem(array $data): ScheduleItem
    {
        return DB::transaction(function () use ($data) {
            $item = ScheduleItem::create($data);

            activity()
                ->performedOn($item)
                ->causedBy(auth()->user())
                ->event('schedule_item.created')
                ->withProperties($data)
                ->log('Schedule item created');

            event(new \Fleetbase\Events\ScheduleItemCreated($item));

            return $item;
        });
    }

    /**
     * Update an existing schedule item.
     */
    public function updateScheduleItem(ScheduleItem $item, array $data): ScheduleItem
    {
        return DB::transaction(function () use ($item, $data) {
            $item->update($data);

            activity()
                ->performedOn($item)
                ->causedBy(auth()->user())
                ->event('schedule_item.updated')
                ->withProperties($data)
                ->log('Schedule item updated');

            event(new \Fleetbase\Events\ScheduleItemUpdated($item));

            return $item->fresh();
        });
    }

    /**
     * Delete a schedule item.
     */
    public function deleteScheduleItem(ScheduleItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            activity()
                ->performedOn($item)
                ->causedBy(auth()->user())
                ->event('schedule_item.deleted')
                ->log('Schedule item deleted');

            event(new \Fleetbase\Events\ScheduleItemDeleted($item));

            return $item->delete();
        });
    }

    /**
     * Assign a schedule item to an assignee.
     */
    public function assignScheduleItem(ScheduleItem $item, string $assigneeType, string $assigneeUuid): ScheduleItem
    {
        return DB::transaction(function () use ($item, $assigneeType, $assigneeUuid) {
            $item->update([
                'assignee_type' => $assigneeType,
                'assignee_uuid' => $assigneeUuid,
            ]);

            activity()
                ->performedOn($item)
                ->causedBy(auth()->user())
                ->event('schedule_item.assigned')
                ->withProperties([
                    'assignee_type' => $assigneeType,
                    'assignee_uuid' => $assigneeUuid,
                ])
                ->log('Schedule item assigned');

            event(new \Fleetbase\Events\ScheduleItemAssigned($item));

            return $item->fresh();
        });
    }

    /**
     * Get schedules for a specific subject.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSchedulesForSubject(string $subjectType, string $subjectUuid, array $filters = [])
    {
        $query = Schedule::forSubject($subjectType, $subjectUuid);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->withinDateRange($filters['start_date'], $filters['end_date']);
        }

        return $query->with('items')->get();
    }

    /**
     * Get schedule items for a specific assignee.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getScheduleItemsForAssignee(string $assigneeType, string $assigneeUuid, array $filters = [])
    {
        $query = ScheduleItem::forAssignee($assigneeType, $assigneeUuid);

        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (isset($filters['start_at']) && isset($filters['end_at'])) {
            $query->withinTimeRange($filters['start_at'], $filters['end_at']);
        }

        return $query->with(['schedule', 'assignee', 'resource'])->get();
    }
}
