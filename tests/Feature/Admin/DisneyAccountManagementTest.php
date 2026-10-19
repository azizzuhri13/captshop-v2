<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\DisneyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DisneyAccountManagementTest extends TestCase
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

    public function test_admin_can_view_disney_accounts_index(): void
    {
        $admin = $this->createSuperAdmin();

        DisneyAccount::create([
            'email' => 'ready@disney.com',
            'password' => 'disney123',
            'profile_name' => 'PROFIL 1',
            'pin' => '1111',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.disney-accounts.index'));
        $response->assertStatus(200);
        $response->assertSee('Stok Akun Disney+');
        $response->assertSee('ready@disney.com');
        $response->assertSee('PROFIL 1');
    }

    public function test_admin_can_store_disney_account(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.disney-accounts.store'), [
            'email' => 'new@disney.com',
            'password' => 'secret99',
            'profile_name' => 'PROFIL 4',
            'pin' => '5678',
            'notes' => 'Catatan test',
        ]);

        $response->assertRedirect(route('admin.disney-accounts.index'));
        $this->assertDatabaseHas('disney_accounts', [
            'email' => 'new@disney.com',
            'profile_name' => 'PROFIL 4',
            'status' => 'ready',
        ]);
    }

    public function test_admin_can_import_csv(): void
    {
        $admin = $this->createSuperAdmin();

        $csvContent = "email,password,profile,pin,notes\n";
        $csvContent .= "acc1@disney.com,pass1,P1,1111,Batch 1\n";
        $csvContent .= "acc2@disney.com,pass2,P2,2222,Batch 1\n";

        $file = UploadedFile::fake()->createWithContent('disney.csv', $csvContent);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.disney-accounts.import'), [
            'csv_file' => $file,
        ]);

        $response->assertRedirect(route('admin.disney-accounts.index'));
        $this->assertDatabaseHas('disney_accounts', ['email' => 'acc1@disney.com']);
        $this->assertDatabaseHas('disney_accounts', ['email' => 'acc2@disney.com']);
    }

    public function test_admin_can_update_disney_account(): void
    {
        $admin = $this->createSuperAdmin();

        $acc = DisneyAccount::create([
            'email' => 'edit@disney.com',
            'password' => 'pass123',
            'profile_name' => 'Old',
            'pin' => '1111',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.disney-accounts.update', $acc), [
            'email' => 'edited@disney.com',
            'password' => 'passBaru',
            'profile_name' => 'New Profile',
            'pin' => '9999',
            'status' => 'sold',
            'notes' => 'Catatan baru',
        ]);

        $response->assertRedirect(route('admin.disney-accounts.index'));
        $this->assertDatabaseHas('disney_accounts', [
            'id' => $acc->id,
            'email' => 'edited@disney.com',
            'profile_name' => 'New Profile',
            'status' => 'sold',
        ]);
    }

    public function test_admin_cannot_delete_sold_account(): void
    {
        $admin = $this->createSuperAdmin();

        $soldAcc = DisneyAccount::create([
            'email' => 'sold@disney.com',
            'password' => 'p',
            'status' => 'sold',
        ]);

        $response = $this->actingAs($admin, 'admin')->delete(route('admin.disney-accounts.destroy', $soldAcc));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('disney_accounts', ['id' => $soldAcc->id]);
    }
}
