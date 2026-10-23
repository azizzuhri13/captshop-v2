<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CapcutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CapcutAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function createSuperAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_capcut_accounts_index(): void
    {
        $admin = $this->createSuperAdmin();

        CapcutAccount::create([
            'email' => 'ready@capcut.com',
            'password' => 'capcut123',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.capcut-accounts.index'));
        $response->assertStatus(200);
        $response->assertSee('Stok Akun CapCut');
        $response->assertSee('ready@capcut.com');
    }

    public function test_ready_web_stock_without_transaction_shows_no_related_transaction(): void
    {
        $admin = $this->createSuperAdmin();

        CapcutAccount::create([
            'email' => 'unlinked@capcut.com',
            'password' => 'capcut123',
            'status' => 'ready',
            'channel' => 'web',
            'transaction_id' => null,
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.capcut-accounts.index'));

        $response->assertStatus(200);
        $response->assertSee('unlinked@capcut.com');
        $response->assertSee('—');
        $response->assertDontSee('🌐 Web');
        $response->assertDontSee('INV-CAPCUT-UNLINKED');
    }

    public function test_admin_can_store_capcut_account(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.capcut-accounts.store'), [
            'email' => 'new@capcut.com',
            'password' => 'secret99',
            'notes' => 'Catatan test capcut',
        ]);

        $response->assertRedirect(route('admin.capcut-accounts.index'));
        $this->assertDatabaseHas('capcut_accounts', [
            'email' => 'new@capcut.com',
            'status' => 'ready',
        ]);
    }

    public function test_admin_can_import_csv(): void
    {
        $admin = $this->createSuperAdmin();

        $csvContent = "email,password,notes\n";
        $csvContent .= "acc1@capcut.com,pass1,Batch 1\n";
        $csvContent .= "acc2@capcut.com,pass2,Batch 1\n";

        $file = UploadedFile::fake()->createWithContent('capcut.csv', $csvContent);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.capcut-accounts.import'), [
            'csv_file' => $file,
        ]);

        $response->assertRedirect(route('admin.capcut-accounts.index'));
        $this->assertDatabaseHas('capcut_accounts', ['email' => 'acc1@capcut.com']);
        $this->assertDatabaseHas('capcut_accounts', ['email' => 'acc2@capcut.com']);
    }

    public function test_admin_can_update_capcut_account(): void
    {
        $admin = $this->createSuperAdmin();

        $acc = CapcutAccount::create([
            'email' => 'old@capcut.com',
            'password' => 'oldpass',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.capcut-accounts.update', $acc), [
            'email' => 'updated@capcut.com',
            'password' => 'newpass',
            'status' => 'sold',
            'notes' => 'Diperbarui via test',
        ]);

        $response->assertRedirect(route('admin.capcut-accounts.index'));
        $this->assertDatabaseHas('capcut_accounts', [
            'id' => $acc->id,
            'email' => 'updated@capcut.com',
            'status' => 'sold',
        ]);
    }

    public function test_admin_cannot_delete_sold_account(): void
    {
        $admin = $this->createSuperAdmin();

        $soldAcc = CapcutAccount::create([
            'email' => 'sold@capcut.com',
            'password' => 'pass',
            'status' => 'sold',
        ]);

        $response = $this->actingAs($admin, 'admin')->delete(route('admin.capcut-accounts.destroy', $soldAcc));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('capcut_accounts', ['id' => $soldAcc->id]);
    }
}
