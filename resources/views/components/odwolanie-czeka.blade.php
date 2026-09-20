@props(['odwolanie', 'kanal'])
<?php /** @var \App\Models\Appeal $odwolanie */ /** @var string $kanal */ ?>
{{--
    STAN OTWARTEGO ODWOŁANIA — jedno miejsce na dwie drogi (issue #799).

    DLACZEGO KOMPONENT, A NIE DWA AKAPITY W DWÓCH WIDOKACH
    Bo to jest dokładnie ten problem, który rozwiązała klasa
    `OdpowiedzDlaZglaszajacego`: te same zdania stały przedtem osobno
    w `appeals/create.blade.php` (autor) i `appeals/reporter.blade.php`
    (zgłaszający) i rozjechałyby się przy pierwszej poprawce. A to nie jest
    kwestia stylu — to jest obietnica terminu, której dotrzymujemy albo nie.
    `$kanal` jest jedyną rzeczą, która naprawdę różni obie drogi: autor
    czyta odpowiedź w powiadomieniach, zgłaszający dostaje ją pocztą.

    CO BYŁO PRZEDTEM (pomiar na `534e0a51`)
    Obie drogi mówiły „Odpowiadamy w ciągu 7 dni roboczych" NIEZALEŻNIE
    od wieku sprawy. Kod umiał rozpoznać przekroczenie terminu
    (`Appeal::isOverdue()`), ale mówił o nim wyłącznie panelowi
    (`pages/admin/appeals.blade.php`). Człowiek, który czekał trzy tygodnie,
    wracał na stronę swojej sprawy i dostawał tę samą bezwarunkową
    obietnicę co pierwszego dnia.

    PRÓG JEST TEN, KTÓRY JUŻ ISTNIEJE — `Appeal::responseDeadline()`, czyli
    siedem dni roboczych z `MODERATION_PLAYBOOK.md` §3 pkt 4, liczone przez
    `addWeekdays()`. Ta reguła świadomie nie zna świąt (komentarz modelu)
    i ta zmiana tego nie rusza: dokładanie tu kalendarza świąt albo własnego
    progu byłoby rozstrzyganiem pytania, którego nikt nie zadał. Ekran ma
    mówić prawdę o terminie, który już obowiązuje, a nie ustanawiać nowy.

    NIE OBIECUJEMY NOWEJ DATY — nie znamy jej, a druga obietnica po
    złamaniu pierwszej jest gorsza niż jej brak. Nie sugerujemy też złożenia
    odwołania drugi raz: sprawa jest w kolejce, a `FileAppeal`
    i `FileReporterAppeal` i tak odbijają duplikat.

    ROZSTRZYGNIĘTEGO ODWOŁANIA TO NIE DOTYCZY: `isOverdue()` wymaga
    `isOpen()`, a widoki wołają ten komponent wyłącznie w gałęzi otwartej.
--}}
@if($odwolanie->isOverdue())
    <p><strong>Odpowiedź się opóźnia.</strong>
        Mieliśmy odpowiedzieć w ciągu
        {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych,
        czyli do {{ \App\Support\Czas::data($odwolanie->responseDeadline(), 'j F Y') }} —
        i ten termin minął. Przepraszamy. Twoje odwołanie nadal czeka
        w kolejce; nie trzeba składać go drugi raz. {{ $kanal }}</p>
    <p>Jeśli chcesz o nie zapytać, napisz na
        {{ config('kuking.community.contact_email') }}@if($odwolanie->report?->numer_sprawy), podając numer sprawy {{ $odwolanie->report->numer_sprawy }}@endif.</p>
@else
    <p><strong>Czekamy na rozpatrzenie.</strong>
        Odpowiadamy w ciągu {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych.
        {{ $kanal }}</p>
@endif
