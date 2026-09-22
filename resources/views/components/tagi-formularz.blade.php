@props(['tagNames' => [], 'sugestieTagow' => null, 'maksTagow' => null, 'pytanie' => false])

{{--
    Sekcja „Tagi" w formularzu wpisu (D-021) — podgląd, usuwanie i awaryjne
    dodawanie tagów.
    DZIAŁA BEZ JAVASCRIPTU (AGENTS.md §5). Główna ścieżka prowadzi przez
    hashtag w opisie; awaryjny panel nadal ma zwykłe przyciski „Sprawdź tag",
    „Dodaj" i „Usuń" w TYM SAMYM formularzu co publikacja. Każde kliknięcie
    to zwykły POST przeładowujący stronę — `PostController` rozpoznaje, że to
    krok pośredni, i NIE próbuje wtedy publikować wpisu.

    Wpisane i wybrane tagi PRZETRWAJĄ błąd walidacji treści/widoczności —
    trafiają do formularza jako ukryte pola, dokładnie jak zdjęcia (audyt C1).

    Progressive enhancement: ten sam formularz działa identycznie z i bez
    JavaScriptu. Nie ma tu żadnego skryptu — a nie musi być, żeby działać.
--}}
@php
    $sugestieTagow ??= collect();
    $limit = $maksTagow ?? \App\Support\LimityTagow::maksTagowNaWpis();
    $limitOsiagniety = count($tagNames) >= $limit;
    $zapytanie = trim((string) old('tag_query', ''));
    $juzWybrany = collect($tagNames)->contains(
        fn ($name) => \App\Models\Tag::znormalizujNazwe($name) === \App\Models\Tag::znormalizujNazwe($zapytanie),
    );
    $pasujeDokladnie = $juzWybrany || $sugestieTagow->contains(
        fn ($tag) => mb_strtolower($tag->name) === mb_strtolower($zapytanie),
    );
@endphp

<div class="mt-6" id="f-tagi">
    <h2 class="font-bold mb-1">{{ $pytanie ? 'Z czym to jest związane?' : 'Tagi' }} <span class="meta">(maksymalnie {{ $limit }})</span></h2>
    <p class="field-help mb-3">
        Wpisuj tagi bezpośrednio w opisie, na przykład <strong>#sernik</strong>.
        Podpowiedź pokaże istniejące tagi i liczbę publicznych wpisów.
        Możesz też pominąć tagi.
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
                    <button class="btn btn-quiet" type="submit" formnovalidate name="usun_tag" value="{{ $nazwaTagu }}">
                        Usuń
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if($limitOsiagniety)
        <p class="field-help">Masz już maksymalną liczbę tagów. Usuń jeden, żeby dodać inny.</p>
    @else
        {{--
            `open`, gdy w środku JEST CO POKAZAĆ. Bez tego kliknięcie
            „Sprawdź tag" (ścieżka bez JavaScriptu) przeładowuje stronę,
            a podpowiedzi, komunikat „już dodany" i przycisk „Dodaj … jako
            nowy tag" lądują w ZWINIĘTEJ sekcji — czyli wyszukiwanie bez JS
            wygląda, jakby nic nie zrobiło. Wszystkie cztery bloki niżej są
            warunkowane frazą, więc jeden warunek wystarcza.
        --}}
        <details class="mt-3" @if($zapytanie !== '') open @endif>
            <summary class="btn btn-quiet inline-flex">Dodaj tag bezpośrednio</summary>
            <div class="mt-3">
        <label for="f-tag-query">Nazwa tagu</label>
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
            <button class="btn btn-secondary" type="submit" formnovalidate name="szukaj_tagu" value="1">
                Sprawdź tag
            </button>
        </div>
        <span class="field-help" id="f-tag-query-help">Na przykład: sernik, zupa pomidorowa, bez glutenu.</span>

        @if($juzWybrany)
            <p class="field-help mt-3">Ten tag jest już dodany.</p>
        @elseif($sugestieTagow->isNotEmpty())
            <p class="font-bold mt-4 mb-2">Podpowiedzi</p>
            <ul class="lista-naga stack-tight mb-3" aria-label="Podpowiedzi tagów">
                @foreach($sugestieTagow as $sugestia)
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ $sugestia->name }}</span>
                        <button class="btn btn-quiet" type="submit" formnovalidate name="dodaj_tag" value="{{ $sugestia->name }}">
                            Dodaj
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($zapytanie !== '' && ! $pasujeDokladnie)
            <p class="field-help mt-3">Nic nie znaleźliśmy — możesz dodać ten tag jako nowy.</p>
        @endif

        @if($zapytanie !== '' && mb_strlen($zapytanie) >= \App\Support\LimityTagow::minZnakow() && ! $pasujeDokladnie)
            <p class="mt-3">
                <button class="btn btn-quiet" type="submit" formnovalidate name="dodaj_tag" value="{{ $zapytanie }}">
                    Dodaj „{{ $zapytanie }}” jako nowy tag
                </button>
            </p>
        @endif
            </div>
        </details>
    @endif
</div>
