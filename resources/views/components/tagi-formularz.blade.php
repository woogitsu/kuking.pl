@props(['tagNames' => [], 'sugestieTagow' => null])

{{--
    Sekcja „Tagi" w formularzu wpisu (D-021) — dodawanie, szukanie i usuwanie
    DZIAŁA BEZ JAVASCRIPTU (AGENTS.md §5). Trzy osobne przyciski w TYM SAMYM
    formularzu co „Opublikuj"/„Zapisz zmiany": „Znajdź tag", „Dodaj" przy
    każdej podpowiedzi i „Usuń" przy każdym wybranym tagu. Każde kliknięcie
    to zwykły POST przeładowujący stronę — `PostController` rozpoznaje, że to
    krok pośredni, i NIE próbuje wtedy publikować wpisu.

    Wpisane i wybrane tagi PRZETRWAJĄ błąd walidacji treści/widoczności —
    trafiają do formularza jako ukryte pola, dokładnie jak zdjęcia (audyt C1).

    Progressive enhancement: ten sam formularz działa identycznie z i bez
    JavaScriptu. Nie ma tu żadnego skryptu — a nie musi być, żeby działać.
--}}
@php
    $sugestieTagow ??= collect();
    $limit = \App\Support\LimityTagow::maksTagowNaWpis();
    $limitOsiagniety = count($tagNames) >= $limit;
    $zapytanie = trim((string) old('tag_query', ''));
    $pasujeDokladnie = $sugestieTagow->contains(
        fn ($tag) => mb_strtolower($tag->name) === mb_strtolower($zapytanie),
    );
@endphp

<div class="mt-6" id="f-tagi">
    <h2 class="font-bold mb-1">Tagi <span class="meta">(maksymalnie {{ $limit }})</span></h2>
    <p class="field-help mb-3">
        Tagi pomagają innym znaleźć Twój wpis, a Tobie — trafić na ludzi,
        którzy gotują to samo. Możesz to pominąć.
    </p>

    @error('tagi')
        <p class="field-error mb-3">{{ $message }}</p>
    @enderror

    @if(count($tagNames) > 0)
        <ul class="lista-naga stack-tight mb-4" aria-label="Dodane tagi">
            @foreach($tagNames as $nazwaTagu)
                <li class="flex items-center justify-between gap-3">
                    <input type="hidden" name="tag_names[]" value="{{ $nazwaTagu }}">
                    <span class="font-bold">{{ $nazwaTagu }}</span>
                    <button class="btn btn-quiet" type="submit" name="usun_tag" value="{{ $nazwaTagu }}">
                        Usuń
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if($limitOsiagniety)
        <p class="field-help">Masz już maksymalną liczbę tagów. Usuń jeden, żeby dodać inny.</p>
    @else
        <label for="f-tag-query">Znajdź albo dodaj tag</label>
        <div class="flex flex-wrap gap-3 mt-2">
            <input
                class="field-input max-w-xs"
                id="f-tag-query"
                type="text"
                name="tag_query"
                value="{{ $zapytanie }}"
                maxlength="{{ \App\Support\LimityTagow::maksZnakow() }}"
                aria-describedby="f-tag-query-help"
            >
            <button class="btn btn-secondary" type="submit" name="szukaj_tagu" value="1">
                Znajdź tag
            </button>
        </div>
        <span class="field-help" id="f-tag-query-help">Na przykład: sernik, zupa pomidorowa, bez glutenu.</span>

        @if($sugestieTagow->isNotEmpty())
            <p class="font-bold mt-4 mb-2">Podpowiedzi</p>
            <ul class="lista-naga stack-tight mb-3" aria-label="Podpowiedzi tagów">
                @foreach($sugestieTagow as $sugestia)
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ $sugestia->name }}</span>
                        <button class="btn btn-quiet" type="submit" name="dodaj_tag" value="{{ $sugestia->name }}">
                            Dodaj
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($zapytanie !== '')
            <p class="field-help mt-3">Nic nie znaleźliśmy — możesz dodać ten tag jako nowy.</p>
        @endif

        @if($zapytanie !== '' && mb_strlen($zapytanie) >= \App\Support\LimityTagow::minZnakow() && ! $pasujeDokladnie)
            <p class="mt-3">
                <button class="btn btn-quiet" type="submit" name="dodaj_tag" value="{{ $zapytanie }}">
                    Dodaj „{{ $zapytanie }}” jako nowy tag
                </button>
            </p>
        @endif
    @endif
</div>
