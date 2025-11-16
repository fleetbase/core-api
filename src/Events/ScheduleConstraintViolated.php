<?php
namespace Fleetbase\Events;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class ScheduleConstraintViolated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $scheduleItem;
    public $violations;
    public function __construct(ScheduleItem $scheduleItem, array $violations)
    {
        $this->scheduleItem = $scheduleItem;
        $this->violations = $violations;
    }
}
