{{--
    „Co ugotuję z tego, co mam” (V2, D-285).

    Reguła kolejności stoi na ekranie JEDNYM ZDANIEM (`CoUgotuje::REGULA`) —
    człowiek ma wiedzieć, dlaczego ten przepis jest pierwszy, i ma widzieć,
    że nie decyduje o tym popularność (AGENTS.md §8 i §12).

    Przy każdym przepisie: „Masz 5 z 7 składników. Brakuje: …” — brakujące
    linijki dosłownie tak, jak napisał je autor przepisu.

    „Pokaż więcej” jest zwykłym odnośnikiem z `?od=`, nie nieskończonym
    przewijaniem (AGENTS.md §5).
--}}
<x-layout title="Co ugotuję z tego, co mam" :noindex="true">
    <h1>{{ $najpierwTermin ? 'Przepisy na produkty z krótkim terminem' : 'Co ugotuję z tego, co mam?' }}</h1>

    @if($produktow === 0)
        <x-empty-state title="Najpierw wpisz, co masz w domu" action="Wpisz, co masz w domu" :href="route('pantry.index')">
            Dodaj kilka produktów, które masz teraz w kuchni, na przykład „jajka”, „mąka” i „mleko”.
            Potem pokażemy przepisy, do których brakuje Ci najmniej.
        </x-empty-state>
    @else
        <p class="text-lead" data-regula-doboru>{{ $regula }}</p>

        {{-- Dwa tryby jednego mechanizmu (#1903): domyślny (najmniej brakujących
             składników) i „najpierw to, co się psuje”. Przełącznik to zwykły
             odnośnik; widok domyślny się nie zmienia. --}}
        <p>
            @if($najpierwTermin)
                <a class="btn btn-secondary" href="{{ route('pantry.cook') }}">Pokaż wszystkie propozycje</a>
            @else
                <a class="btn btn-secondary" href="{{ route('pantry.cook', ['najpierw' => 'termin']) }}">Najpierw to, co się psuje</a>
            @endif
        </p>

        <p>
            Porównujemy z Twoją listą: {{ $produktow }} {{ \App\Support\Odmiana::rzeczownik($produktow, 'produkt', 'produkty', 'produktów') }}.
            Produkty po terminie „Należy zużyć do” pomijamy przy doborze przepisów.
            <a href="{{ route('pantry.index') }}">Zmień listę</a>
        </p>

        @if($przepisy->isEmpty())
            @if($od > 0)
                <p>To już wszystkie przepisy, w których jest coś z Twojej listy.</p>
                <p><a class="btn btn-secondary" href="{{ route('pantry.cook', array_filter(['najpierw' => $najpierwTermin ? 'termin' : null])) }}">Wróć na początek</a></p>
            @elseif($najpierwTermin)
                <x-empty-state title="Żaden przepis nie pasuje do produktów z krótkim terminem" action="Zobacz wszystkie propozycje" :href="route('pantry.cook')">
                    Żaden przepis nie pasuje do produktów z krótkim terminem. Dopisz terminy do produktów
                    na liście „Co mam w domu” albo zobacz wszystkie propozycje.
                </x-empty-state>
            @elseif($wszystkie_po_terminie ?? false)
                <x-empty-state title="Wszystkie Twoje produkty są po terminie" action="Sprawdź listę „Co mam w domu”" :href="route('pantry.index')">
                    Produkty po terminie „Należy zużyć do” pomijamy przy doborze przepisów, a na Twojej liście
                    są teraz tylko takie. Popraw datę albo usuń produkt na liście „Co mam w domu”.
                </x-empty-state>
            @else
                <x-empty-state title="Nie znaleźliśmy przepisu z tymi produktami" action="Dopisz więcej produktów" :href="route('pantry.index')">
                    Żaden przepis nie ma jeszcze niczego z Twojej listy. Dopisz więcej produktów
                    albo wpisz je prościej — „ser” zamiast „ser gouda plastry”.
                </x-empty-state>
            @endif
        @else
            <ol class="lista-naga stack-tight" start="{{ $od + 1 }}">
                @foreach($przepisy as $przepis)
                    @php
                        $razem = (int) $przepis->skladnikow_razem;
                        $brakuje = (int) $przepis->skladnikow_brakuje;
                        $mam = $razem - $brakuje;
                        $brakujaceLinijki = $brakujace[$przepis->getKey()] ?? [];
                        $doZuzycia = $do_zuzycia[$przepis->getKey()] ?? [];
                    @endphp
                    <li>
                        <x-recipe-card :recipe="$przepis" />
                        <p class="mt-2 mb-0" data-dopasowanie>
                            @if($brakuje === 0)
                                <strong>Masz wszystkie składniki ({{ $razem }}).</strong>
                            @else
                                <strong>Masz {{ $mam }} z {{ $razem }} {{ \App\Support\Odmiana::rzeczownik($razem, 'składnika', 'składników', 'składników') }}.</strong>
                                Brakuje: {{ implode(', ', $brakujaceLinijki) }}.
                            @endif
                        </p>
                        @if($doZuzycia !== [])
                            <p class="mt-1 mb-0" data-zuzyjesz>
                                <strong>Zużyjesz:</strong>
                                {{ collect($doZuzycia)->map(fn ($p) => $p['nazwa'].' (do '.\App\Domain\Pantry\PriorytetZuzycia::dataSlownie(\Carbon\CarbonImmutable::parse($p['termin']), false).')')->implode(', ') }}.
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if($jest_wiecej && ! $granicaPrzegladania)
                <p class="text-center mt-6">
                    <a class="btn btn-secondary" href="{{ route('pantry.cook', array_filter(['od' => $nastepne, 'najpierw' => $najpierwTermin ? 'termin' : null])) }}">Pokaż więcej przepisów</a>
                </p>
            @elseif($granicaPrzegladania)
                <p class="text-center mt-6">To koniec dostępnego przeglądania tej listy.@if($jest_wiecej) Mogą być jeszcze inne pasujące przepisy. Zmień produkty na swojej liście, aby zobaczyć inne propozycje.@endif</p>
                <p class="text-center"><a class="btn btn-secondary" href="{{ route('pantry.index') }}">Zmień listę produktów</a></p>
            @endif
        @endif
    @endif
</x-layout>
