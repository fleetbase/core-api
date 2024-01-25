<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDashboardWidgetsTable extends Migration
{
    public function up()
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('name');
            $table->string('component');
            $table->json('grid_options');
            $table->json('options');
            $table->unsignedBigInteger('dashboard_id');
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            // Define foreign key relationship
            $table->foreign('dashboard_id')->references('id')->on('dashboards')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('dashboard_widgets');
    }
}
