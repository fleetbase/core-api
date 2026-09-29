<?php

namespace Fleetbase\Tests\Fixtures\Support;

/**
 * An in-memory stand-in for the Redis manager and connection used by ApiConsumerMetrics.
 *
 * Bound as the container's `redis` so that Redis::connection($name) returns this object.
 * Only the sorted-set, string and pipeline commands the metrics use are implemented, and
 * every command is recorded in $commands for assertions.
 */
class RedisMetricsFake
{
    public array $commands    = [];
    public array $connections = [];
    public array $sortedSets  = [];
    public array $strings     = [];
    public array $ttls        = [];

    /**
     * Replace the pipeline reply entirely (e.g. to simulate a client's flat WITHSCORES replies).
     */
    public ?array $pipelineReply = null;

    /**
     * Reply to zrangebyscore with a flat [member, score, ...] list instead of [member => score].
     */
    public bool $flatScores = false;

    public bool $pipelineThrows = false;

    public bool $mgetThrows = false;

    public function connection(?string $name = null): self
    {
        $this->connections[] = $name;

        return $this;
    }

    public function pipeline(callable $callback): array
    {
        if ($this->pipelineThrows) {
            throw new \RuntimeException('redis unavailable');
        }

        $pipe = new class {
            public array $queued = [];

            public function __call(string $method, array $arguments): self
            {
                $this->queued[] = [$method, $arguments];

                return $this;
            }
        };

        $callback($pipe);

        $replies = [];
        foreach ($pipe->queued as [$method, $arguments]) {
            $replies[] = $this->{$method}(...$arguments);
        }

        return $this->pipelineReply ?? $replies;
    }

    public function zincrby(string $key, int|float $increment, string $member): float
    {
        $this->commands[] = ['zincrby', $key, $increment, $member];

        $this->sortedSets[$key][$member] = ($this->sortedSets[$key][$member] ?? 0) + $increment;

        return (float) $this->sortedSets[$key][$member];
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->commands[] = ['expire', $key, $seconds];
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function setex(string $key, int $seconds, string $value): bool
    {
        $this->commands[]    = ['setex', $key, $seconds, $value];
        $this->strings[$key] = $value;
        $this->ttls[$key]    = $seconds;

        return true;
    }

    public function zrangebyscore(string $key, string $min, string $max, array $options = []): array
    {
        $this->commands[] = ['zrangebyscore', $key, $min, $max, $options];

        $set = $this->sortedSets[$key] ?? [];
        asort($set);

        // Redis replies with scores as strings.
        $set = array_map(fn ($score) => (string) $score, $set);

        if (!$this->flatScores) {
            return $set;
        }

        $flat = [];
        foreach ($set as $member => $score) {
            $flat[] = (string) $member;
            $flat[] = $score;
        }

        return $flat;
    }

    public function mget(array $keys): array
    {
        $this->commands[] = ['mget', $keys];

        if ($this->mgetThrows) {
            throw new \RuntimeException('redis unavailable');
        }

        return array_map(fn ($key) => $this->strings[$key] ?? null, $keys);
    }

    public function commandNames(): array
    {
        return array_column($this->commands, 0);
    }
}
