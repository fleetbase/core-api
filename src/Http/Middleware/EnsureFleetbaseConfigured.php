<?php

namespace Fleetbase\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureFleetbaseConfigured
{
    /**
     * Core tables required before the API can serve Fleetbase requests.
     *
     * @var array<int, string>
     */
    protected array $requiredTables = [
        'settings',
        'users',
        'companies',
    ];

    protected static bool $configured = false;

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->shouldCheck($request)) {
            return $next($request);
        }

        if (!$this->isConfigured()) {
            return response()->json([
                'error'   => 'fleetbase_not_configured',
                'errors'  => ['fleetbase_not_configured'],
                'message' => 'Fleetbase is not installed or configured. Complete setup from the CLI or application container, then reload the console.',
            ], 503);
        }

        return $next($request);
    }

    protected function shouldCheck(Request $request): bool
    {
        if ($request->isMethod('OPTIONS')) {
            return false;
        }

        return $request->is('int/*')
            || $request->is('*/int/*')
            || $request->is('v1/*')
            || $request->is('*/v1/*');
    }

    protected function isConfigured(): bool
    {
        if (static::$configured) {
            return true;
        }

        try {
            DB::connection()->getPdo();

            if (!DB::connection()->getDatabaseName()) {
                return false;
            }

            foreach ($this->requiredTables as $table) {
                if (!Schema::hasTable($table)) {
                    return false;
                }
            }

            static::$configured = true;

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
