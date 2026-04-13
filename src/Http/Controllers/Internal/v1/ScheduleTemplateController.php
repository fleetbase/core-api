<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Services\Scheduling\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleTemplateController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'schedule_template';

    /**
     * The ScheduleService instance.
     *
     * @var ScheduleService
     */
    protected ScheduleService $scheduleService;

    public function __construct(ScheduleService $scheduleService)
    {
        parent::__construct();
        $this->scheduleService = $scheduleService;
    }

    /**
     * Apply a library template to a specific Schedule.
     *
     * Creates a driver-specific copy of the template linked to the schedule
     * and immediately materializes it for the rolling 60-day window.
     *
     * POST /schedule-templates/{id}/apply
     * Body: { "schedule_uuid": "...", "subject_type": "driver", "subject_uuid": "...", "effective_from": "..." }
     */
    public function apply(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'schedule_uuid' => 'required|string',
        ]);

        $template = ScheduleTemplate::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $schedule = Schedule::where('uuid', $request->input('schedule_uuid'))
            ->orWhere('public_id', $request->input('schedule_uuid'))
            ->firstOrFail();

        // applyTemplateToSchedule now returns ['template' => $applied, 'items_created' => $count]
        $result  = $this->scheduleService->applyTemplateToSchedule($template, $schedule);
        $applied = $result['template'];

        return response()->json([
            'status'            => 'ok',
            'schedule_template' => new \Fleetbase\Http\Resources\ScheduleTemplate($applied),
            'items_created'     => $result['items_created'],
        ]);
    }

    /**
     * Manually trigger materialization for a specific applied template.
     *
     * POST /schedule-templates/{id}/materialize
     */
    public function materialize(string $id): JsonResponse
    {
        $template = ScheduleTemplate::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->whereNotNull('schedule_uuid')
            ->firstOrFail();

        $schedule = $template->schedule;

        if (!$schedule) {
            return response()->json(['error' => 'Template is not applied to any schedule.'], 422);
        }

        $created = $this->scheduleService->materializeTemplate($template, $schedule);

        return response()->json([
            'status'        => 'ok',
            'items_created' => $created,
        ]);
    }
}
