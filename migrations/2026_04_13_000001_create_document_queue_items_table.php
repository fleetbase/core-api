<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('document_queue_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('file_uuid', 36)->nullable();

            $table->string('source', 20)->default('manual'); // manual, email, edi, api
            $table->string('document_type', 30)->default('unknown'); // carrier_invoice, bol, pod, rate_confirmation, insurance_cert, customs, other, unknown
            $table->string('status', 20)->default('received');
            // received, processing, parsed, matched, needs_review, failed

            $table->longText('raw_content')->nullable();
            $table->json('parsed_data')->nullable();

            $table->char('matched_order_uuid', 36)->nullable()->index();
            $table->char('matched_shipment_uuid', 36)->nullable()->index();
            $table->char('matched_carrier_invoice_uuid', 36)->nullable();
            $table->decimal('match_confidence', 4, 2)->nullable(); // 0.00 to 1.00
            $table->string('match_method', 30)->nullable(); // pro_number, bol_number, carrier_date, manual

            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_queue_items');
    }
};
