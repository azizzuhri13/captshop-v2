<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CanvaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CanvaAccountManagementTest extends TestCase
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

    public function test_admin_can_view_canva_accounts_index(): void
    {
        $admin = $this->createSuperAdmin();

        CanvaAccount::create([
            'email' => 'ready@canva.com',
            'password' => 'canva123',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.canva-accounts.index'));
        $response->assertStatus(200);
        $response->assertSee('Stok Akun Canva Pro');
        $response->assertSee('ready@canva.com');
    }

    public function test_admin_can_store_canva_account(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.canva-accounts.store'), [
            'email' => 'new@canva.com',
            'password' => 'secret99',
            'notes' => 'Catatan test canva',
        ]);

        $response->assertRedirect(route('admin.canva-accounts.index'));
        $this->assertDatabaseHas('canva_accounts', [
            'email' => 'new@canva.com',
            'status' => 'ready',
        ]);
    }

    public function test_admin_can_import_csv(): void
    {
        $admin = $this->createSuperAdmin();

        $csvContent = "email,password,notes\n";
        $csvContent .= "acc1@canva.com,pass1,Batch 1\n";
        $csvContent .= "acc2@canva.com,pass2,Batch 1\n";

        $file = UploadedFile::fake()->createWithContent('canva.csv', $csvContent);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.canva-accounts.import'), [
            'csv_file' => $file,
        ]);

        $response->assertRedirect(route('admin.canva-accounts.index'));
        $this->assertDatabaseHas('canva_accounts', ['email' => 'acc1@canva.com']);
        $this->assertDatabaseHas('canva_accounts', ['email' => 'acc2@canva.com']);
    }

    public function test_admin_can_update_canva_account(): void
    {
        $admin = $this->createSuperAdmin();

        $acc = CanvaAccount::create([
            'email' => 'edit@canva.com',
            'password' => 'pass123',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.canva-accounts.update', $acc), [
            'email' => 'edited@canva.com',
            'password' => 'passBaru',
            'status' => 'sold',
            'notes' => 'Catatan baru',
        ]);

        $response->assertRedirect(route('admin.canva-accounts.index'));
        $this->assertDatabaseHas('canva_accounts', [
            'id' => $acc->id,
            'email' => 'edited@canva.com',
            'status' => 'sold',
        ]);
    }

    public function test_admin_cannot_delete_sold_account(): void
    {
        $admin = $this->createSuperAdmin();

        $soldAcc = CanvaAccount::create([
            'email' => 'sold@canva.com',
            'password' => 'p',
            'status' => 'sold',
        ]);

        $response = $this->actingAs($admin, 'admin')->delete(route('admin.canva-accounts.destroy', $soldAcc));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('canva_accounts', ['id' => $soldAcc->id]);
    }
}
