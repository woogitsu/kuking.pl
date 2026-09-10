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

Do czekania na wynik CI jest [`scripts/stan-ci.sh`](../../scripts/stan-ci.sh)
(`scripts/stan-ci.sh <sha> [liczba prób]`). Wymaga `GITHUB_TOKEN` w środowisku
i **świadomie** żąda, żeby wszystkie siedem nazwanych jobów było `completed`
i `success`/`skipped`. Mój pierwszy, naiwny wariant tej pętli odpowiedział raz
„ZIELONE" na odpowiedź API, w której wszystko dopiero stało w kolejce — dlatego
skrypt sprawdza nazwy jobów z listy, a nie „czy cokolwiek jest zielone".

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

Scalone dziś do `main` (18 PR-ów): #265, #266, #267, #268, #271, #277, #279,
#280, #281, #282, #283, #284, #288, #289, #290, #291, #292, #293.

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
| #275 | licznik zapisów — **scalone w #292**; punkt „algorytm ciekawych tematów" zostaje zamknięty jako niezgodny z założeniami do jawnej decyzji właściciela |
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
  docenienie." Realizacja w #292 (scalone): autor widzi od 1, inny zalogowany od 3,
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

## 8b. Przebieg sesji — co właściciel prosił i co z tego wyszło

Zapisane w kolejności, bo część rzeczy jest **częściowo** zrobiona i bez tej
listy łatwo je zgubić. „✔" = zamknięte i scalone. „◐" = w toku, jest gałąź.
„☐" = tylko issue albo decyzja.

