<?php
namespace Fleetbase\Events;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class ScheduleItemDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $scheduleItem;
    public function __construct(ScheduleItem $scheduleItem)
    {
        $this->scheduleItem = $scheduleItem;
    }
}
