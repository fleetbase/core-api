<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * App Review access: one row per thing an app signs reviewers in to (a Storefront store
     * or network, an organization's Navigator app, ...). Apple and Google reviewers can't
     * receive a texted code, so the owner turns access on and gives the app store a fixed
     * fictional number and a code. `subject_type` is the type an extension registered with
     * Fleetbase\Support\AppReview. The code is only ever stored hashed.
     */
    public function up(): void
    {
        if (Schema::hasTable('review_accesses')) {
            return;
        }

        Schema::create('review_accesses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->string('subject_type', 64);
            $table->uuid('subject_uuid');
            $table->boolean('enabled')->default(false);
            $table->string('code')->nullable();
            $table->timestamp('code_generated_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->uuid('updated_by_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['subject_type', 'subject_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_accesses');
    }
};
