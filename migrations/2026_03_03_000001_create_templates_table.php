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
        Schema::create('templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid', 191)->unique()->nullable();
            $table->string('public_id', 191)->unique()->nullable();
            $table->string('company_uuid', 191)->nullable()->index();
            $table->string('created_by_uuid', 191)->nullable()->index();
            $table->string('updated_by_uuid', 191)->nullable()->index();

            // Identity
            $table->string('name');
            $table->text('description')->nullable();

            // Context type — defines which Fleetbase model this template is designed for.
            // e.g. 'order', 'invoice', 'transaction', 'shipping_label', 'receipt', 'report'
            $table->string('context_type')->default('generic')->index();

            // Canvas dimensions (in mm by default, configurable via unit)
            $table->string('unit')->default('mm'); // mm, px, in
            $table->decimal('width', 10, 4)->default(210);   // A4 width
            $table->decimal('height', 10, 4)->default(297);  // A4 height
            $table->string('orientation')->default('portrait'); // portrait | landscape

            // Page settings
            $table->json('margins')->nullable(); // { top, right, bottom, left }
            $table->string('background_color')->nullable();
            $table->string('background_image_uuid')->nullable();

            // The full template content — array of element objects
            $table->longText('content')->nullable(); // JSON array of TemplateElement objects

            // Element type definitions / schema overrides (optional per-template customisation)
            $table->json('element_schemas')->nullable();

            // Status
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_public')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            $table->foreign('created_by_uuid')->references('uuid')->on('users')->onDelete('set null');
            $table->foreign('updated_by_uuid')->references('uuid')->on('users')->onDelete('set null');

            // Composite indexes
            $table->index(['company_uuid', 'context_type']);
            $table->index(['company_uuid', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
