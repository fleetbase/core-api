<?php

namespace Fleetbase\Support;

use Fleetbase\LaravelMysqlSpatial\Eloquent\Builder as SpatialQueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Log;

/**
 * QueryOptimizer - Optimizes Eloquent query builders by removing duplicate where clauses.
 *
 * This implementation uses a robust approach that:
 * - Tracks bindings alongside their where clauses
 * - Safely handles all where clause types
 * - Validates binding integrity before and after optimization
 * - Falls back gracefully if optimization would break the query
 */
class QueryOptimizer
{
    /**
     * Removes duplicate where clauses from the query builder while correctly handling bindings.
     *
     * This method ensures that duplicate where clauses are removed from the query builder
     * while maintaining the correct bindings. It uses a safe approach that validates
     * binding integrity and falls back to the original query if optimization would break it.
     *
     * @param SpatialQueryBuilder|Builder $query the query builder instance to optimize
     *
     * @return SpatialQueryBuilder|Builder the optimized query builder with unique where clauses
     */
    public static function removeDuplicateWheres(SpatialQueryBuilder|EloquentBuilder|Builder $query): SpatialQueryBuilder|EloquentBuilder|Builder
    {
        try {
            $baseQuery = $query->getQuery();
            $wheres    = $baseQuery->wheres;
            $bindings  = $baseQuery->bindings['where'] ?? [];

            // If no wheres or bindings, nothing to optimize
            if (empty($wheres)) {
                return $query;
            }

            // Build a list of where clauses with their associated bindings
            $whereClauses = static::buildWhereClauseList($wheres, $bindings);

            // Remove duplicates while preserving bindings
            $uniqueClauses = static::removeDuplicates($whereClauses);

            // Extract unique wheres and bindings
            $uniqueWheres   = array_column($uniqueClauses, 'where');
            $uniqueBindings = static::extractBindings($uniqueClauses);

            // Validate that we haven't broken anything
            if (!static::validateOptimization($wheres, $bindings, $uniqueWheres, $uniqueBindings)) {
                // If validation fails, return original query unchanged
                Log::warning('QueryOptimizer: Validation failed, returning original query');

                return $query;
            }

            // Apply the optimized wheres and bindings
            $baseQuery->wheres            = $uniqueWheres;
            $baseQuery->bindings['where'] = $uniqueBindings;

            return $query;
        } catch (\Exception $e) {
            // If anything goes wrong, log and return original query
            Log::error('QueryOptimizer: Exception during optimization', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $query;
        }
    }

    /**
     * Builds a list of where clauses with their associated bindings.
     *
     * This method iterates through the where clauses and associates each one
     * with its corresponding bindings from the bindings array.
     *
     * @param array $wheres   the where clauses from the query
     * @param array $bindings the bindings from the query
     *
     * @return array an array of ['where' => $where, 'bindings' => [...], 'signature' => '...']
     */
    protected static function buildWhereClauseList(array $wheres, array $bindings): array
    {
        $whereClauses = [];
        $bindingIndex = 0;

        foreach ($wheres as $where) {
            $clauseBindings = [];
            $bindingCount   = static::getBindingCount($where);

            // Extract the bindings for this where clause
            for ($i = 0; $i < $bindingCount; $i++) {
                if (isset($bindings[$bindingIndex])) {
                    $clauseBindings[] = $bindings[$bindingIndex];
                }
                $bindingIndex++;
            }

            // Create a unique signature for this where clause
            $signature = static::createWhereSignature($where, $clauseBindings);

            $whereClauses[] = [
                'where'     => $where,
                'bindings'  => $clauseBindings,
                'signature' => $signature,
            ];
        }

        return $whereClauses;
    }

    /**
     * Determines how many bindings a where clause requires.
     *
     * @param array $where the where clause
     *
     * @return int the number of bindings required
     */
    protected static function getBindingCount(array $where): int
    {
        $type = $where['type'] ?? 'Basic';

        switch ($type) {
            case 'Null':
            case 'NotNull':
            case 'Raw':
                // These types don't use bindings (Raw might, but it's handled separately)
                return 0;

            case 'In':
            case 'NotIn':
                // Count the number of values
                return count($where['values'] ?? []);

            case 'Between':
            case 'NotBetween':
                // Between uses 2 bindings
                return 2;

            case 'Nested':
                // Nested queries have their own bindings
                if (isset($where['query']) && $where['query'] instanceof Builder) {
                    return count($where['query']->bindings['where'] ?? []);
                }

                return 0;

            case 'Exists':
            case 'NotExists':
                // Exists queries have their own bindings
                if (isset($where['query']) && $where['query'] instanceof Builder) {
                    return count($where['query']->bindings['where'] ?? []);
                }

                return 0;

            case 'Basic':
            default:
                // Basic where clauses use 1 binding (unless value is an Expression)
                if (isset($where['value']) && $where['value'] instanceof Expression) {
                    return 0;
                }

                return 1;
        }
    }

    /**
     * Creates a unique signature for a where clause including its bindings.
     *
     * This signature is used to identify duplicate where clauses.
     *
     * @param array $where    the where clause
     * @param array $bindings the bindings for this where clause
     *
     * @return string a unique signature
     */
    protected static function createWhereSignature(array $where, array $bindings): string
    {
        $type = $where['type'] ?? 'Basic';

        $signatureData = [
            'type'    => $type,
            'boolean' => $where['boolean'] ?? 'and',
        ];

        switch ($type) {
            case 'Basic':
                $signatureData['column']   = $where['column'] ?? '';
                $signatureData['operator'] = $where['operator'] ?? '=';
                if ($where['value'] instanceof Expression) {
                    $signatureData['value'] = (string) $where['value'];
                } else {
                    $signatureData['bindings'] = $bindings;
                }
                break;

            case 'In':
            case 'NotIn':
                $signatureData['column']   = $where['column'] ?? '';
                $signatureData['bindings'] = $bindings;
                break;

            case 'Null':
            case 'NotNull':
                $signatureData['column'] = $where['column'] ?? '';
                break;

            case 'Between':
            case 'NotBetween':
                $signatureData['column']   = $where['column'] ?? '';
                $signatureData['bindings'] = $bindings;
                break;

            case 'Nested':
            case 'Exists':
            case 'NotExists':
                // For nested queries, include the nested where structure
                if (isset($where['query']) && $where['query'] instanceof Builder) {
                    $nestedWheres            = $where['query']->wheres ?? [];
                    $signatureData['nested'] = array_map(function ($nestedWhere) {
                        return static::normalizeWhereForSignature($nestedWhere);
                    }, $nestedWheres);
                    $signatureData['bindings'] = $bindings;
                }
                break;

            case 'Raw':
                $signatureData['sql'] = $where['sql'] ?? '';
                break;

            default:
                // For unknown types, include the entire where clause
                $signatureData['where']    = $where;
                $signatureData['bindings'] = $bindings;
        }

        return json_encode($signatureData);
    }

    /**
     * Normalizes a where clause for signature creation.
     *
     * @param array $where the where clause to normalize
     *
     * @return array the normalized where clause
     */
    protected static function normalizeWhereForSignature(array $where): array
    {
        return [
            'type'     => $where['type'] ?? 'Basic',
            'column'   => $where['column'] ?? null,
            'operator' => $where['operator'] ?? null,
            'boolean'  => $where['boolean'] ?? 'and',
        ];
    }

    /**
     * Removes duplicate where clauses based on their signatures.
     *
     * @param array $whereClauses the list of where clauses with signatures
     *
     * @return array the unique where clauses
     */
    protected static function removeDuplicates(array $whereClauses): array
    {
        $seen   = [];
        $unique = [];

        foreach ($whereClauses as $clause) {
            $signature = $clause['signature'];

            if (!isset($seen[$signature])) {
                $seen[$signature] = true;
                $unique[]         = $clause;
            }
        }

        return $unique;
    }

    /**
     * Extracts bindings from the unique where clauses.
     *
     * @param array $whereClauses the unique where clauses
     *
     * @return array the flattened bindings array
     */
    protected static function extractBindings(array $whereClauses): array
    {
        $bindings = [];

        foreach ($whereClauses as $clause) {
            foreach ($clause['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    /**
     * Validates that the optimization hasn't broken the query.
     *
     * This method performs basic sanity checks to ensure the optimized query
     * is still valid.
     *
     * @param array $originalWheres   the original where clauses
     * @param array $originalBindings the original bindings
     * @param array $uniqueWheres     the optimized where clauses
     * @param array $uniqueBindings   the optimized bindings
     *
     * @return bool true if validation passes, false otherwise
     */
    protected static function validateOptimization(
        array $originalWheres,
        array $originalBindings,
        array $uniqueWheres,
        array $uniqueBindings,
    ): bool {
        // The unique wheres should not be more than the original
        if (count($uniqueWheres) > count($originalWheres)) {
            return false;
        }

        // The unique bindings should not be more than the original
        if (count($uniqueBindings) > count($originalBindings)) {
            return false;
        }

        // If we removed all wheres, something went wrong
        if (count($originalWheres) > 0 && count($uniqueWheres) === 0) {
            return false;
        }

        // Calculate expected binding count for unique wheres
        $expectedBindingCount = 0;
        foreach ($uniqueWheres as $where) {
            $expectedBindingCount += static::getBindingCount($where);
        }

        // The binding count should match
        if ($expectedBindingCount !== count($uniqueBindings)) {
            return false;
        }

        return true;
    }
}
