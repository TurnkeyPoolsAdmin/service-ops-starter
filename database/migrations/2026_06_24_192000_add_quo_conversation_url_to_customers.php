<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Deep link into the customer's Quo/OpenPhone inbox conversation,
            // captured the first time we exchange a message via Quo. Powers the
            // "Call" button and the Messaging "Custom" option on the job view.
            $table->string('quo_conversation_url')->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('quo_conversation_url');
        });
    }
};
