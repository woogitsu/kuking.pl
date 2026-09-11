<x-layout title="Twoje dane" :noindex="true">
    <h1>Twoje dane</h1>
    <p class="mb-5">
        Wszystko, co tu masz, należy do Ciebie. W każdej chwili możesz to pobrać na swój komputer.
    </p>

    {{-- Sekcja strony, nie panel formularza: nie ma tu nic do wypełnienia,
         a mocna obwódka zrównałaby pobieranie danych ze „Strefą zagrożenia"
         niżej — akcja destrukcyjna ma zostać odsunięta (AGENTS.md §5). --}}
    <section class="sekcja-strony">
        <h2 class="mt-0">Pobierz swoje dane</h2>
        <p>
            Przygotujemy paczkę ze wszystkimi Twoimi wpisami, przepisami, zdjęciami i komentarzami.
            Dostaniesz plik ZIP, który otworzysz na komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć.
        </p>

        <form method="POST" action="{{ route('settings.data.export') }}">
            @csrf
            <button class="btn btn-primary" type="submit">Przygotuj paczkę z moimi danymi</button>
        </form>

        @if($exports->isNotEmpty())
            <h3 class="mt-6">Twoje paczki</h3>
            <ul>
                @foreach($exports as $export)
                    <li class="mb-4">
                        {{ \App\Support\Czas::data($export->created_at, 'j F Y, H:i') }} —
                        @switch($export->status)
                            @case('ready') gotowa @break
                            @case('queued') w kolejce @break
                            @case('processing') przygotowujemy @break
                            @case('failed') nie udało się przygotować @break
                            @default wygasła
                        @endswitch

                        @if(isset($downloadUrls[$export->getKey()]))
                            <br>
                            <a class="btn btn-primary mt-2"
                               href="{{ $downloadUrls[$export->getKey()] }}">Pobierz paczkę</a>
                            <br>
                            <span class="field-help">Do pobrania do {{ \App\Support\Czas::data($export->expires_at, 'j F Y') }}.</span>
                        @elseif($export->status === 'failed')
                            {{-- Nigdy surowa kolumna: failureReasonLabel() zamienia kod
                                 (App\Models\DataExport::REASONS) na tekst po polsku,
                                 nawet gdy kod jest nieznany albo pusty (audyt W7-07). --}}
                            <br><span class="field-help">{{ $export->failureReasonLabel() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="danger-zone">
        <h2>Usunięcie konta</h2>
        <p>
            Konto zniknie ze strony od razu — razem z Twoimi wpisami, przepisami
            i komentarzami. Przez <strong>{{ $graceDays }} dni</strong> możesz jeszcze
            zmienić zdanie i wtedy wszystko wraca tak, jak było. Po tym czasie
            usuniemy Twoje dane i zdjęcia na stałe, a o tym, co zrobimy z Twoimi
            {{-- Ukośnik rodzajowy „sam/sama" (issue #38) — słowo nie
                 wnosi tu żadnej informacji, więc najtańszą naprawą jest je
                 skreślić, tak jak każe COPY_STYLE.md §2. --}}
            tekstami, decydujesz Ty w formularzu niżej.
        </p>

        {{--
            CO DOKŁADNIE ZNIKA, A CO ZOSTAJE (decyzje D-018 i D-022,
            audyt W4-01, weryfikacja W1).

            Stało tu „dane zostaną usunięte na stałe", a niżej trzeba było
            potwierdzić „moje wpisy, przepisy i zdjęcia zostaną usunięte na
            stałe". Kod tego nie robił: kasował zdjęcie profilowe, a resztę
            zostawiał przy zanonimizowanym koncie. Obietnica złożona
            konkretnym zdaniem i niedotrzymana.

            Potem ten ekran obiecywał, że teksty ZOSTANĄ zanonimizowane —
            i to też nie było prawdą, tylko w drugą stronę: konto zostawało
            po anonimizacji na statusie `pending_delete`, więc jego przepisy,
            wpisy i komentarze oddawały 403. Zmierzone
            (`UsunieteKontoTresciZostajaWidoczneTest`), naprawione przez
            osobny stan końcowy konta (`erased`).

            Ten ekran ma teraz TRZY listy, nie dwie, bo zakres usunięcia
            wybiera człowiek (D-022): co znika zawsze, co zostaje przy
            haczyku nietkniętym i co znika dodatkowo, gdy haczyk zostanie
            zaznaczony. Każde z tych zdań musi być prawdziwe w kodzie —
            pilnuje tego `UsuwanieKontaZakresTest`.
        --}}
        {{-- SEKCJA, nie ramka pomocnicza. Te trzy listy są MATERIAŁEM do
             wyboru zakresu usunięcia (D-022), a nie przypisem obok niego —
             to jedyne miejsce, gdzie napisano, co dokładnie kasuje haczyk.
             Na warstwie wgłębionej sąsiednie „Pobierz swoje dane" (akcja
             zwykła, odwracalna) stało wizualnie WYŻEJ niż opis skutków,
             których cofnąć się nie da. --}}
        <div class="sekcja-strony mt-4">
            <h3 class="mt-0">Co zniknie, a co zostanie</h3>

            <p><strong>Znikną na stałe — zawsze:</strong></p>
            <ul>
                <li>Twój adres e-mail, hasło i nazwa użytkownika.</li>
                <li>Twoje zdjęcie profilowe, opis i wszystko, co Cię nazywa.</li>
                <li><strong>Wszystkie Twoje zdjęcia</strong> — te we wpisach, w przepisach
                    i w wykonaniach. Także oryginały, razem z zapisaną w nich datą,
                    modelem telefonu i miejscem, w którym powstały.</li>
            </ul>

            <p><strong>Zostaną bez Twojego nazwiska — jeśli nie zaznaczysz haczyka niżej:</strong></p>
            <ul>
                <li>Tekst przepisów, wpisów i komentarzy — podpisany
                    „Użytkownik usunięty".</li>
                <li>Twoje „Ugotowałem" pod cudzymi przepisami — też podpisane
                    „Użytkownik usunięty".</li>
                <li>Twoje publiczne zeszyty — zostanie nazwa zeszytu i lista
                    zapisanych w nim przepisów. Twojego nazwiska nie ma tam
                    w ogóle.</li>
            </ul>

            {{-- STAŁO TU KAZANIE, NIE INFORMACJA (audyt tekstów 11.09.2026).

                 „Zostaje, bo to jest już także cudza historia: ktoś
                 odpowiedział Ci w komentarzu, ktoś ugotował z Twojego przepisu
                 i ma go w swoim zeszycie. Skasowanie tego zabrałoby coś
                 ludziom, którzy o nic nie prosili."

                 Ostatnie zdanie mówiło człowiekowi, co byłoby nie w porządku,
                 gdyby wybrał drugą opcję — na ekranie, na którym ma wybrać.
                 Uzasadnienie domyślnego zakresu usunięcia jest decyzją D-022
                 i mieszka w `docs/DECISIONS.md`, nie przy haczyku.

                 Zdania o FAKTACH nie zniknęły: co dokładnie zostaje, a co
                 znika, mówią trzy listy w tej sekcji; że przepis może być
                 w cudzym zeszycie, mówi lista niżej. --}}
            <p><strong>Znikną razem z resztą — jeśli zaznaczysz haczyk niżej:</strong></p>
            <ul>
                <li>Wszystkie Twoje przepisy, wpisy, komentarze, wykonania
                    („Ugotowałem") i zeszyty — razem z całym tekstem.</li>
                <li>Twoje przepisy znikną też z zeszytów innych osób.</li>
                <li>Komentarze i wykonania, które inni dodali pod Twoim przepisem
                    albo wpisem, znikną razem z nim.</li>
            </ul>

            {{-- „Dlatego haczyk jest domyślnie pusty" mówiło, CZEMU tak
                 zrobiliśmy. Że haczyk jest pusty, człowiek widzi niżej sam;
                 powód stoi w D-022. Fakty zostają: tego nie da się odwrócić,
                 i co zrobić, jeśli chce usunąć tylko część. --}}
            <p>
                Tego nie da się odwrócić — skasowanego tekstu nikt już nie
                przywróci. Jeśli chcesz usunąć tylko wybrane przepisy albo
                wpisy, usuń je samodzielnie, zanim skasujesz konto: później nie
                będzie już jak, bo do usuniętego konta nie da się zalogować.
            </p>
        </div>
        <p><strong>Zanim to zrobisz, warto najpierw pobrać swoje dane.</strong></p>

        <x-error-summary />

        <details>
            <summary class="btn btn-secondary inline-flex">Chcę usunąć swoje konto</summary>
            <form class="mt-4" method="POST" action="{{ route('settings.data.delete') }}">
                @csrf
                <x-field name="password" label="Wpisz swoje hasło" type="password" required autocomplete="current-password"
                         help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

                {{--
                    ZAKRES USUNIĘCIA WYBIERA CZŁOWIEK (decyzja D-022).

                    Haczyk jest DOMYŚLNIE PUSTY i to nie jest wygoda dla
                    serwisu: domyślną opcją ma być ta, której skutków nie da
                    się cofnąć w mniejszym stopniu. Zostawiony tekst da się
                    skasować później, skasowanego nie da się przywrócić.

                    Wybór trafia do kolumny `users.delete_scope` przy
                    ZGŁOSZENIU, nie jest odczytywany 30 dni później z ekranu,
                    którego wtedy może już nie być w tej formie.

                    `old()` MUSI tu być: nieudana walidacja hasła nie może
                    cicho odhaczyć haczyka, który człowiek świadomie postawił
                    (AGENTS.md §5 — „poprawnie wpisane dane nigdy nie
                    znikają"). Odwrotny kierunek jest tu groźniejszy niż
                    zwykle: chodzi o to, czy skasujemy czyjeś przepisy.
                --}}
                <label class="choice mt-4" for="f-usun-tresci">
                    <input id="f-usun-tresci" type="checkbox" name="usun_tresci" value="1"
                           @checked(old('usun_tresci'))>
                    <span class="choice-label">Usuń także moje przepisy, wpisy, komentarze, wykonania i zeszyty</span>
                </label>
                {{-- Tekst podstawowy (18 px), nie `field-help` (16 px): to
                     jest zdanie, od którego zależy, czy skasujemy komuś
                     wszystkie przepisy. `docs/UX_50_PLUS.md`. --}}
                <p class="mt-3">
                    Bez tego haczyka Twoje teksty zostaną w serwisie, podpisane
                    „Użytkownik usunięty". Z haczykiem znikną na stałe i nikt ich
                    już nie przywróci.
                </p>

                <label class="choice mt-4" for="f-confirm">
                    <input id="f-confirm" type="checkbox" name="confirm" value="1">
                    <span class="choice-label">Rozumiem, że po {{ $graceDays }} dniach moje dane i wszystkie moje zdjęcia zostaną usunięte na stałe i że tego nie da się cofnąć</span>
                </label>

                <button class="btn btn-danger mt-5" type="submit">Usuń moje konto</button>
            </form>
        </details>
    </div>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="data" />
    </x-slot:rail>
</x-layout>
