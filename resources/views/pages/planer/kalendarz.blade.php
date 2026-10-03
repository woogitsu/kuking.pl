{{--
    Plik kalendarza z wybranych pozycji planera (#2529, V2, D-333 — paczka E).

    Zwykły formularz bez skryptu. Każda pozycja ma w etykiecie dokładnie tę
    nazwę i datę, które trafią do pliku (wydarzenie całodniowe). Pozycje
    z przepisem niedostępnym albo usuniętym stoją bez nazwy i nie dają się
    zaznaczyć. Przed przyciskiem jest wyjaśnienie, że to KOPIA, która może
    trafić do chmury używanego kalendarza i nie aktualizuje się z Kuking.
    Plik powstaje dopiero po kliknięciu i nie ma stałego adresu.
--}}
@use('App\Domain\Planer\PlanerTygodnia')
@use('App\Domain\Planer\PlikKalendarza')
@php
    $zaznaczone = collect((array) old('wpisy', []))->map(fn ($id) => (string) $id)->all();
    $dostepne = collect($pozycje)->filter(fn ($p) => PlikKalendarza::nazwaPozycji($p) !== null)->count();
@endphp
<x-layout title="Plan do kalendarza" :noindex="true">
    <h1>Plan do własnego kalendarza</h1>
    <p class="mb-5">{{ PlanerTygodnia::zakresTygodnia($poniedzialek) }}. Zaznacz pozycje, które chcesz zapisać w pliku. Poniżej widzisz dokładnie, jaka nazwa i jaka data trafią do pliku.</p>

    <x-error-summary :field-ids="['wpisy' => 'f-wpisy-pierwszy']" />

    <nav class="planer-nawigacja mb-5" aria-label="Wybór tygodnia">
        <a class="btn btn-secondary" href="{{ route('planer.calendar', ['tydzien' => $poniedzialek->subDays(7)->toDateString()]) }}">Poprzedni tydzień</a>
        <a class="btn btn-secondary" href="{{ route('planer.calendar', ['tydzien' => $poniedzialek->addDays(7)->toDateString()]) }}">Następny tydzień</a>
        <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $poniedzialek->toDateString()]) }}">Wróć do planera</a>
    </nav>

    @if($pozycje === [])
        <p>W tym tygodniu nie ma jeszcze żadnych pozycji w planie, więc nie ma czego zapisać w pliku. Wróć do planera i dodaj przepis na któryś dzień.</p>
    @else
        <form class="panel-formularza" method="POST" action="{{ route('planer.calendar.download') }}" novalidate>
            @csrf
            <input type="hidden" name="tydzien" value="{{ $poniedzialek->toDateString() }}">

            <fieldset class="field wybor-dnia-lista @error('wpisy') has-error @enderror">
                <legend class="field-label">Które pozycje zapisać w pliku?</legend>
                @php $pierwszy = true; @endphp
                @foreach($pozycje as $pozycja)
                    @php
                        $nazwa = PlikKalendarza::nazwaPozycji($pozycja);
                        $dataTekst = \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($pozycja['wpis']->day)).' '.$pozycja['wpis']->day->year;
                        $idPola = $nazwa !== null && $pierwszy ? 'f-wpisy-pierwszy' : null;
                    @endphp
                    @if($nazwa !== null)
                        @php $pierwszy = false; @endphp
                        <label class="wybor-dnia-opcja">
                            <input type="checkbox" name="wpisy[]" value="{{ $pozycja['wpis']->getKey() }}"
                                   @if($idPola) id="{{ $idPola }}" @endif
                                   @checked(in_array((string) $pozycja['wpis']->getKey(), $zaznaczone, true))>
                            <span>{{ $dataTekst }} — <strong>{{ $nazwa }}</strong>@if($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY) <span class="meta">(własny wpis)</span>@endif</span>
                        </label>
                    @else
                        <p class="meta meta-samodzielne">{{ $dataTekst }} — przepis jest już niedostępny, więc tej pozycji nie da się zapisać w pliku.</p>
                    @endif
                @endforeach
                @error('wpisy')
                    <p class="field-error" id="f-wpisy-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="notice mt-4">
                <p class="mt-0"><strong>Zanim pobierzesz plik:</strong></p>
                <ul>
                    <li>To jednorazowa kopia: nazwy i daty jako wydarzenia całodniowe, bez godzin, składników, kroków, zdjęć i Twoich dopisków.</li>
                    <li>Po zaimportowaniu kopia może trafić do chmury Twojego kalendarza (np. Google albo Apple). Kuking niczego tam nie wysyła.</li>
                    <li>Gdy zmienisz albo usuniesz plan w Kuking, kopia w kalendarzu się nie zmieni ani nie zniknie — usuń ją tam sam.</li>
                    <li>Nie obiecujemy, że ponowny import tego samego pliku nie zrobi podwójnych wydarzeń; zależy to od kalendarza. Przypomnienia ustawia Twój kalendarz.</li>
                    <li>Plik otworzysz w kalendarzu telefonu lub komputera (zwykle wystarczy go dotknąć albo wybrać „Importuj”).</li>
                </ul>
            </div>

            <div class="form-actions mt-4">
                @if($dostepne > 0)
                    <button class="btn btn-primary" type="submit">Pobierz plik kalendarza (.ics)</button>
                @else
                    <p class="meta">Żadnej pozycji z tego tygodnia nie da się zapisać w pliku.</p>
                @endif
            </div>
        </form>
    @endif
</x-layout>
