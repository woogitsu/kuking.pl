# Odbiór Alfa 0.29 — komunikaty po publikacji

## Kod i kontrole

[PR #546](https://github.com/woogitsu/kuking.pl/pull/546), head **8e4da2f2ff51178df57d7dd118978e441b8c5130**, scalony normalnie jako **443da38c763f5ee2c26e8610b516c95fa4e42b94**. Poprawki #545 i #547 dotyczą prawdziwości komunikatów po pierwszym wpisie i Ugotowałem; bez migracji i zmian mechanizmów zapisu, prywatności czy powiadomień.

[CI PR34889241332](https://github.com/woogitsu/kuking.pl/actions/runs/34889241332) i [CI main34891635127](https://github.com/woogitsu/kuking.pl/actions/runs/34891635127) zakończyły się sukcesem wszystkich10zadań, w tym testu równoczesnych operacji. Osobno odczytane logi PHP z zadań104127514041 i104135570669 potwierdzają **3807 testów /76412 asercji** w każdym przebiegu. Pojedynczy nieudany test przed pełnym zestawem był oczekiwaną kontrolą ujemną; po przywróceniu źródła przeszedł.

Zwykły końcowy push przeszedł obowiązkowy hook w244,09s. Lokalnie #545:31/104 i dwie fizyczne kontrole ujemne; #547:31/148 i sześć fizycznych kontroli ujemnych. MD5 i mtime przywrócone. Niezależny review kodu i raportów bez blokera. Zakresy: [pierwszy wpis](PIERWSZY_WPIS_545.md), [Ugotowałem](KOMUNIKAT_UGOTOWALEM_547.md).

## Faktyczne wdrożenie

Railway deployment **6445551937 success —14.09.2026,20:45:52UTC**, dla dokładnego SHA443da38, production. Panel Railway wskazywał początkowo Waiting for CI, następnie Building. Wewnętrzny identyfikator Railway:75e001bb-5106-4ae0-aee5-819ff0d4f675. [Końcowy Deploy34894880938](https://github.com/woogitsu/kuking.pl/actions/runs/34894880938) success. Wstępny Deploy34891646785 skipped nie był dowodem wdrożenia.

Niezależny odczyt HTTP bez cache: **200, Alfa0.29, wydanie14września2026,22:44, SHA443da38**.

| Zasób | Wynik |
|---|---|
| CSS app-BhNKF9Y-.css | HTTP200; SHA256 cd85131fae3d9dcda461b9e9cc6133387dd5da652a69045e061c730536c3b59a |
| JS app-DXNAnudp.js | HTTP200; SHA256 e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75 |
| Inter latin-ext | HTTP200, font/woff2,85068bajtów |
| Inter latin | HTTP200, font/woff2,48256bajtów |

Assety są takie jak w0.28: pakiet nie zmieniał CSS/JS. Niezmieniony adres pliku przy nowym SHA strony nie oznacza starego wdrożenia.

## Zalogowana produkcja

Przed wdrożeniem istniejący pierwszy prywatny wpis autora pokazywał starą obietnicę „teraz idzie najszybciej”. Po odświeżeniu dokładnie tego samego ekranu odczytano i obejrzano: **„To Twój pierwszy wpis. Kolejne zdjęcie dodasz przez ten sam formularz.”** Przycisk pozostaje widoczny; metryczka potwierdza0.29/443da38. Obejrzano normalny szeroki widok Chrome i fragment pod wpisem po przewinięciu. Nie utworzono ani nie zmieniono treści produkcyjnych.

Konto nie ma własnego przepisu, więc własnej gałęzi formularza Ugotowałem nie zaliczamy jako obejrzanej na produkcji. Jej dokładne źródło ma dowód lokalny, CI i potwierdzone wdrożenie całego commita. Odbiór tej strony nie rozszerza się na cały portal ani wszystkie urządzenia.

## Dodatkowe odbiory i pozostałe braki

[Publikowanie](ODBIOR_PUBLIKOWANIA_492.md), [pełna edycja i Wyszło](ODBIOR_EDYCJI_WYSZLO_492.md), [gotowanie](ODBIOR_GOTOWANIA_UZUPELNIENIE_492.md), [zdjęcia](ODBIOR_ZDJEC_492.md) oraz [odzyskiwanie419/429](ODBIOR_ODZYSKIWANIA_492.md) mają rozdzielone dowody i ograniczenia. Rzeczywisty zoom nie jest powiększeniem fontu. Przy419 jawnie zastosowano kontrolowany transport starszego klienta z niezmienioną odpowiedzią serwera;429 ponowiono po naturalnym TTL. Nie sprawdzono wszystkich kombinacji, fizycznego telefonu, dźwięku/WakeLock ani rzeczywistych klientów pocztowych.

Pozostałe potwierdzone błędy tekstowe: #548 (odmiana czasu minutnika), #549 (zgadywanie przyczyny419). Kolejka jest w [punkcie kontynuacji](KONTYNUACJA_AUTONOMICZNA.md) i [macierzy](MACIERZ_KOMPLETNOSCI_517.md). Scenariusze #15 są przygotowaniem badań, nie ich wynikami. Pełny port marki nadal **CZĘŚCIOWO**.