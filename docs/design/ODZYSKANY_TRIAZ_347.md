# Odzyskane pozycje audytu #347

14 września 2026. Odczyt kodu `8339b373e7ee6909ee3e69b58ec4de22dc7b5eb0` i historii decyzji, niezależnie przez dodatkowego agenta. Nie jest to nowy pomiar przeglądarkowy ani ponowne wykonanie wymienionych testów.

Źródło historyczne: `docs/research/audyt-uiux-2026-09-11/TRIAZ.md` na gałęzi `claude/audyt-uiux-triaz`, commit `027b1546e54343b8a6a2761b202391de93418fc1`, blob `b80d198051d46bfd472b5fa9aef38263bcab4e01`. Pliku nie ma na sprawdzonym main. Poniżej zapisujemy treść sześciu brakujących pozycji i ich obecny status, zamiast przenosić nieaktualne zalecenia do zasad projektu.

| Pozycja starego TRIAZ | Obecny stan | Dowód w sprawdzonym kodzie |
|---|---|---|
| §8a, linia193: szyna gościa w wyszukiwaniu i profilu | Rozwiązane; profil dodatkowo przebudowany | D-122; `pages/search.blade.php:16`, `components/layout.blade.php:580`, `marka-rama.css:173`; profil `pages/profile/show.blade.php:261` i `:350`, `marka-profil.css:118`. |
| §8b, linia203: odkrywanie bez szyny, tablica nad strumieniem | Rozwiązane na szerokim ekranie; na telefonie tablica świadomie poprzedza wpisy | `pages/discover.blade.php:19`, `ekran-odkrywania.css:68` i `:94`; istniejący test `OdkrywanieUzywaKolumnySzynyTest`. |
| §8c, linia214: trzy karty logowania w pionie | Zastąpione późniejszą kompozycją: zaproszenie obok wspólnej karty dróg wejścia | D-210; `auth/login.blade.php:23`, `:32`, `:71`; `marka-wejscie.css:7`, `:25`, `:42`. Nie przywracamy dokładnego podziału starego audytu. |
| §5a, linia271: jedna klasa `.card` dla wszystkich ról | Rozdział ról wdrożony; sama obecność klasy nie dowodzi usterki | D-125–128; `panel-formularza`, `sekcja-strony`, `szyna-blok`; testy `WarstwyPowierzchniTest`, `TrzyRozstrzygnieciaWarstwTest`, `WyjatkiRolKartTest`. |
| §10a, linia307: kropki bez widocznego napisu „Więcej” | Świadomie odwrócone przez właściciela | D-172; `components/post-card.blade.php:182` zachowuje nazwę dostępną. Nie rozszerzamy wyjątku na inne przyciski. |
| §14, linia353: obszerny `app.css` | Pozostał dług utrzymaniowy, częściowo rozdzielony | Na sprawdzonym SHA:6231 wierszy głównego pliku i22 arkusze w `resources/css`; importy `app.css:18–44`. Sama długość nie dowodzi błędu widocznego dla użytkownika. |

Ścieżki Blade w tabeli są względem `resources/views/`, arkusze względem `resources/css/`. Numery linii opisują wskazany SHA, nie każdą przyszłą wersję.

## Co zostaje w #347

Issue pozostaje otwarte, ale sześć pozycji nie jest już listą nieznanych numerów. Pozostaje dalszy podział arkusza, gdy ułatwi konkretną zmianę, oraz uporządkowanie historycznego opisu kompromisu dwóch kolumn wpisów. Nie zmieniamy siatki bez potrzeby ani decyzji właściciela. Usunięta martwa `.landing-wpisy` ma już D-160 i `PorzadkiWArkuszuKartyTest`; dawny wariant „Jak działa” zastąpiła D-208.

## Porządkowanie pozostałych zgłoszeń

- #38 zamknięte jako wykonane: datowany przegląd gotowych tekstów w COPY_STYLE, skaner `PrzewodnikTrzymaSieWlasnychZasadTest`, pytanie w kaflu publikacji i `PytanieDniaTest`. D-179 wyjaśnia błąd wcześniejszego odczytu; aktualny podział powitania i pytania wynika z późniejszego portu marki.
- #402 zamknięte jako zastąpione historyczne przekazanie, nie jako wykonanie całej listy. Czynności właściciela pozostają w #393, a kopie i magazyn mediów w #9, #193 i #120.
- #492 pozostaje otwarte: ten odczyt nie rozszerza odbioru wizualnego całego portalu i klientów pocztowych.

Dodatkowo uruchomiono lokalnie dwie rodziny regresji dotyczące #38: **17 testów / 1242 asercje PASS** (PostgreSQL na izolowanej bazie zadania). Bajty obu testów, COPY_STYLE i widoku strony głównej porównano z kanonicznym repo. Ten przebieg nie testuje sześciu pozycji z tabeli ani konfiguracji usług właściciela.
