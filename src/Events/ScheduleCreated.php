<?php

namespace Fleetbase\Events;

use Fleetbase\Models\Schedule;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduleCreated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The schedule instance.
     *
     * @var Schedule
     */
    public $schedule;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
    }
}
