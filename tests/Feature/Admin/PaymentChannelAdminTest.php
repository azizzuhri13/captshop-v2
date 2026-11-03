<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\PaymentChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel admin channel pembayaran harus mengelola baris Tripay juga, bukan
 * hanya Belibayar. Baris gateway tak didukung dan baris Tripay ber-kode
 * provider lama (BCAVA) harus ditolak, karena checkout hanya mengenal kode
 * internal (BCA) dari config('payment.tripay.channels').
 */
class PaymentChannelAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');
    }

    private function channel(array $attributes = []): PaymentChannel
    {
        return PaymentChannel::create(array_merge([
            'gateway' => 'tripay',
            'code' => 'BCA',
            'name' => 'BCA Virtual Account',
            'group' => 'Virtual Account',
            'flat_fee' => '0',
            'percent_fee' => '0',
            'is_active' => false,
        ], $attributes));
    }

    public function test_index_lists_both_gateways_with_gateway_qualified_groups(): void
    {
        $this->channel();
        $this->channel(['gateway' => 'belibayar', 'code' => 'QRIS', 'name' => 'QRIS', 'group' => 'QRIS']);

        $response = $this->get(route('admin.payment-channels.index'));

        $response->assertOk()
            ->assertSee('Tripay — Virtual Account')
            ->assertSee('Belibayar — QRIS')
            ->assertSee('tripay:BCA')
            ->assertSee('belibayar:QRIS');

        $groups = $response->viewData('channels');

        $this->assertArrayHasKey('Tripay — Virtual Account', $groups->all());
        $this->assertArrayHasKey('Belibayar — QRIS', $groups->all());
    }

    public function test_admin_can_edit_update_and_toggle_tripay_channel(): void
    {
        $channel = $this->channel();

        $this->get(route('admin.payment-channels.edit', $channel))
            ->assertOk()
            ->assertSee('BCA Virtual Account');

        $this->put(route('admin.payment-channels.update', $channel), [
            'name' => 'BCA VA (admin)',
            'group' => 'Virtual Account',
            'flat_fee' => '4500',
            'percent_fee' => '0.70',
            'sort_order' => 3,
            'is_active' => '1',
        ])->assertRedirect(route('admin.payment-channels.index'));

        $channel->refresh();
        $this->assertTrue($channel->is_active, 'Admin harus bisa mengaktifkan kanal Tripay.');
        $this->assertSame('BCA VA (admin)', $channel->name);
        $this->assertSame('4500.00', (string) $channel->flat_fee);
        $this->assertSame('0.70', (string) $channel->percent_fee);

        $this->post(route('admin.payment-channels.toggle', $channel))->assertRedirect();
        $this->assertFalse($channel->fresh()->is_active);

        $this->post(route('admin.payment-channels.toggle', $channel))->assertRedirect();
        $this->assertTrue($channel->fresh()->is_active);
    }

    public function test_unsupported_gateway_rows_are_rejected_and_hidden(): void
    {
        $legacy = $this->channel([
            'gateway' => 'midtrans',
            'code' => 'BCA',
            'name' => 'Midtrans BCA',
        ]);

        $this->get(route('admin.payment-channels.index'))
            ->assertOk()
            ->assertDontSee('midtrans:BCA');

        $this->get(route('admin.payment-channels.edit', $legacy))->assertStatus(404);
        $this->put(route('admin.payment-channels.update', $legacy), [
            'name' => 'Midtrans BCA',
            'group' => 'Virtual Account',
            'flat_fee' => '0',
            'percent_fee' => '0',
            'is_active' => '1',
        ])->assertStatus(404);
        $this->post(route('admin.payment-channels.toggle', $legacy))->assertStatus(404);

        $this->assertFalse($legacy->fresh()->is_active);
    }

    public function test_tripay_rows_with_legacy_provider_codes_are_rejected_and_hidden(): void
    {
        $providerCode = $this->channel([
            'code' => 'BCAVA',
            'name' => 'BCA VA (kode provider)',
            'is_active' => true,
        ]);

        $this->get(route('admin.payment-channels.index'))
            ->assertOk()
            ->assertDontSee('tripay:BCAVA');

        $this->get(route('admin.payment-channels.edit', $providerCode))->assertStatus(422);
        $this->put(route('admin.payment-channels.update', $providerCode), [
            'name' => 'BCA VA (kode provider)',
            'group' => 'Virtual Account',
            'flat_fee' => '0',
            'percent_fee' => '0',
            'is_active' => '1',
        ])->assertStatus(422);
        $this->post(route('admin.payment-channels.toggle', $providerCode))->assertStatus(422);

        $this->assertTrue($providerCode->fresh()->is_active, 'Penolakan tidak boleh mengubah baris.');
    }
}