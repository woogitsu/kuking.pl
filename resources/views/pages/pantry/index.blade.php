{{--
    „Co mam w domu” — prywatna lista produktów (V2, D-285).

    Jedno duże pole i jeden przycisk „Dodaj”. Pole działa bez skryptu:
    wpisana nazwa idzie zwykłym POST-em. Skrypt (`resources/js/co-mam-w-domu.js`)
    tylko DOKŁADA podpowiedzi ze słownika składników pod polem — jako
    przyciski z napisem, nie rozwijaną listę przeglądarki, bo `<datalist>`
    rysuje system i nie da się mu dać 18 px ani 48 px celu dotyku
    (AGENTS.md §5). Bez skryptu podpowiedzi po prostu nie ma; przycisk
    „Dodaj” działa tak samo (D-053: żadnego martwego przycisku).

    Usunięcie produktu wymaga pytania przy konkretnej pozycji (#2467).
    Pierwszy dotyk je rozwija, a „Anuluj” zamyka bez żądania do serwera.
    Dopiero osobny formularz wysyła DELETE; nazwa jest dostępna dla czytnika.
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

    @if($nowyProdukt)
        <p data-ustaw-termin>
            <a class="btn btn-secondary" href="{{ route('pantry.edit', $nowyProdukt) }}"
               aria-label="Ustaw termin: {{ $nowyProdukt->name }}">Ustaw termin</a>
            <span class="meta">Nie trzeba — możesz to zrobić później.</span>
        </p>
    @endif

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

            <section aria-labelledby="sekcja-pilne" data-sekcja="pilne">
                <h3 id="sekcja-pilne">Zużyj w pierwszej kolejności</h3>
                @if($grupy['pilne']->isEmpty())
                    <p>Nic nie wymaga pilnego zużycia. Dodaj terminy do produktów, a pokażemy je tutaj.</p>
                @else
                    @include('pages.pantry._produkty', ['lista' => $grupy['pilne'], 'dzis' => $dzis])
                    <p class="mt-4">
                        <a class="btn btn-primary" href="{{ route('pantry.cook', ['najpierw' => 'termin']) }}">Najpierw to, co się psuje</a>
                    </p>
                @endif
                <p data-regula-priorytetu>{{ $regula }}</p>
            </section>

            @if($grupy['po_terminie']->isNotEmpty())
                <section class="mt-8" aria-labelledby="sekcja-po-terminie" data-sekcja="po_terminie">
                    <h3 id="sekcja-po-terminie">Po terminie „Należy zużyć do”</h3>
                    <p>Nie podpowiadamy tych produktów do gotowania. Możesz poprawić wpisany termin albo usunąć produkt z listy.</p>
                    @include('pages.pantry._produkty', ['lista' => $grupy['po_terminie'], 'dzis' => $dzis])
                </section>
            @endif

            @if($grupy['pozniej']->isNotEmpty())
                <section class="mt-8" aria-labelledby="sekcja-pozniej" data-sekcja="pozniej">
                    <h3 id="sekcja-pozniej">Później</h3>
                    @include('pages.pantry._produkty', ['lista' => $grupy['pozniej'], 'dzis' => $dzis])
                </section>
            @endif

            @if($grupy['bez_terminu']->isNotEmpty())
                <section class="mt-8" aria-labelledby="sekcja-bez-terminu" data-sekcja="bez_terminu">
                    <h3 id="sekcja-bez-terminu">Bez terminu</h3>
                    @include('pages.pantry._produkty', ['lista' => $grupy['bez_terminu'], 'dzis' => $dzis])
                </section>
            @endif

            @if($grupy['mrozone']->isNotEmpty())
                <section class="mt-8" aria-labelledby="sekcja-mrozone" data-sekcja="mrozone">
                    <h3 id="sekcja-mrozone">Mrożone</h3>
                    @include('pages.pantry._produkty', ['lista' => $grupy['mrozone'], 'dzis' => $dzis])
                </section>
            @endif

            <p class="mt-6">
                <a class="btn btn-primary" href="{{ route('pantry.cook') }}">Co ugotuję z tego, co mam?</a>
            </p>
        @endif
    </section>

    {{-- SOBOTNIE PRZYPOMNIENIE (#1903, D-333) — OSOBNA zgoda, domyślnie
         wyłączona. Ani założenie listy, ani ustawienie terminu jej nie daje.
         Ukryte pole niesie stan widziany przy otwarciu strony (#879):
         stary formularz nie zapisze nikogo z powrotem na list, z którego
         wypisał się odnośnikiem. Działa bez skryptu. --}}
    <section class="sekcja-strony mt-8" aria-labelledby="sekcja-przypomnienie">
        <h2 class="mt-0" id="sekcja-przypomnienie">Sobotnie przypomnienie</h2>
        <form method="POST" action="{{ route('pantry.reminder') }}" novalidate>
            @csrf
            <input type="hidden" name="original_pantry_reminder" value="{{ (int) auth()->user()->wants_pantry_reminder }}">
            <div class="field">
                <label class="choice" for="f-wants_pantry_reminder">
                    <input id="f-wants_pantry_reminder" type="checkbox" name="wants_pantry_reminder" value="1"
                           @checked(auth()->user()->wants_pantry_reminder)>
                    <span>
                        <span class="choice-label">Chcę dostawać w sobotę e-mail o produktach do zużycia</span>
                        <span class="choice-help">Jeden list tygodniowo, rano, i tylko wtedy, gdy na liście jest produkt z terminem, który minął albo upływa w ciągu {{ \App\Domain\Pantry\PriorytetZuzycia::pilneDni() }} dni. Nie przypominamy o produktach po terminie „Należy zużyć do”. W liście są nazwy produktów z Twojej listy. Wypisać się możesz odnośnikiem na dole listu, bez logowania.</span>
                    </span>
                </label>
            </div>
            <button class="btn btn-secondary" type="submit">Zapisz wybór</button>
        </form>
    </section>

    <p class="mt-8">
        <a class="btn btn-quiet" href="{{ route('collections.index') }}">Wróć do zeszytu</a>
    </p>
</x-layout>
