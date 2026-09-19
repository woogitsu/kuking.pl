{{--
    Spis wszystkich tagów (#273, druga połowa — słownik z D-026 dał
    zawartość, ta strona daje do niej wejście; pełne uzasadnienie kolejności
    w `docs/DECISIONS.md`, D-087).

    NA EKRANIE OBOWIĄZUJE JEDNO SŁOWO: „TAG". Do 11 września 2026 ta strona
    mówiła „tematy" — w nagłówku, w metadanych i w `aria-label` — a formularz
    publikacji prosił o „tagi". Dwa słowa na jedną rzecz to dokładnie ten stan,
    przez który D-021 usunęła obiekt `Temat`: osoba 50+ musiałaby zrozumieć,
    czym jedno różni się od drugiego, a produkt nie ma na to dobrej
    odpowiedzi. Słowo „temat" w znaczeniu klasyfikacji treści na ekran nie
    wraca; pilnuje tego `JednoSlowoNaTagiTest`.

    (Uwaga dla następnej osoby: „temat" ma w tym repozytorium DRUGIE,
    uprawnione znaczenie — temat listu, `app/Mail/*` i `app/Notifications/*`.
    Strażnik jest dlatego zawężony do ekranów tagów, a nie globalny.)

    Odpowiednik Garnkowej „fotofory": jawna, zamknięta lista, z której da
    się zacząć przeglądanie, nie znając wcześniej adresu żadnego tagu
    (docs/product/PROSTOTA_JAK_GARNEK.md §5a).

    DWIE SEKCJE, ŻADNA PO POPULARNOŚCI
      - „Polecane tagi" — wybór gospodarza (D-021), kolejność redakcyjna
        z panelu /admin/tagi-promowane.
      - „Wszystkie tagi" — alfabet, stronicowany „Pokaż więcej".
    Liczba wpisów przy każdym tagu jest PRAWDZIWA, także gdy wynosi zero
    (issue #273: „nie wolno wgrać fałszywych wpisów, żeby wyglądały na
    żywe") — pusty tag prowadzi do tego samego pustego stanu, co strona
    pojedynczego tagu już dziś pokazuje.

    OPIS DLA WYSZUKIWARKI MÓWI, CO NA TEJ STRONIE JEST (audyt tekstów
    11.09.2026). Stało w nim „Bez rankingu — kolejność to wybór gospodarza
    i alfabet.", czyli nasza polityka porządkowania treści. Człowiek
    w wynikach wyszukiwania szuka spisu tagów, nie naszej filozofii
    sortowania — a kto wejdzie, ten i tak czyta na stronie „Wybór gospodarza
    Kuking." oraz „Wszystkie tagi od A do Z". Sama reguła kolejności
    zostaje bez zmian; zniknął tylko jej opis w metadanych.
--}}
<x-layout
    title="Wszystkie tagi"
    description="Spis tagów w Kuking: dania, składniki, okazje i sposoby przygotowania. Przy każdym tagu liczba wpisów widocznych dla wszystkich.">
    <p class="meta mb-2">
        <a href="{{ route('discover') }}">Świeżo z <x-kuking-word /></a> · wszystkie tagi
    </p>

    <h1 class="mt-0">Wszystkie tagi</h1>

    <p class="lead tag-directory-intro">
        Tagi to sposób na przeglądanie bez konieczności znajomości nikogo —
        dania, składniki, okazje i sposoby przygotowania, tak jak ktoś je
        opisał przy swoim wpisie. Liczba obok nazwy to liczba wpisów
        widocznych dla wszystkich, także zero — pusty tag czeka na
        pierwszy wpis. Wpis prywatny albo tylko dla obserwujących do tej
        liczby nie wchodzi, nawet gdy jest Twój.
    </p>

    @if($polecane->isNotEmpty())
        <h2>Polecane tagi</h2>
        <p class="meta">Wybór gospodarza <x-kuking-word />.</p>
        <nav class="tag-featured" aria-label="Polecane tagi">
            @foreach($polecane as $tag)
                <a class="tag-featured-card" href="{{ route('tags.show', $tag) }}">
                    <x-tag-collage :photos="$collages[$tag->getKey()]" :linked="false" />
                    <span class="tag-featured-copy">
                    <strong>{{ $tag->name }}</strong>
                    @if($tag->promotion?->note)
                        <span>{{ $tag->promotion->note }}</span>
                    @endif
                    <span class="meta">
                    ({{ $tag->posts_count }}
                    {{ \App\Support\Odmiana::rzeczownik($tag->posts_count, 'wpis', 'wpisy', 'wpisów') }})
                    </span>
                    <x-tag-public-stats :stats="$publicStats[$tag->getKey()]" :invitation="false" />
                    <span class="tag-featured-action">Zobacz wpisy <span aria-hidden="true">→</span></span>
                    </span>
                </a>
            @endforeach
        </nav>
    @endif

    <h2>Wszystkie tagi od A do Z</h2>

    @if($tagi->isEmpty())
        <x-empty-state title="Tagi jeszcze się nie pojawiły">
            <p class="mb-0">
                Wróć tutaj, gdy gospodarz doda pierwsze propozycje — albo
                dodaj wpis z własnym tagiem, żeby być pierwszą osobą.
            </p>
        </x-empty-state>
    @else
        <nav class="tag-directory-grid" aria-label="Wszystkie tagi, alfabetycznie">
            @foreach($tagi as $tag)
                <x-tag-directory-card :tag="$tag" :photo="$collages[$tag->getKey()]->first()" :stats="$publicStats[$tag->getKey()]" />
            @endforeach
        </nav>

        <x-show-more :paginator="$tagi" czego="tagów" />
    @endif
</x-layout>
