<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::connection(config('flb:activitylog.database_connection'))->table(config('flb:activitylog.table_name'), function (Blueprint $table) {
            $table->string('event')->nullable()->after('subject_type');
        });
    }

    public function down()
    {
        Schema::connection(config('flb:activitylog.database_connection'))->table(config('flb:activitylog.table_name'), function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};
