@props(['stats', 'invitation' => true])
@if($stats['photosCount'] >= config('kuking.tag_public_stats.min_photos') && $stats['contributorsCount'] >= config('kuking.tag_public_stats.min_contributors'))
    <span class="meta" data-tag-public-stats>
        Publicznie: {{ $stats['photosCount'] }}
        {{ \App\Support\Odmiana::rzeczownik($stats['photosCount'], 'zdjęcie', 'zdjęcia', 'zdjęć') }}
        od {{ $stats['contributorsCount'] }}
        {{ $stats['contributorsCount'] === 1 ? 'osoby' : 'osób' }}.
    </span>
@elseif($invitation)
    <span class="meta" data-tag-public-stats>Pokaż, co gotujesz — dodaj swój wpis.</span>
@endif
