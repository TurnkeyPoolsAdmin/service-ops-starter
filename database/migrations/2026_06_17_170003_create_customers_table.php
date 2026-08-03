<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164')->unique();   // normalized E.164, findOrCreate key
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('contact_pref')->nullable();      // text | call

            $table->boolean('do_not_text')->default(false);
            // unknown | transactional_ok | marketing_ok | opted_out
            $table->string('sms_consent_status')->default('unknown');
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_out_source')->nullable();    // inbound_sms | manual | provider | phone_call
            $table->text('opt_out_text')->nullable();
            $table->timestamp('last_inbound_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('do_not_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
