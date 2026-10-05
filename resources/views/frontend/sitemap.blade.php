<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('home') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

@foreach ($categories as $category)
    <url>
        <loc>{{ route('category.show', $category->slug) }}</loc>
        @if ($category->updated_at)
            <lastmod>{{ $category->updated_at->toAtomString() }}</lastmod>
        @endif
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
@endforeach

@foreach ($products as $product)
    <url>
        <loc>{{ route('product.show', [$product->category->slug, $product->slug]) }}</loc>
        @if ($product->updated_at)
            <lastmod>{{ $product->updated_at->toAtomString() }}</lastmod>
        @endif
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
@endforeach

@foreach (['pages.about', 'pages.faq', 'pages.terms', 'pages.privacy'] as $staticRoute)
    <url>
        <loc>{{ route($staticRoute) }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.3</priority>
    </url>
@endforeach
</urlset>
