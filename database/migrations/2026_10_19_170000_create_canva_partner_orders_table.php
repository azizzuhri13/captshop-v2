<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_partner_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 100)->unique();
            $table->string('reservation_id', 36)->unique();
            $table->string('payload_hash', 64);
            $table->string('package_id', 50);
            $table->string('buyer_reference', 50);
            $table->string('buyer_username', 100)->nullable();
            $table->unsignedSmallInteger('ttl_minutes')->default(15);
            $table->string('status', 30)->default('reserved')->index();
            $table->foreignId('canva_account_id')->nullable()->constrained('canva_accounts')->nullOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_reference', 150)->nullable();
            $table->decimal('payment_amount', 15, 2)->nullable();
            $table->string('payment_currency', 3)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('otp_token', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_partner_orders');
    }
};
