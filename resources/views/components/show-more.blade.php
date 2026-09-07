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

    Zwykły odnośnik, nie przycisk sterowany skryptem: te ekrany mają działać
    bez JavaScriptu (AGENTS.md §5).
--}}
@props([
    'paginator',
    'czego' => 'wpisów',
])
@if($paginator->hasMorePages())
    <p class="text-center mt-6">
        <a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}">Pokaż więcej {{ $czego }}</a>
    </p>
@endif
