@php
    // Słowo niesie znaczenie, kolor tylko je wzmacnia (WCAG 1.4.1).
    $slowa = [
        'dodano' => 'Dodano',
        'usunieto' => 'Usunięto',
        'zmieniono' => 'Zmieniono',
    ];
@endphp
<x-layout :title="'Zmiany w wersji '.$wersja->version_number.': '.$recipe->title" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a>
        <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
    </p>

    @if($poprzednia === null)
        <h1>Wersja {{ $wersja->version_number }}</h1>
        @if($pominieteUkryte > 0)
            <p>To najstarsza wersja, którą możesz zobaczyć, więc nie ma z czym jej porównać. Starsze wersje ukrył autor albo moderacja.</p>
        @else
            <p>To najstarsza zapisana wersja, więc nie ma z czym jej porównać.</p>
        @endif
        <p><a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Zobacz wersję {{ $wersja->version_number }}</a></p>
    @else
        <h1>Co się zmieniło</h1>
        <p class="text-lead">Wersja {{ $poprzednia->version_number }} ({{ $poprzednia->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}) →
            wersja {{ $wersja->version_number }} ({{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }})</p>
        @if($pominieteUkryte > 0)
            <p class="notice">
                Porównanie pomija {{ $pominieteUkryte === 1 ? 'jedną ukrytą wersję' : $pominieteUkryte.' '.\App\Support\Odmiana::rzeczownik($pominieteUkryte, 'ukrytą wersję', 'ukryte wersje', 'ukrytych wersji') }}
                między wersją {{ $poprzednia->version_number }} a {{ $wersja->version_number }}, bo autor albo moderacja {{ $pominieteUkryte === 1 ? 'ją ukryli' : 'je ukryli' }}.
                Widać tu więc wszystkie zmiany z tego czasu razem.
            </p>
        @endif
        @if($wersja->czyUkryta() || $poprzednia->czyUkryta())
            <p class="notice">
                W tym porównaniu jest wersja ukryta. Takie wersje widzi tylko autor przepisu i moderacja,
                a inni oglądają porównanie bez nich.
            </p>
        @endif
        <p class="meta">
            Każda zmiana ma napis: Dodano, Usunięto albo Zmieniono. Zdjęcia nie są porównywane.
        </p>

        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $poprzednia->version_number]) }}">Zobacz wersję {{ $poprzednia->version_number }}</a>
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Zobacz wersję {{ $wersja->version_number }}</a>
            @if($nowszy !== null)
                <a class="btn btn-secondary" href="{{ route('recipes.history.changes', [$recipe->slug, $nowszy]) }}">Następne zmiany (wersja {{ $nowszy }})</a>
            @endif
        </p>

        @if($porownanie['brakZmian'])
            <p class="historia-brak-zmian">W tekście i danych przepisu nie ma różnic między tymi wersjami.</p>
        @endif

        @if($porownanie['pola'] !== [])
            <section class="sekcja-strony" aria-labelledby="hz-dane">
                <h2 id="hz-dane">Dane przepisu</h2>
                <ul class="historia-zmiany list-none p-0">
                    @foreach($porownanie['pola'] as $zmiana)
                        <li class="historia-zmiana historia-zmiana-{{ $zmiana['rodzaj'] }}">
                            <p class="historia-zmiana-slowo">{{ $slowa[$zmiana['rodzaj']] }}: {{ $zmiana['etykieta'] }}</p>
                            @if($zmiana['przed'] !== null)<p class="historia-przed whitespace-pre-line">Było: {{ $zmiana['przed'] }}</p>@endif
                            @if($zmiana['po'] !== null)<p class="historia-po whitespace-pre-line">Jest: {{ $zmiana['po'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($porownanie['skladniki'] !== [])
            <section class="sekcja-strony" aria-labelledby="hz-skladniki">
                <h2 id="hz-skladniki">Składniki</h2>
                <ul class="historia-zmiany list-none p-0">
                    @foreach($porownanie['skladniki'] as $zmiana)
                        <li class="historia-zmiana historia-zmiana-{{ $zmiana['rodzaj'] }}">
                            <p class="historia-zmiana-slowo">{{ $slowa[$zmiana['rodzaj']] }}</p>
                            @if($zmiana['przed'] !== null)<p class="historia-przed">Było: {{ $zmiana['przed'] }}</p>@endif
                            @if($zmiana['po'] !== null)<p class="historia-po">Jest: {{ $zmiana['po'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($porownanie['kroki'] !== [])
            <section class="sekcja-strony" aria-labelledby="hz-kroki">
                <h2 id="hz-kroki">Przygotowanie</h2>
                <ul class="historia-zmiany list-none p-0">
                    @foreach($porownanie['kroki'] as $zmiana)
                        <li class="historia-zmiana historia-zmiana-{{ $zmiana['rodzaj'] }}">
                            <p class="historia-zmiana-slowo">{{ $slowa[$zmiana['rodzaj']] }}: krok {{ $zmiana['numer'] }}</p>
                            @if($zmiana['przed'] !== null)<p class="historia-przed whitespace-pre-line">Było: {{ $zmiana['przed'] }}</p>@endif
                            @if($zmiana['po'] !== null)<p class="historia-po whitespace-pre-line">Jest: {{ $zmiana['po'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($porownanie['bezDanych'] !== [])
            <p class="meta">
                Tego nie da się porównać, bo starsza wersja nie zapisała tych pól: {{ implode(', ', $porownanie['bezDanych']) }}.
            </p>
        @endif
    @endif
</x-layout>
