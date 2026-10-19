<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('thumbnail')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->foreignId('author_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('category')->nullable()->index();
            $table->json('tags')->nullable();
            $table->foreignId('related_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedSmallInteger('reading_time_minutes')->default(1);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
