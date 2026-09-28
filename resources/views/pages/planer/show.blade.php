{{--
    Planer tygodnia (#27, D-310). Prywatny, `noindex`.

    Bez przeciągania — każda akcja to zwykły formularz z przyciskiem, więc
    ekran działa bez skryptu i jedną ręką. Każdy dzień ma własny formularz
    „Dopisz coś własnego”; `:wiersz` = data dnia, żeby błąd i wpisany tekst
    wróciły tylko do tego dnia, z którego przyszły (issue #243).

    Przepis, którego właściciel planu już nie widzi, stoi bez tytułu i bez
    linku („Przepis jest już niedostępny.”) — plan nie jest furtką do treści
    (`PlanerTygodnia`).
--}}
@use('App\Domain\Planer\PlanerTygodnia')
<x-layout title="Planer tygodnia" :noindex="true">
    <h1>Plan na tydzień</h1>
    <p class="mb-5">{{ PlanerTygodnia::zakresTygodnia($poniedzialek) }}. Ten plan widzisz tylko Ty.</p>

    <x-error-summary />

    <nav class="planer-nawigacja mb-5" aria-label="Wybór tygodnia">
        <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $poniedzialek->subDays(7)->toDateString()]) }}">Poprzedni tydzień</a>
        @unless($tenTydzien)
            <a class="btn btn-secondary" href="{{ route('planer.show') }}">Wróć do tego tygodnia</a>
        @endunless
        <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $poniedzialek->addDays(7)->toDateString()]) }}">Następny tydzień</a>
    </nav>

    @if($poprzedniMaPozycje)
        <form class="card mb-5" method="POST" action="{{ route('planer.copy') }}">
            @csrf
            <input type="hidden" name="tydzien" value="{{ $poniedzialek->toDateString() }}">
            <p class="mt-0" id="opis-kopii">Dopiszemy pozycje z poprzedniego tygodnia do tych samych dni tego tygodnia. To, co już jest w planie, zostaje i nie powtórzy się.</p>
            <button class="btn btn-secondary" type="submit" aria-describedby="opis-kopii">Skopiuj poprzedni tydzień</button>
        </form>
    @endif

    <p class="meta mb-5">Przepis dodasz z jego strony — przycisk „Dodaj do planera”. Tutaj możesz dopisać coś własnego, np. „obiad u mamy”.</p>

    <div class="planer-dni">
        @foreach($dni as $dataDnia => $dzien)
            @php
                $naglowekId = 'dzien-'.$dataDnia;
            @endphp
            <section class="card" aria-labelledby="{{ $naglowekId }}">
                <h2 class="mt-0 planer-dzien-naglowek" id="{{ $naglowekId }}">
                    {{ \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($dzien['dzien'])) }}
                    @if($dataDnia === $dzis)
                        <span class="planer-dzis">dziś</span>
                    @endif
                </h2>

                @if($dzien['pozycje'] === [])
                    <p class="meta">Nic jeszcze nie zaplanowane.</p>
                @else
                    <ul class="planer-pozycje">
                        @foreach($dzien['pozycje'] as $pozycja)
                            @php
                                $wpis = $pozycja['wpis'];
                                $nazwa = match ($pozycja['stan']) {
                                    PlanerTygodnia::STAN_PRZEPIS => $pozycja['przepis']->title,
                                    PlanerTygodnia::STAN_WLASNY => $wpis->label,
                                    PlanerTygodnia::STAN_NIEDOSTEPNY => 'przepis niedostępny',
                                    default => 'przepis usunięty',
                                };
                            @endphp
                            <li class="planer-pozycja">
                                <span class="planer-pozycja-tresc">
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                        <a href="{{ route('recipes.show', $pozycja['przepis']->slug) }}">{{ $pozycja['przepis']->title }}</a>
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY)
                                        {{ $wpis->label }}
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_NIEDOSTEPNY)
                                        <span class="meta">Przepis jest już niedostępny.</span>
                                    @else
                                        <span class="meta">Przepis został usunięty.</span>
                                    @endif
                                </span>
                                <form method="POST" action="{{ route('planer.destroy', $wpis) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-secondary" type="submit">Usuń z planu<span class="visually-hidden">: {{ $nazwa }}</span></button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if(count($dzien['pozycje']) < $wpisowNaDzien)
                    <form class="planer-dopisz mt-4" method="POST" action="{{ route('planer.store') }}">
                        @csrf
                        <input type="hidden" name="_wiersz" value="{{ $dataDnia }}">
                        <input type="hidden" name="day" value="{{ $dataDnia }}">
                        <x-field name="label" label="Dopisz coś własnego" :wiersz="$dataDnia" :bez-oznaczenia="true" />
                        <button class="btn btn-secondary" type="submit">Dopisz</button>
                    </form>
                @else
                    <p class="meta mt-4">Ten dzień ma komplet: {{ $wpisowNaDzien }} pozycji. Usuń którąś, żeby dopisać nową.</p>
                @endif
            </section>
        @endforeach
    </div>
</x-layout>
