@props(['board', 'zeszyt' => null])

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
                            <x-photo :media="$pozycja->heroMedia" variant="thumb" :zoom="false" sizes="72px" alt="" />
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

<x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />
