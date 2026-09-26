@props(['recipe'])
{{--
    SZACUNKOWE WARTOŚCI ODŻYWCZE (V2, D-299) — pod listą składników.

    Liczy `App\Domain\Recipes\Odzywcze\KalkulatorWartosci`: deterministycznie,
    w PHP, z tabel CIQUAL 2025 i USDA FoodData Central, bez modelu AI.
    Liczby pokazujemy wyłącznie przy pokryciu ≥ 90% masy przepisu; w każdym
    innym przypadku — uczciwe zdanie, dlaczego ich nie ma.

    Czego tu świadomie NIE MA (projekt §8.3): słów „zdrowe”, „dietetyczne”,
    „lekkie”, „fit”, „dla cukrzyków”, żadnych ocen typu „mało kalorii”,
    żadnych filtrów ani sortowania po kaloriach. Pilnuje tego test
    `WartosciOdzywczeNaStroniePrzepisuTest`.

    Autor może sekcję ukryć (domyślnie widoczna — decyzja właściciela
    z 26.09.2026). Ukrytej nie widzi nikt poza autorem, a autor widzi tylko
    informację o ukryciu i przycisk, który ją przywraca.
--}}
@php
    $mozeZmienic = auth()->check() && auth()->user()->can('update', $recipe);
    $widoczne = (bool) ($recipe->pokazuj_wartosci_odzywcze ?? true);
    $wynik = $widoczne && $recipe->ingredients->isNotEmpty()
        ? app(\App\Domain\Recipes\Odzywcze\KalkulatorWartosci::class)->policz($recipe)
        : null;
    $gramy = static fn (float $g): string => \App\Domain\Recipes\Odzywcze\WynikWartosci::gramyDoPokazania($g) < 1
        ? 'mniej niż 1 g'
        : 'ok. '.\App\Domain\Recipes\Odzywcze\WynikWartosci::gramyDoPokazania($g).' g';
@endphp
@if($recipe->ingredients->isNotEmpty() && ($widoczne || $mozeZmienic))
    <section class="wartosci-odzywcze" id="wartosci-odzywcze" aria-labelledby="wartosci-odzywcze-naglowek">
        @if(! $widoczne)
            <h3 id="wartosci-odzywcze-naglowek">Szacunkowe wartości odżywcze</h3>
            <p>Ta sekcja jest ukryta. Widzisz tę informację tylko Ty, inni nie widzą przy tym przepisie żadnych liczb.</p>
        @elseif($wynik !== null && $wynik->policzone())
            <h3 id="wartosci-odzywcze-naglowek">Szacunkowe wartości odżywcze ({{ $wynik->naPorcje() ? 'na porcję' : 'na cały przepis' }})</h3>
            <dl class="wartosci-odzywcze-lista">
                <div><dt>Energia</dt><dd>ok. {{ $wynik->kcalDoPokazania() }} kcal</dd></div>
                <div><dt>Białko</dt><dd>{{ $gramy($wynik->bialko) }}</dd></div>
                <div><dt>Tłuszcz</dt><dd>{{ $gramy($wynik->tluszcz) }}</dd></div>
                <div><dt>Węglowodany</dt><dd>{{ $gramy($wynik->weglowodany) }}</dd></div>
            </dl>
            <p class="meta">Szacunek na podstawie tabel CIQUAL/USDA.</p>
        @else
            <h3 id="wartosci-odzywcze-naglowek">Szacunkowe wartości odżywcze</h3>
            <p>Nie liczymy wartości odżywczych tego przepisu.</p>
            @foreach($wynik?->powodyBrakuLiczb() ?? [] as $powod)
                <p class="meta">{{ $powod }}</p>
            @endforeach
        @endif

        @if($widoczne)
            <details class="wartosci-odzywcze-jak">
                <summary>Jak to liczymy</summary>
                <p>Każdy składnik szukamy w dwóch otwartych tabelach składu żywności: CIQUAL 2025 (Anses, Francja, wersja z 3 listopada 2025, Licencja Otwarta Etalab 2.0) i USDA FoodData Central SR Legacy (Departament Rolnictwa USA, domena publiczna).</p>
                <p>Szklanka to 250 ml, łyżka 15 ml, łyżeczka 5 ml, szczypta 0,5 g. Szklanka mąki waży mniej niż szklanka cukru, dlatego każdy składnik ma własne gramatury — na przykład szklanka mąki pszennej to 140 g, a średnia cebula 110 g.</p>
                <p>Liczby pokazujemy tylko wtedy, gdy składniki z tabel to co najmniej 90% masy przepisu. Składniki „do smaku”, a także sól, pieprz i zioła bez podanej ilości pomijamy.</p>
                @if($wynik !== null && $wynik->policzone() && $wynik->naPorcje())
                    <p>Wartości są na jedną porcję, a autor podał {{ $recipe->servingsLabel() }}. Gdy przeliczasz przepis na inną liczbę porcji, wartości na jedną porcję się nie zmieniają.</p>
                @endif
                <p>Energię zaokrąglamy do 10 kcal, pozostałe wartości do 1 g. To szacunek: prawdziwy wynik zależy od produktów, których używasz, i od tego, ile czego naprawdę trafi do garnka.</p>
            </details>
        @endif

        @if($mozeZmienic)
            <form method="POST" action="{{ route('recipes.wartosci-odzywcze', $recipe) }}" class="wartosci-odzywcze-przelacznik">
                @csrf
                @method('PATCH')
                <input type="hidden" name="pokazuj" value="{{ $widoczne ? '0' : '1' }}">
                <button class="btn btn-secondary" type="submit">{{ $widoczne ? 'Ukryj tę sekcję w moim przepisie' : 'Pokaż wartości odżywcze' }}</button>
                @if($widoczne)
                    <p class="meta">Tę możliwość widzisz tylko Ty. Sekcję możesz przywrócić w każdej chwili.</p>
                @endif
            </form>
        @endif
    </section>
@endif
