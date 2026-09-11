<?php /** @var \App\Models\Report $zgloszenie */ ?>
<?php /** @var \App\Models\ModerationAction|null $decyzja */ ?>
{{--
    Karta jednej sprawy zgłaszającego (issue #10, DSA art. 16 ust. 4 i 5).

    CZEGO TU NIE MA I DLACZEGO (Luka 3 z `docs/research/DSA-LUKI.md`)
    Nie ma nazwy ani profilu osoby, której zgłoszenie dotyczyło, nie ma
    odnośnika do zgłoszonej treści i nie ma tego, jaką dokładnie karę
    zastosowaliśmy. Zgłaszający ma prawo wiedzieć, czy treść zostaje czy
    znika — i to mu mówimy. Reszta to dane osobowe osoby trzeciej, a
    mechanizm zgłoszeń nie jest narzędziem do ustalania, kogo ukarano.

    Odnośnika do zgłoszonej treści nie ma także z drugiego, prostszego
    powodu: po ukryciu albo usunięciu prowadziłby prosto w ścianę 403/404.
    Sprawę rozpoznaje się po numerze, dacie, rodzaju treści i po własnych
    słowach zgłaszającego, które są niżej.

    Bez JavaScriptu — sam tekst i dwa odnośniki (AGENTS.md §5).
--}}
<x-layout title="Twoje zgłoszenie" :noindex="true">
    <h1>Twoje zgłoszenie</h1>

    <article class="sekcja-strony">
        <h2 class="mt-0 text-title-sm">Treść zgłoszenia</h2>
        <p class="meta">
            Numer sprawy {{ $zgloszenie->numer_sprawy }} ·
            wysłane {{ \App\Support\Czas::data($zgloszenie->created_at, 'j F Y') }}
        </p>
        <p>Rodzaj treści: {{ $zgloszenie->targetLabel() }}.</p>
        <p>Wybrany powód: {{ $zgloszenie->reasonLabel() }}.</p>

        @if($zgloszenie->details)
            <h3 class="text-title-sm">Twoje słowa</h3>
            <p class="whitespace-pre-line">{{ $zgloszenie->details }}</p>
        @endif
    </article>

    @if(! $zgloszenie->jestRozstrzygniete())
        {{-- TA SAMA WARSTWA CO „Nasza decyzja" NIŻEJ — obie gałęzie stoją
             w tym samym miejscu ekranu i odpowiadają na to samo pytanie
             („co z moją sprawą"), tylko w dwóch stanach. Różnica warstw
             kazałaby ekranowi zmieniać wygląd zależnie od tego, czy sprawa
             jest już rozstrzygnięta — a to nie jest różnica rangi. --}}
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">Na czym stoi sprawa</h2>
            <p><strong>Sprawdzamy.</strong> Zgłoszenie trafiło do kolejki i przeczyta je człowiek.</p>
            <p>
                Gdy zapadnie decyzja, dostaniesz powiadomienie i zobaczysz ją tutaj.
                Nie musisz zgłaszać tej samej rzeczy drugi raz — sprawa jest u nas.
            </p>
        </article>
    @else
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">Nasza decyzja</h2>

            @if($decyzja !== null)
                @php
                    $skutek = \App\Domain\Moderation\OdpowiedzDlaZglaszajacego::skutek($decyzja);
                @endphp
                <p class="meta">{{ \App\Support\Czas::data($decyzja->created_at, 'j F Y') }}</p>
                <p><strong>{{ $skutek['naglowek'] }}</strong> {{ $skutek['reszta'] }}</p>
            @else
                {{-- Sprawa zamknięta bez wiersza w `moderation_actions`: wpis
                     z czasów sprzed kolejki moderacji albo porządkowanie
                     ręczne. Nie zgadujemy, co wtedy postanowiono — mówimy
                     tyle, ile wiemy. --}}
                <p><strong>Sprawa jest zamknięta.</strong></p>
            @endif
        </article>

        {{--
            POUCZENIE O DOSTĘPNYCH ŚRODKACH (DSA art. 16 ust. 5 zdanie drugie).

            Stoi POZA gałęzią „znamy decyzję", bo jest prawdziwe niezależnie
            od tego, czego o sprawie nie wiemy — a pouczenie pokazywane tylko
            czasem nie jest pouczeniem.

            Zdania liczy `OdpowiedzDlaZglaszajacego::pouczenie()` — te same,
            które idą listem przy zgłoszeniu prawnym, żeby obie drogi pouczały
            tak samo, a nie podobnie.
        --}}
        {{-- SEKCJA, nie ramka pomocnicza. D-042 odbiera zgłaszającemu
             formularz skargi i stawia to pouczenie W JEGO MIEJSCE — to jest
             cały środek prawny, jaki mu zostaje, a nie przypis obok sprawy.
             Wgłębienie mówiłoby „to jest obok głównej rzeczy" o jedynej
             rzeczy, którą człowiek może jeszcze zrobić. --}}
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">{{ \App\Domain\Moderation\OdpowiedzDlaZglaszajacego::NAGLOWEK_POUCZENIA }}</h2>
            @foreach(\App\Domain\Moderation\OdpowiedzDlaZglaszajacego::pouczenie($zgloszenie) as $zdanie)
                <p>{{ $zdanie }}</p>
            @endforeach
        </article>
    @endif

    <p class="mt-6">
        <a class="btn btn-secondary" href="{{ route('reports.mine') }}">Wróć do listy zgłoszeń</a>
    </p>
</x-layout>
