<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Services\UserDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type UserDeletionPlan from UserDeletionService
 */
class DeleteUser extends Command
{
    protected $signature = 'fleetbase:user-delete
                            {--email= : Delete every user matching this email}
                            {--uuid=* : Delete one or more users by UUID}
                            {--execute : Execute the displayed deletion plan}
                            {--yes : Skip the interactive confirmation}';

    protected $description = 'Safely preview and delete users and their identity-bound resources across Fleetbase schemas';

    protected UserDeletionService $deletionService;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(UserDeletionService $deletionService): int
    {
        // Resolve the database-backed service only when this command is executed.
        // Laravel instantiates every registered command during package discovery;
        // constructor injection here opened MySQL while the release image was still
        // being built, before a database service existed.
        $this->deletionService = $deletionService;

        $emailOption = $this->option('email');
        $email       = is_string($emailOption) && $emailOption !== '' ? $emailOption : null;
        $uuids       = array_values(array_unique(array_filter((array) $this->option('uuid'), 'is_string')));

        if (($email && $uuids !== []) || (!$email && $uuids === [])) {
            $this->error('Provide exactly one selector: --email or --uuid.');

            return self::FAILURE;
        }

        $invalidUuids = array_values(array_filter($uuids, fn ($uuid) => !Str::isUuid($uuid)));
        if ($invalidUuids !== []) {
            $this->error('Invalid UUIDs: ' . implode(', ', $invalidUuids));

            return self::FAILURE;
        }

        $users = $this->deletionService->findUsers($email, $uuids);
        if ($users->isEmpty()) {
            $this->warn('No matching users were found.');

            return self::SUCCESS;
        }

        $selectedUuids = [];
        $userRows      = [];
        foreach ($users as $user) {
            $selectedUuids[] = $user->uuid;
            $userRows[]      = [$user->uuid, $user->email, $user->name];
        }
        $this->table(['UUID', 'Email', 'Name'], $userRows);

        $plan = $this->deletionService->plan($selectedUuids);
        $this->displayPlan($plan);

        if ($plan['blockers'] !== []) {
            $this->error('Deletion is blocked by unresolved references: ' . implode(', ', $plan['blockers']));

            return self::FAILURE;
        }

        if (!$this->option('execute')) {
            $this->info('Dry run only. Re-run with --execute to apply this plan.');

            return self::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm('Permanently delete the displayed users and apply this cleanup plan?')) {
            $this->warn('Deletion cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $this->deletionService->execute($selectedUuids);
        } catch (\Throwable $error) {
            $this->error('Deletion failed and was rolled back: ' . $error->getMessage());

            return self::FAILURE;
        }

        $deleted = (int) ($result['users_deleted'] ?? 0);
        $this->info("Deleted {$deleted} users successfully.");

        return self::SUCCESS;
    }

    /**
     * @param UserDeletionPlan $plan
     */
    protected function displayPlan(array $plan): void
    {
        $rows = [];
        foreach ($plan['actions'] as $action) {
            if ($action['count'] === 0) {
                continue;
            }

            $rows[] = [
                $action['schema'],
                $action['table'],
                $action['column'],
                strtoupper($action['action']),
                $action['count'],
                $action['reason'],
            ];
        }

        $this->table(['Schema', 'Table', 'Column', 'Action', 'Rows', 'Reason'], $rows);
    }
}
