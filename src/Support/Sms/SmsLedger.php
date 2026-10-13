<?php

namespace Fleetbase\Support\Sms;

use Fleetbase\Models\SmsSend;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Writes and reads the SMS send ledger (sms_sends).
 *
 * Every write is best effort: an install that has not run the migration yet, or a test
 * without the table, must still be able to send. A failed write is logged once and the
 * ledger is treated as unavailable for a minute, so a broken table cannot add a failing
 * query to every send.
 */
final class SmsLedger
{
    public const UNAVAILABLE_KEY = 'sms-guard:ledger-unavailable';

    /**
     * Record a send the guard decided on. Returns the row's uuid, or null when the ledger
     * could not be written.
     */
    public static function record(SmsContext $context, string $status, array $attributes = []): ?string
    {
        if (!self::available()) {
            return null;
        }

        try {
            $send = SmsSend::create(array_merge([
                'company_uuid'     => $context->companyUuid,
                'tenant_type'      => $context->tenantType,
                'tenant_uuid'      => $context->tenantUuid,
                'purpose'          => $context->purpose,
                'source'           => $context->source,
                'destination'      => $context->destination,
                'destination_hash' => self::hash($context->destination),
                'status'           => $status,
                'subject_type'     => $context->subjectType,
                'subject_uuid'     => $context->subjectUuid,
                'ip_address'       => $context->ip,
                'user_agent'       => $context->userAgent,
            ], $attributes));

            return $send->uuid;
        } catch (\Throwable $e) {
            self::markUnavailable($e);

            return null;
        }
    }

    /**
     * Update a row with how the send ended.
     */
    public static function update(?string $uuid, array $attributes): void
    {
        if (!$uuid || !self::available()) {
            return;
        }

        try {
            SmsSend::where('uuid', $uuid)->update(array_merge($attributes, ['updated_at' => Carbon::now()]));
        } catch (\Throwable $e) {
            self::markUnavailable($e);
        }
    }

    /**
     * Countries a company has sent to in the last `$days`, cached for ten minutes.
     *
     * @return array<int, string>
     */
    public static function countriesFor(string $companyUuid, int $days = 30): array
    {
        if (!self::available()) {
            return [];
        }

        try {
            return Cache::remember('sms-guard:countries:' . $companyUuid, 600, function () use ($companyUuid, $days) {
                return SmsSend::where('company_uuid', $companyUuid)
                    ->where('status', SmsSend::STATUS_SENT)
                    ->where('created_at', '>=', Carbon::now()->subDays($days))
                    ->whereNotNull('destination_country')
                    ->distinct()
                    ->pluck('destination_country')
                    ->map(fn ($country) => strtoupper((string) $country))
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * How many sends a company has on the ledger in the last `$days`, cached for ten minutes.
     */
    public static function sentCountFor(string $companyUuid, int $days = 30): int
    {
        if (!self::available()) {
            return 0;
        }

        try {
            return (int) Cache::remember('sms-guard:sent-count:' . $companyUuid, 600, function () use ($companyUuid, $days) {
                return SmsSend::where('company_uuid', $companyUuid)
                    ->where('status', SmsSend::STATUS_SENT)
                    ->where('created_at', '>=', Carbon::now()->subDays($days))
                    ->count();
            });
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Apply retention: mask the full number on rows older than `$maskAfterDays` (the hash
     * and the last four digits stay, so per-number limits and forensics still work), and
     * delete rows older than `$retentionDays`.
     *
     * @return array{masked: int, deleted: int}
     */
    public static function prune(int $retentionDays, int $maskAfterDays): array
    {
        $masked  = 0;
        $deleted = 0;

        if ($retentionDays > 0) {
            $deleted = SmsSend::where('created_at', '<', Carbon::now()->subDays($retentionDays))->delete();
        }

        if ($maskAfterDays > 0) {
            $rows = SmsSend::where('created_at', '<', Carbon::now()->subDays($maskAfterDays))
                ->whereNotNull('destination')
                ->where('destination', 'not like', '•%')
                ->get(['uuid', 'destination']);

            foreach ($rows as $row) {
                SmsSend::where('uuid', $row->uuid)->update(['destination' => self::mask($row->destination)]);
                $masked++;
            }
        }

        return ['masked' => $masked, 'deleted' => (int) $deleted];
    }

    /**
     * `•••4821`: the last four digits.
     */
    public static function mask(string $destination): string
    {
        return '•••' . substr(preg_replace('/\D/', '', $destination) ?? '', -4);
    }

    public static function hash(string $destination): string
    {
        return hash('sha256', PhoneValidator::normalize($destination));
    }

    /**
     * Whether the ledger can be written: a database is bound and the table did not fail
     * within the last minute.
     */
    public static function available(): bool
    {
        if (Model::getConnectionResolver() === null) {
            return false;
        }

        try {
            return !Cache::has(self::UNAVAILABLE_KEY);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function markUnavailable(\Throwable $e): void
    {
        Log::warning('sms.ledger_unavailable', ['error' => $e->getMessage()]);

        try {
            Cache::put(self::UNAVAILABLE_KEY, 1, 60);
        } catch (\Throwable $ignored) {
            // nothing to remember it in; the next write tries again
        }
    }
}
