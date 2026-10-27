<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capcut_partner_orders', function (Blueprint $table) {
            // Snapshot tier & akhir masa aktif akun stok saat reservasi dibuat,
            // supaya harga/istilah tidak berubah walau katalog global berubah.
            $table->string('tier', 20)->nullable()->after('package_id');
            $table->date('stock_expires_at')->nullable()->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('capcut_partner_orders', function (Blueprint $table) {
            $table->dropColumn(['tier', 'stock_expires_at']);
        });
    }
};