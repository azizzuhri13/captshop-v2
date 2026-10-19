<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\NetflixAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class NetflixAccountManagementTest extends TestCase
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

    public function test_admin_can_view_netflix_accounts_index(): void
    {
        $admin = $this->createSuperAdmin();

        NetflixAccount::create([
            'email' => 'ready@test.com',
            'password' => 'pass123',
            'profile_name' => 'PROFIL 1',
            'pin' => '1111',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.netflix-accounts.index'));
        $response->assertStatus(200);
        $response->assertSee('Stok Akun Netflix');
        $response->assertSee('ready@test.com');
        $response->assertSee('PROFIL 1');
    }

    public function test_admin_can_store_netflix_account(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.netflix-accounts.store'), [
            'email' => 'new@netflix.com',
            'password' => 'secret99',
            'profile_name' => 'PROFIL 4',
            'pin' => '5678',
            'notes' => 'Catatan test',
        ]);

        $response->assertRedirect(route('admin.netflix-accounts.index'));
        $this->assertDatabaseHas('netflix_accounts', [
            'email' => 'new@netflix.com',
            'profile_name' => 'PROFIL 4',
            'status' => 'ready',
        ]);
    }

    public function test_admin_can_import_csv(): void
    {
        $admin = $this->createSuperAdmin();

        $csvContent = "email,password,profile,pin,notes\n";
        $csvContent .= "acc1@gmail.com,pass1,P1,1111,Batch 1\n";
        $csvContent .= "acc2@gmail.com,pass2,P2,2222,Batch 2\n";

        $file = UploadedFile::fake()->createWithContent('accounts.csv', $csvContent);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.netflix-accounts.import'), [
            'csv_file' => $file,
        ]);

        $response->assertRedirect(route('admin.netflix-accounts.index'));
        $this->assertDatabaseHas('netflix_accounts', ['email' => 'acc1@gmail.com']);
        $this->assertDatabaseHas('netflix_accounts', ['email' => 'acc2@gmail.com']);
    }

    public function test_admin_can_update_netflix_account(): void
    {
        $admin = $this->createSuperAdmin();
        $account = NetflixAccount::create([
            'email' => 'old@gmail.com',
            'password' => 'oldpass',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.netflix-accounts.update', $account), [
            'email' => 'updated@gmail.com',
            'password' => 'newpass',
            'profile_name' => 'Profile VIP',
            'pin' => '8888',
            'status' => 'ready',
            'notes' => 'Catatan baru',
        ]);

        $response->assertRedirect(route('admin.netflix-accounts.index'));
        $this->assertDatabaseHas('netflix_accounts', [
            'id' => $account->id,
            'email' => 'updated@gmail.com',
            'profile_name' => 'Profile VIP',
            'pin' => '8888',
        ]);
    }

    public function test_admin_cannot_delete_sold_account(): void
    {
        $admin = $this->createSuperAdmin();

        $soldAcc = NetflixAccount::create([
            'email' => 'sold@netflix.com',
            'password' => 'secret',
            'status' => 'sold',
        ]);

        $response = $this->actingAs($admin, 'admin')->delete(route('admin.netflix-accounts.destroy', $soldAcc));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('netflix_accounts', ['id' => $soldAcc->id]);
    }
}
