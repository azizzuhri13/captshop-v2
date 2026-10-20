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
        Schema::table('netflix_accounts', function (Blueprint $table) {
            $table->string('channel', 30)->nullable()->after('status')->index();
        });

        Schema::table('disney_accounts', function (Blueprint $table) {
            $table->string('channel', 30)->nullable()->after('status')->index();
        });

        Schema::table('canva_accounts', function (Blueprint $table) {
            $table->string('channel', 30)->nullable()->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('netflix_accounts', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::table('disney_accounts', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::table('canva_accounts', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
