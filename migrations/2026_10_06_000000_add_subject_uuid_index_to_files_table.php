<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Files are looked up by subject (store media, product images, proofs of
     * delivery). Without an index each lookup scanned the whole files table.
     */
    public function up(): void
    {
        if ($this->indexExists('files', 'files_subject_uuid_index')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->index('subject_uuid');
        });
    }

    public function down(): void
    {
        if (!$this->indexExists('files', 'files_subject_uuid_index')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['subject_uuid']);
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        try {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes($table);

            return isset($indexes[$index]);
        } catch (Throwable $e) {
            return false;
        }
    }
};
