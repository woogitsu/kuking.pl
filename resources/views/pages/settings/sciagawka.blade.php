{{--
    „ŚCIĄGAWKA DO WYDRUKU” (F4, research z 30 września 2026).

    Jedna kartka A4 dużym drukiem dla osoby, której ktoś pomógł założyć konto
    — albo dla prowadzącej zajęcia, która rozdaje kartki. Na ekranie widać
    wstęp i przycisk „Wydrukuj”; na papier idzie sama `.sciagawka`
    (arkusz `resources/css/wydruk-przepisu.css`, ten sam co przy przepisie).

    NA KARTCE NIE MA HASŁA, LINKU LOGOWANIA, KODU ANI PEŁNEGO E-MAILA.
    Kartka leży na stole. Uzasadnienie w `SciagawkaController`.

    Przycisk „Wydrukuj” działa jak „Drukuj przepis”: zwykły odnośnik
    z `?druk=1`, który skrypt `drukuj-przepis.js` zamienia w `window.print()`,
    a bez skryptu prowadzi do zdania, co nacisnąć (D-053).
--}}
<x-layout title="Ściągawka do wydruku" :noindex="true">
    <div class="druk-podpowiedz">
        <h1>Ściągawka do wydruku</h1>
        <p class="mb-3">
            Jedna kartka dużym drukiem: jak wejść na Kuking i jak dodać zdjęcie.
            Przyda się, gdy ktoś pomaga w założeniu konta — albo po prostu
            na lodówkę.
        </p>
        <p class="mb-3"><strong>Na kartce nie ma hasła ani żadnego kodu.</strong> Adres e-mail jest częściowo zasłonięty.</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="{{ route('settings.sciagawka', ['druk' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis>Wydrukuj ściągawkę</a>
            <a class="btn btn-quiet" href="{{ route('settings.index') }}">Wróć do ustawień</a>
        </div>
        @if(request()->boolean('druk'))
            <div class="notice mt-4" id="jak-wydrukowac" role="status">
                <p class="m-0"><strong>Jak wydrukować tę kartkę:</strong></p>
                <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
            </div>
        @endif
        <p class="mt-6 mb-3">Tak będzie wyglądać kartka:</p>
    </div>

    <article class="sciagawka sekcja-strony" aria-labelledby="sciagawka-tytul">
        <h2 id="sciagawka-tytul">Jak wejść na <x-kuking-word /></h2>

        <dl class="sciagawka-dane">
            <dt>Adres strony</dt>
            <dd>{{ $adresSerwisu }}</dd>
            @if($nazwaUzytkownika)
                <dt>Nazwa użytkownika</dt>
                <dd>{{ $nazwaUzytkownika }}</dd>
            @endif
            <dt>Adres e-mail konta</dt>
            <dd>{{ $emailZakryty }} (częściowo zasłonięty)</dd>
        </dl>

        <h3>Jak wejść bez hasła</h3>
        <ol class="sciagawka-kroki">
            <li>Wpisz w przeglądarce adres <strong>{{ $adresSerwisu }}</strong> i naciśnij „Zaloguj się”.</li>
            @if($linkLogowaniaWlaczony)
                <li>Pod formularzem naciśnij „Wyślij mi link do zalogowania” i wpisz swój adres e-mail.</li>
                <li>Otwórz pocztę, kliknij link w wiadomości od Kuking, a potem „Zaloguj mnie”.</li>
            @else
                <li>Naciśnij „Nie pamiętam hasła” i wpisz swój adres e-mail.</li>
                <li>Otwórz pocztę, kliknij link w wiadomości od Kuking i ustaw nowe hasło.</li>
            @endif
        </ol>

        <h3>Jak pokazać, co dziś ugotowane</h3>
        <ol class="sciagawka-kroki">
            <li>Naciśnij „Dodaj”, a potem „Zdjęcie i kilka słów”.</li>
            <li>Wybierz zdjęcie obiadu i napisz kilka słów.</li>
            <li>Naciśnij „Opublikuj”.</li>
        </ol>

        <h3>Większy tekst</h3>
        <p>Ustawienia → Czytelność. Wybrana wielkość zostaje na każdym urządzeniu.</p>

        <p class="sciagawka-uwaga"><strong>Hasła nie zapisuj na tej kartce.</strong> Na kartce nie ma niczego, co samo otwiera konto.</p>
    </article>
</x-layout>
