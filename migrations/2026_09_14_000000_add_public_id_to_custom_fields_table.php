<?php

use Fleetbase\Models\CustomField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Custom fields were addressed by uuid alone, which left every API that
     * hands one out exposing an internal identifier where the rest of the
     * platform shows a public id. Existing rows are backfilled so nothing has
     * to cope with a field that has no id.
     */
    public function up(): void
    {
        if (!Schema::hasTable('custom_fields') || Schema::hasColumn('custom_fields', 'public_id')) {
            return;
        }

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->string('public_id', 191)->nullable()->after('uuid')->index();
        });

        CustomField::withTrashed()->whereNull('public_id')->get()->each(function (CustomField $field) {
            $field->update(['public_id' => CustomField::generatePublicId('custom_field')]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('custom_fields') || !Schema::hasColumn('custom_fields', 'public_id')) {
            return;
        }

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropIndex(['public_id']);
            $table->dropColumn(['public_id']);
        });
    }
};
