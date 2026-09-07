{{--
    419 — sesja wygasła, a tekst został (issue #81).

    To NIE jest strona błędu. To jest ten sam formularz, wystawiony jeszcze
    raz, z treścią i ze świeżym tokenem. Kod 419 jest tu tylko statusem
    HTTP — na ekranie nie ma go wcale, bo nikomu nic nie mówi.

    Scenariusz, dla którego to powstało: ktoś zaczął pisać przepis, odszedł
    do garnka, wrócił po godzinie i kliknął „Opublikuj". Do tej pory tracił
    wszystko i dowiadywał się o tym po angielsku.

    Cała strona działa BEZ JavaScriptu — to zwykły formularz.

    Uzasadnienie wyboru (i tego, czego nie wybraliśmy):
    App\Exceptions\OdzyskanyFormularz.
--}}
@php
    // Zwykłą drogą `$formularz` przychodzi z bootstrap/app.php. Ten domyślnik
    // jest na wypadek, gdyby stronę 419 wyrenderowało coś innego (np. sam
    // Laravel przy innej ścieżce) — wtedy zamiast białego ekranu z „Undefined
    // variable" człowiek dostaje tę samą stronę, tyle że bez odzyskanej treści.
    $formularz ??= \App\Exceptions\OdzyskanyFormularz::zZadania(request());

    // Na formularzach przeznaczonych dla gościa (logowanie, rejestracja,
    // odzyskiwanie hasła) podpowiedź „najpierw się zaloguj" byłaby absurdem.
    $sciezka = trim((string) parse_url($formularz->akcja, PHP_URL_PATH), '/');
    $formularzGoscia = in_array(
        $sciezka,
        ['login', 'register', 'nie-pamietam-hasla', 'nowe-haslo'],
        strict: true,
    );
@endphp

<x-layout title="Ta strona była otwarta zbyt długo" :noindex="true">
    <h1>Ta strona była otwarta zbyt długo</h1>

    @if($formularz->maCoOdzyskac())
        <p class="mb-5">
            Ze względów bezpieczeństwa formularz jest ważny tylko przez pewien czas,
            a ten był otwarty dłużej. <strong>Twój tekst jest na miejscu</strong> —
            nic nie przepadło. Kliknij „Wyślij jeszcze raz”, a wpis pójdzie tam,
            gdzie miał iść.
        </p>

        @if(auth()->guest() && ! $formularzGoscia)
            {{-- Sesja wygasła, więc razem z tokenem przepadło też zalogowanie.
                 Mówimy o tym wprost i ZANIM ktoś kliknie, bo po kliknięciu
                 trafi na ekran logowania i drugi raz zobaczy pusty formularz.
                 Link otwiera się w nowej karcie właśnie po to, żeby ta strona
                 — jedyne miejsce, w którym jest jego tekst — została otwarta. --}}
            <div class="notice mb-5" role="status">
                <strong>Najpierw zaloguj się jeszcze raz.</strong>
                <span>
                    <a href="{{ route('login') }}" target="_blank" rel="noopener">
                        Otwórz logowanie w nowej karcie</a>,
                    zaloguj się, wróć na tę kartę i dopiero wtedy kliknij „Wyślij jeszcze raz”.
                    Ta strona z Twoim tekstem zostaje otwarta — nie zamykaj jej.
                </span>
            </div>
        @endif

        <form class="card" method="POST" action="{{ $formularz->akcja }}"
              @if($formularz->maPliki()) enctype="multipart/form-data" @endif>
            {{-- Świeży token. Ochrona CSRF zostaje w mocy — ponowne wysłanie
                 idzie normalną drogą, przez ValidateCsrfToken. --}}
            @csrf

            @if($formularz->metodaUdawana())
                @method($formularz->metodaUdawana())
            @endif

            @php $numer = 0; @endphp

            @foreach($formularz->pola as $pole)
                @if($pole['dlugi'])
                    @php $numer++; @endphp
                    <div class="field">
                        <label for="odzyskane-{{ $numer }}">
                            Twój tekst @if($numer > 1)({{ $numer }})@endif
                        </label>
                        <span class="field-help" id="odzyskane-{{ $numer }}-help">
                            Możesz go jeszcze poprawić przed wysłaniem.
                        </span>
                        <textarea class="field-input" id="odzyskane-{{ $numer }}"
                                  name="{{ $pole['nazwa'] }}" rows="8"
                                  aria-describedby="odzyskane-{{ $numer }}-help">{{ $pole['wartosc'] }}</textarea>
                    </div>
                @else
                    <input type="hidden" name="{{ $pole['nazwa'] }}" value="{{ $pole['wartosc'] }}">
                @endif
            @endforeach

            @foreach($formularz->pliki as $plik)
                <div class="field">
                    <label for="odzyskany-plik-{{ $loop->index }}">Wybierz zdjęcie jeszcze raz</label>
                    <span class="field-help" id="odzyskany-plik-{{ $loop->index }}-help">
                        Zdjęcia nie da się odzyskać — przeglądarka na to nie pozwala.
                        Tekst jest bezpieczny, brakuje tylko pliku.
                    </span>
                    <input class="field-input" id="odzyskany-plik-{{ $loop->index }}" type="file"
                           name="{{ $plik['nazwa'] }}" @if($plik['wiele']) multiple @endif
                           accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                           aria-describedby="odzyskany-plik-{{ $loop->index }}-help">
                </div>
            @endforeach

            @if($formularz->obciete)
                <p class="field-help">
                    Ten formularz był wyjątkowo duży i nie wszystko udało się przenieść.
                    Sprawdź treść przed wysłaniem.
                </p>
            @endif

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij jeszcze raz</button>
                <a class="btn btn-quiet" href="{{ route('landing') }}">Strona główna</a>
            </div>
        </form>
    @else
        <p class="mb-5">
            Ze względów bezpieczeństwa formularz jest ważny tylko przez pewien czas,
            a ten był otwarty dłużej. Nie było w nim jednak nic do zapisania —
            wystarczy otworzyć stronę od nowa i zrobić to jeszcze raz.
        </p>

        <p>
            <a class="btn btn-primary" href="{{ route('landing') }}">Strona główna</a>
        </p>
    @endif
</x-layout>
