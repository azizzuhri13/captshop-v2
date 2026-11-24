<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove stale credentials from the pre-gateway integration.
     *
     * This cleanup is intentionally irreversible: down() does not restore
     * deleted secrets because migrations must never recreate credentials.
     */
    public function up(): void
    {
        DB::table('site_settings')
            ->whereIn('key', ['fonnte_token', 'fonnte_base_url'])
            ->delete();
    }

    /**
     * Stale credentials are not restored on rollback.
     */
    public function down(): void
    {
        // Irreversible stale-config cleanup; deleted secrets stay deleted.
    }
};