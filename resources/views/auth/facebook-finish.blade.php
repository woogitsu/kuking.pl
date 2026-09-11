<x-layout title="Jeszcze dwie rzeczy i konto gotowe" :noindex="true">
    <h1>Jeszcze dwie rzeczy i konto gotowe</h1>

    {{--
        TEN EKRAN JEST BLIŹNIAKIEM `auth/google-finish` I JEDNA RZECZ JEST
        NA NIM INNA: zdanie o wiadomości z potwierdzeniem adresu na dole.

        Powód jest cały w D-098 i w `FacebookLoginController`: Facebook NIE
        MÓWI, czy adres e-mail jest potwierdzony (Graph API oddaje pole
        `email` albo nie oddaje go wcale, i nigdy nie mówi nic więcej).
        Konto powstaje więc z adresem NIEPOTWIERDZONYM i przechodzi naszą
        zwykłą ścieżkę potwierdzenia, tak jak przy rejestracji hasłem — a to
        znaczy, że człowiek dostanie od nas wiadomość i ma o tym wiedzieć
        ZANIM kliknie, a nie dowiedzieć się ze skrzynki.

        Przy Google tego zdania nie ma, bo tam adres jest potwierdzony
        i żadna wiadomość nie wychodzi (D-069).
    --}}
    <p class="mb-5">
        Wchodzisz kontem Facebooka na adres <strong>{{ $email }}</strong> —
        taki adres podał nam Facebook.
        Nie o to konto chodziło? <a href="{{ route('login') }}">Wróć do logowania</a>.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('facebook.finish.store') }}">
        @csrf

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required
                 :value="$proponowaneImie"
                 autocomplete="name" placeholder="Basia"
                 help="Imię, przezwisko albo cokolwiek chcesz. To będzie widoczne dla innych." />

        {{--
            NAZWA JEST PODPOWIEDZIANA, NIE NADANA — ten sam wywód co przy
            Google: decyzja właściciela z 10 września (dwa pola przy
            rejestracji, nazwa podpowiadana i do zmiany), a podpowiedź liczy
            SERWER i sprawdza przy tym, czy jest wolna, więc nie proponujemy
            czegoś, co odbije się o walidację.
        --}}
        <x-field name="username" label="Nazwa, która będzie w adresie Twojego profilu" required
                 :value="$proponowanaNazwa"
                 autocomplete="username" placeholder="Basia z Podkarpacia"
                 help="Podpowiadamy ją z Twojego imienia — możesz zostawić albo wpisać własną. Polskie litery i spacje są w porządku, zapis poprawimy za Ciebie." />

        {{--
            OŚWIADCZENIA — PUSTE, ZAWSZE.

            Facebook ich nie przekaże, a zaznaczenie ich za człowieka byłoby
            ciemnym wzorcem; przy oświadczeniu o wieku dodatkowo bez żadnej
            wartości, bo oświadczenie złożone przez serwer nie jest niczyim
            oświadczeniem. `old()` wraca po nieudanej walidacji i to jest
            jedyna sytuacja, w której haczyk może tu przyjść zaznaczony —
            bo zaznaczył go człowiek.
        --}}
        <div class="field @error('age_confirmed') has-error @enderror mt-6">
            <label class="choice" for="f-age_confirmed">
                <input id="f-age_confirmed" type="checkbox" name="age_confirmed" value="1" @checked(old('age_confirmed'))>
                <span class="choice-label">Mam co najmniej {{ config('kuking.account.min_age') }} lat</span>
            </label>
            @error('age_confirmed')<span class="field-error" id="f-age_confirmed-error">{{ $message }}</span>@enderror
        </div>

        <div class="field @error('terms_accepted') has-error @enderror">
            <label class="choice" for="f-terms_accepted">
                <input id="f-terms_accepted" type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted'))>
                <span class="choice-label">
                    Znam <a href="{{ route('rules') }}">zasady Kuking</a>
                    i <a href="{{ route('terms') }}">regulamin</a>
                </span>
            </label>
            @error('terms_accepted')<span class="field-error" id="f-terms_accepted-error">{{ $message }}</span>@enderror
        </div>

        {{--
            ZDANIE O BŁĘDZIE TAM, GDZIE CZŁOWIEK PATRZY, GDY KLIKA — ten sam
            powód co na ekranie rejestracji hasłem: po wysłaniu przeglądarka
            zostawia go na dole, a podsumowanie stoi na górze.
        --}}
        @if($errors->any())
            <p class="field-error mb-4">
                Formularz nie został wysłany —
                <a href="#tresc">na górze jest napisane, czego jeszcze brakuje</a>.
            </p>
        @endif

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Załóż konto i wejdź</button>
        </div>
    </form>

    <p class="mt-6">
        Hasła nie ustawiasz — na to konto będziesz wchodzić kontem Facebooka.
        Wyślemy Ci jeszcze jedną wiadomość na <strong>{{ $email }}</strong> z przyciskiem,
        którym potwierdzisz, że to Twoja skrzynka. Warto to zrobić: wtedy będziesz mieć
        drugą drogę wejścia na konto, gdyby Facebook kiedyś przestał działać.
    </p>
</x-layout>
