<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Jobs\RunDatabaseBackup;
use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Support\DatabaseBackupSettings;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * System administration of database backups: their settings, an on-demand run and the
 * record of recent runs.
 */
class DatabaseBackupController extends Controller
{
    public function getSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(DatabaseBackupSettings::settings()));
    }

    /**
     * Save the administrator's settings; the scheduler picks them up from its next minute.
     */
    public function saveSettings(AdminRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'           => ['required', 'boolean'],
            'frequency'         => ['required', Rule::in(DatabaseBackupSettings::FREQUENCIES)],
            'time'              => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'day_of_week'       => ['sometimes', 'integer', 'min:0', 'max:6'],
            'disk'              => ['required', 'string', Rule::in(array_column(DatabaseBackupSettings::disks(), 'name'))],
            'bucket'            => ['nullable', 'string', 'max:255'],
            'path'              => ['nullable', 'string', 'max:255'],
            'connections'       => ['required', 'array', 'min:1'],
            'connections.*'     => ['string', Rule::in(DatabaseBackupSettings::connections())],
            'retention_days'    => ['nullable', 'integer', 'min:1', 'max:3650'],
            'retention_count'   => ['nullable', 'integer', 'min:1', 'max:10000'],
            'min_size_bytes'    => ['sometimes', 'integer', 'min:0'],
            'notify_on_failure' => ['sometimes', 'boolean'],
            'notify_emails'     => ['sometimes', 'array'],
            'notify_emails.*'   => ['email'],
        ]);

        return response()->json($this->settingsPayload(DatabaseBackupSettings::store($validated)));
    }

    /**
     * Discard the administrator's settings and fall back to the environment.
     */
    public function resetSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(DatabaseBackupSettings::reset()));
    }

    /**
     * Recent runs, newest first.
     */
    public function runs(AdminRequest $request): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:200']]);

        $runs = DatabaseBackup::orderByDesc('started_at')
            ->limit((int) $request->input('limit', 25))
            ->get()
            ->map(fn (DatabaseBackup $backup) => $backup->toAdminArray())
            ->values();

        return response()->json(['runs' => $runs]);
    }

    /**
     * Queue a backup of the configured databases now, whether or not scheduling is enabled.
     */
    public function run(AdminRequest $request): JsonResponse
    {
        app(Dispatcher::class)->dispatch(new RunDatabaseBackup(DatabaseBackup::TRIGGER_MANUAL));

        return response()->json(['status' => 'queued'], 202);
    }

    protected function settingsPayload(array $settings): array
    {
        $lastRun     = DatabaseBackup::orderByDesc('started_at')->first();
        $lastSuccess = DatabaseBackup::where('status', DatabaseBackup::STATUS_COMPLETED)->orderByDesc('started_at')->first();

        return [
            'settings'     => $settings,
            'defaults'     => DatabaseBackupSettings::defaults(),
            'disks'        => DatabaseBackupSettings::disks(),
            'connections'  => DatabaseBackupSettings::connections(),
            'last_run'     => $lastRun?->toAdminArray(),
            'last_success' => $lastSuccess?->toAdminArray(),
        ];
    }
}
