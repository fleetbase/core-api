<?php

namespace Fleetbase\Jobs;

use Fleetbase\Services\Scheduling\ScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily background job that extends the rolling materialization window for all active schedules.
 *
 * This job is registered in the console kernel to run once per day (typically at midnight).
 * It calls ScheduleService::materializeAll(), which:
 *   1. Finds all active Schedule records whose materialization_horizon is within 60 days from today
 *   2. For each such schedule, reads its applied ScheduleTemplate records (those with an rrule)
 *   3. Expands each RRULE using rlanvin/php-rrule to generate occurrence dates
 *   4. Creates ScheduleItem records for each occurrence, skipping:
 *      - Dates already covered by an existing ScheduleItem (idempotency)
 *      - Dates covered by an approved ScheduleException
 *      - Dates where a manually-edited exception item (is_exception=true) already exists
 *   5. Updates the schedule's last_materialized_at and materialization_horizon
 *
 * The job is idempotent — running it multiple times on the same day is safe.
 */
class MaterializeSchedulesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Execute the job.
     */
    public function handle(ScheduleService $scheduleService): void
    {
        Log::info('[MaterializeSchedulesJob] Starting rolling schedule materialization...');

        $stats = $scheduleService->materializeAll();

        Log::info('[MaterializeSchedulesJob] Materialization complete.', [
            'schedules_materialized' => $stats['materialized'],
            'schedules_skipped'      => $stats['skipped'],
            'errors'                 => $stats['errors'],
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[MaterializeSchedulesJob] Job failed: ' . $exception->getMessage(), [
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
