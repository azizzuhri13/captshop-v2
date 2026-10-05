<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id')->unique();
            $table->foreignId('product_item_id')->constrained()->restrictOnDelete();
            $table->text('customer_data'); // JSON nilai input dinamis (user_id, zone_id, dll)
            $table->string('customer_phone', 30)->index();
            $table->string('customer_email')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('profit', 15, 2)->default(0);
            $table->string('payment_method')->nullable(); // grup: Virtual Account, E-Wallet, dst
            $table->string('payment_channel')->nullable(); // kode channel, mis. BRIVA
            $table->string('payment_ref')->nullable();
            $table->string('payment_url')->nullable();
            $table->enum('status', ['pending', 'paid', 'processing', 'completed', 'failed', 'expired', 'refunded'])->default('pending')->index();
            $table->string('supplier_ref')->nullable();
            $table->string('supplier_sn')->nullable();
            $table->text('supplier_response')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->datetime('processed_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->datetime('expired_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
