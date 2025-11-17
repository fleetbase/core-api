<?php

namespace Fleetbase\Events;

use Fleetbase\Models\ScheduleItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class ScheduleItemUpdated
{
    use InteractsWithSockets;
    use SerializesModels;

    public $scheduleItem;

    public function __construct(ScheduleItem $scheduleItem)
    {
        $this->scheduleItem = $scheduleItem;
    }
}
