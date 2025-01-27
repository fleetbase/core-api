<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_user_id')->nullable()->unique()->after('email');
            $table->string('facebook_user_id')->nullable()->unique()->after('apple_user_id');
            $table->string('google_user_id')->nullable()->unique()->after('facebook_user_id');
            $table->index(['apple_user_id', 'facebook_user_id', 'google_user_id'], 'social_login_index');
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
            $table->dropColumn('apple_user_id');
            $table->dropColumn('facebook_user_id');
            $table->dropColumn('google_user_id');
            $table->dropIndex('social_login_index');
        });
    }
};
