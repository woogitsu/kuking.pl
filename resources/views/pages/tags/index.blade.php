{{--
    Spis wszystkich tematów (#273, druga połowa — słownik z D-026 dał
    zawartość, ta strona daje do niej wejście; pełne uzasadnienie kolejności
    w `docs/DECISIONS.md`, D-087).

    Odpowiednik Garnkowej „fotofory": jawna, zamknięta lista, z której da
    się zacząć przeglądanie, nie znając wcześniej adresu żadnego tematu
    (docs/product/PROSTOTA_JAK_GARNEK.md §5a).

    DWIE SEKCJE, ŻADNA PO POPULARNOŚCI
      - „Polecane tematy" — wybór gospodarza (D-021), kolejność redakcyjna
        z panelu /admin/tagi-promowane.
      - „Wszystkie tematy" — alfabet, stronicowany „Pokaż więcej".
    Liczba wpisów przy każdym temacie jest PRAWDZIWA, także gdy wynosi zero
    (issue #273: „nie wolno wgrać fałszywych wpisów, żeby wyglądały na
    żywe") — pusty temat prowadzi do tego samego pustego stanu, co strona
    pojedynczego tagu już dziś pokazuje.

    OPIS DLA WYSZUKIWARKI MÓWI, CO NA TEJ STRONIE JEST (audyt tekstów
    11.09.2026). Stało w nim „Bez rankingu — kolejność to wybór gospodarza
    i alfabet.", czyli nasza polityka porządkowania treści. Człowiek
    w wynikach wyszukiwania szuka spisu tematów, nie naszej filozofii
    sortowania — a kto wejdzie, ten i tak czyta na stronie „Wybór gospodarza
    Kuking." oraz „Wszystkie tematy od A do Z". Sama reguła kolejności
    zostaje bez zmian; zniknął tylko jej opis w metadanych.
--}}
<x-layout
    title="Wszystkie tematy"
    description="Spis tematów w Kuking: dania, składniki, okazje i sposoby przygotowania. Przy każdym temacie liczba wpisów widocznych dla wszystkich.">
    <p class="meta mb-2">
        <a href="{{ route('discover') }}">Świeżo z Kuking</a> · wszystkie tematy
    </p>

    <h1 class="mt-0">Wszystkie tematy</h1>

    <p class="lead">
        Tematy to sposób na przeglądanie bez konieczności znajomości nikogo —
        dania, składniki, okazje i sposoby przygotowania, tak jak ktoś je
        opisał przy swoim wpisie. Liczba obok nazwy to liczba wpisów
        widocznych dla wszystkich, także zero — pusty temat czeka na
        pierwszy wpis.
    </p>

    @if($polecane->isNotEmpty())
        <h2>Polecane tematy</h2>
        <p class="meta">Wybór gospodarza Kuking.</p>
        <nav class="chipsy" aria-label="Polecane tematy">
            @foreach($polecane as $tag)
                <a class="chip" href="{{ route('tags.show', $tag) }}">
                    {{ $tag->name }}
                    ({{ $tag->posts_count }}
                    {{ \App\Support\Odmiana::rzeczownik($tag->posts_count, 'wpis', 'wpisy', 'wpisów') }})
                </a>
            @endforeach
        </nav>
    @endif

    <h2>Wszystkie tematy od A do Z</h2>

    @if($tematy->isEmpty())
        <x-empty-state title="Tematy jeszcze się nie pojawiły">
            <p class="mb-0">
                Wróć tutaj, gdy gospodarz doda pierwsze propozycje — albo
                dodaj wpis z własnym tematem, żeby być pierwszą osobą.
            </p>
        </x-empty-state>
    @else
        <nav class="chipsy" aria-label="Wszystkie tematy, alfabetycznie">
            @foreach($tematy as $tag)
                <a class="chip" href="{{ route('tags.show', $tag) }}">
                    {{ $tag->name }}
                    ({{ $tag->posts_count }}
                    {{ \App\Support\Odmiana::rzeczownik($tag->posts_count, 'wpis', 'wpisy', 'wpisów') }})
                </a>
            @endforeach
        </nav>

        <x-show-more :paginator="$tematy" czego="tematów" />
    @endif
</x-layout>
