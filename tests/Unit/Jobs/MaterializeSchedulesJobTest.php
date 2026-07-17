<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace {
    use Fleetbase\Jobs\MaterializeSchedulesJob;
    use Fleetbase\Services\Scheduling\ScheduleService;
    use Illuminate\Support\Facades\Facade;

    class MaterializeSchedulesJobLogFake
    {
        public array $entries = [];

        public function info(string $message, array $context = []): void
        {
            $this->entries[] = ['info', $message, $context];
        }

        public function error(string $message, array $context = []): void
        {
            $this->entries[] = ['error', $message, $context];
        }
    }

    class MaterializeSchedulesJobServiceFake extends ScheduleService
    {
        public int $calls = 0;

        public function __construct(private array $stats)
        {
        }

        public function materializeAll(): array
        {
            $this->calls++;

            return $this->stats;
        }
    }

    function materialize_schedules_job_log(): MaterializeSchedulesJobLogFake
    {
        $container = bind_test_container();
        $log       = new MaterializeSchedulesJobLogFake();

        $container->instance('log', $log);
        Facade::clearResolvedInstance('log');

        return $log;
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    it('materializes schedules once and logs summary stats', function () {
        $log     = materialize_schedules_job_log();
        $service = new MaterializeSchedulesJobServiceFake([
            'materialized' => 7,
            'skipped'      => 2,
            'errors'       => 1,
        ]);
        $job = new MaterializeSchedulesJob();

        $job->handle($service);

        expect($service->calls)->toBe(1)
            ->and($job->tries)->toBe(3)
            ->and($job->backoff)->toBe(60)
            ->and($log->entries)->toBe([
                ['info', '[MaterializeSchedulesJob] Starting rolling schedule materialization...', []],
                ['info', '[MaterializeSchedulesJob] Materialization complete.', [
                    'schedules_materialized' => 7,
                    'schedules_skipped'      => 2,
                    'errors'                 => 1,
                ]],
            ]);
    });

    it('logs failed materialization jobs with the exception message and trace', function () {
        $log = materialize_schedules_job_log();
        $job = new MaterializeSchedulesJob();

        $job->failed(new RuntimeException('database unavailable'));

        expect($log->entries)->toHaveCount(1)
            ->and($log->entries[0][0])->toBe('error')
            ->and($log->entries[0][1])->toBe('[MaterializeSchedulesJob] Job failed: database unavailable')
            ->and($log->entries[0][2])->toHaveKey('trace')
            ->and($log->entries[0][2]['trace'])->toBeString();
    });
}
