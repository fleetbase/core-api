<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallerController extends Controller
{
    /**
     * Checks installation status with aggressive caching.
     *
     * @return \Illuminate\Http\Response
     */
    public function initialize()
    {
        $cacheKey = 'installer_status';
        $cacheTTL = now()->addHour(); // Cache for 1 hour

        // Try cache first
        $status = Cache::remember($cacheKey, $cacheTTL, function () {
            return $this->checkInstallationStatus();
        });

        // Return with cache headers
        return response()->json($status)
            ->header('Cache-Control', 'private, max-age=3600') // 1 hour
            ->header('X-Cache-Status', Cache::has($cacheKey) ? 'HIT' : 'MISS');
    }

    /**
     * Check installation status.
     *
     * @return array
     */
    protected function checkInstallationStatus(): array
    {
        $shouldInstall = false;
        $shouldOnboard = false;
        $defaultTheme  = 'dark'; // Default fallback

        try {
            // Quick connection check
            DB::connection()->getPdo();

            if (!DB::connection()->getDatabaseName()) {
                $shouldInstall = true;
            } else {
                // Use exists() instead of count() - much faster
                if (Schema::hasTable('companies')) {
                    $shouldOnboard = !DB::table('companies')->exists();
                } else {
                    $shouldInstall = true;
                }

                // Only lookup theme if not installing
                if (!$shouldInstall) {
                    $defaultTheme = Setting::lookup('branding.default_theme', 'dark');
                }
            }
        } catch (\Exception $e) {
            $shouldInstall = true;
        }

        return [
            'shouldInstall' => $shouldInstall,
            'shouldOnboard' => $shouldOnboard,
            'defaultTheme'  => $defaultTheme,
        ];
    }

    /**
     * Clear installer cache (call after installation/onboarding).
     *
     * @return void
     */
    public static function clearCache()
    {
        Cache::forget('installer_status');
    }

    public function createDatabase()
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', 0);

        Artisan::call('mysql:createdb');

        // Clear cache after database creation
        static::clearCache();

        return response()->json(
            [
                'status' => 'success',
            ]
        );
    }

    public function migrate()
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', 0);

        shell_exec(base_path('artisan') . ' migrate');
        Artisan::call('sandbox:migrate');

        // Clear cache after migration
        static::clearCache();

        return response()->json(
            [
                'status' => 'success',
            ]
        );
    }

    public function seed()
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', 0);

        Artisan::call('fleetbase:seed');

        // Clear cache after seeding
        static::clearCache();

        return response()->json(
            [
                'status' => 'success',
            ]
        );
    }
}
