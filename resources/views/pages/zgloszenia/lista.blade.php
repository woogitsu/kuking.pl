<?php /** @var \Illuminate\Pagination\LengthAwarePaginator $zgloszenia */ ?>
<?php /** @var array<string, \App\Models\ModerationAction> $decyzje */ ?>
{{--
    „Twoje zgłoszenia" — strona ZGŁASZAJĄCEGO (issue #10, DSA art. 16 ust. 4
    i 5). Do tej zmiany jedynym śladem po zgłoszeniu był flash w sesji,
    znikający po odświeżeniu strony.

    Bez JavaScriptu: same odnośniki i „Pokaż więcej" jako zwykły link
    (AGENTS.md §5). Bez gry słowem „kuKING" — to jest ekran przy sprawie
    moderacyjnej (D-009).
--}}
<x-layout title="Twoje zgłoszenia" :noindex="true">
    <h1>Twoje zgłoszenia</h1>

    <p class="mb-5">
        To są sprawy, które do nas trafiły od Ciebie. Przy każdej piszemy, na czym stoi
        i co postanowiliśmy. Numer sprawy podaj, jeśli będziesz do nas pisać.
    </p>

    @if($zgloszenia->count() > 0)
        <ul class="lista-naga">
            @foreach($zgloszenia as $zgloszenie)
                @php
                    $decyzja = $decyzje[(string) $zgloszenie->getKey()] ?? null;
                @endphp
                <li><article class="card mb-3">
                    <h2 class="mt-0 text-title-sm">
                        Zgłoszenie: {{ $zgloszenie->targetLabel() }}
                    </h2>

                    <p class="meta">
                        Numer sprawy {{ $zgloszenie->numer_sprawy }} ·
                        wysłane {{ \App\Support\Czas::data($zgloszenie->created_at, 'j F Y') }}
                    </p>

                    <p>Wybrany powód: {{ $zgloszenie->reasonLabel() }}.</p>

                    @if($zgloszenie->jestRozstrzygniete() && $decyzja !== null)
                        @php
                            $skutek = \App\Domain\Moderation\OdpowiedzDlaZglaszajacego::skutek($decyzja);
                        @endphp
                        <p><strong>{{ $skutek['naglowek'] }}</strong> {{ $skutek['reszta'] }}</p>
                    @elseif($zgloszenie->jestRozstrzygniete())
                        {{-- Sprawa zamknięta bez wiersza w `moderation_actions`:
                             wpis z czasów sprzed kolejki moderacji albo
                             porządkowanie ręczne. Nie zgadujemy, co wtedy
                             postanowiono — mówimy tyle, ile wiemy. --}}
                        <p><strong>Sprawa jest zamknięta.</strong></p>
                    @else
                        <p><strong>Sprawdzamy.</strong> Napiszemy, gdy będziemy mieć decyzję.</p>
                    @endif

                    <p class="m-0">
                        <a class="btn btn-secondary" href="{{ route('reports.mine.show', $zgloszenie) }}">
                            Zobacz szczegóły
                        </a>
                    </p>
                </article></li>
            @endforeach
        </ul>

        <x-show-more :paginator="$zgloszenia" czego="zgłoszeń" />
    @else
        <x-empty-state title="Nie masz jeszcze żadnych zgłoszeń.">
            Przy każdym przepisie i komentarzu jest przycisk „Zgłoś”, a przy wpisie —
            „Zgłoś ten wpis” w menu z trzema kropkami.
            Jeśli coś jest nie w porządku, napisz nam o tym — sprawdzimy i tutaj
            zobaczysz, co z tym zrobiliśmy.
        </x-empty-state>
    @endif

    <p class="meta mt-6">
        Treść niezgodną z prawem możesz zgłosić także bez logowania —
        <a href="{{ route('zglos.nielegalna') }}">formularzem zgłoszenia nielegalnej treści</a>.
        Tamte sprawy prowadzimy korespondencyjnie i nie ma ich na tej liście.
    </p>
</x-layout>
