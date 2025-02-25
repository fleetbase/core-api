<?php

namespace Fleetbase\Console\Commands;

use Aws\Credentials\Credentials;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class QueueStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the health status of the queue connection';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $defaultDriver = config('queue.default');
        $this->info("Checking queue status for driver: {$defaultDriver}");

        switch ($defaultDriver) {
            case 'redis':
                $redisConfig         = config('queue.connections.redis');
                $redisConnectionName = $redisConfig['connection'] ?? 'default';

                try {
                    $redis = Redis::connection($redisConnectionName);
                    $ping  = $redis->ping();

                    // Redis may return "PONG" or "+PONG" depending on the client.
                    if (in_array($ping, ['PONG', '+PONG', 1, true], true)) {
                        $this->info("Redis connection is healthy: {$ping}");

                        return 0;
                    }

                    $this->error("Unexpected response from Redis: {$ping}");

                    return 1;
                } catch (\Exception $e) {
                    $this->error('Redis connection failed: ' . $e->getMessage());

                    return 1;
                }

            case 'database':
                $dbConfig       = config('queue.connections.database');
                $connectionName = $dbConfig['connection'] ?? config('database.default');

                try {
                    // Run a simple query to test the connection.
                    DB::connection($connectionName)->select('select 1');
                    $this->info('Database queue connection is healthy.');

                    return 0;
                } catch (\Exception $e) {
                    $this->error('Database queue connection failed: ' . $e->getMessage());

                    return 1;
                }

            case 'sqs':
                $sqsConfig = config('queue.connections.sqs');

                // Create credentials instance using the AWS SDK's Credentials class
                $credentials = new Credentials(
                    $sqsConfig['key'],
                    $sqsConfig['secret'],
                    $sqsConfig['token'] ?? null
                );

                try {
                    $client = new SqsClient([
                        'version'     => 'latest',
                        'region'      => $sqsConfig['region'],
                        'credentials' => $credentials,
                    ]);

                    // Attempt to list queues to ensure connectivity
                    $result = $client->listQueues();
                    $queues = $result->get('QueueUrls') ?: [];
                    $this->info('SQS connection is healthy. Queues: ' . implode(', ', $queues));

                    return 0;
                } catch (\Exception $e) {
                    $this->error('SQS connection failed: ' . $e->getMessage());

                    return 1;
                }

            default:
                $this->warn("No specific health check implemented for driver: {$defaultDriver}");

                return 0;
        }
    }
}
