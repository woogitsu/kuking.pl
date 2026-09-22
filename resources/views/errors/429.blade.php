{{--
    429 — za dużo prób, a tekst został (issue #81, audyt zewnętrzny G06).

    Przez długi czas ta strona mówiła „nic nie przepadło", nie zachowując
    niczego: `old()` puste, treści nigdzie w odpowiedzi, zdjęcia nie było
    jak odzyskać. Powrót „wstecz" bywa ratunkiem, ale to zachowanie
    przeglądarki, nie obietnica aplikacji — a obietnica stała na ekranie.

    Teraz na trasach treści (`App\Support\OdzyskiwalneDane`) ten ekran jest
    tym samym formularzem, wystawionym jeszcze raz i wypełnionym treścią
    z odbitego żądania — dokładnie jak ekran 419. Na logowaniu, rejestracji
    i drugim składniku nie odzyskuje niczego i nic takiego nie obiecuje.

    Cała strona działa BEZ JavaScriptu — to zwykły formularz.

    Uzasadnienie wyboru (i tego, czego nie wybraliśmy):
    App\Exceptions\OdzyskanyFormularz oraz komentarz przy
    `ThrottleRequestsException` w bootstrap/app.php.
--}}
@php
    // Laravel podaje w nagłówku `Retry-After` liczbę SEKUND. Dla człowieka
    // „za 300 s" nie znaczy nic, więc zaokrąglamy w górę do pełnych minut —
    // w górę, bo obietnica „za 1 minutę" złamana o 20 sekund jest gorsza
    // niż uczciwe „za 2 minuty".
    //
    // `$sekundy` przychodzi z bootstrap/app.php. Odczyt z `$exception` jest
    // zapasowy: tą stroną Laravel renderuje też 429 spoza naszego wywołania
    // zwrotnego (np. limiter globalny), a wtedy zmiennej nie ma.
    $sekundy ??= null;

    if ($sekundy === null && isset($exception) && method_exists($exception, 'getHeaders')) {
        $sekundy = $exception->getHeaders()['Retry-After'] ?? null;
    }

    $minuty = is_numeric($sekundy) ? max(1, (int) ceil(((int) $sekundy) / 60)) : null;

    // Ten sam domyślnik co na ekranie 419: gdyby stronę wyrenderowało coś
    // innego niż nasze wywołanie zwrotne, człowiek dostaje tę samą stronę,
    // tyle że bez odzyskanej treści — zamiast „Undefined variable".
    $formularz ??= \App\Exceptions\OdzyskanyFormularz::zZadania(request());
@endphp

<x-layout title="Za dużo prób" :noindex="true">
    <h1>Za dużo prób</h1>

    <p class="mb-5">
        To samo działanie powtórzyło się kilka razy pod rząd, więc Kuking robi
        krótką przerwę.
        @if($minuty)
            Spróbuj ponownie za {{ $minuty }} min.
        @else
            Spróbuj ponownie za kilka minut.
        @endif
    </p>

    @if($formularz->maCoOdzyskac())
        <p class="mb-5">
            @if($formularz->obciete)
                <strong>Część Twojego tekstu jest niżej</strong>, ale formularz był wyjątkowo duży
                i nie wszystko udało się przenieść. Sprawdź treść i uzupełnij, czego brakuje.
            @else
                <strong>Twój tekst jest na miejscu</strong> — nic z niego nie przepadło.
            @endif
            Poczekaj
            @if($minuty)
                te {{ $minuty }} min,
            @else
                kilka minut,
            @endif
            a potem kliknij „Wyślij jeszcze raz”. Ta strona może zostać otwarta;
            klikanie „odśwież” niczego nie przyspieszy.
        </p>

        {{-- WARSTWA ZALEŻY OD TEGO, CZY COŚ TU WIDAĆ. Krótkie wartości
             wracają jako pola ukryte, więc przy komentarzu na kilkanaście
             znaków i bez zdjęcia w tym bloku nie ma nic do wypełnienia —
             tylko przycisk „Wyślij jeszcze raz". Mocna obwódka panelu
             obiecywałaby wtedy formularz, którego nie widać, a ekran mówi
             w tym samym czasie „Twój tekst jest na miejscu". Sekcja, a nie
             ramka pomocnicza: blok nadal niesie akcję, tylko nie wypełnianie. --}}
        <form @class([
                  'panel-formularza' => $formularz->maWidocznePola(),
                  'sekcja-strony' => ! $formularz->maWidocznePola(),
              ])
              method="POST" action="{{ $formularz->akcja }}"
              @if($formularz->maPliki()) enctype="multipart/form-data" @endif>
            {{-- Ochrona CSRF zostaje w mocy — ponowne wysłanie idzie normalną
                 drogą, przez ValidateCsrfToken, i normalnie przez limiter. --}}
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

            {{-- Ten sam obszar wyboru zdjęcia co na ekranie 419 i w formularzach,
                 z których ten ekran odbił człowieka (`.pole-zdjecia`,
                 resources/css/ekran-dodawania.css). Natywne pole pliku jest
                 schowane dla oka (D-035). --}}
            @foreach($formularz->pliki as $plik)
                <div class="field">
                    <input class="visually-hidden pole-zdjecia-input" id="odzyskany-plik-{{ $loop->index }}" type="file"
                           name="{{ $plik['nazwa'] }}" @if($plik['wiele']) multiple @endif
                           accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                           aria-labelledby="odzyskany-plik-{{ $loop->index }}-tytul"
                           aria-describedby="odzyskany-plik-{{ $loop->index }}-help">
                    <label class="pole-zdjecia" for="odzyskany-plik-{{ $loop->index }}">
                        <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                        <span class="pole-zdjecia-tytul" id="odzyskany-plik-{{ $loop->index }}-tytul">Wybierz zdjęcie jeszcze raz</span>
                        <span class="field-help" id="odzyskany-plik-{{ $loop->index }}-help">
                            Zdjęcia nie da się odzyskać — przeglądarka na to nie pozwala.
                            Trzeba je wybrać jeszcze raz.
                        </span>
                    </label>
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
                <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">Strona główna</a>
            </div>
        </form>
    @else
        {{-- Tu NIE MA zdania „nic nie przepadło" i to jest cała treść naprawy
             G06: bez odzyskanego formularza nie ma czym tej obietnicy pokryć.
             Ta gałąź obsługuje przede wszystkim limit logowania, rejestracji
             i drugiego składnika — tam nie ma czego zachowywać i mówimy tylko
             to, co jest prawdą: przerwa mija sama. --}}
        @if($formularz->obciete)
            <p class="mb-5">
                <strong>Nie udało się odzyskać tekstu</strong>, bo formularz był wyjątkowo duży.
                Wróć do poprzedniej strony. Jeśli przeglądarka zachowała wpisaną treść,
                skopiuj ją przed ponowną próbą wysłania.
            </p>
        @endif
        <p class="mb-5">
            Ta przerwa mija sama, a klikanie „odśwież”
            jej nie skróci.
            @if($formularz->maPliki())
                Zdjęcie trzeba będzie wybrać jeszcze raz: przeglądarka nie pozwala
                wpisać pliku za człowieka.
            @endif
        </p>

        <div class="form-actions">
            <a class="btn btn-primary" href="{{ auth()->check() ? route('home') : route('landing') }}">Strona główna</a>
            <a class="btn btn-quiet" href="{{ route('help') }}">Pomoc</a>
        </div>
    @endif
</x-layout>
