<x-layout title="Załóż konto" description="Załóż darmowe konto w Kuking i pokaż, co dziś ugotowałeś.">
    <h1>Zostań <x-kuking-word forma="iem" /></h1>
    {{--
        Kontrakt projektowy 60+ (docs/research/AUDYT_60_PLUS.md, ranking
        pkt 3 i 9; test regresyjny: RejestracjaOnboardingKopiaTest).
        „Cztery pola i gotowe" musi zapowiadać, co będzie DALEJ — inaczej
        „Krok 1 z 3" zaraz potem czyta się jak „jednak coś nie wyszło".
    --}}
    <p class="mb-5 rejestracja-zapowiedz">
        Cztery pola i gotowe — konto zaczyna działać od razu. Potem zapytamy jeszcze
        o parę rzeczy, żeby dobrać Ci pierwsze wpisy, ale to całkiem opcjonalne
        i można to pominąć. Nie pytamy o numer telefonu ani o datę urodzenia.
    </p>

    {{-- Drogi dodatkowe: konto Google (issue #258, D-069) i konto Facebooka
         (issue #259, D-098). Przycisk dostawcy znika razem z jego kluczami,
         a cały blok — gdy nie działa żaden.

         NAD FORMULARZEM, NIE POD NIM — zgłoszenie właściciela.

         Pod formularzem ten blok widziała wyłącznie osoba, która przewinęła
         przez cztery pola, Turnstile i przycisk — czyli ta, która już
         postanowiła wymyślić hasło. Człowiek, dla którego to wejście
         powstało, odbijał się wcześniej. Większość naszej grupy ma konto
         Google albo Facebooka i to jest dla niej droga krótsza, nie
         dodatek. --}}
    <x-wejscia-zewnetrzne naglowek="Nie chcesz wymyślać hasła? Załóż konto przez Google albo Facebooka" />

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('register') }}">
        @csrf

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required
                 autocomplete="name" placeholder="Basia"
                 help="Imię, przezwisko albo cokolwiek chcesz. To będzie widoczne dla innych." />

        {{-- POMOC MÓWI, PO CO TO POLE JEST, A NIE JAKIE ZNAKI SĄ DOZWOLONE.
             Stary tekst („tylko litery bez polskich znaków, cyfry
             i podkreślnik") mówił językiem reguły i odbił od rejestracji
             63-letnią osobę z grupy docelowej. Zapis poprawia teraz serwis
             (`NazwaUzytkownika`), więc lista dozwolonych znaków przestała być
             informacją, którą trzeba komuś podawać z góry. --}}
        <x-field name="username" label="Nazwa, która będzie w adresie Twojego profilu" required
                 autocomplete="username" placeholder="Basia z Podkarpacia"
                 help="Podpowiadamy ją z Twojego imienia — możesz zostawić albo wpisać własną. Polskie litery i spacje są w porządku, zapis poprawimy za Ciebie." />

        {{--
            ADRES Z ZAPROSZENIA NIE JEST POLEM FORMULARZA (D-085).

            Kto przyszedł z linku w wiadomości, ma adres już potwierdzony —
            i to potwierdzenie stoi na tym, że kliknął link ZE SWOJEJ skrzynki.
            Gdyby adres dało się tu podmienić, powstałoby konto z potwierdzonym
            adresem, którego nikt nigdy nie potwierdził. Dlatego adres nie
            przyjeżdża z przeglądarki w ogóle: `RegisterController` bierze go
            z wiersza w bazie wskazanego przez sesję, a to, co tu widać, jest
            wyłącznie informacją dla człowieka. `readonly` byłoby podpowiedzią
            dla oka, nie zabezpieczeniem.

            WYJŚCIE MUSI BYĆ WIDOCZNE, bo z jednej skrzynki korzysta czasem
            całe małżeństwo — bez niego to jest ślepa ściana (docs/UX_50_PLUS.md).
        --}}
        @if($zaproszenie !== null)
            <div class="field">
                <span class="field-label">Twój adres e-mail</span>
                <p class="field-static"><strong>{{ $zaproszenie->email }}</strong></p>
                {{-- BEZ RODZAJU GRAMATYCZNEGO (docs/brand/COPY_STYLE.md §2):
                     „kliknąłeś" przypisywało czytelnikowi płeć. Rzeczownik
                     zamiast czasownika w czasie przeszłym — i zdanie jest
                     przy okazji krótsze. --}}
                <span class="field-help">
                    Ten adres jest już potwierdzony — wystarczyło kliknięcie linku z tej skrzynki,
                    więc żadna kolejna wiadomość od nas nie musi przyjść.
                </span>
            </div>
        @else
            {{-- ADRES NIE SŁUŻY „TYLKO WTEDY, GDY ZAPOMNISZ HASŁA".

                 Tak tu stało i było to nieprawdą na trzy sposoby naraz:
                 adresem MOŻNA SIĘ LOGOWAĆ (pole na ekranie logowania nazywa
                 się „Adres e-mail albo nazwa użytkownika"), na adres idzie
                 link wpuszczający na konto bez hasła (D-056 — droga
                 równorzędna z hasłem, nie awaryjna), a poza tym potwierdza
                 zmianę samego adresu (issue #195) i odbiera tygodniowe
                 podsumowanie, gdy ktoś je sobie włączy (D-057, domyślnie
                 wyłączone).

                 Nowe zdanie wymienia tylko te dwa zastosowania, które są
                 prawdziwe ZAWSZE. Logowania linkiem świadomie nie wymieniam
                 z nazwy: cała ta droga znika przy
                 `KUKING_LOGOWANIE_LINKIEM=false` i wtedy zdanie o niej byłoby
                 nową nieprawdą w miejscu starej. --}}
            <x-field name="email" label="Twój adres e-mail" type="email" required
                     autocomplete="email"
                     help="Możesz się nim logować, a gdy zapomnisz hasła — wyślemy na niego link. Nie pokażemy go nikomu." />
        @endif

        {{-- PRZYKŁAD HASŁA UCZY SPOSOBU, A NIE KONKRETNEGO HASŁA.

             Stało tu „trzy słowa razem, na przykład: zielonapietruszkarano" —
             jedno słowo bez separatorów, czyli wzorzec, który łamie się
             słownikowo szybciej niż wygląda, a do tego jest gotowym hasłem
             do przepisania. Myślniki rozdzielają słowa, a zdanie mówi wprost,
             żeby wpisać swoje. --}}
        <x-field name="password" label="Hasło" type="password" required
                 autocomplete="new-password"
                 help="Co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu." />

        <div class="field @error('age_confirmed') has-error @enderror mt-6">
            <label class="choice" for="f-age_confirmed">
                <input id="f-age_confirmed" type="checkbox" name="age_confirmed" value="1"
                       @error('age_confirmed') aria-invalid="true" aria-describedby="f-age_confirmed-error" @enderror @checked(old('age_confirmed'))>
                <span class="choice-label">Mam co najmniej {{ config('kuking.account.min_age') }} lat</span>
            </label>
            @error('age_confirmed')<span class="field-error" id="f-age_confirmed-error">{{ $message }}</span>@enderror
        </div>

        <div class="field @error('terms_accepted') has-error @enderror">
            <label class="choice" for="f-terms_accepted">
                <input id="f-terms_accepted" type="checkbox" name="terms_accepted" value="1"
                       @error('terms_accepted') aria-invalid="true" aria-describedby="f-terms_accepted-error" @enderror @checked(old('terms_accepted'))>
                <span class="choice-label">
                    Znam <a href="{{ route('rules') }}">zasady Kuking</a>
                    i <a href="{{ route('terms') }}">regulamin</a>
                </span>
            </label>
            @error('terms_accepted')<span class="field-error" id="f-terms_accepted-error">{{ $message }}</span>@enderror
        </div>

        <x-turnstile miejsce="rejestracja" />

        {{--
            ZDANIE O BŁĘDZIE TAM, GDZIE CZŁOWIEK PATRZY, GDY KLIKA.

            Podsumowanie błędów stoi na górze formularza i tak ma zostać — ale
            po wysłaniu przeglądarka zostawia człowieka w tym samym miejscu, na
            dole. 63-latka, która odbiła się o walidację nazwy użytkownika, nie
            zobaczyła ani podsumowania, ani czerwonego tekstu przy polu:
            zobaczyła to, co miała przed oczami.

            JavaScript przewija teraz do podsumowania (`resources/js/app.js`),
            ale to jest DODATEK. To zdanie jest wersją bez JavaScriptu i nie
            wolno go usuwać razem z nim.
        --}}
        @if($errors->any())
            <p class="field-error mb-4">
                Formularz nie został wysłany —
                <a href="#tresc">na górze jest napisane, czego jeszcze brakuje</a>.
            </p>
        @endif

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Załóż konto</button>
        </div>
    </form>

    {{--
        „CHCĘ KONTO NA INNY ADRES" — osobny formularz, POZA tamtym.
        Zagnieżdżenie formularzy jest w HTML niedozwolone, a ten przycisk
        musi być POST-em, bo zmienia stan sesji (porzuca zaproszenie).
        Bez JavaScriptu, tak jak cała ta droga.
    --}}
    @if($zaproszenie !== null)
        <form method="POST" action="{{ route('zaproszenie.porzuc') }}" class="mt-5">
            @csrf
            <button class="btn btn-quiet" type="submit">Chcę konto na inny adres e-mail</button>
        </form>
    @endif

    <p class="mt-6">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>.</p>
</x-layout>
