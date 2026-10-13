<?php

namespace Tests\Feature\Frontend;

use App\Services\GameValidator\GameValidatorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameValidatorTest extends TestCase
{
    public function test_can_validate_free_fire_nickname_and_caches_result(): void
    {
        Cache::flush();

        Http::fake([
            'https://gamestoreindonesia.com/api/v1/order/prepare/FREEFIRE*' => Http::response([
                'message' => 'Success',
                'data' => 'ProPlayer_FF',
            ], 200),
        ]);

        $response = $this->postJson('/game/check-nickname', [
            'game_code' => 'free-fire',
            'user_id' => '12345678',
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'nickname' => 'ProPlayer_FF',
            ]);

        // Cek cache
        $this->assertTrue(Cache::has('game_nick:FREEFIRE:12345678:-'));
    }

    public function test_can_validate_mobile_legends_with_zone_id(): void
    {
        Cache::flush();

        Http::fake([
            'https://gamestoreindonesia.com/api/v1/order/prepare/MOBILE_LEGENDS*' => Http::response([
                'message' => 'Success',
                'data' => 'Vino_TZY',
            ], 200),
        ]);

        $response = $this->postJson('/game/check-nickname', [
            'game_code' => 'mobile-legends',
            'user_id' => '98765432',
            'zone_id' => '2001',
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'nickname' => 'Vino_TZY',
            ]);
    }

    public function test_fails_when_mlbb_zone_id_is_missing(): void
    {
        Cache::flush();

        $response = $this->postJson('/game/check-nickname', [
            'game_code' => 'mobile-legends',
            'user_id' => '98765432',
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'nickname' => null,
            ]);
    }

    public function test_returns_invalid_when_account_not_found(): void
    {
        Cache::flush();

        Http::fake([
            'https://gamestoreindonesia.com/api/v1/order/prepare/FREEFIRE*' => Http::response([
                'statusCode' => 404,
                'message' => 'Invalid User Account',
            ], 404),
            'https://cek-username.onrender.com/game/freefire*' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $response = $this->postJson('/game/check-nickname', [
            'game_code' => 'free-fire',
            'user_id' => '000000',
        ]);

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'nickname' => null,
            ]);
    }
}
