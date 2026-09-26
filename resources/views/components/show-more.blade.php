{{--
    Paginacja jako przycisk „Pokaż więcej”, nie infinite scroll (AGENTS.md §5).

    Infinite scroll uniemożliwia dojście do stopki, gubi pozycję po powrocie
    i jest nieprzewidywalny przy czytniku ekranu.

    DLACZEGO ETYKIETA JEST PARAMETREM
    Ten sam komponent stoi teraz pod listą wpisów, przepisów, wykonań, osób
    i powiadomień. Wpisany na sztywno napis „Pokaż więcej wpisów” pod listą
    osób jest po prostu nieprawdziwy — a tekst, który kłamie o tym, co się
    stanie po kliknięciu, jest gorszy niż tekst ogólny. Domyślna wartość
    zostaje przy wpisach, bo tak brzmiał ten przycisk, zanim komponent trafił
    na inne ekrany.

    BEZ SKRYPTU TO ODNOŚNIK „NASTĘPNA STRONA”, ZE SKRYPTEM „POKAŻ WIĘCEJ” (#986)
    Zwykły odnośnik do `nextPageUrl()` otwiera stronę z SAMĄ kolejną porcją —
    wcześniejsze karty znikają. Nazywanie tego „Pokaż więcej” obiecywało coś,
    czego odnośnik nie robi. Dlatego z serwera przychodzi uczciwa etykieta
    „Następna strona …”, a `resources/js/pokaz-wiecej.js` zamienia ją na
    przycisk „Pokaż więcej …”, który naprawdę dokleja porcję do listy —
    i robi to TYLKO wtedy, gdy wie, gdzie ją dokleić (D-053: bez martwych
    przycisków).

    `lista` to `id` kontenera, którego BEZPOŚREDNIE dzieci są elementami
    listy. Ekran bez tego parametru dostaje sam odnośnik „Następna strona”,
    jak przed zmianą — nic się na nim nie psuje. Elementy powinny mieć
    `data-klucz` (albo `id`), żeby przesunięcie porcji między żądaniami nie
    dokleiło tej samej karty drugi raz.

    Etykietę przycisku podaje serwer (`data-pokaz-wiecej-etykieta`), żeby
    polski tekst żył w jednym miejscu z resztą komponentu, a nie w skrypcie.

    Region ogłoszenia stoi poza przyciskiem: po ostatniej porcji przycisk
    znika, a komunikat „To już koniec listy” musi mieć jeszcze gdzie paść.
--}}
@props([
    'paginator',
    'czego' => 'wpisów',
    'lista' => null,
])
@if($paginator->hasMorePages())
    @php
        // Nazwa parametru paginatora odróżnia dwie listy na jednym ekranie
        // (np. `komentarze` i `wykonania` pod przepisem, `page` i `wpisy`
        // w zeszycie) — każda pamięta w adresie własną liczbę porcji.
        $klucz = match (true) {
            method_exists($paginator, 'getCursorName') => $paginator->getCursorName(),
            method_exists($paginator, 'getPageName') => $paginator->getPageName(),
            default => 'page',
        };
    @endphp
    <div class="pokaz-wiecej text-center mt-6"
         data-pokaz-wiecej="{{ $klucz }}"
         data-pokaz-wiecej-czego="{{ $czego }}"
         data-pokaz-wiecej-etykieta="Pokaż więcej {{ $czego }}"
         @if($lista) data-pokaz-wiecej-lista="{{ $lista }}" @endif>
        <p class="m-0">
            <a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}">Następna strona {{ $czego }}</a>
        </p>
        <p class="visually-hidden" aria-live="polite" data-pokaz-wiecej-ogloszenie></p>
        <p class="field-error mt-3" role="alert" data-pokaz-wiecej-blad hidden></p>
    </div>
@endif
