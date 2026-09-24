{{--
    Pytanie przed wyjściem z formularza z niezapisanymi zmianami (issue #899).

    Ramka jest `hidden` i odsłania ją WYŁĄCZNIE `resources/js/niezapisane-zmiany.js`,
    gdy człowiek kliknie oznaczony odnośnik przy zmienionym formularzu. Bez
    skryptu nikt jej nie zobaczy, a odnośnik działa jak zwykły link — tekst
    przy nim sam mówi, że kreator otworzy ostatnią zapisaną wersję.

    `tabindex="-1"`: skrypt przenosi tu fokus, żeby czytnik ekranu przeczytał
    pytanie, a osoba z klawiaturą miała oba wyjścia tuż pod ręką.
--}}
@props(['id', 'href', 'zapisz'])

<div id="{{ $id }}" class="notice mb-5" role="alert" tabindex="-1" hidden>
    <p>
        <strong>Masz niezapisane zmiany na tej stronie.</strong>
        Kreator otworzy ostatnią zapisaną wersję przepisu — tego, co tu wpisano
        od ostatniego zapisu, w nim nie będzie. Żeby niczego nie stracić, zostań
        i kliknij „{{ $zapisz }}” na dole strony.
    </p>
    <div class="form-actions">
        <button class="btn btn-primary" type="button" data-niezapisane-zostan>Zostań na tej stronie</button>
        <a class="btn btn-secondary" href="{{ $href }}">Przejdź do kreatora bez tych zmian</a>
    </div>
</div>
