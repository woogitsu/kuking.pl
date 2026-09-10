# Przekazanie pracy — stan na 10.09.2026, wieczór

Ten dokument jest **poleceniem dla modelu, który przejmuje pracę**. Napisany
tak, żeby dało się zacząć bez odtwarzania cudzych wniosków. Wszystko, co tu
stoi, było sprawdzone w kodzie albo zmierzone — a tam, gdzie nie było,
napisano „NIE WIEM".

---

## 0. ŚRODOWISKO — zrób to najpierw, oszczędzi Ci pół godziny

### Pierwsze pięć minut — wklej i sprawdź

```bash
cd /workspace/kuking.pl
pg_isready && php -v | head -1 && node -v            # czy środowisko żyje
[ -d vendor ] && [ -d node_modules ] && [ -f .env ] && echo "zależności są"
php artisan migrate --force                          # nic nie zrobi, jeśli aktualne
php artisan test --filter=OdstepPodNaglowkiemStrony   # 2 testy, ~5 s: czy baza działa
git log --oneline -5                                  # gdzie stoi main
grep -n "^## D-0" docs/DECISIONS.md | tail -3          # jaki numer decyzji jest wolny
```

Jeśli ostatni test przeszedł, **środowisko jest gotowe i nic nie instalujesz**.
Jeśli padł na bazie — patrz „Baza testowa" niżej.

### Co jest gotowe samo

Repozytorium ma hook `.claude/hooks/session-start.sh`, który **odpala się sam
na starcie sesji** i robi: uruchamia PostgreSQL, tworzy rolę `kuking`
(hasło `kuking`, SUPERUSER), tworzy bazy, `composer install`, `.env`
z `.env.example` + `php artisan key:generate`, `npm install`, `php artisan
migrate`. Jeśli w pierwszej wypowiedzi widzisz „── Gotowe. Przeczytaj
AGENTS.md ──", to wszystko powyżej już się stało i **nie instaluj tego
ponownie**.

### Zmierzone wersje w kontenerze (10.09.2026)

| Rzecz | Wersja | Uwaga |
|---|---|---|
| PHP CLI | **8.4.19** | `composer.json` deklaruje `^8.3` — deklaracja jest szersza od prawdy, target to 8.4 (otwarte, D-074 na niescalonej gałęzi) |
| PostgreSQL **serwer** | **16.13** | **UWAGA: CI używa `postgres:18-alpine`.** Lokalnie testujesz na 16, CI na 18 |
| node | 22.22.2 | |
| npm | 10.9.7 | |
| composer | 2.8.12 | |
| Chromium dla Playwrighta | `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` | jest; `scripts/dostepnosc.mjs` sam go znajduje, **nie uruchamiaj `playwright install`** |

**Rozbieżność PostgreSQL 16 vs 18 jest realnym ryzykiem.** Testy zielone
lokalnie mogą paść w CI i odwrotnie. Jeśli zobaczysz różnicę zachowania —
to jest pierwsze miejsce do sprawdzenia, nie flake.

### Baza testowa — nazwa zależy od katalogu

`tests/bootstrap.php` liczy nazwę: **`kuking_test`** w głównym katalogu,
**`kuking_test_<nazwa_worktree>`** w każdym `git worktree`. Dlatego:

- **nie uruchamiaj dwóch `php artisan test` na tej samej bazie** — objaw to
  `relation … does not exist` albo zakleszczenie na `ALTER TABLE`. To kolizja,
  nie usterka repozytorium. Powtórz przebieg pojedynczo;
- w nowym worktree baza może nie istnieć — utwórz ją:
  `PGPASSWORD=kuking createdb -h 127.0.0.1 -U kuking kuking_test_<sufiks>`.

### Praca w worktree — obowiązkowa, gdy pracuje kilka nurtów

