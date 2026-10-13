<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\Sms\SmsLedger;
use Illuminate\Console\Command;

/**
 * Apply the SMS ledger's retention: mask numbers on older rows and delete old ones.
 */
class SmsLedgerPrune extends Command
{
    protected $signature = 'sms:ledger:prune
                            {--days= : delete rows older than this many days (default: sms.guard.ledger.retention_days)}
                            {--mask-after= : mask the destination on rows older than this many days (default: sms.guard.ledger.mask_after_days)}';

    protected $description = 'Mask and delete old SMS ledger rows according to the retention settings';

    public function handle(): int
    {
        $retention = $this->option('days') !== null ? (int) $this->option('days') : (int) config('sms.guard.ledger.retention_days', 90);
        $maskAfter = $this->option('mask-after') !== null ? (int) $this->option('mask-after') : (int) config('sms.guard.ledger.mask_after_days', 30);

        try {
            $result = SmsLedger::prune($retention, $maskAfter);
        } catch (\Throwable $e) {
            $this->error('The SMS ledger could not be pruned: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('SMS ledger: masked %d row(s) older than %d day(s), deleted %d row(s) older than %d day(s).', $result['masked'], $maskAfter, $result['deleted'], $retention));

        return self::SUCCESS;
    }
}
