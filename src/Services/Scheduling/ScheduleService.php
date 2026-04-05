<?php

namespace Fleetbase\Services\Scheduling;

use Carbon\Carbon;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleService
{
    /**
     * The number of days ahead to materialize shifts for.
     * The rolling window will always extend at least this many days from today.
     */
    const MATERIALIZATION_WINDOW_DAYS = 60;

    // ─── Schedule CRUD ────────────────────────────────────────────────────────

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
     * Delete a schedule and all its items.
     */
    public function deleteSchedule(Schedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            $schedule->items()->delete();
            $schedule->templates()->delete();
            $schedule->exceptions()->delete();

            activity()
                ->performedOn($schedule)
                ->causedBy(auth()->user())
                ->event('schedule.deleted')
                ->log('Schedule deleted');

            event(new \Fleetbase\Events\ScheduleDeleted($schedule));

            return $schedule->delete();
        });
    }

    // ─── ScheduleItem CRUD ────────────────────────────────────────────────────

    /**
     * Create a new standalone (non-recurring) schedule item.
     */
    public function createScheduleItem(array $data): ScheduleItem
    {
        return DB::transaction(function () use ($data) {
            $item = ScheduleItem::create($data);

            // Activate the parent schedule if it is still in draft state
            if (!empty($data['schedule_uuid'])) {
                Schedule::where('uuid', $data['schedule_uuid'])
                    ->where('status', 'draft')
                    ->update(['status' => 'active']);
            }

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
     * If the item was generated from a template, it will be automatically flagged as an exception.
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

    // ─── ScheduleException CRUD ───────────────────────────────────────────────

    /**
     * Create a new schedule exception (time off request, sick leave, etc.).
     */
    public function createException(array $data): ScheduleException
    {
        return DB::transaction(function () use ($data) {
            $exception = ScheduleException::create($data);

            activity()
                ->performedOn($exception)
                ->causedBy(auth()->user())
                ->event('schedule_exception.created')
                ->withProperties($data)
                ->log('Schedule exception created');

            return $exception;
        });
    }

    /**
     * Approve a schedule exception and cancel any generated ScheduleItems
     * that fall within the exception's date range.
     */
    public function approveException(ScheduleException $exception, ?string $reviewerUuid = null): ScheduleException
    {
        return DB::transaction(function () use ($exception, $reviewerUuid) {
            $exception->approve($reviewerUuid);

            // Cancel any generated ScheduleItems that overlap with this exception
            if ($exception->subject_uuid && $exception->start_at && $exception->end_at) {
                ScheduleItem::forAssignee($exception->subject_type, $exception->subject_uuid)
                    ->withinTimeRange($exception->start_at, $exception->end_at)
                    ->where('status', '!=', 'completed')
                    ->update(['status' => 'cancelled']);
            }

            activity()
                ->performedOn($exception)
                ->causedBy(auth()->user())
                ->event('schedule_exception.approved')
                ->log('Schedule exception approved');

            return $exception->fresh();
        });
    }

    /**
     * Reject a schedule exception.
     */
    public function rejectException(ScheduleException $exception, ?string $reviewerUuid = null): ScheduleException
    {
        return DB::transaction(function () use ($exception, $reviewerUuid) {
            $exception->reject($reviewerUuid);

            activity()
                ->performedOn($exception)
                ->causedBy(auth()->user())
                ->event('schedule_exception.rejected')
                ->log('Schedule exception rejected');

            return $exception->fresh();
        });
    }

    // ─── Template Application ─────────────────────────────────────────────────

    /**
     * Apply a library ScheduleTemplate to a subject's Schedule.
     *
     * This creates a driver-specific copy of the template linked to the schedule,
     * then immediately materializes it for the rolling window.
     *
     * @param ScheduleTemplate $template
     * @param Schedule         $schedule
     *
     * @return array{template: ScheduleTemplate, items_created: int}  The applied template copy and the number of ScheduleItems created
     */
    public function applyTemplateToSchedule(ScheduleTemplate $template, Schedule $schedule): array
    {
        return DB::transaction(function () use ($template, $schedule) {
            $applied = $template->applyToSchedule($schedule);

            // Immediately materialize the newly applied template
            $created = $this->materializeTemplate($applied, $schedule);

            // Activate the schedule if it was still in draft state
            if ($schedule->status === 'draft') {
                $schedule->update(['status' => 'active']);
            }

            activity()
                ->performedOn($schedule)
                ->causedBy(auth()->user())
                ->event('schedule_template.applied')
                ->withProperties(['template_uuid' => $template->uuid])
                ->log('Schedule template applied');

            return ['template' => $applied, 'items_created' => $created];
        });
    }

    // ─── Materialization Engine ───────────────────────────────────────────────

    /**
     * Materialize all active schedules that need their rolling window extended.
     *
     * This is the entry point called by the MaterializeSchedulesJob.
     * It finds all schedules whose materialization_horizon is before the target date
     * and materializes each one.
     *
     * @return array{materialized: int, skipped: int, errors: int}
     */
    public function materializeAll(): array
    {
        $horizon = Carbon::today()->addDays(static::MATERIALIZATION_WINDOW_DAYS);
        $stats   = ['materialized' => 0, 'skipped' => 0, 'errors' => 0];

        Schedule::active()
            ->needsMaterialization($horizon)
            ->with(['templates' => fn ($q) => $q->applied()->whereNotNull('rrule')])
            ->chunk(100, function (Collection $schedules) use ($horizon, &$stats) {
                foreach ($schedules as $schedule) {
                    try {
                        $count = $this->materializeSchedule($schedule, $horizon);
                        if ($count > 0) {
                            $stats['materialized']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('[ScheduleService] Materialization error for schedule ' . $schedule->uuid, [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Materialize a single Schedule up to the given horizon date.
     *
     * @param Schedule    $schedule
     * @param Carbon|null $horizon  Defaults to today + MATERIALIZATION_WINDOW_DAYS
     *
     * @return int  Number of ScheduleItem records created
     */
    public function materializeSchedule(Schedule $schedule, ?Carbon $horizon = null): int
    {
        $horizon   = $horizon ?? Carbon::today()->addDays(static::MATERIALIZATION_WINDOW_DAYS);
        $templates = $schedule->templates()->applied()->whereNotNull('rrule')->get();
        $created   = 0;

        foreach ($templates as $template) {
            $created += $this->materializeTemplate($template, $schedule, $horizon);
        }

        // Update the materialization tracking columns
        $schedule->update([
            'last_materialized_at'    => now(),
            'materialization_horizon' => $horizon->toDateString(),
        ]);

        return $created;
    }

    /**
     * Materialize a single applied ScheduleTemplate into ScheduleItem records.
     *
     * The engine:
     *   1. Calculates all RRULE occurrences between today and the horizon
     *   2. Loads all approved exceptions for the subject in that window
     *   3. Loads all existing exception-flagged ScheduleItems (manual overrides)
     *   4. For each occurrence, skips if:
     *      - An approved ScheduleException covers that date, OR
     *      - A ScheduleItem with is_exception=true already exists for that date
     *      - A ScheduleItem already exists for that date (idempotency)
     *   5. Creates a new ScheduleItem for each remaining occurrence
     *
     * @param ScheduleTemplate $template
     * @param Schedule         $schedule
     * @param Carbon|null      $horizon
     *
     * @return int  Number of ScheduleItem records created
     */
    public function materializeTemplate(ScheduleTemplate $template, Schedule $schedule, ?Carbon $horizon = null): int
    {
        if (!$template->hasRrule()) {
            Log::debug('[materializeTemplate] no rrule on template', ['template_uuid' => $template->uuid]);
            return 0;
        }

        $horizon  = $horizon ?? Carbon::today()->addDays(static::MATERIALIZATION_WINDOW_DAYS);
        $from     = Carbon::today();
        $timezone = $schedule->getEffectiveTimezone();

        Log::debug('[materializeTemplate] starting', [
            'template_uuid'      => $template->uuid,
            'rrule'              => $template->rrule,
            'start_time'         => $template->start_time,
            'subject_type'       => $template->subject_type,
            'subject_uuid'       => $template->subject_uuid,
            'from'               => $from->toDateString(),
            'horizon'            => $horizon->toDateString(),
            'timezone'           => $timezone,
            'rrule_class_exists' => class_exists('RRule\\RRule'),
        ]);

        // Get all RRULE occurrences in the window
        $occurrences = $template->getOccurrencesBetween($from, $horizon, $timezone);

        Log::debug('[materializeTemplate] occurrences', [
            'template_uuid' => $template->uuid,
            'count'         => count($occurrences),
            'first_3'       => array_map(fn($c) => $c->toDateTimeString(), array_slice($occurrences, 0, 3)),
        ]);

        if (empty($occurrences)) {
            return 0;
        }

        // Load approved exceptions covering this window
        $approvedExceptions = ScheduleException::forSubject($template->subject_type, $template->subject_uuid)
            ->approved()
            ->overlapping($from, $horizon)
            ->get();

        // Load existing ScheduleItems from this template in the window (for idempotency)
        $existingItems = ScheduleItem::fromTemplate($template->uuid)
            ->withinTimeRange($from, $horizon)
            ->get()
            ->keyBy(fn ($item) => $item->start_at->toDateString());

        $created = 0;

        DB::transaction(function () use (
            $occurrences, $template, $schedule, $approvedExceptions,
            $existingItems, $timezone, &$created
        ) {
            foreach ($occurrences as $occurrenceDate) {
                $dateString = $occurrenceDate->toDateString();

                // Skip if a ScheduleItem already exists for this date (idempotency)
                if (isset($existingItems[$dateString])) {
                    continue;
                }

                // Skip if an approved exception covers this date
                $coveredByException = $approvedExceptions->first(function ($exception) use ($occurrenceDate) {
                    return $exception->start_at->lte($occurrenceDate)
                        && $exception->end_at->gte($occurrenceDate);
                });

                if ($coveredByException) {
                    continue;
                }

                // Build the concrete shift start/end datetimes
                $startAt = Carbon::parse($dateString . ' ' . ($template->start_time ?: '00:00'), $timezone)
                    ->setTimezone('UTC');

                $endAt = $template->end_time
                    ? Carbon::parse($dateString . ' ' . $template->end_time, $timezone)->setTimezone('UTC')
                    : $startAt->copy()->addMinutes($template->duration ?: 480); // default 8h

                // Build optional break times
                $breakStartAt = null;
                $breakEndAt   = null;
                if ($template->break_duration && $template->break_duration > 0) {
                    // Place break at the midpoint of the shift
                    $shiftMidpoint = $startAt->copy()->addMinutes(
                        (int) ($startAt->diffInMinutes($endAt) / 2)
                    );
                    $breakStartAt = $shiftMidpoint->copy()->subMinutes((int) ($template->break_duration / 2));
                    $breakEndAt   = $shiftMidpoint->copy()->addMinutes((int) ($template->break_duration / 2));
                }

                ScheduleItem::create([
                    'schedule_uuid'  => $schedule->uuid,
                    'template_uuid'  => $template->uuid,
                    'assignee_type'  => $template->subject_type,
                    'assignee_uuid'  => $template->subject_uuid,
                    'start_at'       => $startAt,
                    'end_at'         => $endAt,
                    'break_start_at' => $breakStartAt,
                    'break_end_at'   => $breakEndAt,
                    'status'         => 'scheduled',
                    'is_exception'   => false,
                ]);

                $created++;
            }
        });

        return $created;
    }

    // ─── Query Helpers ────────────────────────────────────────────────────────

    /**
     * Get all schedules for a specific polymorphic subject.
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

        return $query->with(['items', 'templates', 'exceptions'])->get();
    }

    /**
     * Get all schedule items for a specific polymorphic assignee.
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

        return $query->with(['schedule', 'template', 'assignee', 'resource'])
            ->orderBy('start_at', 'asc')
            ->get();
    }

    /**
     * Get the active shift for a specific assignee on a given date.
     * Returns null if the assignee has no shift on that date or has an approved exception.
     *
     * @param string $assigneeType
     * @param string $assigneeUuid
     * @param Carbon $date
     *
     * @return ScheduleItem|null
     */
    public function getActiveShiftFor(string $assigneeType, string $assigneeUuid, Carbon $date): ?ScheduleItem
    {
        // Check for an approved exception covering this date first
        $hasException = ScheduleException::forSubject($assigneeType, $assigneeUuid)
            ->approved()
            ->coveringDate($date)
            ->exists();

        if ($hasException) {
            return null;
        }

        return ScheduleItem::forAssignee($assigneeType, $assigneeUuid)
            ->onDate($date)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->orderBy('start_at', 'asc')
            ->first();
    }

    /**
     * Get all exceptions for a specific subject.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getExceptionsForSubject(string $subjectType, string $subjectUuid, array $filters = [])
    {
        $query = ScheduleException::forSubject($subjectType, $subjectUuid);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (isset($filters['start_at']) && isset($filters['end_at'])) {
            $query->overlapping($filters['start_at'], $filters['end_at']);
        }

        return $query->orderBy('start_at', 'asc')->get();
    }
}
