<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds a nullable JSON `meta` column to the `invites` table so that
     * arbitrary key-value data (e.g. role_uuid for user invitations) can be
     * stored on an invite record without requiring dedicated columns for each
     * use-case. The HasMetaAttributes trait is used to read and write values.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
