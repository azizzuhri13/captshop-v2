<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guard fulfillment supplier:
 * - supplier_name          : supplier yang benar-benar dipilih saat attempt
 * - supplier_attempts      : jumlah percobaan topup yang sudah dikirim (anti retry tanpa batas)
 * - supplier_last_attempt_at: dasar backoff antar percobaan
 * - needs_manual_review    : order butuh tindakan admin (refund/mapping), tidak boleh auto-retry
 * - manual_review_reason   : alasan kenapa ditahan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('supplier_name')->nullable()->after('supplier_ref');
            $table->unsignedInteger('supplier_attempts')->default(0)->after('supplier_name');
            $table->timestamp('supplier_last_attempt_at')->nullable()->after('supplier_attempts');
            $table->boolean('needs_manual_review')->default(false)->after('supplier_last_attempt_at');
            $table->string('manual_review_reason')->nullable()->after('needs_manual_review');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_name',
                'supplier_attempts',
                'supplier_last_attempt_at',
                'needs_manual_review',
                'manual_review_reason',
            ]);
        });
    }
};