| Prośba właściciela (jego słowami, skrótowo) | Stan | Gdzie |
|---|---|---|
| Newsletter marketingowy — „odpuszczamy w tej formie, rób jak zrobiłeś" | ✔ | D-059; odpowiedzią jest tygodniowy digest |
| Zapisz w repo playbook kampanii i projekt klauzuli UGC | ✔ | `docs/marketing/KAMPANIA_GARNEK.md`, `docs/legal/LICENCJA_UGC_PROJEKT.md` |
| Mama nie mogła założyć konta — błąd nazwy użytkownika, „ma 63 lata, nie rozumiała" | ✔ | `NazwaUzytkownika` wybacza zapis, walidacja podaje wolną propozycję |
| „Zmienić styl i język — nie «list», tylko e-mail/wiadomość" | ✔ | wraz z powyższym |
| „Nie dostała maila" | ✔ | rozpoznane: nie miała konta, a ekran logowania linkiem kłamał; ekran poprawiony |
| „Te «mam co najmniej 16 lat» i regulamin są na czerwono, na to zwracają uwagę — zmienić kolor" | ✔ | zaznaczone przestaje być czerwone |
| „System musi być debilnoodporny" | ◐ | zasada, nie zadanie; realizowana przez wszystkie poprawki UX |
| „Musi pokazywać od razu zdjęcie, nie napis, bo starsza osoba się gubi" | ◐ | gałąź `claude/zdjecie-widac-od-razu`, **stan niepewny** |
| „Klikając «Dodaj zdjęcie» przenosi gdzie indziej niż «Dodaj» u góry — ujednolicić" | ◐ | gałąź `claude/jedna-droga-dodawania`, **stan niepewny** |
| „Na garnek.pl było prościej" + zrzuty | ✔ | `docs/product/PROSTOTA_JAK_GARNEK.md`, landing uproszczony |
| „Zrób prompt dla ChatGPT na research migracji" | ✔ | wykonane, wynik w `docs/research/MIGRACJA_Z_GARNKA.md` i PR #264 |
| „Dodaj funkcję: brak konta + logowanie linkiem → mail → zakładanie konta" | ◐ | **SZKIC bez testów**, gałąź `claude/link-prowadzi-do-rejestracji` |
| „Trzeba dać logowanie przez Gmail, ewentualnie Facebook" | ◐ | D-069, gałąź `claude/logowanie-kontem-google`; Facebook NIE zaczęty |
| Decyzja: rejestracja zostaje z dwoma polami, z podpowiedzią | ✔ | PR #265 |
| Decyzja: landing — „Jak działa" pierwsze I mniej kart dla gościa | ✔ | PR #268 |
| Decyzja: logowanie Google teraz, przed kampanią | ◐ | gałąź istnieje |
| „Prawa kolumna źle zrobiona, miniatury w pionie, kupy się nie trzyma" | ✔ | PR #293 |
| „Trzeba zrobić bazę podstawowych tagów na start" | ☐ | issue #273 |
| „Jestem mężczyzną, a mam «gotowałam» — sprawdzić wszędzie" | ✔ | PR #288, 33 wystąpienia |
| „Wpisy bez odpowiedzi w panelu to coś do przejrzenia?" | ✔ | odpowiedziane: to wszystkie opublikowane wpisy bez komentarza, kolejka opieki nad społecznością, nie moderacji. **Siedzi pod nagłówkiem PANEL MODERACJI, co kłamie o funkcji** — nie wystawione jako issue, warte wystawienia |
| „Po «Zapisuję» nie widać, że ktoś zapisał" + decyzja „to trzeba pokazać… nie chodzi o rywalizację a docenienie" | ✔ | PR #292 scalony — siedem nazwanych jobów zielonych, 2562 testy |
| „Za mało miejsca między «Podziel się» a «Komentarze»" | ✔ | PR #279 |
| „Zmarnowane miejsce nad «Napisz komentarz», brak miejsca przed przyciskiem, po co «(wymagane)»" | ✔ | PR #279 |
| „Przeczytałem powiadomienia, a dalej mam 3 nieprzeczytane" | ☐ | issue #276; **prawdziwa dziura**: powiadomienie bez „Zobacz" nie da się oznaczyć pojedynczo |
| „Dodaj przycisk «dodaj do ulubionych», to ułatwienie dla starszych" | ☐ | issue #278; **przycisk dodający do zakładek jest technicznie niemożliwy**, mocniejsza wersja to instalacja PWA |
| „Panel na Foldzie rozjeżdża się" + trzy zrzuty | ◐ | issue #294, gałąź `claude/panel-na-szerokim-telefonie` |
| Trzy warstwy audytu zewnętrznego (29 raportów) | ✔ | w repo, `docs/research/audyt-2026-09-10/` |
| „Napisz polecenie dla ChatGPT, które Ci pomoże" | ✔ | audyt kolejności blokad — treść w §12 tego dokumentu |
| „Przekaż całą pracę innemu modelowi" | ✔ | ten dokument |

**Trzy pozycje `◐` o niepewnym stanie** (`zdjecie-widac-od-razu`,
`jedna-droga-dodawania`, `logowanie-kontem-google`) powstały u agentów, których
zabił limit. Sprawdź je pierwszym `git log origin/claude/<gałąź>` i
`git diff origin/main...origin/claude/<gałąź>` — nie zakładaj, że są gotowe ani
że są puste.

---

## 8c. Decyzje D-070 … D-081 w jednym zdaniu każda

`docs/DECISIONS.md` ma ~5800 linii. To skrót, żebyś nie musiał go czytać
całego. **Pierwszy wolny numer: D-082.**

