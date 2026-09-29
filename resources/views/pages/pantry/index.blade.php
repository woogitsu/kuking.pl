{{--
    „Co mam w domu” — prywatna lista produktów (V2, D-285).

    Jedno duże pole i jeden przycisk „Dodaj”. Pole działa bez skryptu:
    wpisana nazwa idzie zwykłym POST-em. Skrypt (`resources/js/co-mam-w-domu.js`)
    tylko DOKŁADA podpowiedzi ze słownika składników pod polem — jako
    przyciski z napisem, nie rozwijaną listę przeglądarki, bo `<datalist>`
    rysuje system i nie da się mu dać 18 px ani 48 px celu dotyku
    (AGENTS.md §5). Bez skryptu podpowiedzi po prostu nie ma; przycisk
    „Dodaj” działa tak samo (D-053: żadnego martwego przycisku).

    Usunięcie produktu nie ma potwierdzenia: to jedna linijka na własnej
    liście, którą dopisuje się z powrotem jednym polem — nie treść, nie
    dorobek i nic, co widzi ktoś inny. Przycisk ma pełny napis z nazwą
    produktu, więc czytnik ekranu nie czyta dziesięć razy samego „Usuń”.
--}}
<x-layout title="Co mam w domu" :noindex="true">
    <h1>Co mam w domu</h1>

    <p class="text-lead">
        Wpisz, co masz w kuchni. Na tej podstawie pokażemy przepisy, do których brakuje Ci najmniej.
        Tę listę widzisz tylko Ty.
    </p>

    <x-error-summary />

    <section class="sekcja-strony" data-co-mam-w-domu data-podpowiedzi-url="{{ route('pantry.suggestions') }}">
        <form method="POST" action="{{ route('pantry.store') }}">
            @csrf
            <x-field name="nazwa" label="Co masz w domu?" :bezOznaczenia="true" autocomplete="off"
                     help="Jeden produkt naraz, na przykład „mąka”, „jajka” albo „ser żółty”. Polskie znaki i liczba mnoga nie mają znaczenia." />

            {{-- Tu skrypt wstawia podpowiedzi. Pusty kontener nic nie
                 pokazuje, więc bez skryptu nie ma czego ukrywać. --}}
            <div data-podpowiedzi hidden>
                <p class="font-bold mb-2" id="podpowiedzi-naglowek">Podpowiedzi — dotknij, żeby wpisać</p>
                <ul class="lista-naga stack-tight mb-3" aria-labelledby="podpowiedzi-naglowek" data-podpowiedzi-lista></ul>
            </div>
            <p class="sr-only" role="status" aria-live="polite" data-podpowiedzi-status></p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Dodaj do listy</button>
            </div>
        </form>
    </section>

    <section class="sekcja-strony mt-8">
        <h2 class="mt-0">Twoja lista</h2>

        @if($produkty->isEmpty())
            <p>
                Lista jest jeszcze pusta. Dodaj kilka produktów, które masz teraz w domu —
                wystarczy pięć, żeby zobaczyć pierwsze propozycje.
            </p>
        @else
            <p role="status">
                Masz na liście {{ $produkty->count() }} {{ \App\Support\Odmiana::rzeczownik($produkty->count(), 'produkt', 'produkty', 'produktów') }}
                (najwyżej {{ $maksProduktow }}).
            </p>

            <ul class="lista-naga stack-tight">
                @foreach($produkty as $produkt)
                    <li class="card flex flex-wrap items-center justify-between gap-3">
                        <span class="text-lg">{{ $produkt->name }}</span>
                        <form method="POST" action="{{ route('pantry.destroy', $produkt) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-secondary" type="submit"
                                    aria-label="Usuń z listy: {{ $produkt->name }}">Usuń</button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <p class="mt-6">
                <a class="btn btn-primary" href="{{ route('pantry.cook') }}">Co ugotuję z tego, co mam?</a>
            </p>
        @endif
    </section>

    <p class="mt-8">
        <a class="btn btn-quiet" href="{{ route('collections.index') }}">Wróć do zeszytu</a>
    </p>
</x-layout>
