@props(['post', 'powod', 'zAkcja' => true])

{{--
    Jedna pozycja na półce „Mój stół" (issue #1749, D-304).

    Każda pozycja mówi, SKĄD się wzięła („Pokazujemy, bo…") — reguła doboru
    jest jawna na poziomie półki (`MojStol::DLACZEGO`) i na poziomie pozycji.
    „Nie pokazuj mi tego" to zwykły formularz „Ukryj ten wpis" z #1810:
    ukrycie tylko dla widza, z „Cofnij" po powrocie i na liście „Ukryte".
    Tekst na przycisku, bez ikon, bez hover — cel 48 px (`.btn`).
--}}
<li class="szyna-pozycja stack-tight" data-moj-stol-pozycja="{{ $post->getKey() }}">
    <a class="szyna-pozycja-link" href="{{ route('recipes.show', $post->recipe->slug) }}">
        <span class="szyna-miniatura">
            <x-photo :media="$post->recipe->heroMedia ?? $post->media->first()" variant="thumb" :zoom="false" sizes="72px" alt="" tresc="przepis" />
        </span>
        <span class="min-w-0">
            <span class="szyna-nazwa">{{ $post->recipe->title }}</span>
            <span class="meta szyna-podpis">{{ $post->author->displayName() }}</span>
        </span>
    </a>
    <p class="meta m-0">Pokazujemy, bo {{ $powod }}</p>
    @if($zAkcja)
        <form method="POST" action="{{ route('posts.hide', $post) }}">
            @csrf
            <button class="btn btn-quiet" type="submit">Nie pokazuj mi tego</button>
        </form>
    @endif
</li>
