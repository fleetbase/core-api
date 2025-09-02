<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();
            $table->foreignUuid('acknowledged_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('resolved_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->string('type')->index();           // geofence_breach, harsh_event, temp_out_of_range, offline, etc.
            $table->string('severity')->index();       // info, warning, critical
            $table->string('status')->default('open')->index(); // open, acknowledged, resolved

            // Origin of alert / subject ie: device, sensor, asset, driver, place, etc.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->index(['subject_type', 'subject_uuid']);

            $table->text('message')->nullable();
            $table->json('rule')->nullable();          // serialized rule that triggered this
            $table->json('context')->nullable();       // snapshot at trigger time
            $table->json('meta')->nullable();

            $table->timestamp('triggered_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'type', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
