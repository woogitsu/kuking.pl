@props(['board', 'zeszyt' => null, 'mojStol' => null])

{{--
    Prawa szyna strony startowej (UI kit v2, ekran 01).

    TRZY BLOKI I KAŻDY ODPOWIADA NA INNE PYTANIE
      „Mój zeszyt"          — co ja tu odłożyłem na potem;
      „kuKINGi na dziś"     — co dziś warto zobaczyć;
      „Poznaj ludzi"        — kogo zacząć obserwować.

    DLACZEGO TABLICA JEST TUTAJ, A NIE W GŁÓWNEJ KOLUMNIE
    Decyzja właściciela. W kicie tablicy nie ma w ogóle, ale usunięcie jej
    zabrałoby jedyną rzecz, która ratuje pusty feed nowej osoby
    (docs/product/COLD_START.md). Szyna jest kompromisem: tablica przestaje
    zajmować główną kolumnę, ale nie znika.

    NA TELEFONIE SZYNA JEST POD TREŚCIĄ, nie znika — patrz `.app-rail`.
    Ukrycie „Mojego zeszytu" na telefonie znaczyłoby, że połowa ludzi nie ma
    do niego dojścia ze strony startowej.
--}}

<x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" :wybrane="$board['wybrane']" :wKarcie="true" />

@if($zeszyt !== null && $zeszyt->isNotEmpty())
    <section class="card szyna-blok" aria-labelledby="szyna-zeszyt">
        <div class="szyna-naglowek">
            <h2 id="szyna-zeszyt" class="szyna-tytul">
                <x-ikona nazwa="book" :rozmiar="22" /> Mój zeszyt
            </h2>
            <a class="szyna-wiecej" href="{{ route('collections.index') }}">Zobacz wszystko</a>
        </div>

        <ul class="szyna-lista">
            @foreach($zeszyt as $pozycja)
                <li class="szyna-pozycja">
                    <a class="szyna-pozycja-link" href="{{ route('recipes.show', $pozycja->slug) }}">
                        <span class="szyna-miniatura">
                            <x-photo :media="$pozycja->heroMedia" variant="thumb" :zoom="false" sizes="72px" alt="" tresc="przepis" />
                        </span>
                        <span class="min-w-0">
                            <span class="szyna-nazwa">{{ $pozycja->title }}</span>
                            <span class="meta szyna-podpis">{{ $pozycja->author->displayName() }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{--
    „Mój stół" (issue #1749, D-304). Włączony: trzy pierwsze pozycje półki
    z powodem przy każdej i odnośnik do całej. Wyłączony: jedno zdanie, czym
    jest półka — bez żadnej propozycji (wyłączona półka niczego nie liczy).
--}}
<section class="card szyna-blok" aria-labelledby="szyna-moj-stol">
    <div class="szyna-naglowek">
        <h2 id="szyna-moj-stol" class="szyna-tytul">Mój stół</h2>
        <a class="szyna-wiecej" href="{{ route('moj-stol') }}">{{ $mojStol === null ? 'Jak to działa' : 'Cały Mój stół' }}</a>
    </div>
    @if($mojStol === null)
        <p class="meta m-0">Prywatna półka z przepisami z tagów, które obserwujesz. Jest wyłączona, dopóki jej nie włączysz.</p>
    @else
        @php
            $pozycjeStolu = collect($mojStol['z_tagow'])
                ->map(fn ($p) => ['post' => $p['post'], 'powod' => 'obserwujesz tag: '.$p['tag']->name.'.'])
                ->concat(collect($mojStol['od_gospodarza']['wpisy'] ?? [])
                    ->map(fn ($post) => ['post' => $post, 'powod' => 'gospodarz poleca tag: '.$mojStol['od_gospodarza']['tag']->name.'.']))
                ->concat(collect($mojStol['na_dzis'])
                    ->map(fn ($post) => ['post' => $post, 'powod' => 'gospodarz wybrał ten przepis na dziś.']))
                ->take(3);
        @endphp
        @if($pozycjeStolu->isEmpty())
            <p class="meta m-0">Na razie nie mamy tu czego pokazać.</p>
        @else
            <ul class="szyna-lista">
                @foreach($pozycjeStolu as $pozycja)
                    <x-moj-stol-pozycja :post="$pozycja['post']" :powod="$pozycja['powod']" :zAkcja="false" />
                @endforeach
            </ul>
        @endif
        <p class="m-0"><a href="{{ route('moj-stol') }}#moj-stol-dlaczego">Dlaczego to widzę</a></p>
    @endif
</section>
