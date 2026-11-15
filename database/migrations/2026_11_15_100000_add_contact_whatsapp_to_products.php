<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor WhatsApp penanganan pesanan per-produk (opsional).
 *
 * Dipakai untuk produk manual yang dikirim admin via WhatsApp: invoice memakai
 * nomor khusus ini bila terisi, dan fallback ke nomor CS global dari
 * site_settings bila kosong. Dinormalisasi ke digit berawalan 62 di view
 * (memakai SupportContact::normalizeWhatsapp) — kolom ini hanya menyimpan
 * input mentah dari admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('contact_whatsapp')->nullable()->after('supplier_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('contact_whatsapp');
        });
    }
};
