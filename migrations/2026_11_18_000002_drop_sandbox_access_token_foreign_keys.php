<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the sandbox's dangling `access_token_id` foreign keys.
 *
 * `2024_10_17_075756_add_access_token_id_to_log_tables` adds foreign keys from three log tables to
 * `personal_access_tokens`. `sandbox:migrate` runs it on the sandbox connection too, where that
 * table doesn't exist, so the keys point at nothing. This drops them there, and only there. The
 * `access_token_id` columns stay. On the main connection, or where the keys are already gone,
 * nothing happens.
 */
return new class extends Migration {
    /**
     * @var array<int, string>
     */
    public array $tables = ['api_events', 'api_request_logs', 'webhook_request_logs'];

    public function up(): void
    {
        if (!$this->onSandbox() || Schema::hasTable('personal_access_tokens')) {
            return;
        }

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && $this->hasForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->dropForeign($this->foreignKeyName($table));
                });
            }
        }
    }

    /**
     * Puts the keys back the way the original migration made them, with foreign key checks off.
     */
    public function down(): void
    {
        if (!$this->onSandbox() || Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'access_token_id') && !$this->hasForeignKey($table)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($table) {
                        $blueprint->foreign('access_token_id', $this->foreignKeyName($table))
                            ->references('id')
                            ->on('personal_access_tokens')
                            ->onUpdate('CASCADE')
                            ->onDelete('CASCADE');
                    });
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    protected function onSandbox(): bool
    {
        return Schema::getConnection()->getName() === config('fleetbase.connection.sandbox', 'sandbox');
    }

    protected function hasForeignKey(string $table): bool
    {
        $name = $this->foreignKeyName($table);
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    protected function foreignKeyName(string $table): string
    {
        return $table . '_access_token_id_foreign';
    }
};
