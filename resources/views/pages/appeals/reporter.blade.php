<?php /** @var \App\Models\Report $zgloszenie */ /** @var \App\Models\ModerationAction $decyzja */ /** @var \App\Models\Appeal|null $odwolanie */ ?>
{{--
    Odwołanie od decyzji moderacyjnej — droga dla ZGŁASZAJĄCEGO (issue #23,
    DSA art. 20 ust. 1). Ten sam wzorzec co appeals/create.blade.php po
    stronie autora: przypomnienie sprawy, potem stan (już złożone / termin
    minął / formularz).

    Jedno pole, jeden przycisk, bez JavaScriptu (D-007). Bez gry słowem
    „kuKING" — D-009.
--}}
<x-layout title="Odwołanie od decyzji" :noindex="true">
    <h1>Odwołanie od decyzji</h1>

    <x-error-summary />

    <article class="sekcja-strony">
        <h2 class="mt-0 text-title-sm">Twoje zgłoszenie</h2>
        <p class="meta">
            Numer sprawy {{ $zgloszenie->numer_sprawy }} ·
            {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y') }}
        </p>
        {{-- SKUTEK DLA ZGŁASZAJĄCEGO, NIE ETYKIETA Z PANELU (issue #800).

             Stało tu `$decyzja->label()` — czyli dokładnie ta sama etykieta,
             którą widzi moderator w kolejce: „Ostrzeżenie dla autora",
             „Zawieś konto autora", „Zablokuj konto autora na stałe".
             `OdpowiedzDlaZglaszajacego` celowo tych słów zgłaszającemu NIE
             mówi (Luka 3 z `docs/research/DSA-LUKI.md`): rodzaj kary
             wymierzonej osobie trzeciej to jej dane osobowe, a mechanizm
             zgłoszeń nie jest narzędziem do ustalania, kogo ukarano.

             Ten sam tekst dostaje zgłaszający w liście
             `DecyzjaWSprawieZgloszenia` i na karcie sprawy
             `pages/zgloszenia/szczegoly.blade.php` — trzy kanały, jedno
             zdanie, bo to jest treść wymagana przez DSA art. 16 ust. 5,
             a nie kwestia stylu.

             WAŻNY PODPIS NIE JEST ZGODĄ NA UJAWNIENIE WSZYSTKICH PÓL MODELU.
             Podpisany link potwierdza tylko, że to ten zgłaszający i jego
             własna sprawa — nie zmienia tego, ile mu o cudzym koncie wolno
             powiedzieć.

             UZASADNIENIA POTRZEBNEGO DO ODWOŁANIA TO NIE ZABIERA: przy
             `no_action` zgłaszający nadal czyta, że treść zostaje i dlaczego,
             a przy `hide`/`remove` — że jej już nie ma. Znika wyłącznie to,
             czego dowiedzieć się nie miał prawa.

             AUTORA TREŚCI to nie dotyczy: on ma prawo wiedzieć, jaką karę
             dostał, i `appeals/create.blade.php` nadal pokazuje mu
             `label()`. --}}
        @php
            $skutek = \App\Domain\Moderation\OdpowiedzDlaZglaszajacego::skutek($decyzja);
        @endphp
        <p><strong>{{ $skutek['naglowek'] }}</strong> {{ $skutek['reszta'] }}</p>
    </article>

    @if($odwolanie !== null)
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">Twoje odwołanie</h2>
            <p class="meta">Złożone {{ \App\Support\Czas::data($odwolanie->created_at, 'j F Y') }} · {{ $odwolanie->statusLabel() }}</p>
            <p class="whitespace-pre-line">{{ $odwolanie->body }}</p>

            @if($odwolanie->isOpen())
                {{-- Stan oczekiwania rozróżnia dziś sprawę przed terminem
                     od spóźnionej (issue #799) — wspólnie z drogą autora
                     treści, w jednym komponencie. --}}
                <x-odwolanie-czeka :odwolanie="$odwolanie"
                                   kanal="Odpowiedź wyślemy na adres e-mail, z którego przyszło Twoje zgłoszenie." />
            @else
                <h3 class="text-title-sm">Nasza odpowiedź</h3>
                <p class="meta">{{ \App\Support\Czas::data($odwolanie->decided_at, 'j F Y') }}</p>
                <p class="whitespace-pre-line">{{ $odwolanie->decision_note }}</p>
                @if($odwolanie->status === \App\Models\Appeal::STATUS_OVERTURNED && $odwolanie->decisionAfterAppeal)
                    {{-- #989: skutek nowej decyzji, tym samym zdaniem co wyżej
                         i z tą samą granicą — bez rodzaju kary (#800). --}}
                    @php($poOdwolaniu = \App\Domain\Moderation\OdpowiedzDlaZglaszajacego::skutek($odwolanie->decisionAfterAppeal))
                    <p><strong>{{ $poOdwolaniu['naglowek'] }}</strong> {{ $poOdwolaniu['reszta'] }}</p>
                @endif
                <p class="meta">
                    Odwołanie rozpatrujemy raz. Jeśli pojawiły się nowe okoliczności,
                    napisz na {{ config('kuking.community.contact_email') }}.
                </p>
            @endif
        </article>
    @elseif(! $decyzja->isAppealableByReporter())
        {{-- SEKCJA, nie ramka pomocnicza: w tej gałęzi to jest CAŁA treść
             ekranu i jedyna odpowiedź, jaką człowiek tu dostaje. Ramka jest
             wgłębiona i znaczy „to jest obok głównej rzeczy" — a obok czego
             miałoby to stać, skoro formularza odwołania tu nie ma. Sąsiednie
             gałęzie tego samego `@if` mają `sekcja-strony` i
             `panel-formularza`, więc akurat stan „termin minął" dostawał
             najsłabszą warstwę ekranu. --}}
        {{-- TA GAŁĄŹ ZACZĘŁA BYĆ OSIĄGALNA DOPIERO Z #798. Przedtem podpis
             wygasał dokładnie wtedy, gdy zaczynała obowiązywać, więc
             `middleware('signed')` odrzucało żądanie przed kontrolerem
             (`[pomiar cudzy: stanowisko dsa-odwolania, 20.09.2026]`,
             potwierdzone własnym testem: przed poprawką `403`, po niej `200`).
             Stąd poprawki niżej — nikt nie czytał tych zdań na ekranie.

             „CO NAJMNIEJ sześć miesięcy", nie „sześć miesięcy":
             `ModerationAction::appealDeadline()` bierze PÓŹNIEJSZĄ z dwóch dat
             (`appeal_days` z konfiguracji i sześć miesięcy kalendarzowych),
             więc przy `KUKING_APPEAL_DAYS` większym niż pół roku zdanie
             o „sześciu miesiącach" byłoby nieprawdą obok wypisanej wyżej,
             prawdziwej daty. Sześć miesięcy jest DOLNĄ granicą z DSA art. 20
             ust. 1 i tak to trzeba mówić.

             KIEDY TA STRONA ZNIKNIE — bo tu jest jedyne miejsce, w którym
             człowiek jeszcze ją widzi i może zdążyć napisać. Ten sam okres
             mówi list z decyzją (`DecyzjaWSprawieZgloszenia`). --}}
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">Tej decyzji nie da się już zakwestionować tutaj</h2>
            <p>
                Na odwołanie jest co najmniej sześć miesięcy od decyzji.
                Ten termin minął {{ \App\Support\Czas::data($decyzja->appealDeadline(), 'j F Y') }}.
            </p>
            <p>
                Jeśli pojawiły się nowe okoliczności, napisz na
                {{ config('kuking.community.contact_email') }} — przeczytamy.
                Podaj numer sprawy {{ $zgloszenie->numer_sprawy }}.
            </p>
            <p class="meta">
                Ta strona będzie tu jeszcze przez
                {{ config('kuking.moderation.reporter_case_link_days') }} dni od tego terminu,
                a potem odnośnik z naszego listu przestanie ją otwierać.
            </p>
        </article>
    @else
        <form class="panel-formularza mt-5" method="POST" action="{{ url()->full() }}">
            @csrf

            <h2 class="mt-0 text-title-sm">Napisz, dlaczego się nie zgadzasz</h2>
            <p>
                Wystarczy kilka zdań własnymi słowami. Napisz, co Twoim zdaniem
                przemawia za inną decyzją — to trafi do osoby, która obejrzy
                sprawę drugi raz.
            </p>

            <x-field name="body" label="Twoje wyjaśnienie" type="textarea" :rows="6" required
                     help="Od 10 do 2000 znaków." />

            <p class="meta">
                Co będzie dalej: przeczytamy odwołanie i odpowiemy w ciągu
                {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych —
                na adres e-mail, z którego przyszło Twoje zgłoszenie. Odpowiedź,
                podtrzymanie albo zmiana decyzji, zawsze z wyjaśnieniem. Odwołanie
                od jednej decyzji składa się raz, więc napisz od razu wszystko,
                co ważne.
            </p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij odwołanie</button>
            </div>
        </form>
    @endif
</x-layout>
