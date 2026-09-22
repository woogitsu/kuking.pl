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

    // Publiczne zgłoszenia i odwołania też nie wymagają konta. Warunek
    // pochodzi z istniejącej trasy, nie z niepełnej listy adresów formularzy.
    $wymagaLogowania = collect(request()->route()?->gatherMiddleware() ?? [])
        ->contains(fn ($middleware) => is_string($middleware)
            && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));
@endphp

<x-layout title="Nie udało się wysłać formularza" :noindex="true">
    <h1>Nie udało się wysłać formularza</h1>

    @if($formularz->maCoOdzyskac())
        {{-- ZDANIE O TEKŚCIE ZALEŻY OD TEGO, CZY TEKST NAPRAWDĘ JEST CAŁY.

             Wcześniej stało tu bezwarunkowe „nic nie przepadło”, a ostrzeżenie
             „nie wszystko udało się przenieść” wisiało niżej, pod warunkiem
             `$formularz->obciete`. Przy obciętym formularzu strona mówiła
             więc jednocześnie obie rzeczy — w chwili, gdy człowiek ma za moment
             wysłać coś, co właśnie napisał. To ta sama usterka co G06
             w `docs/AUDYT_GPT_2026-09.md`: nie wolno obiecywać, że nic nie
             przepadło, gdy serwer części treści nie zachował.

             „Nic z niego nie przepadło” mówi o TEKŚCIE, nie o całym formularzu:
             zdjęcia odzyskać się nie da (przeglądarka na to nie pozwala) i mówi
             o tym wprost pomoc przy polu pliku niżej. --}}
        @if($formularz->obciete)
            <p class="mb-5">
                Nie mogliśmy potwierdzić tego wysłania. <strong>Część Twojego tekstu jest niżej</strong>,
                ale formularz był wyjątkowo duży i nie wszystko udało się przenieść.
                Przeczytaj treść, uzupełnij, czego brakuje, i kliknij „Wyślij jeszcze raz”.
            </p>
        @else
            <p class="mb-5">
                Nie mogliśmy potwierdzić tego wysłania. <strong>Twój tekst jest na miejscu</strong> —
                nic z niego nie przepadło. Sprawdź treść i kliknij „Wyślij jeszcze raz”.
            </p>
        @endif

        @if(auth()->guest() && $wymagaLogowania)
            {{-- Ta trasa wymaga zalogowania, a obecne żądanie pochodzi od gościa.
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

            {{-- Ten sam obszar wyboru zdjęcia co w formularzach, z których ten
                 ekran odbił człowieka (`.pole-zdjecia`,
                 resources/css/ekran-dodawania.css). Natywne pole pliku jest
                 schowane dla oka (D-035) — na ekranie, na którym trzeba coś
                 zrobić od nowa, angielskie „Choose File / No file chosen" jest
                 najgorszym możliwym napisem. Pole zostaje pod klawiaturą
                 i w drzewie dostępności, a klikalna jest etykieta. --}}
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
                        {{-- TU NIE MÓWIMY NIC O TEKŚCIE.

                             Stało tu „Tekst jest bezpieczny, brakuje tylko
                             pliku." — czyli to samo bezwarunkowe zapewnienie,
                             które wyżej zostało obwarowane warunkiem
                             `$formularz->obciete`. Przy obciętym formularzu
                             sprzeczność wracała więc niżej na tej samej
                             stronie, tylko drobniejszym pismem. Co się stało
                             z tekstem, mówi jedno miejsce: akapit na górze. --}}
                        <span class="field-help" id="odzyskany-plik-{{ $loop->index }}-help">
                            Zdjęcia nie da się odzyskać — przeglądarka na to nie pozwala.
                            Trzeba je wybrać jeszcze raz.
                        </span>
                    </label>
                </div>
            @endforeach

            @if($formularz->obciete)
                {{-- Przypomnienie przy samym przycisku, bez powtarzania
                     wyjaśnienia z góry: powód („formularz był wyjątkowo duży")
                     stoi w akapicie na górze i dwa razy na jednym ekranie
                     nie musi. Zostaje to, co jest tu do zrobienia. --}}
                <p class="field-help">Sprawdź treść przed wysłaniem.</p>
            @endif

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij jeszcze raz</button>
                <a class="btn btn-quiet" href="{{ route('landing') }}">Strona główna</a>
            </div>
        </form>
    @else
        <p class="mb-5">
            Nie mogliśmy potwierdzić tego wysłania.
            @if($formularz->obciete)
                <strong>Nie udało się odzyskać tekstu</strong>, bo formularz był wyjątkowo duży.
                Wróć do poprzedniej strony. Jeśli przeglądarka zachowała wpisaną treść,
                skopiuj ją przed ponowną próbą wysłania.
            @else
                Nie odzyskaliśmy treści tego formularza. Otwórz formularz od nowa
                i uzupełnij potrzebne pola jeszcze raz.
            @endif
            @if($formularz->maPliki())
                Zdjęcie trzeba wybrać jeszcze raz — przeglądarka nie pozwala go odzyskać.
            @endif
        </p>

        <p>
            <a class="btn btn-primary" href="{{ route('landing') }}">Strona główna</a>
        </p>
    @endif
</x-layout>
