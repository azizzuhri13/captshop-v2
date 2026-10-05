<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-bagian pada daftar nominal (mis. "Special Items", "First Top Up").
     * Nullable: item tanpa grup tampil di bagian paling atas tanpa heading.
     */
    public function up(): void
    {
        Schema::table('product_items', function (Blueprint $table) {
            $table->string('group', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('product_items', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};