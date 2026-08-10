<?php

namespace Fleetbase\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type UserDeletionAction array{schema:string,table:string,column:string,action:string,count:int,reason:string,match_values:array<int,string>,values:array<string,mixed>,affected?:int}
 * @phpstan-type UserDeletionPlan array{userUuids:array<int,string>,companyUserUuids?:array<int,string>,contactUuids?:array<int,string>,driverUuids?:array<int,string>,actions:array<int,UserDeletionAction>,blockers:array<int,string>,users_deleted?:int}
 */
class UserDeletionService
{
    protected ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? DB::connection();
    }

    /**
     * @param array<int,string> $uuids
     *
     * @return Collection<int,object{uuid:string,email:?string,name:?string}>
     */
    public function findUsers(?string $email = null, array $uuids = []): Collection
    {
        return $this->db->table('users')
            ->select(['uuid', 'email', 'name'])
            ->when($email, fn ($query) => $query->where('email', $email))
            ->when($uuids !== [], fn ($query) => $query->whereIn('uuid', $uuids))
            ->orderBy('uuid')
            ->get();
    }

    /**
     * @param array<int,string> $userUuids
     *
     * @return UserDeletionPlan
     */
    public function plan(array $userUuids): array
    {
        $userUuids = array_values(array_unique(array_filter($userUuids, 'is_string')));
        $database  = $this->databaseName();
        $actions   = [];
        $blockers  = [];

        if ($userUuids === []) {
            return compact('userUuids', 'actions', 'blockers');
        }

        $companyUserUuids = $this->relatedUuids($database, 'company_users', $userUuids);
        $contactUuids     = $this->relatedUuids($database, 'contacts', $userUuids);
        $driverUuids      = $this->relatedUuids($database, 'drivers', $userUuids);

        $modelUuids = array_values(array_unique(array_merge($userUuids, $companyUserUuids)));
        foreach (['model_has_roles', 'model_has_permissions', 'model_has_policies'] as $pivotTable) {
            if (!$this->tableExists($database, $pivotTable)) {
                continue;
            }

            $actions[] = $this->action(
                $database,
                $pivotTable,
                'model_uuid',
                'delete',
                $modelUuids,
                [],
                'Delete user and company-membership authorization assignments'
            );
        }

        if ($contactUuids !== [] && $this->tableExists($database, 'orders')) {
            $actions[] = $this->action(
                $database,
                'orders',
                'customer_uuid',
                'null',
                $contactUuids,
                ['customer_uuid' => null, 'customer_type' => null],
                'Preserve orders before deleting linked contacts'
            );
        }

        if ($driverUuids !== [] && $this->tableExists($database, 'orders')) {
            $actions[] = $this->action(
                $database,
                'orders',
                'driver_assigned_uuid',
                'null',
                $driverUuids,
                ['driver_assigned_uuid' => null],
                'Preserve orders before deleting linked drivers'
            );
        }

        foreach ($this->discoverUserReferences($database) as $reference) {
            $key    = $reference['schema'] . '.' . $reference['table'] . '.' . $reference['column'];
            $values = $userUuids;
            $action = null;
            $reason = null;

            if ($reference['column'] === 'user_uuid') {
                $action = 'delete';
                $reason = 'Delete identity-bound rows';
            } elseif ($reference['nullable']) {
                $action = 'null';
                $reason = 'Preserve business or audit rows by clearing the user reference';
            } elseif ($reference['delete_rule'] === 'CASCADE') {
                $action = 'cascade';
                $reason = 'Database cascade deletes this non-nullable dependent row';
            } else {
                $blockers[] = $key;
                continue;
            }

            $actions[] = $this->action(
                $reference['schema'],
                $reference['table'],
                $reference['column'],
                $action,
                $values,
                $action === 'null' ? [$reference['column'] => null] : [],
                $reason
            );
        }

        $uniqueActions = [];
        foreach ($actions as $action) {
            $key                 = implode('|', [$action['schema'], $action['table'], $action['column'], $action['action']]);
            $uniqueActions[$key] = $action;
        }
        $actions = array_values($uniqueActions);
        usort(
            $actions,
            fn ($left, $right) => match ($left['action']) {
                'null'   => 0,
                'delete' => 1,
                default  => 2,
            } <=> match ($right['action']) {
                'null'   => 0,
                'delete' => 1,
                default  => 2,
            }
        );

        return compact('userUuids', 'companyUserUuids', 'contactUuids', 'driverUuids', 'actions', 'blockers');
    }

    /**
     * @param array<int,string> $userUuids
     *
     * @return UserDeletionPlan
     */
    public function execute(array $userUuids): array
    {
        /** @var UserDeletionPlan $result */
        $result = $this->db->transaction(function () use ($userUuids) {
            /** @var UserDeletionPlan $plan */
            $plan = $this->plan($userUuids);

            if ($plan['blockers'] !== []) {
                throw new \RuntimeException('Unresolved user references: ' . implode(', ', $plan['blockers']));
            }

            foreach ($plan['actions'] as &$action) {
                if ($action['count'] === 0 || $action['action'] === 'cascade') {
                    $action['affected'] = 0;
                    continue;
                }

                $query = $this->db->table($this->qualifiedTable($action['schema'], $action['table']))
                    ->whereIn($action['column'], $action['match_values']);

                $action['affected'] = $action['action'] === 'null'
                    ? $query->update($action['values'])
                    : $query->delete();
            }
            unset($action);

            $plan['users_deleted'] = $this->db->table('users')
                ->whereIn('uuid', $plan['userUuids'])
                ->delete();

            return $plan;
        });

        return $result;
    }

    /**
     * @param array<int,string> $userUuids
     *
     * @return array<int,string>
     */
    protected function relatedUuids(string $schema, string $table, array $userUuids): array
    {
        if (!$this->tableExists($schema, $table)) {
            return [];
        }

        $uuids = $this->db->table($this->qualifiedTable($schema, $table))
            ->whereIn('user_uuid', $userUuids)
            ->whereNotNull('uuid')
            ->pluck('uuid')
            ->unique()
            ->values()
            ->all();

        return array_values(array_filter($uuids, 'is_string'));
    }

    /**
     * @param array<int,string>   $matchValues
     * @param array<string,mixed> $values
     *
     * @return UserDeletionAction
     */
    protected function action(
        string $schema,
        string $table,
        string $column,
        string $action,
        array $matchValues,
        array $values,
        string $reason,
    ): array {
        $count = $this->db->table($this->qualifiedTable($schema, $table))
            ->whereIn($column, $matchValues)
            ->count();

        return [
            'schema'       => $schema,
            'table'        => $table,
            'column'       => $column,
            'action'       => $action,
            'count'        => $count,
            'reason'       => $reason,
            'match_values' => $matchValues,
            'values'       => $values,
        ];
    }

    /**
     * @return array<int,array{schema:string,table:string,column:string,nullable:bool,delete_rule:string}>
     */
    protected function discoverUserReferences(string $database): array
    {
        $foreignKeys = $this->db->select(
            <<<'SQL'
SELECT
    kcu.TABLE_SCHEMA AS table_schema,
    kcu.TABLE_NAME AS table_name,
    kcu.COLUMN_NAME AS column_name,
    columns.IS_NULLABLE AS is_nullable,
    constraints.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE AS kcu
JOIN information_schema.COLUMNS AS columns
  ON columns.TABLE_SCHEMA = kcu.TABLE_SCHEMA
 AND columns.TABLE_NAME = kcu.TABLE_NAME
 AND columns.COLUMN_NAME = kcu.COLUMN_NAME
JOIN information_schema.REFERENTIAL_CONSTRAINTS AS constraints
  ON constraints.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
 AND constraints.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
 AND constraints.TABLE_NAME = kcu.TABLE_NAME
WHERE kcu.REFERENCED_TABLE_SCHEMA = ?
  AND kcu.REFERENCED_TABLE_NAME = 'users'
  AND kcu.REFERENCED_COLUMN_NAME = 'uuid'
SQL,
            [$database]
        );

        $userColumns = $this->db->select(
            <<<'SQL'
SELECT
    TABLE_SCHEMA AS table_schema,
    TABLE_NAME AS table_name,
    COLUMN_NAME AS column_name,
    IS_NULLABLE AS is_nullable
FROM information_schema.COLUMNS
WHERE COLUMN_NAME = 'user_uuid'
  AND (TABLE_SCHEMA = ? OR TABLE_SCHEMA LIKE ?)
SQL,
            [$database, $database . '\_%']
        );

        $references = [];
        foreach (array_merge($foreignKeys, $userColumns) as $reference) {
            $reference = (array) $reference;
            $key       = $reference['table_schema'] . '.' . $reference['table_name'] . '.' . $reference['column_name'];

            $references[$key] = [
                'schema'      => $reference['table_schema'],
                'table'       => $reference['table_name'],
                'column'      => $reference['column_name'],
                'nullable'    => ($reference['is_nullable'] ?? 'NO') === 'YES',
                'delete_rule' => strtoupper($reference['delete_rule'] ?? 'NO ACTION'),
            ];
        }

        return array_values($references);
    }

    protected function databaseName(): string
    {
        return $this->db->getDatabaseName();
    }

    protected function tableExists(string $schema, string $table): bool
    {
        return $this->db->selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [$schema, $table]
        ) !== null;
    }

    protected function qualifiedTable(string $schema, string $table): string
    {
        foreach ([$schema, $table] as $identifier) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
                throw new \RuntimeException("Unsafe database identifier: {$identifier}");
            }
        }

        return $schema . '.' . $table;
    }
}
