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
            <p class="meta meta-samodzielne">Ta wersja nie ma składników.</p>
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
            <p class="meta meta-samodzielne">Ta wersja nie ma opisanego przygotowania.</p>
        @else
            @foreach(\App\Domain\Recipes\EtapyPrzygotowania::grupy($kroki) as $etap)
            @if($etap['nazwa'] !== null)
                <h3 class="naglowek-grupy">{{ $etap['nazwa'] }}</h3>
            @endif
            <ol class="step-list">
                @foreach($etap['kroki'] as $indeksKroku => $krok)
                    <li>
                        <span class="step-number" aria-hidden="true">{{ $indeksKroku + 1 }}</span>
                        <div>
                            <span class="visually-hidden">Krok {{ $indeksKroku + 1 }}.</span>
                            <p class="m-0 whitespace-pre-line">{{ $krok['instruction'] }}</p>
                            @if(\App\Domain\Recipes\Historia\MigawkaWersji::minutnik($krok['timer_seconds']))
                                <p class="meta m-0">Minutnik: {{ \App\Domain\Recipes\Historia\MigawkaWersji::minutnik($krok['timer_seconds']) }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @endforeach
        @endif
    </section>