| Nr | Rzecz | Stan |
|---|---|---|
| D-070 | priorytet sprawy moderacyjnej jest kolumną w bazie i wymuszoną kolejnością, `reviewing` zaczyna działać | gałąź `claude/priorytet-w-kolejce-moderacji`, **niescalona** |
| D-071 | granica zaufania do `Host`: `X-Forwarded-Host` wypada z zaufanych nagłówków, `Host` przez `TrustHosts` | ✔ scalone |
| D-072 | dowód zgody na digest: append-only dziennik zgód, rollback bez `DEFAULT true` | PR #270, **bez kontroli ujemnych** |
| D-073 | orientacja PWA i jedna nazwa dla „Moje"/„Zeszyt" | gałąź `claude/pwa-orientacja-i-sitemap`, **niescalona** |
| D-074 | zawężenie deklarowanej wersji PHP do `^8.4` | gałąź `claude/dokumentacja-nie-klamie`, **niescalona** |
| D-075 | wymiana tokenu logowania linkiem pod blokadą wiersza konta; konflikt = ta sama neutralna odpowiedź | ✔ scalone |
| D-076 | dobowy budżet listów jako jedna atomowa rezerwacja pod `Cache::lock()`, bez nowej tabeli | ✔ scalone |
| D-077 | trwały klucz idempotencji digestu `(osoba, tydzień)`, rezerwacja PRZED wysłaniem; przy awarii wolimy pominięcie niż duplikat | ✔ scalone |
| D-078 | sygnał digestu mówi „zakolejkowano"; „jeden aktywny eksport" pilnuje baza, nie `exists()` | ✔ scalone |
| D-079 | jedna kolejność blokad na koncie + rewalidacja pod blokadą; **obowiązuje w całym repo** | ✔ scalone |
| D-080 | blokada i obserwowanie nie mogą współistnieć: kolejność blokad na PARZE osób + wyzwalacz w bazie | ✔ scalone |
| D-081 | licznik zapisów widoczny: autor od 1, obcy od 3, gość nigdzie; liczba, nie imiona | scalone (#292) |

---

## 8d. Jak pracować z agentami — jeśli będziesz

Właściciel autoryzował w tej sesji **do 2 agentów Opus i 3 Sonnet
jednocześnie** (wcześniej więcej). Pod koniec poprosił, żeby przestać ich
używać z powodu limitu. **Zapytaj go, zanim odpalisz pierwszego.**

Jeśli tak — brief startowy dla agenta leży w
[`docs/zlecenia/ZASADY_AGENTA.md`](ZASADY_AGENTA.md). Wklejaj go **każdemu**,
zamiast tłumaczyć te same rzeczy w rozmowie. Zawiera: obowiązkowy worktree
z `cp -al vendor`, zakaz `git add -A`, wymóg kontroli ujemnej, pięć pułapek
z pomiarami i fakty o środowisku.

Co się w tej sesji sprawdziło przy briefowaniu:

- **Podaj agentowi ZMIERZONY stan sprzed zmiany**, z numerami linii. Agenci,
  którym dałem „sprawdziłem i potwierdzam, plik:linia", zaczynali od naprawy.
  Agenci, którym dałem samą tezę audytu, tracili czas na jej weryfikację —
  albo, gorzej, przyjmowali ją na wiarę.
- **Napisz wprost, czego NIE wolno** i dlaczego. Trzy razy uratowało to
  cofnięcie cudzej naprawy (`flex-wrap` przy miniaturach, `UNIQUE(user_id)`
  przy tokenach, `min(11rem, 100%)` w panelu).
- **Każ nazwać wybór, którego nie da się mieć w dwie strony.** Przy digeście
  to było „nikt nie dostanie dwa razy" vs „nikt nie zostanie pominięty" —
  agent, któremu kazałem to nazwać, rozstrzygnął i uzasadnił; bez tego
  przemilczałby.
- **Każ sprawdzić, co jest na `main`**, nie wierzyć briefowi. Dwa razy
  napisałem agentowi nieprawdę o stanie `main` i jeden z nich to wychwycił.
- **Sprawdzaj ich pracę sam, kontrolą ujemną.** W tej sesji trzy razy okazało
  się, że test przechodzi także po zepsuciu tego, czego pilnuje. Skrypt do
  czekania na CI: [`scripts/stan-ci.sh`](../../scripts/stan-ci.sh) — wymaga
  wszystkich siedmiu jobów `completed`, bo naiwna wersja raz odpowiedziała
  „ZIELONE" na joby stojące w kolejce.

**Nie odpalaj agentów na to samo pole bez sprawdzenia otwartych PR-ów.** Mój
błąd: wysłałem agenta na poprawianie rodzaju w tekstach, nie zauważywszy, że
#254 już to robi. Wyszły dwa PR-y konfliktujące w dwudziestu miejscach.

---

## 8e. Inwentarz gałęzi `claude/*` — 18 na `origin`

Scalone gałęzie zostają na `origin`; nie kasowałem ich. Żywe, czyli takie,
w których jest coś niescalonego:

| Gałąź | Co | Stan |
|---|---|---|
| `claude/widac-ze-ktos-zapisal` | licznik zapisów (#292) | **scalone, gałąź do usunięcia** |
| `claude/audyt-60-plus-wdrozenie` | focus not obscured, belka sticky (#269) | **CI czerwone** |
| `claude/panel-na-szerokim-telefonie` | panel na Foldzie (#294) | w toku, stan niepewny |
| `claude/link-prowadzi-do-rejestracji` | zaproszenie do rejestracji | **SZKIC bez testów** |
| `claude/priorytet-w-kolejce-moderacji` | D-070, priorytet w kolejce | niescalona, nieweryfikowana |
| `claude/pwa-orientacja-i-sitemap` | D-073 | niescalona |
| `claude/dokumentacja-nie-klamie` | D-074 + poprawki nieprawdziwych opisów | niescalona |
| `claude/logowanie-kontem-google` | D-069 | stan niepewny |
| `claude/jedna-droga-dodawania` | jedna droga dodawania | stan niepewny |
| `claude/zdjecie-widac-od-razu` | zdjęcie od razu, nie napis | stan niepewny |
| `claude/teksty-sciezki-uzytkownika` | #254 | **do przeniesienia i zamknięcia** |
| `claude/kopia-bazy-offsite` | #213 | właściciel odłożył |
| `claude/przekazanie-pracy` | ten dokument | #296 |

**W kontenerze zostały worktree z niezacommitowanymi zmianami, których
świadomie NIE zapisałem:** `/tmp/wt-kolejne` i `/tmp/wt-heic` mają w zmianach
**usunięcie dokumentów, które są na `main`** — ślad po wcześniejszym
incydencie z `git reset`. Zapisanie tego skasowałoby cudzą pracę. Jeśli
kontener jeszcze żyje, obejrzyj je zanim znikną; jeśli nie — nic wartościowego
nie przepadło, bo commity z tych gałęzi są wypchnięte.

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

> **NIEAKTUALNE W PUNKTACH 2–6 — patrz sekcja 13.** Wieczorem 10.09 zamknięto
> #254 vs #288 (#301), MEDIA-01 (#300), MEDIA-03 (#298), #276 (#297), obie
> połowy #273 (było zrobione + #303) oraz MIG-01 (#299) i audyt blokad (#302).
> Z tej listy zostaje **#269** (punkt 2, ale problem jest inny niż opisany —
> patrz 13.3) i **punkt 7** (zaproszenie do rejestracji, wciąż szkic bez
> testów). Kolejność poniżej zostawiam bez zmian jako zapis stanu z popołudnia.

1. **Przeczytaj `docs/PULAPKI_TESTOW.md`.** Piętnaście minut, oszczędza dzień.
2. **Zamknij #269** (CI czerwone). Zielone `main` jest warunkiem sensownej
   pracy nad czymkolwiek innym, a to jest jedyny czerwony PR.
3. **Zamknij sprawę #254 vs #288** zgodnie z decyzją właściciela.
4. **MEDIA-01 (#285)** — bo skutkiem jest nieodwracalna utrata zdjęcia,
   a wzorzec naprawy już w repo jest.
5. **MEDIA-03 (#286)**, ale **tylko pomiar** w pierwszym kroku.
6. **#276** (powiadomienia) i **#273** (baza tagów) — oba widoczne dla
   właściciela i oba niewielkie.
7. **Dokończ szkic zaproszenia do rejestracji** (`claude/link-prowadzi-do-rejestracji`)
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

---

## 13. Wieczór 10.09 — co z tego dokumentu okazało się nieprawdą

Ta sekcja powstała kilka godzin po reszcie dokumentu, w sesji z jedenastoma
agentami. **Prostuje trzy twierdzenia z sekcji wcześniejszych.** Jeśli
czytasz ten plik po raz pierwszy, przeczytaj tę sekcję ZANIM zaczniesz
działać na podstawie sekcji 5 i 10 — inaczej naprawisz rzeczy, których nie ma.

### 13.1. „PR-y agentów nie dostają CI" — NIEPRAWDA

Sekcja 8b i doświadczenie z #292 sugerowały, że PR otwarty z konta agenta
nie wywołuje CI. Zmierzone tego wieczoru: **PR #299 dostał przebiegi pięć
sekund po założeniu, bez żadnego dodatkowego pushu.** Odczyty „zero
przebiegów" na #297 i #298 były po prostu ZA WCZESNE.

Joby chodzą na własnej puli runnerów `woogitsu-linux-01`–`10` i pojawiają
się z opóźnieniem od kilku sekund do kilku minut, a przy wysyceniu puli stoją
w kolejce dłużej. **„0 przebiegów" tuż po otwarciu PR-a znaczy „sprawdź za
chwilę", nie „CI zepsute".**

Co ZOSTAJE niewyjaśnione i czego nie udawaj, że rozumiesz: #269 i #270 stały
**pięć godzin** bez ani jednego przebiegu i ruszyły dopiero po pushu z merge
`main`. Nie wiem dlaczego. Jeśli zobaczysz to znowu — to jest realne
zjawisko, tylko nie takie, jak je opisano.

### 13.2. `.env` kontenera nadpisywał decyzję właściciela

`ListyZSystemuPoPolskuTest::test_nadawca_podpisuje_sie_imieniem_gospodarza`
oblewał **u każdego agenta niezależnie**, przez co wszyscy tracili czas na
rozstrzyganie, czy to ich regresja.

Przyczyna: `.env` w kontenerze miał **odkomentowane** `MAIL_FROM_NAME="Kuking"`,
podczas gdy `.env.example` trzyma tę linię zakomentowaną **celowo** — żeby
zadziałał liczony domyślnie podpis z `config/mail.php`
(`config('kuking.community.host_name').' z Kuking'`, decyzja właściciela
o nadawcy niosącym imię gospodarza). Hook `session-start.sh` tego nie robi
(kopiuje `.env.example` tylko gdy `.env` nie istnieje), więc ktoś wpisał to
ręcznie we wcześniejszej sesji.

Naprawione: linia zakomentowana, test przechodzi 7/7. `.env` jest w
`.gitignore`, więc **w nowym kontenerze problem może wrócić** — jeśli
zobaczysz dokładnie ten jeden czerwony test, sprawdź `.env` zanim cokolwiek
zmienisz w kodzie. Test działa poprawnie; złapał realny rozjazd środowiska.

### 13.3. #269: konflikt 2.4.11 vs 2.5.8 to już nie konflikt

Sekcja 5 mówi, że `.bottom-nav` `fixed` → `sticky` naprawia 2.4.11 i łamie
2.5.8 (trzy cele dotykowe tracą odstęp przy 320 px). **Połowa 2.5.8 jest
rozwiązana.** Pomiar z CI melduje: przepełnień w poziomie 0, rozjazdów belki
0, niespójnych szerokości 0 — na wszystkich pięciu szerokościach, w każdym
z trzech wariantów skalowania.

Zostało 2.4.11, i to **wyłącznie przy skali tekstu 140%**:

    szukaj / 320 px / tekst 140%:             a „Wszystko" pod .bottom-nav
    szukaj / 360 px / tekst 140%:             a „Do 30 minut" pod .bottom-nav
    wpis (przykładowy) / 360 px / tekst 140%: a „Napisz komentarz" pod .bottom-nav

Wariant „czcionka przeglądarki 200%" przechodzi na tych samych szerokościach.
To wskazuje, że rezerwa pod belką jest liczona w jednostce, która nie rośnie
razem ze skalą tekstu — sprawdź to przy kodzie, nie przyjmuj na wiarę.

Ustalone przy okazji: commit `1585120` **nie był rozluźnieniem progu**, tylko
poprawką poprawności pomiaru (`document.fonts.ready` w wyścigu z limitem 5 s —
`font-display: swap` sprawiał, że skrypt mierzył układ ułożony czcionką
systemową runnera). Ta poprawka nigdy wcześniej nie przeszła przez CI.

### 13.4. #273 — pierwsza połowa była zrobiona przed założeniem issue

`TagSeeder` ze słownikiem (~1250 nazw kanonicznych, ~2366 aliasów, 13
kategorii), `TagPromotionSeeder` i panel `/admin/tagi-promowane` są na `main`
**od 7 września**, czyli trzy dni przed założeniem #273 (D-021, D-026).
Sprawdzone przez `git show origin/main:`. Nie pisz drugiego seedera.

Brakowało wyłącznie **drugiej połowy**, którą samo issue nazywa drugą częścią
sprawy: publicznej strony `tags.index`. Zrobione w #303 (D-087). Do decyzji
właściciela zostaje, czy #273 zamknąć.

### 13.5. Kolejność blokad — ZMIERZONE, nie wydedukowane

Sekcja 12 nazywa to największą dziurą w pewności. Audyt wykonany
(`docs/research/2026-09-10-kolejnosc-blokad.md`, PR #302), **na dwóch
połączeniach do prawdziwego PostgreSQL**, bo pod `RefreshDatabase` nic z tego
nie jest widoczne.

Ustalenie, które zmienia obraz: **najważniejsze blokady w tym repozytorium
nie są napisane w PHP.** `INSERT` do `follows`/`blocks` bierze blokadę obu
wierszy `users` przez klucze obce (`FOR KEY SHARE`) — w kolejności **ról**,
podczas gdy `ZamekPary` szereguje po **identyfikatorach**. Dwa różne porządki
na tych samych wierszach, czyli zakleszczenie; w zmierzonym przypadku ofiarą
padło „Zablokuj", wprost przeciw zdaniu D-080, że blokada ma się udać zawsze.

Naprawione jako D-090: `BlockUser` przechodzi przez `ZamekPary`.

**Dwa zakleszczenia ZOSTAJĄ nienaprawione**, z opisanymi naprawami i szkicami
testów w raporcie:
- kasowanie konta — zmierzone `deadlock detected` na wierszach `follows`;
- `LoginLinkController::store()` — odwrócona kolejność, dziś bez cyklu, bo nic
  w tej transakcji nie dotyka `users`. **Pierwsza dopisana tam linijka cykl
  domknie.** To mina, nie usterka.

Cztery podejrzenia **obalone pomiarem** (zapisz to równie wyraźnie jak
znaleziska): collation w `ZamekPary`, wyzwalacz na `follows`, `Cache::lock`
trzymany w transakcji, oraz współistnienie blokady z obserwowaniem.

**Pułapka metodologiczna, która kosztowała trzy fałszywie czyste pomiary:**
`pg_connect` z tym samym ciągiem połączenia zwraca TO SAMO połączenie.
Bez `PGSQL_CONNECT_FORCE_NEW` cały audyt powiedziałby „wszystko w porządku".
Złapała to dopiero kontrola pozytywna.

### 13.6. Po stronie właściciela — jedna rzecz doszła

Do listy z sekcji 7: **phar Composera na `actions-runner-kuking-03` jest
uszkodzony** (`Class "Composer\Semver\Intervals" not found`,
`Class "Symfony\Component\String\UnicodeString" not found`). Job pada na
`composer install`, ZANIM uruchomi się jakikolwiek test — objaw wygląda jak
losowa czerwień CI na dowolnym PR-ze. Naprawa: przeinstalować Composera na
tej jednej maszynie. Dopóki to nie jest zrobione, jest to jedyny przypadek,
w którym wolno ponowić job.

### 13.7. Dług nazwany dziś, świadomie niespłacony

- **Trzy drogi przypięcia zdjęcia zostają z tą samą luką** co MEDIA-01:
  awatar, zdjęcie główne i skan przepisu, zdjęcie kroku. Znacznik `deleted`
  zwęża im okno, ale go nie zamyka. Opisane w D-083.
- **Żaden test w repozytorium nadal nie chodzi na dwóch połączeniach.**
  Raport blokad zawiera propozycję takiej grupy testów z oceną kosztu
  i rekomendacją, od którego testu zacząć (jest jeden, który byłby dziś
  czerwony). Grupa NIE została założona.
- **`/tagi` nie jest mierzone przez `scripts/dostepnosc.mjs`**, choć jest
  stroną publiczną, a wszystkie pozostałe są. Nie dopisane, bo tego wieczoru
  trzy gałęzie naraz trzymały ten plik.
- **Trzy rozstrzygnięcia D-072 nie miały testów** — dało się je cofnąć bez
  czerwonego CI. Dopisane w #270. Warto sprawdzić, czy inne decyzje nie mają
  tej samej właściwości: opis w komentarzu to nie jest gwarancja.

### 13.8. Zaproszenie do rejestracji — wyrocznia, zła numeracja, brakująca decyzja

Szkic z `claude/link-prowadzi-do-rejestracji` (sekcja 5) dokończony w #304.
Trzy rzeczy z tej pracy dotyczą całego repozytorium, nie tylko tej gałęzi.

**1. Wyrocznia „kto ma konto w Kuking" — naprawiona.** `registration_invites.email`
ma `->unique()`, a szkic kasował i zakładał wiersz **bez przechwycenia
konfliktu**. Dwie prośby naraz o ten sam adres kończyły się `500` — ale
**wyłącznie dla adresu BEZ konta**, bo adres z kontem trafia w ścieżkę linku
do logowania i sprowadza swój wyścig do `302` (D-075). Zwykły dwuklik
w „Wyślij" odpowiadał więc różnie zależnie od tego, czy konto istnieje.

To jest **dokładnie ta wyrocznia, którą D-075 zamknęło — odbita w lustrze na
sąsiedniej ścieżce**. Wniosek na przyszłość, ważniejszy od samej naprawy:
zamknięcie wyroczni na jednej drodze wejścia do konta nie zamyka jej na
pozostałych. **Przy każdej nowej ścieżce dotykającej adresu e-mail sprawdź
osobno, czy odpowiedź dla adresu z kontem i bez konta jest nieodróżnialna —
także w wyścigu, nie tylko w zwykłym przebiegu.**

Naprawione drugą połową konstrukcji z D-075 (blokady wiersza konta nie ma tu
na czym postawić — konta jeszcze nie ma). Przy okazji sufit przeszedł z pary
„sprawdź, potem zajmij" na `sprobujZarezerwowac()` — to była regresja wobec
D-076.

**2. D-067 NIGDY NIE ZOSTAŁO NAPISANE.** Kod szkicu powoływał się na nie
w **siedemnastu miejscach**, a wpisu w `docs/DECISIONS.md` nie ma. Odwołania
przepięte na D-085. **Dziennik decyzji ma dziury, na które kod się powołuje** —
warto sprawdzić, czy D-067 jest jedyną. Prosty test skanujący (`grep` po
`D-0\d\d` w `app/` i porównanie z nagłówkami w `DECISIONS.md`) zamknąłby tę
klasę błędu na stałe; nie został napisany.

**3. Sekcja 5 wiązała ten szkic z #258 — BŁĘDNIE.** #258 dotyczy **logowania
kontem Google**, nie zaproszenia do rejestracji. #258 zostaje otwarte, a #304
go nie zamyka.

**Świadomie niezrobione w #304**, żeby nie udawało zrobionego: brak testu na
`LogicException` przy zużyciu zaproszenia poza transakcją (`RefreshDatabase`
owija każdy test we własną transakcję, więc warunku nie da się wywołać — test
„sprawdzający" przechodziłby też po usunięciu zabezpieczenia; zabezpieczenie
zostaje, powód w komentarzu), oraz brak limitera na `GET /zaproszenie/{token}`
(token to 64 losowe znaki, więc nie ma czego blokować — ale to decyzja, nie
przeoczenie).
