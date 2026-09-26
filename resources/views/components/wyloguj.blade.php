{{--
    WYLOGOWANIE — jedyne miejsce w serwisie, w którym da się wyjść z konta.

    DLACZEGO TO JEST OSOBNY SKŁADNIK, A NIE DWA FORMULARZE
    Trasa `POST /logout` istniała od początku, a w warstwie widoków nie
    było ANI JEDNEGO odwołania do niej: `grep -rn "route('logout')"
    resources/views/` dawało zero trafień. Jedyne „Wyloguj" w całym
    serwisie to „Wyloguj INNE urządzenia" na ekranie bezpieczeństwa, czyli
    coś zupełnie innego. Człowiek na cudzym albo wspólnym komputerze nie
    miał jak wyjść.

    Skoro trzeba to wystawić w dwóch miejscach (nawigacja boczna znika
    poniżej 64rem), to ma być JEDEN kawałek kodu. Dwie kopie formularza
    z tokenem CSRF rozjeżdżają się przy pierwszej zmianie.

    DLACZEGO FORMULARZ, A NIE ODNOŚNIK
    Wylogowanie zmienia stan konta, więc idzie POST-em — odnośnik GET
    wylogowywałby człowieka z podglądu linku albo z prefetchu przeglądarki.
    Zwykły formularz, bez JavaScriptu (AGENTS.md §6).

    BEZ POTWIERDZENIA
    Wylogowanie jest odwracalne jednym zalogowaniem i nic nie kasuje.
    Okno „czy na pewno" przy nieszkodliwej akcji uczy odklikiwania ostrzeżeń
    i przez to osłabia te ostrzeżenia, które są potrzebne naprawdę.

    WYLOGOWANIE GASI POWIADOMIENIA NA TYM URZĄDZENIU (#1979)
    Puste pole `push_endpoint` uzupełnia `resources/js/powiadomienia-push.js`
    adresem subskrypcji tej przeglądarki i wypisuje ją z Web Push
    (`unsubscribe()`), zanim formularz pójdzie. Serwer na tym nie polega:
    przeglądarkę, która włączyła powiadomienia w tej sesji, rozpoznaje po
    sesji (`OdlaczUrzadzeniePush`). Bez skryptu przycisk działa jak dotąd.
--}}
@auth
    <form method="POST" action="{{ route('logout') }}" class="{{ $formClass ?? '' }}" data-wyloguj>
        @csrf
        <input type="hidden" name="push_endpoint" value="" data-wyloguj-push>
        <button type="submit" class="{{ $class ?? 'btn btn-secondary' }}">
            {{ $slot->isEmpty() ? 'Wyloguj się' : $slot }}
        </button>
    </form>
@endauth
