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
        Schema::create('template_queries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid', 191)->unique()->nullable();
            $table->string('public_id', 191)->unique()->nullable();
            $table->string('company_uuid', 191)->nullable()->index();
            $table->string('template_uuid', 191)->nullable()->index();
            $table->string('created_by_uuid', 191)->nullable()->index();

            // The fully-qualified model class this query targets
            // e.g. 'Fleetbase\Models\Order', 'Fleetbase\FleetOps\Models\Order'
            $table->string('model_type');

            // The variable name used in the template to access this collection
            // e.g. 'orders', 'transactions', 'invoices'
            $table->string('variable_name');

            // Human-readable label shown in the variable picker
            $table->string('label')->nullable();

            // JSON array of filter condition groups
            // Each condition: { field, operator, value, type }
            $table->json('conditions')->nullable();

            // JSON array of sort directives: [{ field, direction }]
            $table->json('sort')->nullable();

            // Maximum number of records to return (null = no limit)
            $table->unsignedInteger('limit')->nullable();

            // Which relationships to eager-load on the result set
            $table->json('with')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            $table->foreign('template_uuid')->references('uuid')->on('templates')->onDelete('cascade');
            $table->foreign('created_by_uuid')->references('uuid')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_queries');
    }
};
