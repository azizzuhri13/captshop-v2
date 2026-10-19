<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArticleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): Admin
    {
        return Admin::create([
            'name' => 'Admin Test',
            'email' => 'admintest@captshop.id',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_articles_index(): void
    {
        $admin = $this->admin();
        Article::factory()->create(['title' => 'Artikel Uji Coba']);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.articles.index'));

        $response->assertStatus(200);
        $response->assertSee('Artikel Uji Coba');
    }

    public function test_admin_can_create_article(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.articles.store'), [
            'title' => 'Judul Artikel Baru',
            'category' => 'Tips & Trik',
            'excerpt' => 'Ringkasan artikel',
            'body' => '<h2>Subjudul</h2><p>Isi artikel menarik di sini.</p>',
            'thumbnail' => UploadedFile::fake()->image('cover.jpg', 600, 400),
            'is_published' => '1',
        ]);

        $response->assertRedirect(route('admin.articles.index'));
        $this->assertDatabaseHas('articles', [
            'title' => 'Judul Artikel Baru',
            'slug' => 'judul-artikel-baru',
            'category' => 'Tips & Trik',
            'is_published' => true,
        ]);
    }

    public function test_admin_can_update_article(): void
    {
        $admin = $this->admin();
        $article = Article::factory()->create(['title' => 'Judul Lama']);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.articles.update', $article), [
            'title' => 'Judul Diperbarui',
            'category' => 'Event Game',
            'body' => '<p>Konten baru</p>',
            'is_published' => '1',
        ]);

        $response->assertRedirect(route('admin.articles.index'));
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Judul Diperbarui',
            'category' => 'Event Game',
        ]);
    }

    public function test_admin_can_delete_article(): void
    {
        $admin = $this->admin();
        $article = Article::factory()->create(['title' => 'Akan Dihapus']);

        $response = $this->actingAs($admin, 'admin')->delete(route('admin.articles.destroy', $article));

        $response->assertRedirect(route('admin.articles.index'));
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
    }
}