```bash
cd /workspace/kuking.pl
git fetch origin main
git worktree add /tmp/wt-<nazwa> -b claude/<gałąź> origin/main
cp -al /workspace/kuking.pl/vendor /tmp/wt-<nazwa>/vendor   # HARDLINKI
cp /workspace/kuking.pl/.env /tmp/wt-<nazwa>/.env
ln -s /workspace/kuking.pl/node_modules /tmp/wt-<nazwa>/node_modules   # tylko gdy robisz `npm run build`
```

**`cp -al` dla `vendor`, nie symlink.** Symlink sprawia, że Composer
rozwiązuje `App\` do głównego katalogu i Twoje nowe klasy są niewidoczne. Dla
`node_modules` symlink jest w porządku.

### Czym sprawdzasz swoją pracę

```bash
vendor/bin/pint                      # styl, poprawia w miejscu
vendor/bin/pint --test               # tylko sprawdza
vendor/bin/phpstan analyse --no-progress
php artisan test                     # PEŁNY zestaw, ~2540 testów, 6–8 minut
npm run build                        # gdy ruszasz resources/
node scripts/dostepnosc.mjs          # axe + pomiar układu, długo
node scripts/dostepnosc.mjs --szybko # skrócony
```

**`php artisan test` uruchamiaj w pierwszym planie**, z timeoutem rzędu
2 400 000 ms. Kilku agentów w tej sesji zatrzymało się na „czekam na wynik
w tle" i tura się skończyła, więc nic nie czekało.

**`scripts/dostepnosc.mjs` NIE jest warunkiem ukończenia zadania.** Na
współdzielonym runnerze pada z powodu zbieżności (issue #262). Jeśli padnie,
sprawdź jednym uruchomieniem, czy pada tak samo na `origin/main`, i zapisz
wynik jako niepewny, a nie „zielony".

### Pułapka w konfiguracji testów, o której trzeba wiedzieć

`phpunit.xml` ustawia **`CACHE_STORE=array`**, a produkcja ma `database`.
Znaczy to, że `Cache::lock()` w testach jest blokadą **wewnątrzprocesową** —
nie dowodzi niczego o współbieżności. Produkcyjna blokada opiera się na tabeli
`cache_locks` i jest prawdziwa. Jest na to osobny test pilnujący, że
`.env.example` ma `CACHE_STORE=database`.

### Czego NIE instalować

Redisa, Sentry, PostHoga, dodatkowych baz, `playwright install`, nowych
pakietów Composera „bo się przydadzą". `AGENTS.md` §3 wymaga **nazwanego,
dziś istniejącego problemu**, który pakiet usuwa. Trzy warstwy audytu
niezależnie odradzają Redisa bez zmierzonego wąskiego gardła.

### Dostęp do GitHuba

**Nie ma `gh` CLI.** Wszystko przez narzędzia MCP GitHuba.
**Push agenta nie wywołuje CI** — zmierzone w tej sesji: gałęzie pchane przez
agenta miały **zero** przebiegów checków, a te same gałęzie po moim pushu
dostawały pełny zestaw. Obejście: `git merge origin/main` i push (i tak
potrzebne). PR bez checków nie znaczy „CI padło".

---

## 1. Z kim pracujesz

Właściciel: jedna osoba, pracuje sam, pisze po polsku i **oczekuje polskiego
we wszystkim** — kod, komentarze, commity, opisy PR, teksty w produkcie.

Jak z nim pracować, ze zmierzonego doświadczenia:

- **Podejmuje decyzje szybko i konkretnie.** Jeśli przedstawisz argumenty
  za i przeciw, wybierze. Nie zostawiaj go z otwartym pytaniem, jeśli możesz
  dać rekomendację.
- **Gdy powtórzy prośbę po Twoim zastrzeżeniu — to jest decyzja.** Tak było
  z licznikiem zapisów: wyłożyłem, że §12 zakazuje rankingów, odpowiedział
  „ale jednak to trzeba pokazać… nie chodzi o rywalizację a docenienie".
  Zrobiliśmy. Zapisz taką decyzję w `docs/DECISIONS.md`, żeby nikt jej za
  miesiąc nie „poprawił".
- **Testuje produkt na prawdziwych ludziach** — jego 63-letnia mama założyła
  konto i wygenerowała więcej realnych znalezisk niż dwa automatyczne audyty.
  Zgłoszenia od niej traktuj jako najwyższy priorytet: to jedyne prawdziwe
  dane o grupie docelowej, jakie ten projekt ma.
- **Prosi, żeby jego pomysły zapisywać jako issues**, bo sesje się zmieniają.
  Rób to od razu, z uzasadnieniem i granicami — nie „TODO".
- **Nie znosi udawania.** Jeśli czegoś nie sprawdziłeś, powiedz to. Jeśli się
  pomyliłeś, popraw się jednym zdaniem i idź dalej.

---

## 2. Zasady projektu — nie duplikuj ich, przeczytaj

**Przeczytaj w tej kolejności, przed pierwszą zmianą:**

1. `AGENTS.md` — jedyne źródło prawdy o zasadach. `CLAUDE.md` jest tylko
   wskaźnikiem na nie.
2. **`docs/PULAPKI_TESTOW.md`** — sześć pomyłek w testach, które w tym repo
   przeszły przez zielone CI. **Przeczytaj to zanim napiszesz pierwszy test.**
   Każda z nich wróci; dwie zadziałały jeszcze tego samego dnia, w którym
   dokument powstał.
3. `docs/OTWARCIE.md` — tabela 25 bramek startowych ze stanem. Zasada:
   **`NIE WIEMY` liczy się jako nieprzejście bramki, nie jako sukces.**
4. `docs/brand/COPY_STYLE.md` — zanim napiszesz jakikolwiek tekst widoczny
   dla człowieka.
5. `docs/research/audyt-2026-09-10/` — trzy warstwy audytu zewnętrznego.
   Czytaj **od raportów 28 i 29** (są nadrzędne), potem 14, potem szczegółowe.
   Raporty 09, 10 i 14 leżą w wersji sprzed korekty i zawierają jeden
   **usunięty** P0 — raport 29 to prostuje.
6. `docs/research/audyt-2026-09-10/SPRAWDZENIE.md` — co z audytu potwierdzone
   przy plikach, co zmierzone, co okazało się nieprawdą.

### Dwie zasady o kodzie, które obowiązują szerzej niż miejsce zapisu (D-079)

1. **Gwarancję daje constraint albo blokada, nie `exists()` w PHP.**
   `exists()` jest dobre na ładny komunikat i tam zostaje.
2. **Blokada bez rewalidacji pod nią nie pilnuje niczego** — serializuje,
   ale nie mówi żądaniu, że świat zmienił się, gdy ono czekało.

---

## 3. Co się dziś stało — jednym akapitem

Właściciel dostarczył **trzy warstwy zewnętrznego audytu** (29 raportów).
Audyt znalazł **osiem P1 w samym kodzie i wszystkie były jednym rodzajem
błędu**: inwariant *sprawdzany, a potem wykonywany*, zamiast wykonany
atomowo. Sześć z ośmiu zostało zamkniętych i scalonych. Równolegle właściciel
przysyłał zgłoszenia z realnego użycia (telefon, Fold rozłożony, formularz
komentarza) — te też są zamknięte albo w toku.

**Jedna teza P0 audytu była nieprawdziwa** (pętla zgłaszającego istnieje od
issue #10). Sprawdzenie przy pliku ją wykryło, audytor przyjął korektę
i usunął ten P0 z czterech swoich raportów. **Wniosek do zapamiętania: audyt
zewnętrzny i PR agenta to HIPOTEZY o kodzie, nie prawda. Sprawdzaj przy
pliku.**

---

## 4. Stan repozytorium — co scalone

Scalone dziś do `main` (17 PR-ów): #265, #266, #267, #268, #271, #277, #279,
#280, #281, #282, #283, #284, #288, #289, #290, #291, #293.

Najważniejsze z nich, żebyś nie odtwarzał wniosków:

| Rzecz | Dlaczego ważne |
|---|---|
| **#277** — potwierdzenie zmiany adresu nie wyprzedza nowego hasła | **Jedyne znalezisko prowadzące do utraty konta bez błędu właściciela.** Ktoś obcy zamawiał zmianę adresu, właściciel odzyskiwał konto nowym hasłem — i link napastnika mimo tego przestawiał adres. Zmierzone kontrolą ujemną |
| **#284** — link do logowania nie zdradza istnienia konta | Zmierzone: **500 dla adresu z kontem, 302 dla adresu bez konta.** Formularz zaprojektowany jako nieodróżnialny odpowiadał na pytanie „czy tu jest konto" |
| **#290** — blokada wygrywa z obserwowaniem | Po wyścigu zablokowana osoba dalej widziała wpisy w feedzie. Dodany wyzwalacz `BEFORE INSERT ON follows` |
| **#280, #281, #283** — poczta i digest | Dobowy sufit był parą „sprawdź, potem zajmij"; digest mógł wysłać dwa razy po awarii; sygnał „wysłano" znaczył „zakolejkowano" |
| **#282** — test dymny po wdrożeniu | 249 przebiegów pominiętych, bo Railway przysyła `deployment.environment = 'ideal-exploration / production'`, a `case` dopasowywał gołe `production` |
| **#288** — teksty bez rodzaju | 33 wystąpienia, nie 5 jak w issue. W polityce prywatności były **obie płcie w jednym dokumencie** |
| **#291** — `docs/PULAPKI_TESTOW.md` | Trwalsze od samych poprawek |

### Numery decyzji

Zajęte dziś: **D-070 … D-081**. **Pierwszy wolny: D-082.**

**`docs/DECISIONS.md` ma dziś wpisy w nieuporządkowanej kolejności
numerycznej** (D-077, D-078, D-071, D-079, D-075, D-076, D-080, D-081) — skutek
scalania wielu gałęzi dopisujących na końcu pliku. Warto posprzątać, ale
**dopiero gdy nie ma otwartych gałęzi dotykających tego pliku**, inaczej
gwarantujesz sobie konflikty.

---

## 5. Co otwarte — z tym, co trzeba zrobić

### Wymaga tylko dokończenia weryfikacji

| PR | Rzecz | Co zostało |
|---|---|---|
| **#292** | licznik zapisów (D-081) | 2535/2535 lokalnie. Czeka na CI. **Sprawdź sam kontrolę dodatnią progu** — reszta ma 11 kontroli ujemnych |
| **#269** | audyt 60+: `.bottom-nav` `fixed` → `sticky` | **CI CZERWONE.** Zmiana naprawia WCAG 2.4.11 i **łamie 2.5.8**: trzy cele dotykowe straciły odstęp (`profil (własny)`, `dodaj przepis`, `twoje tagi` przy 320 px). Nie podnoś progu w skrypcie. Agent nad tym pracował, stan może być częściowy |
| gałąź `claude/panel-na-szerokim-telefonie` | panel na Foldzie (#294) | Agent zapisywał pracę; może być niedokończona |
| **#270** | dowód zgody na digest (D-072) | Agenta zabił limit **przed kontrolami ujemnymi.** Nie scalać bez nich |

### Wymaga decyzji albo dłuższej pracy

| PR | Rzecz | Uwaga |
|---|---|---|
| **#254** | teksty na ścieżkach użytkownika | **Właściciel zdecydował: #288 wygrywa.** Przejrzyj #254 pod kątem rzeczy, których #288 nie ma, przenieś osobnym PR-em, zamknij #254 jako zastąpiony |
| **#253** | poczta nie gubi listów (D-062) | Wymaga uzgodnienia `HealthController` ze scalonym #255 |
| **#256** | identyfikatory pól w kolejkach panelu (#243) | Do sprawdzenia; audyt moderacji wymienia to jako MOD-03 |
| **#264** | research migracji z Garnka | Wstrzymane |
| **#213** | kopia bazy offsite (#193) | Właściciel odłożył. **To jest bramka startowa** — patrz §7 |
| gałąź `claude/link-prowadzi-do-rejestracji` | zaproszenie do rejestracji (#258, D-067) | **SZKIC. Trzynaście plików, ZERO testów.** Uratowany z zabitej sesji. To ścieżka wejścia do konta, czyli powierzchnia bezpieczeństwa — patrz opis commita, jest tam lista tego, co musi mieć test |

### Dwa niezamknięte P1 z audytu, bez PR-a

- **MEDIA-01 (#285)** — sprzątacz osieroconych zdjęć może skasować zdjęcie
  w trakcie przypinania go do wpisu. **Skutkiem jest nieodwracalna utrata
  zdjęcia.** Wzorzec naprawy jest już w repo: `EraseAccountData` kasuje
  storage **po** commicie i zachowuje uchwyt do ponowienia.
- **MEDIA-03 (#286)** — autoryzacja jednego zdjęcia to **co najmniej pięć
  zapytań**, a jedna strona feedu generuje ponad sto żądań obrazków.
  **Najpierw POMIAR liczby zapytań, potem optymalizacja** — repo ma na to
  wzorzec (`DB::flushQueryLog()` i porównanie „mało vs dużo"). To **nie** jest
  argument za Redisem.
- **MIG-01 (#287)** — rollback migracji cicho zamienia wybór „usuń wszystko"
  na „usuń minimum". Ta sama choroba, którą audyt znalazł już raz (DB2).

---

## 6. Issues od właściciela — jego własne zgłoszenia

Wszystkie mają opis, uzasadnienie i granice. Nie odtwarzaj diagnozy.

| # | Rzecz |
|---|---|
| #272 | prawa szyna — **zamknięte przez #293** |
| #273 | baza podstawowych tagów na start |
| #274 | teksty przypisujące płeć — **zamknięte przez #288** |
| #275 | licznik zapisów — **w #292**; punkt „algorytm ciekawych tematów" zostaje zamknięty jako niezgodny z założeniami do jawnej decyzji właściciela |
| #276 | powiadomienie bez „Zobacz" nie da się oznaczyć jako przeczytane |
| #278 | „Dodaj Kuking do ulubionych" — **przycisk dodający do zakładek jest technicznie niemożliwy**; mocniejsza wersja to instalacja PWA, ale najpierw trzeba naprawić wymuszony pion w manifeście |
| #294 | panel moderacji na szerokim telefonie + **ani jedna strona `/admin/` nie jest mierzona przez skrypt dostępności** |

---

## 7. Po stronie właściciela — czego kodem nie zamkniesz

Pełna tabela w `docs/OTWARCIE.md`. Trzy najpilniejsze, w tej kolejności:

1. **Wyłączyć śledzenie otwarć w panelu EmailLabs** (#204) i sprawdzić surowy
   HTML realnie doręczonej wiadomości. Polityka prywatności mówi, że tego nie
   ma, a jest — to stan **sprzeczny z opublikowaną informacją**.
2. **`railway ssh -- php artisan kuking:bramka-r2 --zapis`** (#120), potem
   `kuking:przenies-zdjecia --dry-run`. Nowe zdjęcia już idą przez podpisany
   R2 (zmierzone z zewnątrz), **stare nadal serwuje PHP z wolumenu
   kontenera** — dopóki tam leżą, utrata kontenera to utrata oryginałów.
3. **Kopia bazy poza Railwayem** (#193), potem ćwiczenie odtworzenia (#9).
   **Do wykonania #193 każda utrata bazy jest bezpowrotna.**

**Granica dla marketingu, wynikająca z punktu 3:** „możesz pobrać swoje dane"
jest prawdą i wolno tak mówić. „U nas nic nie zniknie" / „Twoje zdjęcia są
bezpieczne na zawsze" — **nie**, dopóki punkty 2 i 3 nie są zamknięte.
Mówimy to ludziom, którzy raz już stracili dorobek razem z zamkniętym
serwisem.

---

## 8. Decyzje właściciela podjęte dziś — nie podważaj ich

- **Rejestracja zostaje z dwoma polami** (nazwa widoczna + nazwa w adresie),
  ale nazwa jest podpowiadana z imienia. Powód: adres profilu ma być
  świadomym wyborem.
- **Licznik zapisów ma być widoczny.** „Nie chodzi o rywalizację a
  docenienie." Realizacja w #292: autor widzi od 1, inny zalogowany od 3,
  gość nigdzie; liczba, nie imiona (bo zeszyt jest domyślnie **prywatny** —
  sprawdzone w migracji i w `CollectionPolicy`).
- **Logowanie kontem Google — teraz, przed kampanią** (D-069, gałąź istnieje).
- **Landing: „Jak działa" przed tablicą I mniej kart dla gościa** (scalone).
- **Newsletter marketingowy odpada** (D-059); odpowiedzią jest tygodniowy
  digest.
- **Zmiany gramatyczne w dokumentach prawnych scalone za jego zgodą**, po tym
  jak sprawdziłem każdą linijkę.
- Rekrutacja testerów: „sam ogarnę".

---

## 9. Czego NIE robić

Z `AGENTS.md`, z trzech warstw audytu i z tej sesji:

1. **Nie dzielić monolitu na mikroserwisy.** Ryzykiem jest produkt
   i operacje, nie skala architektury.
2. **Nie dodawać Redisa** bez zmierzonego wąskiego gardła.
3. **Nie budować algorytmu feedu.** Feed obserwowanych jest chronologiczny
   i ma taki zostać. Odkrywanie idzie przez **jawne tematy** (#273), nie przez
   ważenie popularności.
4. **Nie dodawać publicznych rankingów ani grywalizacji** (§12). Licznik
   zapisów z #292 jest wyjątkiem podjętym jawnie i ma próg właśnie dlatego.
5. **Nie obniżać limitu 50 Mpx** do 32–36 bez benchmarku współbieżności
   (D-064 prostuje wcześniejszy fałszywy alarm o 384 MB).
6. **Nie podnosić progów tolerancji w `scripts/dostepnosc.mjs`**, żeby coś
   się zmieściło. To zamiana usterki na kłamstwo w pomiarze.
7. **Nie osłabiać 2FA moderatora**, żeby testowi było łatwiej.
8. **Nie skipować, nie wyłączać i nie kwarantannować testów**, żeby CI było
   zielone.
9. **Nie kasować relacji społecznych ani danych migracją** bez wglądu w to,
   co zniknie (§6, zakaz operacji destrukcyjnych).
10. **Nie wracać do pikseli śledzących** w mailach, żeby mieć ładniejszą
    metrykę. To otwarta sprawa #204 i produkt świadomie tego nie chce.
11. **Nie uruchamiać kampanii Garnek przed zasianiem realnej społeczności**
    (20–30 aktywnych osób, 100–150 autentycznych wpisów). Bez fałszywych kont.

---

## 10. Od czego zacząć — kolejność, którą sam bym wybrał

1. **Przeczytaj `docs/PULAPKI_TESTOW.md`.** Piętnaście minut, oszczędza dzień.
2. **Zamknij #269** (CI czerwone). Zielone `main` jest warunkiem sensownej
   pracy nad czymkolwiek innym, a to jest jedyny czerwony PR.
3. **Sprawdź i scal #292** (licznik zapisów) — właściciel na to czeka, bo to
   jego decyzja.
4. **Zamknij sprawę #254 vs #288** zgodnie z decyzją właściciela.
5. **MEDIA-01 (#285)** — bo skutkiem jest nieodwracalna utrata zdjęcia,
   a wzorzec naprawy już w repo jest.
6. **MEDIA-03 (#286)**, ale **tylko pomiar** w pierwszym kroku.
7. **#276** (powiadomienia) i **#273** (baza tagów) — oba widoczne dla
   właściciela i oba niewielkie.
8. **Dokończ szkic zaproszenia do rejestracji** (`claude/link-prowadzi-do-rejestracji`)
   **z testami**, bo to funkcja, o którą właściciel prosił wprost, i dotyka
   wejścia do konta.

**Nie bierz się za nowe funkcje.** Audyt mówi to wprost i zgadzam się:
produkt ma dość funkcji, żeby sprawdzić hipotezę rynku. Następny etap jest
operacyjny i społecznościowy.

---

## 11. Rzeczy, które kosztowały mnie czas — żeby nie kosztowały Ciebie

- **Nie wierz briefowi ani dokumentacji w to, co jest na `main`.** Dwa razy
  napisałem agentowi, że klasa jest scalona, a nie była. Sprawdzaj
  `git show origin/main:<plik>`.
- **`git checkout --` nie przywraca pliku nieśledzonego.** Jeden agent zrobił
  tak trzy sabotaże, które nałożyły się na siebie, i musiał powtórzyć całą
  rundę kontroli.
- **Nie `git add -A`.** W tej sesji jeden agent wciągnął tak plik drugiego do
  swojego commita. Dodawaj pliki z nazwy.
- **`git merge origin/main` przed każdym pushem.** `main` przesuwał się kilka
  razy na godzinę. Przy dwóch gałęziach w tej samej linii konflikt trzeba
  **przeczytać** — był taki, gdzie poprawne rozwiązanie brało jedno z jednej
  strony i drugie z drugiej, a każda strona osobno wyglądała kompletnie.
- **Katalog scratchpada jest współdzielony między agentami.** Jeden nadpisał
  plik z opisem PR-a treścią cudzego zadania. Używaj nazw z własną gałęzią.
- **Sabotaż może być za słaby.** `catch (\RuntimeException)` nie jest
  sabotażem dla konfliktu unikalności, bo `PDOException` po nim dziedziczy.
  Kontrola ujemna, która nie oblewa, ma dwie możliwe przyczyny.

---

## 12. Czego nie wiem i co bym zlecił z zewnątrz

**Dziś wjechało pięć nowych, niezależnie napisanych mechanizmów blokowania:**
`ZamekKonta` (D-079), blokada w `WyslijLinkDoLogowania` (D-075), `ZamekPary`
(D-080), `Cache::lock()` w budżecie poczty (D-076) oraz rezerwacja
`weekly_digest_sends` + wyzwalacz na `follows` (D-077, D-080). Pisało je pięć
osób, z których żadna nie widziała pozostałych.

**Żaden test w tym repozytorium nie chodzi na dwóch połączeniach do
PostgreSQL** (`RefreshDatabase` trzyma dane w niezatwierdzonej transakcji),
więc **zakleszczenia i inwersje kolejności blokad są niewidoczne**. To jest
największa znana mi dziura w pewności co do dzisiejszej pracy.

Gotowe polecenie dla modelu z dostępem do repozytorium (analiza, nie łatki)
leży obok: patrz historia rozmowy właściciela z 10.09 albo napisz je od nowa
na podstawie tej listy pięciu mechanizmów. Najważniejsze pytania: czy
kolejność blokad jest **globalnie spójna**; czy `ZamekPary` porządkuje UUID
deterministycznie w PHP i w SQL jednakowo; czy ścieżka kasowania konta bierze
te same zasoby w tej samej kolejności; czy wyzwalacz na `follows` nie tworzy
cyklu z trzymanymi blokadami wierszy `users`.
