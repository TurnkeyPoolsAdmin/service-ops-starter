<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('from_phone_e164');
            $table->string('to_phone_e164');   // the main number
            $table->text('body')->nullable();
            $table->timestamp('received_at');
            $table->string('provider_message_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('detected_intent')->nullable();  // opt_out | question | other
            $table->boolean('processed')->default(false);
            $table->timestamps();

            $table->index('from_phone_e164');
            $table->index('processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
    }
};
