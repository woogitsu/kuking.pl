<x-layout :title="'Wersja '.$wersja->version_number.': '.$recipe->title" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a>
        <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
    </p>

    <h1>Wersja {{ $wersja->version_number }}</h1>
    <p class="meta">
        Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif.
        @if($czyNajnowsza) To najnowsza zapisana wersja. @endif
        Ekran pokazuje tekst i dane, bez zdjęć.
    </p>

    <p class="historia-akcje">
        @if($starszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.changes', [$recipe->slug, $wersja->version_number]) }}">Co się zmieniło względem wersji {{ $starszy }}</a>
        @endif
        @if($starszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $starszy]) }}">Starsza wersja ({{ $starszy }})</a>
        @endif
        @if($nowszy !== null)
            <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $nowszy]) }}">Nowsza wersja ({{ $nowszy }})</a>
        @endif
    </p>

    <section class="sekcja-strony" aria-labelledby="hw-dane">
        <h2 id="hw-dane">Dane przepisu</h2>
        <dl class="historia-dane">
            @foreach($migawka->pola() as $pole)
                <div class="historia-dane-wiersz">
                    <dt>{{ $pole['etykieta'] }}</dt>
                    <dd class="whitespace-pre-line">@if($pole['wartosc'] !== null){{ $pole['wartosc'] }}@elseif($pole['brakDanych'])Brak danych — ta wersja została zapisana, zanim zapisywaliśmy to pole.@else Nie podano.@endif</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="sekcja-strony" aria-labelledby="hw-skladniki">
        <h2 id="hw-skladniki">Składniki</h2>
        @php($skladniki = $migawka->skladniki())
        @if($skladniki === [])
            <p class="meta">Ta wersja nie ma składników.</p>
        @else
            @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($skladniki) as $grupa)
                @if($grupa['nazwa'] !== null)
                    <h3 class="naglowek-grupy">{{ $grupa['nazwa'] }}</h3>
                @endif
                <ul class="ingredient-list">
                    @foreach($grupa['skladniki'] as $skladnik)
                        <li>
                            {{ $skladnik['text'] }}@if($skladnik['note']) <span class="meta"> — {{ $skladnik['note'] }}</span>@endif
                            @if($skladnik['substitutes'])<span class="skladnik-zamiennik">Zamiast tego: {{ $skladnik['substitutes'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </section>

    <section class="sekcja-strony" aria-labelledby="hw-kroki">
        <h2 id="hw-kroki">Przygotowanie</h2>
        @php($kroki = $migawka->kroki())
        @if($kroki === [])
            <p class="meta">Ta wersja nie ma opisanego przygotowania.</p>
        @else
            <ol class="step-list">
                @foreach($kroki as $krok)
                    <li>
                        <span class="step-number" aria-hidden="true">{{ $loop->iteration }}</span>
                        <div>
                            <span class="visually-hidden">Krok {{ $loop->iteration }}.</span>
                            <p class="m-0 whitespace-pre-line">{{ $krok['instruction'] }}</p>
                            @if(\App\Domain\Recipes\Historia\MigawkaWersji::minutnik($krok['timer_seconds']))
                                <p class="meta m-0">Minutnik: {{ \App\Domain\Recipes\Historia\MigawkaWersji::minutnik($krok['timer_seconds']) }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</x-layout>
