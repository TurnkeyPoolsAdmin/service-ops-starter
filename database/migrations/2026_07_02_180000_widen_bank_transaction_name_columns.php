<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real Chase ACH descriptors blow past VARCHAR(255) — a Payoneer transfer's
     * full "ORIG CO NAME:... TRACE#:... IND NAME:..." blob broke the daily pull
     * with "Data too long for column 'name'". Neither column is indexed, so TEXT
     * costs nothing. (SQLite tests never caught this — it doesn't enforce length.)
     */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->text('name')->change();
            $table->text('merchant_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('name')->change();
            $table->string('merchant_name')->nullable()->change();
        });
    }
};
