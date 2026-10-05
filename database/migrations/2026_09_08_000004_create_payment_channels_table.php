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
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->string('gateway'); // tripay, midtrans, manual
            $table->string('code'); // kode channel di gateway, mis. BRIVA, QRIS
            $table->string('name'); // nama tampilan, mis. BCA Virtual Account
            $table->string('group'); // Virtual Account, E-Wallet, Retail, QRIS
            $table->string('icon')->nullable();
            $table->decimal('flat_fee', 15, 2)->default(0);
            $table->decimal('percent_fee', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['gateway', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
