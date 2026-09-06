{{--
    Odwołanie dla osoby, która nie może się zalogować (issue #10, DSA art. 20).

    To jedyny ekran w serwisie, na którym prosimy o hasło poza logowaniem.
    Powód jest wprost napisany na stronie, bo prośba o hasło bez wyjaśnienia
    wygląda jak phishing — a nasza grupa jest na to szczególnie wyczulona
    (i słusznie).

    Bez JavaScriptu (D-007). Bez gry słowem „kuKING" — D-009.
--}}
<x-layout title="Odwołanie od decyzji" :noindex="true">
    <h1>Odwołanie od decyzji</h1>

    <x-error-summary />

    <p>
        Ten formularz jest dla osób, których konto zostało zablokowane albo zawieszone
        i które uważają, że to pomyłka. Sprawdzimy sprawę jeszcze raz.
    </p>

    <form class="card" method="POST" action="{{ route('appeals.guest.store') }}">
        @csrf

        <h2 style="margin-top:0; font-size:var(--text-title-sm);">Powiedz, kim jesteś</h2>
        <p>
            Prosimy o hasło tylko po to, żeby mieć pewność, że odwołanie składa
            właściciel konta. To Cię nigdzie nie zaloguje i nie zdejmuje blokady.
        </p>

        <x-field name="login" label="Adres e-mail albo nazwa użytkownika" required
                 autocomplete="username"
                 help="Możesz wpisać jedno albo drugie — obojętnie które." />

        <x-field name="password" label="Hasło" type="password" required autocomplete="current-password" />

        <h2 style="font-size:var(--text-title-sm);">Napisz, dlaczego to pomyłka</h2>

        <x-field name="body" label="Twoje wyjaśnienie" type="textarea" :rows="6" required
                 help="Od 10 do 2000 znaków. Wystarczy kilka zdań własnymi słowami." />

        <p class="meta">
            Co będzie dalej: odwołanie trafia do osoby, która obejrzy sprawę drugi raz.
            Odpowiadamy w ciągu {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych —
            zawsze z wyjaśnieniem. Odpowiedź zobaczysz na ekranie logowania, gdy spróbujesz
            wejść na konto. Odwołanie od jednej decyzji składa się raz.
        </p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij odwołanie</button>
            <a class="btn btn-quiet" href="{{ route('password.request') }}">Nie pamiętam hasła</a>
        </div>
    </form>

    <p class="mt-6">
        Nie pamiętasz hasła i nie masz dostępu do skrzynki? Napisz do nas na
        {{ config('kuking.community.contact_email') }} — odwołanie złożone e-mailem
        też rozpatrujemy.
    </p>
</x-layout>
