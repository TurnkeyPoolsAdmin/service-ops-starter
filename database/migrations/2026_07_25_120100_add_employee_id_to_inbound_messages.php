<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            // InboundMessageService previously resolved a sender only against
            // Customer.phone_e164 — a technician's reply landed as an orphan
            // row with customer_id null and nothing happened. This lets
            // inbound routing also try Employee.phone, which the AI
            // scheduling reply flow depends on (see docs/ai-scheduling-spec.md
            // §6.1) and which also makes Incoming Texts readable for a tech
            // reply outside that feature.
            $table->foreignId('employee_id')->nullable()->after('customer_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
