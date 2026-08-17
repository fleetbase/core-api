<?php

use Fleetbase\Console\Commands\DeleteUser;
use Fleetbase\Services\UserDeletionService;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;

class DeleteUserServiceFake extends UserDeletionService
{
    public Collection $users;

    public array $planResult = ['userUuids' => [], 'actions' => [], 'blockers' => []];

    public array|Throwable $executeResult = ['users_deleted' => 1];

    public array $calls = [];

    public function __construct()
    {
        $this->users = collect();
    }

    public function findUsers(?string $email = null, array $uuids = []): Collection
    {
        $this->calls[] = ['find', $email, $uuids];

        return $this->users;
    }

    public function plan(array $userUuids): array
    {
        $this->calls[] = ['plan', $userUuids];

        return $this->planResult;
    }

    public function execute(array $userUuids): array
    {
        $this->calls[] = ['execute', $userUuids];
        if ($this->executeResult instanceof Throwable) {
            throw $this->executeResult;
        }

        return $this->executeResult;
    }
}

class DeleteUserCommandFixture extends DeleteUser
{
    public array $messages = [];

    public array $tables = [];

    public bool $confirmation = true;

    public function __construct(public array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $this->tables[] = [$headers, $rows];
    }

    public function confirm($question, $default = false): bool
    {
        $this->messages[] = ['confirm', $question];

        return $this->confirmation;
    }
}

function delete_user_command_fixture(array $options = []): array
{
    $service        = new DeleteUserServiceFake();
    $service->users = collect([
        (object) ['uuid' => '11111111-1111-4111-8111-111111111111', 'email' => 'shiv@fleetbase.io', 'name' => 'Shiv'],
    ]);
    $service->planResult = [
        'userUuids' => ['11111111-1111-4111-8111-111111111111'],
        'actions'   => [
            ['schema' => 'fleetbase', 'table' => 'invites', 'column' => 'created_by_uuid', 'action' => 'null', 'count' => 2, 'reason' => 'Preserve rows'],
            ['schema' => 'fleetbase', 'table' => 'unused', 'column' => 'user_uuid', 'action' => 'delete', 'count' => 0, 'reason' => 'No rows'],
        ],
        'blockers' => [],
    ];

    return [new DeleteUserCommandFixture($options), $service];
}

it('resolves without resolving the database-backed deletion service', function () {
    $container = new Container();
    $container->bind(UserDeletionService::class, function () {
        throw new RuntimeException('database service must stay lazy');
    });

    expect($container->make(DeleteUser::class))->toBeInstanceOf(DeleteUser::class);
});

it('requires exactly one selector and validates UUIDs', function () {
    [$missing] = delete_user_command_fixture();
    [$both]    = delete_user_command_fixture(['email' => 'shiv@fleetbase.io', 'uuid' => ['11111111-1111-4111-8111-111111111111']]);
    [$invalid] = delete_user_command_fixture(['uuid' => ['not-a-uuid']]);

    expect($missing->handle(new DeleteUserServiceFake()))->toBe(1)
        ->and($both->handle(new DeleteUserServiceFake()))->toBe(1)
        ->and($invalid->handle(new DeleteUserServiceFake()))->toBe(1)
        ->and($invalid->messages)->toContain(['error', 'Invalid UUIDs: not-a-uuid']);
});

it('reports no matches without planning a deletion', function () {
    [$command, $service] = delete_user_command_fixture(['email' => 'nobody@fleetbase.io']);
    $service->users      = collect();

    expect($command->handle($service))->toBe(0)
        ->and($command->messages)->toContain(['warn', 'No matching users were found.'])
        ->and($service->calls)->toBe([['find', 'nobody@fleetbase.io', []]]);
});

it('defaults to a dry run and displays only impacted actions', function () {
    [$command, $service] = delete_user_command_fixture(['uuid' => ['11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111']]);

    expect($command->handle($service))->toBe(0)
        ->and($command->messages)->toContain(['info', 'Dry run only. Re-run with --execute to apply this plan.'])
        ->and($command->tables)->toHaveCount(2)
        ->and($command->tables[1][1])->toHaveCount(1)
        ->and($service->calls[0])->toBe(['find', null, ['11111111-1111-4111-8111-111111111111']]);
});

it('fails closed when the plan has unresolved blockers', function () {
    [$command, $service]             = delete_user_command_fixture(['email' => 'shiv@fleetbase.io']);
    $service->planResult['blockers'] = ['external.required_user_uuid'];

    expect($command->handle($service))->toBe(1)
        ->and($command->messages)->toContain(['error', 'Deletion is blocked by unresolved references: external.required_user_uuid']);
});

it('cancels an executed deletion when confirmation is declined', function () {
    [$command, $service]   = delete_user_command_fixture(['email' => 'shiv@fleetbase.io', 'execute' => true]);
    $command->confirmation = false;

    expect($command->handle($service))->toBe(0)
        ->and($command->messages)->toContain(['warn', 'Deletion cancelled.'])
        ->and(collect($service->calls)->pluck(0)->all())->not->toContain('execute');
});

it('executes with confirmation or the explicit yes option', function () {
    [$confirmed, $confirmedService] = delete_user_command_fixture(['email' => 'shiv@fleetbase.io', 'execute' => true]);
    [$forced, $forcedService]       = delete_user_command_fixture(['email' => 'shiv@fleetbase.io', 'execute' => true, 'yes' => true]);

    expect($confirmed->handle($confirmedService))->toBe(0)
        ->and($confirmed->messages)->toContain(['info', 'Deleted 1 users successfully.'])
        ->and(collect($confirmedService->calls)->pluck(0)->all())->toContain('execute')
        ->and($forced->handle($forcedService))->toBe(0)
        ->and(collect($forced->messages)->pluck(0)->all())->not->toContain('confirm')
        ->and(collect($forcedService->calls)->pluck(0)->all())->toContain('execute');
});

it('reports execution failures as rolled back', function () {
    [$command, $service]    = delete_user_command_fixture(['email' => 'shiv@fleetbase.io', 'execute' => true, 'yes' => true]);
    $service->executeResult = new RuntimeException('foreign key blocked');

    expect($command->handle($service))->toBe(1)
        ->and($command->messages)->toContain(['error', 'Deletion failed and was rolled back: foreign key blocked']);
});
