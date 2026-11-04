<?php

namespace Tests\Feature\Frontend;

use App\Models\SiteSetting;
use App\Support\SupportContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kontak CS publik: halaman /kontak-cs, blok footer global, dan sanitasi nilai.
 */
class SupportContactTest extends TestCase
{
    use RefreshDatabase;

    private const WA_URL = 'https://wa.me/6282260677717';

    private const IG_URL = 'https://www.instagram.com/captshop.id?cplk=b3RheWxxaHk1dWcw';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_kontak_cs_page_renders_whatsapp_and_instagram_links(): void
    {
        $response = $this->get('/kontak-cs');

        $response->assertOk();
        $response->assertSee('Kontak Customer Service');
        $response->assertSee('href="'.self::WA_URL.'"', false);
        $response->assertSee('href="'.self::IG_URL.'"', false);
        $response->assertSee('+62 822-6067-7717');
        $response->assertSee('@captshop.id');
    }

    public function test_footer_contact_block_is_global(): void
    {
        foreach (['/', '/faq', '/kontak-cs'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('data-cs="whatsapp"', false)
                ->assertSee('href="'.self::WA_URL.'"', false)
                ->assertSee('href="'.self::IG_URL.'"', false);
        }
    }

    public function test_short_kontak_url_redirects_permanently(): void
    {
        $this->get('/kontak')->assertStatus(301)->assertRedirect('/kontak-cs');
    }

    public function test_stored_settings_override_defaults_and_are_normalized(): void
    {
        SiteSetting::set('contact_whatsapp', '+62 812-3456-7890');
        SiteSetting::set('contact_instagram', 'https://instagram.com/captshop.id');

        $cs = SupportContact::resolve();

        $this->assertSame('6281234567890', $cs['whatsapp']);
        $this->assertSame('+62 812-3456-7890', $cs['whatsapp_display']);
        $this->assertSame('https://wa.me/6281234567890', $cs['whatsapp_url']);
        $this->assertSame('https://instagram.com/captshop.id', $cs['instagram']);

        $this->get('/kontak-cs')
            ->assertOk()
            ->assertSee('https://wa.me/6281234567890', false)
            ->assertSee('+62 812-3456-7890');
    }

    public function test_local_indonesian_number_is_converted_to_international(): void
    {
        $this->assertSame('6282260677717', SupportContact::normalizeWhatsapp('0822-6067-7717'));
        $this->assertSame('6282260677717', SupportContact::normalizeWhatsapp('822 6067 7717'));
        $this->assertNull(SupportContact::normalizeWhatsapp('12345'));
        $this->assertNull(SupportContact::normalizeWhatsapp(null));
    }

    public function test_unsafe_or_foreign_values_are_rejected(): void
    {
        SiteSetting::set('contact_whatsapp', '"><script>alert(1)</script>');
        SiteSetting::set('contact_instagram', 'https://www.instagram.com.evil.test/captshop.id');
        SiteSetting::set('contact_email', 'bukan-email');

        $cs = SupportContact::resolve();

        $this->assertNull($cs['whatsapp']);
        $this->assertNull($cs['whatsapp_url']);
        $this->assertNull($cs['instagram']);
        $this->assertNull($cs['email']);

        $this->get('/kontak-cs')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('evil.test', false)
            ->assertDontSee('data-cs="whatsapp"', false)
            ->assertDontSee('data-cs="instagram"', false)
            ->assertDontSee('data-cs="email"', false);
    }

    public function test_non_https_instagram_schemes_are_rejected(): void
    {
        foreach ([
            'javascript:alert(1)',
            'http://www.instagram.com/captshop.id',
            'https://evil.test/captshop.id',
            'https://user:pass@www.instagram.com/captshop.id',
            'https://www.instagram.com/captshop.id#x',
        ] as $candidate) {
            $this->assertNull(SupportContact::sanitizeInstagramUrl($candidate), $candidate);
        }

        $this->assertSame(self::IG_URL, SupportContact::sanitizeInstagramUrl(self::IG_URL));
    }

    public function test_layout_never_leaks_raw_stored_whatsapp_markup(): void
    {
        SiteSetting::set('contact_whatsapp', '6282260677717"><img src=x onerror=alert(1)>');

        $html = $this->get('/kontak-cs')->assertOk()->getContent();

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        // Hanya digit yang lolos ke href — tidak ada atribut/tag yang bisa disuntikkan.
        $this->assertMatchesRegularExpression('#href="https://wa\.me/628\d+"#', $html);
    }
}
