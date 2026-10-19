<?php

namespace Tests\Feature\Frontend;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('Masuk atau Daftar');
        $response->assertSee('Lanjutkan dengan Google');
        $response->assertSee(route('auth.google'));
    }

    public function test_authenticated_user_redirected_away_from_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get(route('login'));

        $response->assertRedirect(route('home'));
    }

    public function test_google_redirect_route_redirects_to_provider(): void
    {
        $response = $this->get(route('auth.google'));

        $response->assertStatus(302);
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_google_callback_creates_new_user_and_authenticates(): void
    {
        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-unique-id-12345');
        $abstractUser->shouldReceive('getName')->andReturn('Bolo Gamer');
        $abstractUser->shouldReceive('getEmail')->andReturn('bologamer@gmail.com');
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/avatar123');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated('web');

        $this->assertDatabaseHas('users', [
            'email' => 'bologamer@gmail.com',
            'google_id' => 'google-unique-id-12345',
            'name' => 'Bolo Gamer',
            'avatar' => 'https://lh3.googleusercontent.com/a/avatar123',
        ]);
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        $existing = User::create([
            'name' => 'Existing User',
            'email' => 'existing@gmail.com',
            'password' => bcrypt('secret123'),
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-id-existing-999');
        $abstractUser->shouldReceive('getName')->andReturn('Existing User');
        $abstractUser->shouldReceive('getEmail')->andReturn('existing@gmail.com');
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/avatar-existing');

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($existing, 'web');

        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'google_id' => 'google-id-existing-999',
            'avatar' => 'https://lh3.googleusercontent.com/a/avatar-existing',
        ]);
    }

    public function test_user_can_logout_and_session_is_cleared(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->post(route('logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest('web');
    }
}
