<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off;');
            DB::statement('CREATE TABLE admins_temp AS SELECT * FROM admins;');
            DB::statement('DROP TABLE admins;');
            DB::statement('CREATE TABLE "admins" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "password" varchar not null, "role" varchar check ("role" in (\'super_admin\', \'admin\', \'operator\', \'cs\', \'writer\')) not null default \'admin\', "is_active" tinyint(1) not null default \'1\', "remember_token" varchar, "created_at" datetime, "updated_at" datetime);');
            DB::statement('CREATE UNIQUE INDEX "admins_email_unique" on "admins" ("email");');
            DB::statement('INSERT INTO admins SELECT * FROM admins_temp;');
            DB::statement('DROP TABLE admins_temp;');
            DB::statement('PRAGMA foreign_keys=on;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for sqlite rollback
    }
};
