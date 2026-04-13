<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Services\Scheduling\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleExceptionController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'schedule_exception';

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
     * Approve a schedule exception.
     * This will also cancel any generated ScheduleItems that fall within the exception's date range.
     *
     * POST /schedule-exceptions/{id}/approve
     */
    public function approve(string $id): JsonResponse
    {
        $exception = ScheduleException::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $reviewerUuid = auth()->user()?->uuid;

        $exception = $this->scheduleService->approveException($exception, $reviewerUuid);

        return response()->json([
            'status'             => 'ok',
            'schedule_exception' => new \Fleetbase\Http\Resources\ScheduleException($exception),
        ]);
    }

    /**
     * Reject a schedule exception.
     *
     * POST /schedule-exceptions/{id}/reject
     */
    public function reject(string $id): JsonResponse
    {
        $exception = ScheduleException::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $reviewerUuid = auth()->user()?->uuid;

        $exception = $this->scheduleService->rejectException($exception, $reviewerUuid);

        return response()->json([
            'status'             => 'ok',
            'schedule_exception' => new \Fleetbase\Http\Resources\ScheduleException($exception),
        ]);
    }

    /**
     * Get all exceptions for a specific subject.
     *
     * GET /schedule-exceptions?subject_type=driver&subject_uuid={uuid}
     */
    public function forSubject(Request $request): JsonResponse
    {
        $subjectType = $request->input('subject_type');
        $subjectUuid = $request->input('subject_uuid');

        if (!$subjectType || !$subjectUuid) {
            return response()->json([
                'error' => 'subject_type and subject_uuid are required',
            ], 422);
        }

        $filters = $request->only(['status', 'type', 'start_at', 'end_at']);

        $exceptions = $this->scheduleService->getExceptionsForSubject($subjectType, $subjectUuid, $filters);

        return response()->json([
            'schedule_exceptions' => \Fleetbase\Http\Resources\ScheduleException::collection($exceptions),
        ]);
    }
}
