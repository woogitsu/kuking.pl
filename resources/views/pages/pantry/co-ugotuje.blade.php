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
    <h1>Co ugotuję z tego, co mam?</h1>

    @if($produktow === 0)
        <x-empty-state title="Najpierw wpisz, co masz w domu" action="Wpisz, co masz w domu" :href="route('pantry.index')">
            Dodaj kilka produktów, które masz teraz w kuchni, na przykład „jajka”, „mąka” i „mleko”.
            Potem pokażemy przepisy, do których brakuje Ci najmniej.
        </x-empty-state>
    @else
        <p class="text-lead" data-regula-doboru>{{ $regula }}</p>

        <p>
            Porównujemy z Twoją listą: {{ $produktow }} {{ \App\Support\Odmiana::rzeczownik($produktow, 'produkt', 'produkty', 'produktów') }}.
            <a href="{{ route('pantry.index') }}">Zmień listę</a>
        </p>

        @if($przepisy->isEmpty())
            @if($od > 0)
                <p>To już wszystkie przepisy, w których jest coś z Twojej listy.</p>
                <p><a class="btn btn-secondary" href="{{ route('pantry.cook') }}">Wróć na początek</a></p>
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
                    </li>
                @endforeach
            </ol>

            @if($jest_wiecej)
                <p class="text-center mt-6">
                    <a class="btn btn-secondary" href="{{ route('pantry.cook', ['od' => $nastepne]) }}">Pokaż więcej przepisów</a>
                </p>
            @endif
        @endif
    @endif
</x-layout>
