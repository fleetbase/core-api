<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds the `microsoft_user_id` column that the Office365 / Azure AD
     * OAuth flow stamps on user accounts authenticated via Microsoft
     * identity (issue #453). Companion to the existing
     * `add_social_login_columns_to_users_table` migration which carries
     * `apple_user_id`, `facebook_user_id`, `google_user_id`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_user_id')->nullable()->unique()->after('google_user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('microsoft_user_id');
        });
    }
};
