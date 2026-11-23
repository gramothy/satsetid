@props(['restaurant', 'variant' => 'card', 'loading' => 'lazy'])

@php
    $fallbackImages = [
        'A' => 'images/restaurants/ayam-bakar.jpg',
        'B' => 'images/restaurants/ayam-geprek.jpg',
        'C' => 'images/restaurants/rice-bowl.jpg',
    ];

    $image = $restaurant->image ?: ($fallbackImages[strtoupper((string) $restaurant->prefix)] ?? 'images/restaurants/fallback.jpg');
    $isRemote = \Illuminate\Support\Str::startsWith($image, ['http://', 'https://']);
    $imageUrl = $isRemote ? $image : asset(ltrim($image, '/'));

    // A WebP sibling (same name, .webp) is served to browsers that support it; the original stays the fallback.
    $webpUrl = null;
    if (! $isRemote && preg_match('/\.(jpe?g|png)$/i', $image)) {
        $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', ltrim($image, '/'));
        if (file_exists(public_path($webpPath))) {
            $webpUrl = asset($webpPath);
        }
    }
@endphp

<div {{ $attributes->class(['restaurant-image', 'restaurant-image-'.$variant]) }}>
    <picture>
        @if($webpUrl)<source type="image/webp" srcset="{{ $webpUrl }}">@endif
        <img src="{{ $imageUrl }}" alt="Foto {{ $restaurant->name }}" loading="{{ $loading }}" decoding="async">
    </picture>
    <span>{{ $restaurant->category }}</span>
</div>
