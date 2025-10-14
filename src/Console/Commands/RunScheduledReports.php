<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\Report;
use Fleetbase\Models\ReportExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'reports:run-scheduled 
                            {--dry-run : Show what would be executed without running}
                            {--report= : Run specific report by UUID or public ID}';

    /**
     * The console command description.
     */
    protected $description = 'Execute scheduled reports that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun       = $this->option('dry-run');
        $specificReport = $this->option('report');

        if ($specificReport) {
            return $this->runSpecificReport($specificReport, $isDryRun);
        }

        return $this->runDueReports($isDryRun);
    }

    /**
     * Run a specific report.
     */
    protected function runSpecificReport(string $reportId, bool $isDryRun): int
    {
        $report = Report::where(function ($query) use ($reportId) {
            $query->where('uuid', $reportId)
                  ->orWhere('public_id', $reportId);
        })->first();

        if (!$report) {
            $this->error("Report not found: {$reportId}");

            return 1;
        }

        if ($isDryRun) {
            $this->info("Would execute report: {$report->title} ({$report->public_id})");

            return 0;
        }

        return $this->executeReport($report) ? 0 : 1;
    }

    /**
     * Run all due reports.
     */
    protected function runDueReports(bool $isDryRun): int
    {
        $dueReports = Report::getDueReports();

        if ($dueReports->isEmpty()) {
            $this->info('No scheduled reports are due for execution.');

            return 0;
        }

        $this->info("Found {$dueReports->count()} reports due for execution.");

        if ($isDryRun) {
            $this->table(
                ['Title', 'Public ID', 'Frequency', 'Next Run'],
                $dueReports->map(function ($report) {
                    return [
                        $report->title,
                        $report->public_id,
                        $report->schedule_frequency,
                        $report->next_scheduled_run->format('Y-m-d H:i:s'),
                    ];
                })->toArray()
            );

            return 0;
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($dueReports as $report) {
            if ($this->executeReport($report)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        $this->info("Execution complete: {$successCount} successful, {$failureCount} failed.");

        return $failureCount > 0 ? 1 : 0;
    }

    /**
     * Execute a single report.
     */
    protected function executeReport(Report $report): bool
    {
        $this->info("Executing report: {$report->title} ({$report->public_id})");

        // Create execution record
        $execution = ReportExecution::create([
            'report_uuid' => $report->uuid,
            'status'      => 'running',
            'started_at'  => now(),
        ]);

        try {
            $startTime = microtime(true);

            // Execute the report
            $results = $report->execute();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Update execution record
            $execution->update([
                'status'         => 'completed',
                'execution_time' => $executionTime,
                'result_count'   => count($results['results']),
                'completed_at'   => now(),
            ]);

            // Update next scheduled run
            $report->next_scheduled_run = $report->calculateNextRun();
            $report->save();

            $this->info("✓ Report executed successfully in {$executionTime}ms ({$results['total']} rows)");

            // Log success
            Log::info('Scheduled report executed successfully', [
                'report_uuid'    => $report->uuid,
                'report_title'   => $report->title,
                'execution_time' => $executionTime,
                'result_count'   => $results['total'],
            ]);

            return true;
        } catch (\Exception $e) {
            // Update execution record with error
            $execution->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            $this->error("✗ Report execution failed: {$e->getMessage()}");

            // Log error
            Log::error('Scheduled report execution failed', [
                'report_uuid'  => $report->uuid,
                'report_title' => $report->title,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
