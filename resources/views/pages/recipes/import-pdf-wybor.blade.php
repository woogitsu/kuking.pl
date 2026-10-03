{{--
    WYBÓR STRON PDF PRZED ODCZYTEM (#2535, V2, decyzja właściciela z 2.10.2026).

    Dwa stany tego samego adresu:
      - „przygotowanie”: zadanie robi miniatury i podpisy stron (lokalnie, bez AI);
        strona odświeża się sama (nagłówek `Refresh`), a bez tego jest zwykły link;
      - „gotowy”: lista stron z dużymi polami wyboru. Każde pole ma widoczny napis
        „Strona N” — miniatura jest dodatkiem, nie jedynym opisem, i nic tu nie
        wymaga przeciągania. Odczyt rusza dopiero po przycisku na dole, a zgoda na
        wysłanie skanu jest TU, osobno (wybranie stron nie jest zgodą).

    Pomocnicza informacja o zgodzie to ten sam komponent co w formularzu PDF.
--}}
<x-layout title="Wybierz strony z przepisem" :noindex="true">
    <x-zakladki-dodawania aktywna="przepis" />

    <h1>Wybierz strony z przepisem</h1>

    @if($stan === \App\Support\Storage\PoczekalniaPdf::STAN_PRZYGOTOWANIE)
        <p role="status" data-przygotowanie-podgladu>
            Przygotowujemy podgląd stron Twojego pliku. To trwa chwilę — strona odświeży się sama.
            Nic nie jest jeszcze odczytywane ani wysyłane.
        </p>
        <p><a class="btn btn-secondary" href="{{ route('recipes.import.pdf.wybor', $token) }}">Sprawdź, czy już gotowe</a></p>
        <form method="POST" action="{{ route('recipes.import.pdf.wybor.destroy', $token) }}" novalidate>
            @csrf @method('DELETE')
            <button class="btn btn-quiet" type="submit">Nie chcę, usuń ten plik</button>
        </form>
    @else
        <p>
            Zaznacz <strong>strony, na których jest jeden przepis</strong>. Tylko te strony odczytamy.
            Pozostałych stron pliku nie czytamy i nie wysyłamy nigdzie. Kolejność stron zostaje taka jak w pliku.
        </p>

        <x-error-summary />

        <form class="panel-formularza" method="POST" action="{{ route('recipes.import.pdf.wybor.store', $token) }}" novalidate>
            @csrf
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">

            <fieldset class="border-0 p-0 field @error('strony') has-error @enderror" id="f-strony"
                      @error('strony') aria-invalid="true" aria-describedby="f-strony-error" @enderror>
                <legend class="font-bold mb-3">Które strony zawierają ten przepis? (plik ma {{ $strony }} {{ \App\Support\Odmiana::rzeczownik($strony, 'stronę', 'strony', 'stron') }})</legend>
                <div class="stack">
                    @for($numer = 1; $numer <= $strony; $numer++)
                        @php($fragment = $fragmenty[$numer - 1] ?? '')
                        <label class="choice" data-wybor-strony="{{ $numer }}">
                            <input type="checkbox" name="strony[]" value="{{ $numer }}" @checked(in_array((string) $numer, array_map('strval', (array) old('strony', [])), true))>
                            <span class="choice-label">
                                Strona {{ $numer }} z {{ $strony }}
                                <span class="choice-help">{{ $fragment !== '' ? $fragment : 'Bez tekstu do odczytania u nas — to może być skan albo zdjęcie.' }}</span>
                            </span>
                            <img src="{{ route('recipes.import.pdf.miniatura', [$token, $numer]) }}" alt="Podgląd strony {{ $numer }}" width="180" loading="lazy">
                        </label>
                    @endfor
                </div>
                @error('strony')<span class="field-error" id="f-strony-error">{{ $message }}</span>@enderror
            </fieldset>

            <p class="meta">
                Jeśli to skan bez tekstu, wyślemy do OpenAI w USA <strong>obrazy tylko zaznaczonych stron</strong> — za Twoją zgodą poniżej.
                Plik z tekstem odczytamy u siebie.
            </p>
            <x-zgoda-zrodlo-ai zrodlo="pdf" />

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Odczytaj zaznaczone strony</button>
            </div>
        </form>

        <form method="POST" action="{{ route('recipes.import.pdf.wybor.destroy', $token) }}" class="mt-6" novalidate>
            @csrf @method('DELETE')
            <button class="btn btn-secondary" type="submit">Nie, usuń ten plik</button>
        </form>
    @endif

    <p class="mt-6"><a href="{{ route('recipes.create') }}">Wolę wpisać przepis ręcznie</a></p>
</x-layout>
