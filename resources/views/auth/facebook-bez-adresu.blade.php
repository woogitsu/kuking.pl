<x-layout title="Facebook nie podał nam adresu e-mail" :noindex="true">
    <h1>Facebook nie podał nam adresu e-mail</h1>

    {{--
        EKRAN, KTÓREGO DROGA GOOGLE NIE POTRZEBOWAŁA WCALE (runbook §7.2).

        Dwie przyczyny, obie realne i obie potwierdzone w dokumentacji Meta:
        konto Facebooka założone na numer telefonu nie ma adresu e-mail
        wcale („This field will not be returned if no valid email address is
        available"), a człowiek może odznaczyć zgodę na adres na ekranie
        Facebooka — świadomie i ma do tego prawo.

        Bez adresu nie da się u nas założyć konta i nie jest to nasz kaprys:
        adres e-mail jest jedyną drogą odzyskania konta i jedyną drogą
        powiadomień („ktoś ugotował Twój przepis"). Konto bez niego nie ma
        sensu i po pierwszym zgubionym haśle byłoby stracone.

        CZEGO TU NIE MA, ŚWIADOMIE: przycisku „poproś Facebooka jeszcze raz"
        (`auth_type=rerequest`). Meta ostrzega w tej sprawie sama — „if
        someone is actively choosing not to grant a specific permission to an
        app they are unlikely to change their mind, even in the face of
        continued prompting" — a dla osoby 60+ drugie takie samo okno zgody
        jest sygnałem, że coś tu jest nie tak. Jedna prośba, jasne zdanie
        i droga dalej (D-053: nigdzie martwego przycisku, ale też nigdzie
        pętli).
    --}}
    <div class="sekcja-strony">
        <p>
            Żeby założyć konto w Kuking, potrzebujemy Twojego adresu e-mail — to na niego
            wysyłamy wiadomość, gdy ktoś ugotuje Twój przepis, i tylko nim odzyskasz konto,
            jeśli zgubisz hasło. <strong>Facebook nam go nie podał</strong>, więc tą drogą
            konta nie założymy.
        </p>
        <p>
            Zwykle znaczy to jedno z dwóch: albo Twoje konto na Facebooku jest założone
            na numer telefonu i nie ma przy nim adresu e-mail, albo na ekranie Facebooka
            adres został odznaczony.
        </p>

        <h2>Co zrobić</h2>
        <p>
            <strong>Załóż konto adresem e-mail</strong> — zajmie to chwilę i wymaga tylko
            adresu oraz hasła. Potem, już na swoim koncie, wejdziesz w
            <strong>Ustawienia → Bezpieczeństwo</strong> i klikniesz „Połącz konto
            Facebooka". Po połączeniu kont możesz logować się przyciskiem „Wejdź kontem Facebooka”.
        </p>

        <div class="form-actions">
            <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto adresem e-mail</a>
            <a class="btn btn-secondary" href="{{ route('login') }}">Mam już konto — wejdź na nie</a>
        </div>
    </div>

    <p class="mt-6">
        Nie wiesz, jaki adres e-mail jest przy Twoim Facebooku, albo coś tu nie zagrało?
        Napisz do nas na <strong>{{ config('kuking.community.contact_email') }}</strong> —
        odpisuje człowiek.
    </p>
</x-layout>
