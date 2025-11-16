# Core Scheduling Module

This module provides a polymorphic, reusable scheduling system for the Fleetbase platform.

## Features

- **Polymorphic Architecture**: Schedule any entity type (drivers, vehicles, stores, warehouses, etc.)
- **Flexible Schedule Items**: Assign items to any assignee with any resource
- **Availability Management**: Track availability windows for any entity
- **Constraint System**: Pluggable constraint validation framework
- **Event-Driven**: Comprehensive event system for extensibility
- **Activity Logging**: All scheduling activities logged via Spatie Activity Log

## Database Tables

1. **schedules** - Master schedule records
2. **schedule_items** - Individual scheduled items/slots
3. **schedule_templates** - Reusable schedule patterns
4. **schedule_availability** - Availability tracking
5. **schedule_constraints** - Configurable scheduling rules

## Models

- `Schedule` - Main schedule model with polymorphic subject
- `ScheduleItem` - Schedule item with polymorphic assignee and resource
- `ScheduleTemplate` - Reusable template patterns
- `ScheduleAvailability` - Availability windows
- `ScheduleConstraint` - Constraint definitions

## Services

- `ScheduleService` - Core scheduling operations
- `AvailabilityService` - Availability management
- `ConstraintService` - Pluggable constraint validation

## Events

- `ScheduleCreated`
- `ScheduleUpdated`
- `ScheduleDeleted`
- `ScheduleItemCreated`
- `ScheduleItemUpdated`
- `ScheduleItemDeleted`
- `ScheduleItemAssigned`
- `ScheduleConstraintViolated`

## API Endpoints

All endpoints are available under `/int/v1/` prefix:

- `/schedules` - Schedule CRUD operations
- `/schedule-items` - Schedule item CRUD operations
- `/schedule-templates` - Template CRUD operations
- `/schedule-availability` - Availability CRUD operations
- `/schedule-constraints` - Constraint CRUD operations

## Extension Integration

Extensions can integrate with the scheduling module by:

1. **Registering Constraints**: Use `ConstraintService::register()` to add domain-specific constraints
2. **Listening to Events**: Subscribe to scheduling events to trigger extension-specific workflows
3. **Using the Meta Field**: Store extension-specific data in the `meta` JSON field

### Example: FleetOps HOS Constraint

```php
// In FleetOps ServiceProvider
public function boot()
{
    $constraintService = app(\Fleetbase\Services\Scheduling\ConstraintService::class);
    $constraintService->register('driver', \Fleetbase\FleetOps\Constraints\HOSConstraint::class);
}
```

## Usage Examples

### Creating a Schedule

```php
$schedule = Schedule::create([
    'company_uuid' => $company->uuid,
    'subject_type' => 'fleet',
    'subject_uuid' => $fleet->uuid,
    'name' => 'Weekly Driver Schedule',
    'start_date' => '2025-11-15',
    'end_date' => '2025-11-22',
    'timezone' => 'America/New_York',
    'status' => 'active',
]);
```

### Creating a Schedule Item

```php
$item = ScheduleItem::create([
    'schedule_uuid' => $schedule->uuid,
    'assignee_type' => 'driver',
    'assignee_uuid' => $driver->uuid,
    'resource_type' => 'vehicle',
    'resource_uuid' => $vehicle->uuid,
    'start_at' => '2025-11-15 08:00:00',
    'end_at' => '2025-11-15 17:00:00',
    'status' => 'confirmed',
]);
```

### Setting Availability

```php
$availability = ScheduleAvailability::create([
    'subject_type' => 'driver',
    'subject_uuid' => $driver->uuid,
    'start_at' => '2025-11-20 00:00:00',
    'end_at' => '2025-11-22 23:59:59',
    'is_available' => false,
    'reason' => 'vacation',
]);
```

## Future Enhancements

- Optimization algorithms for automatic schedule generation
- RRULE processing for recurring patterns
- Conflict detection and resolution
- Capacity planning and load balancing
- Multi-timezone support improvements
