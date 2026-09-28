{{--
    Akcja destrukcyjna — z potwierdzeniem, które NIE WYMAGA JavaScriptu.

    CO BYŁO WCZEŚNIEJ I DLACZEGO TO NIE WYSTARCZAŁO
    Formularz z `onsubmit="return confirm(...)"`. Bez JavaScriptu kliknięcie
    „Usuń ten wpis" kasowało wpis OD RAZU, bez pytania. AGENTS.md §5 wymienia
    dwie reguły, które się tu zderzały: akcja destrukcyjna wymaga
    potwierdzenia, a żadna ważna funkcja nie wymaga JavaScriptu. Potwierdzenie
    było funkcją, która JavaScriptu wymagała.

    DRUGI POWÓD, CICHSZY I GORSZY
    Nagłówek CSP w trybie wymuszającym nie ma dziś `script-src`, więc
    `onsubmit` działa. Polityka docelowa — ta mierzona w Report-Only, którą
    domknie issue #12 — ma `script-src 'self'` bez `unsafe-inline`, a to
    blokuje atrybuty zdarzeń w HTML-u. W dniu zaostrzenia CSP WSZYSTKIE
    potwierdzenia w serwisie przestałyby działać po cichu: żadnego błędu,
    po prostu kasowanie bez pytania.

    JAK TO DZIAŁA TERAZ
    `<details>` — czysty HTML. Pierwsze kliknięcie rozwija pytanie i dopiero
    tam stoi prawdziwy przycisk. Dwa świadome kliknięcia zamiast jednego,
    bez linijki skryptu i bez niespodzianek przy zaostrzaniu CSP.

    Przy okazji jest to lepsze dla naszej grupy: okno `confirm()` przeglądarki
    bywa dla osób 50+ niewidoczne — pojawia się poza układem strony, często
    u samej góry ekranu. Pytanie wpisane w stronę widać tam, gdzie człowiek
    właśnie patrzy.
--}}
{{-- `fields`: pola ukryte dopisywane do formularza (np. `oczekiwany_id`
     przy #793 — identyfikator osoby widzianej w chwili renderowania, nie
     tej, którą nazwa użytkownika w adresie wskazuje dziś). --}}
{{-- `name`: nazwa konta, gdy pytanie dotyczy KONKRETNEJ osoby (#1819).
     `question` sam NIE WOLNO mu składać z odmienioną nazwą — polskiej
     odmiany nie da się policzyć z dowolnego ciągu znaków (COPY_STYLE.md,
     „Nie doklejaj przyimka do cudzych słów"). Dlatego nazwa stoi w
     mianowniku, w osobnym elemencie pod pytaniem, a `question` samo w sobie
     ma być kompletnym zdaniem bez niej („Zablokować tę osobę?"). --}}
@props(['action', 'method' => 'DELETE', 'label', 'question', 'name' => null, 'fields' => []])

<details class="confirm">
    <summary class="btn btn-danger confirm-summary">{{ $label }}</summary>

    <div class="confirm-body">
        <p class="confirm-question">{{ $question }}</p>
        @if($name)
            <p class="confirm-question-nazwa"><strong>{{ $name }}</strong></p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            @method($method)
            @foreach($fields as $nazwa => $wartosc)
                <input type="hidden" name="{{ $nazwa }}" value="{{ $wartosc }}">
            @endforeach
            {{ $slot }}
            <button class="btn btn-danger" type="submit">Tak, {{ mb_strtolower($label) }}</button>
        </form>
    </div>
</details>
