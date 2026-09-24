<p>
    Jeśli logujesz się przez Google i nie masz jeszcze hasła do Kuking,
    ustaw je przez odnośnik z wiadomości e-mail. Nie wpisuj tutaj hasła do Google.
</p>
@if(\App\Support\Poczta::dziala())
    <p>
        Najpierw upewnij się, że masz dostęp do swojej skrzynki{{ ($wlaczanie ?? false) ? '' : ' i kodu z aplikacji' }}.
        Wtedy wybierz „Wyloguj się”, potem „Zaloguj się” i „Nie pamiętam hasła”.
        @if($wlaczanie ?? false)
            Po ustawieniu hasła zaloguj się nim, otwórz
            „Ustawienia” → „Weryfikacja dwuetapowa” i wróć do tego formularza.
        @else
            Po ustawieniu hasła zaloguj się nim i kodem z aplikacji, otwórz
            „Ustawienia” → „Weryfikacja dwuetapowa” i wróć do tego formularza.
        @endif
    </p>
    <p>
        Jeśli nie masz dostępu do skrzynki{{ ($wlaczanie ?? false) ? '' : ' lub kodu z aplikacji' }}, pozostań na swoim koncie
        i <a href="{{ route('kontakt') }}">napisz do nas</a>.
    </p>
@else
    <p>
        Nie wysyłamy jeszcze wiadomości e-mail, więc ustawienie hasła tą drogą jest teraz niedostępne.
        Pozostań na razie na swoim koncie. Jeśli potrzebujesz pomocy,
        <a href="{{ route('kontakt') }}">napisz do nas</a>.
    </p>
@endif