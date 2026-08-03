<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location mailing address (Admin -> Entities), shown on the PDF receipt
 * letterhead (App\Services\Receipts\ReceiptPdfService). Free text (street /
 * city, state zip on its own lines) rather than separate columns - it only
 * ever needs to display, never to be queried or geocoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
