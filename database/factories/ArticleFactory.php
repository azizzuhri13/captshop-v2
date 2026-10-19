<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'thumbnail' => 'articles/default.webp',
            'excerpt' => $this->faker->paragraph(2),
            'body' => '<p>' . $this->faker->paragraphs(3, true) . '</p>',
            'category' => 'Tips & Trik',
            'tags' => ['mlbb', 'game'],
            'views_count' => 0,
            'reading_time_minutes' => 3,
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
