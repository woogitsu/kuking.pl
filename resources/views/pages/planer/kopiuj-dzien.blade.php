{{--
    „Skopiuj ten dzień” (#2494, V2). Prywatny, `noindex`.

    Dwa kroki na jednym ekranie, oba zwykłymi formularzami bez skryptu:
    1. wybór dnia docelowego (GET) — pokazuje PODGLĄD tego, co zostanie dodane,
    2. „Skopiuj” (POST) — dopiero to zapisuje.
    Ukryte pole `odcisk` niesie zestaw, który człowiek widział w podglądzie:
    jeśli plan zmienił się od podglądu, akcja niczego nie zapisze i pokaże
    nowy podgląd. Przepis, którego właściciel planu już nie widzi, jest tylko
    policzony — bez tytułu (`SkopiujDzienPlanu`).
--}}
@use('App\Domain\Planer\PlanerTygodnia')
@use('App\Domain\Planer\Actions\SkopiujDzienPlanu')
@use('App\Domain\Planer\ZakresDatPlanu')
<x-layout title="Skopiuj ten dzień" :noindex="true">
    <h1>Skopiuj ten dzień</h1>

    @php
        $dzis = \Carbon\CarbonImmutable::parse(\App\Support\Czas::dzisiajData());
        $dzienZrodla = $zrodlo->toDateString();
        $celeBledow = ['cel' => 'f-cel', 'odcisk' => 'f-cel', 'dzien' => 'f-cel'];
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    <p class="mb-5">
        Kopiujesz dzień: <strong>{{ \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($zrodlo)) }} {{ $zrodlo->year }}</strong>.
        Pozycje z tego dnia zostaną dopisane do wybranego dnia. Ten dzień zostaje bez zmian, a to, co już stoi w dniu docelowym, nie jest zastępowane.
    </p>

    <form class="panel-formularza mb-5" method="GET" action="{{ route('planer.copyday') }}" novalidate>
        <input type="hidden" name="dzien" value="{{ $dzienZrodla }}">
        <x-field name="cel" type="date" label="Na jaki dzień kopiujesz?" :value="$cel?->toDateString()" :required="true" :bez-oznaczenia="true"
                 :min="$dzis->subDays(ZakresDatPlanu::DNI_WSTECZ)->toDateString()"
                 :max="$dzis->addDays(ZakresDatPlanu::DNI_DO_PRZODU)->toDateString()"
                 :help="'Planer przyjmuje dni '.ZakresDatPlanu::opis().'.'" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Pokaż, co zostanie skopiowane</button>
            <a class="btn btn-quiet" href="{{ route('planer.show', ['tydzien' => $dzienZrodla]) }}#dzien-{{ $dzienZrodla }}">Anuluj, nic nie kopiuj</a>
        </div>
    </form>

    @if($ocena !== null)
        <section class="card mb-5" aria-labelledby="podglad-tytul">
            <h2 class="mt-0" id="podglad-tytul">Podgląd: {{ PlanerTygodnia::nazwaDnia($cel) }}</h2>

            @if($ocena['pozycje'] === [])
                <p class="meta meta-samodzielne">Ten dzień jest pusty — nie ma czego skopiować.</p>
            @else
                <ul class="planer-pozycje">
                    @foreach($ocena['pozycje'] as $pozycja)
                        @php
                            $nazwa = match ($pozycja['stan']) {
                                PlanerTygodnia::STAN_PRZEPIS => $pozycja['przepis']->title,
                                PlanerTygodnia::STAN_WLASNY => $pozycja['wpis']->label,
                                default => null,
                            };
                        @endphp
                        <li class="planer-pozycja">
                            <span class="planer-pozycja-tresc">
                                @if($nazwa !== null)
                                    {{ $nazwa }}
                                @else
                                    <span class="meta">Przepis jest już niedostępny albo został usunięty.</span>
                                @endif
                                <br>
                                @if($pozycja['klasa'] === SkopiujDzienPlanu::KLASA_NOWA)
                                    <strong>Zostanie dodane</strong>
                                @elseif($pozycja['klasa'] === SkopiujDzienPlanu::KLASA_JUZ_JEST)
                                    <span class="meta">Już jest w tym dniu — nie zostanie dodane drugi raz</span>
                                @else
                                    <span class="meta">Nie zostanie skopiowane</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-3">
                    Nowych pozycji: <strong>{{ $ocena['nowe'] }}</strong>.
                    @if($ocena['juz_sa'] > 0) Już w dniu docelowym: {{ $ocena['juz_sa'] }}. @endif
                    @if($ocena['niedostepne'] > 0) Pominięte (przepis niedostępny): {{ $ocena['niedostepne'] }}. @endif
                </p>

                @if($ocena['nowe'] === 0)
                    <p class="meta meta-samodzielne">Nie ma nic nowego do skopiowania. Wybierz inny dzień.</p>
                @elseif($ocena['nowe'] > $ocena['wolne'])
                    <p class="meta meta-samodzielne">Na ten dzień zostało miejsca na {{ $ocena['wolne'] }}, a kopia dodałaby {{ $ocena['nowe'] }} (dzień mieści najwyżej {{ $wpisowNaDzien }} pozycji). Wybierz inny dzień albo usuń któreś pozycje z tego dnia. Nie kopiujemy części zestawu.</p>
                @else
                    <form method="POST" action="{{ route('planer.copyday.store') }}" novalidate>
                        @csrf
                        <input type="hidden" name="dzien" value="{{ $dzienZrodla }}">
                        <input type="hidden" name="cel" value="{{ $cel->toDateString() }}">
                        <input type="hidden" name="odcisk" value="{{ $ocena['odcisk'] }}">
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">Skopiuj na {{ PlanerTygodnia::naDzien($cel) }}</button>
                        </div>
                    </form>
                @endif
            @endif
        </section>
    @endif
</x-layout>
