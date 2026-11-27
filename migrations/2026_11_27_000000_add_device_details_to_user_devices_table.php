<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a device is, so the people who support a driver can tell their phones
 * apart: its name and model, its system, the app version on it, and when it
 * was signed out of.
 */
return new class extends Migration {
    protected array $columns = [
        'name'          => 191,
        'model'         => 191,
        'manufacturer'  => 191,
        'os_version'    => 64,
        'app_version'   => 64,
        'app_build'     => 64,
        'locale'        => 32,
        'timezone'      => 64,
    ];

    public function up(): void
    {
        if (!Schema::hasTable('user_devices')) {
            return;
        }

        Schema::table('user_devices', function (Blueprint $table) {
            foreach ($this->columns as $column => $length) {
                if (!Schema::hasColumn('user_devices', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
            if (!Schema::hasColumn('user_devices', 'signed_out_at')) {
                $table->timestamp('signed_out_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_devices')) {
            return;
        }

        Schema::table('user_devices', function (Blueprint $table) {
            foreach (array_merge(array_keys($this->columns), ['signed_out_at']) as $column) {
                if (Schema::hasColumn('user_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
