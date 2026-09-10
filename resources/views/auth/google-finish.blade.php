<x-layout title="Jeszcze dwie rzeczy i konto gotowe" :noindex="true">
    <h1>Jeszcze dwie rzeczy i konto gotowe</h1>

    {{--
        CZŁOWIEK MUSI WIDZIEĆ, NA JAKI ADRES ZAKŁADA KONTO.

        Z jednego telefonu i z jednej skrzynki korzysta czasem całe
        małżeństwo, a Google wchodzi domyślnie kontem ostatnio używanym.
        Bez tego zdania da się założyć konto na adres współmałżonka i nie
        zauważyć tego przez tydzień. Ten sam wywód co przy ekranie
        z linkiem e-mail (D-056).
    --}}
    <p class="mb-5">
        Wchodzisz kontem Google na adres <strong>{{ $email }}</strong>.
        Nie o to konto chodziło? <a href="{{ route('login') }}">Wróć do logowania</a>.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('google.finish.store') }}">
        @csrf

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required
                 :value="$proponowaneImie"
                 autocomplete="name" placeholder="Basia"
                 help="Imię, przezwisko albo cokolwiek chcesz. To będzie widoczne dla innych." />

        {{--
            NAZWA JEST PODPOWIEDZIANA, NIE NADANA.

            Decyzja właściciela z 10 września: przy rejestracji zostają DWA
            pola — imię widoczne dla innych i nazwa, która trafia do adresu
            profilu — a nazwa jest podpowiadana i da się ją zmienić. Droga
            przez Google musi być z tym spójna, więc podpowiedź stoi
            w polu, a nie w bazie: człowiek widzi ją, zanim cokolwiek
            powstanie, i może wpisać własną.

            Podpowiedź liczy `App\Support\NazwaUzytkownika::wolnaPropozycja()`
            po stronie serwera — z imienia z Google, a gdy go nie ma, z
            początku adresu e-mail. Serwer sprawdza przy tym, czy nazwa jest
            wolna, więc nie proponujemy czegoś, co i tak odbije się o
            walidację.
        --}}
        <x-field name="username" label="Nazwa, która będzie w adresie Twojego profilu" required
                 :value="$proponowanaNazwa"
                 autocomplete="username" placeholder="Basia z Podkarpacia"
                 help="Podpowiadamy ją z Twojego imienia — możesz zostawić albo wpisać własną. Polskie litery i spacje są w porządku, zapis poprawimy za Ciebie." />

        {{--
            OŚWIADCZENIA — PUSTE, ZAWSZE.

            Google ich nie przekaże, a zaznaczenie ich za człowieka byłoby
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
        Hasła nie ustawiasz — na to konto będziesz wchodzić kontem Google.
        Zawsze możesz też ustawić sobie hasło przez
        <a href="{{ route('password.request') }}">„Nie pamiętam hasła”</a>.
    </p>
</x-layout>
