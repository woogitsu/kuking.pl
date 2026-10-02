# Środowisko pracy: CI, PostgreSQL, instalacja zależności, przeglądarka

Przeniesione z `AGENTS.md` §10 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

**GitHub Actions są włączone** (D-010): repozytorium żyje w organizacji
`woogitsu`, która ma własną pulę 2 000 minut miesięcznie. CI chodzi na
`push` do `main` i `staging` oraz na każdym Pull Requeście do tych gałęzi.

Kontrola lokalna **zostaje mimo to** — jest szybsza i łapie błąd, zanim ten
zje minuty z puli. Zainstaluj hook raz:

```bash
./scripts/install-hooks.sh
```

Szczegóły i plan awaryjny: `docs/infra/CI_BEZ_ACTIONS.md`.

Pojedyncze kroki, gdy chcesz coś sprawdzić osobno:

```bash
vendor/bin/pint          # formatowanie
php artisan test         # testy (wymagają PostgreSQL, patrz niżej)
npm run build            # assety się budują
```

I to na **PostgreSQL 18 lub nowszym** — tak samo lokalnie, w CI i na produkcji.
Jeden próg dla wszystkich trzech, bo próg niższy od produkcyjnego przepuszcza
lokalnie migracje, które w CI padają. Produkcja ma 18, CI stawia
`postgres:18-alpine`, więc `php artisan test` na starszym majorze mierzy silnik,
którego nigdzie nie używamy. Pilnuje tego `TestyChodzaNaPostgresieTest` —
i pilnuje też tego, żeby ten akapit i próg w strażniku mówiły tę samą liczbę.

To jest wymaganie, a nie opis każdej maszyny. Kontener sesji agentów w chmurze
ma na 127.0.0.1:5432 **PostgreSQL 16.13** (obraz ma tylko pakiet 16; `SELECT
version()` 30.09.2026, audyt wydajności F7, #2292). Tam
`TestyChodzaNaPostgresieTest` oblewa środowiskowo, a rozstrzyga CI na 18.
Progu nie obniżaj. W raporcie z pomiaru planów i JIT podaj wersję z `SELECT
version()`, bo plany różnią się między majorami.

Przed utworzeniem bazy ustal jej właściciela, host, port i nazwę.
Użyj izolowanej bazy tego zadania i jawnych parametrów połączenia.
Nie polegaj na domyślnym porcie ani nazwie w środowisku współdzielonym.

### Gdy instalacja zależności nie działa

Najpierw uruchom zwykłe `composer install` i odczytaj rzeczywisty błąd.
Historyczne kontenery agentów miały proxy odrzucające pobrania z GitHuba;
nie jest to stała właściwość każdego środowiska. Sprawdź bieżący dostęp,
wersję narzędzia i konfigurację, zanim uznasz instalację za niewykonalną.

Jeśli potwierdzisz blokadę pobrań `dist`, a dostęp przez git działa,
możesz spróbować `composer install --prefer-source`. Nie zmieniaj globalnej
konfiguracji Composera w środowisku współdzielonym. Ewentualną konfigurację
obejścia ogranicz do izolowanej kopii lub osobnego katalogu COMPOSER_HOME.

Nie usuwaj zależności z manifestu ani locka, żeby uzyskać pozornie pełną
instalację. Zachowaj dokładne wersje z composer.lock. Historycznie lokalną
instalację PHPStan umożliwiło przygotowanie archiwum wskazanego commita
w cache Composera; to opis zakończonej sesji, nie nakaz stosowania obejścia
przy każdym uruchomieniu.

Przed obejściem wymagającym modyfikacji plików zrób kopię ich aktualnych
bajtów i czasu modyfikacji poza repo. Preferuj izolowaną kopię wykonawczą.
Przywróć dokładnie zapisany stan i sprawdź MD5 oraz mtime; odtworzenie pliku
z commita nie chroni cudzych niezapisanych zmian. Nie ogłaszaj narzędzia
niedostępnym ani testu zaliczonym na podstawie historycznej notatki.
CI pozostaje rozstrzygające, a brak wykonania kontroli musi być jawny.

W nowej izolowanej instancji lokalnej sprawdź, czy istnieje klucz aplikacji.
Generuj go tylko, gdy go brakuje; bez niego wystąpi błąd
„No application encryption key has been specified”. Nie zmieniaj klucza
istniejącej aplikacji produkcyjnej podczas przygotowania testów:

```bash
php artisan key:generate
```

### Przeglądarka w środowisku agenta

Najpierw sprawdź aktualny dostęp do testowanej strony. W jednej z dawnych
sesji Chromium przerywał TLS za proxy; w późniejszych sesjach produkcja
była dostępna zarówno przez Chromium, jak i zalogowany Chrome. Historyczny
błąd nie jest dowodem dzisiejszej blokady ani usterki aplikacji.

Do fixture, formularzy i stanów wymagających danych testowych używaj
izolowanej instancji lokalnej. Przed migracją lub seedowaniem odczytaj
faktyczny host, port i nazwę bazy oraz upewnij się, że należą do tego testu.
Nie zakładaj dostępności domyślnego portu PostgreSQL: może obsługiwać inne
projekty. Współdzielone środowisko wymaga jawnie wybranej bazy i portu.
Nie wykonuj testów niszczących fixture równolegle z oglądem używającym
tej samej bazy lub mediów. Nie obchodź błędów TLS przez wyłączanie ochrony.

Raportuj oddzielnie odczyt kodu, pomiary lokalne i ogląd produkcji.
Brak dostępu do zalogowanej produkcji jest ograniczeniem, nie wynikiem
pozytywnym; odpowiednie stany można sprawdzić lokalnie, bez zmiany danych
użytkowników produkcyjnych.
