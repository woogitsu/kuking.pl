## D-104 · Tabela stacku w `AGENTS.md` §3 opisuje STAN, ma trzecią kolumnę „Gdzie to sprawdzić" i jest sprawdzana testem

**Data:** 10 września 2026 · Status: **obowiązuje**

> **Ta decyzja NIE dotyczy Sentry.** Sprostowanie nieprawdziwego wiersza
> („Monitoring | Sentry" przy braku Sentry) jest poprawką faktu i numeru nie
> wymaga — decyzja o samym Sentry stoi w **D-041** i nie zmienia się tutaj ani
> o słowo. Numer bierze **zmiana kształtu tabeli**: od dziś każdy wiersz musi
> powiedzieć, gdzie tej rzeczy szukać, a test to sprawdza. To jest obowiązek
> nałożony na każdego, kto tę tabelę zmienia, więc ma prawo do wpisu
> w dzienniku — inaczej pierwsza osoba, której trzecia kolumna wyda się
> nadmiarem, skasuje ją jako ozdobę.

### Co było złamane

Tabela w `AGENTS.md` §3 nosi nagłówek „Stack (i czego NIE wolno dokładać)"
i jest czytana jako opis tego, **co jest**. Wymieniała jednak
`| Monitoring | Sentry |`, przy czym Sentry'ego w projekcie nie ma i nigdy nie
było. Zmierzone 10 września 2026 na `main`: `grep -c -i sentry composer.json`
→ **0**, brak `config/sentry.php`, a `SENTRY_LARAVEL_DSN` — przewleczone przez
`.env.example`, `.railway/railway.ts` i **oba** workflow CI — **nie jest czytane
przez ani jedną linijkę PHP**. Ta sama nieprawda stała w drugiej kopii tabeli,
w `README.md` §Stack.

**Kosztowało to cudzą pracę, i to nie hipotetycznie.** Autor PR-a #253 zbudował
całe zdanie „właściciel ma szansę dowiedzieć się o awarii bez zaglądania" na
założeniu, że `Log::error()` dojdzie do Sentry. Nie dochodzi: Sentry'ego nie
ma, a kanał `blad_webhook` nie jest częścią domyślnego stosu
(`LOG_STACK=single`) i wisi na `$exceptions->report()` w `bootstrap/app.php` —
czyli na wyjątkach, nie na dowolnym `Log::error()`. Ktoś inny musiał to
sprostować w trzech plikach i we wpisie D-062.

To jest **ta sama klasa błędu, co martwy numer decyzji**
(`tests/Feature/NumeryDecyzjiMajaWpisyTest.php`), tylko pod inną nazwą:
**deklaracja wygląda na rozstrzygnięcie, więc zatrzymuje szukanie — a jest
nieprawdziwa.** Trzeci raz tego samego jednego dnia.

### Co wybrano

**1. Wiersz opisuje stan dzisiejszy, zamiar idzie do roadmapy i dziennika.**
Wiersz `Monitoring` brzmi teraz „dziennik serwera + kanał `blad_webhook` na
Slack/Discord (D-041)", bo to jest to, co naprawdę działa. Sentry jako wybór
docelowy nie znika — stoi w `docs/ROADMAP.md` §0 i w D-041, razem z wypisanym
warunkiem wejścia. Rozważano wariant drugi (wiersz zostaje z dopiskiem
„zamiar, nie stan") i został **odrzucony**: tabela z nagłówkiem „Stack" jest
czytana skrótem, a dopisek przy jednym wierszu ginie dokładnie tak samo, jak
zginął fakt, że Sentry'ego nie ma.

**2. Trzecia kolumna „Gdzie to sprawdzić", z zamkniętą listą czterech
kształtów** (`composer.json`: pakiet · `package.json`: pakiet ·
w repozytorium: ścieżka · usługa zewnętrzna). Kształt piąty oblewa test.

**Dlaczego kolumna, a nie lista wyjątków w teście.** Naturalne „każda nazwa
z tabeli musi być w `composer.json`" nie działa, bo większość tej tabeli
**słusznie** nie jest pakietem Composera: PostgreSQL, Railway, Cloudflare, R2
i PWA to usługi i platformy, a Tailwind jest pakietem npm. Lista dozwolonych
wyjątków rosłaby przy każdej zmianie tabeli i **sama stałaby się kolejną
deklaracją, która się rozjedzie** — czyli tą chorobą, którą leczy. Kolumna
odwraca ciężar dowodu: nowy wiersz nie wejdzie do tabeli bez odpowiedzi na
pytanie „a gdzie to jest", i odpowiada na nie ten, kto go dopisuje.

**3. `README.md` §Stack ma powtarzać pierwsze dwie kolumny CO DO ZNAKU.**
Druga kopia tabeli była drugim egzemplarzem tej samej nieprawdy. Zamiast
dublować w README lokalizatory (dwa miejsca do utrzymania, czyli ten sam
problem w mniejszej skali) test wymaga, żeby kopia zgadzała się z oryginałem
znak w znak. Skutkiem ubocznym README przestał skracać trzy wiersze po swojemu
— i dobrze, bo skrót w kopii jest pierwszym krokiem rozjazdu.

### Co ustalono o `SENTRY_LARAVEL_DSN` — i dlaczego NIE jest usuwane

Zmienna jest **martwa jako kod**: nie czyta jej żadna linijka PHP; jest tylko
*ustawiana* (`.railway/railway.ts`, `ci.yml` ×2) i *deklarowana*
(`.env.example`). Mimo to **nie usuwamy jej**, i to jest świadome:

- usunięcie z `.env.example` i CI, a pozostawienie w `.railway/railway.ts`,
  w `docs/infra/DEPLOYMENT_RUNBOOK.md` (kroki 4 i 20), `MONITORING_BLEDOW.md`,
  `INFRA_DECISION.md` i `KOPIE_I_ODTWORZENIE.md`, zamieniłoby jedną nieprawdę
  na drugą — konfigurację niekompletną wobec własnych instrukcji wdrożenia;
- `tests/Feature/KopiaBazyPozaRailwayemTest.php` używa tej nazwy jako
  przykładu sekretu, który nie ma prawa wyciec do zrzutu bazy;
- audyt D3-03 (`docs/AUDYT_2026-09.md`) zbadał dokładnie ten przypadek
  i zakwalifikował go jako „nieużywane, bo **przygotowane**", nie jako śmieć;
- D-041 wprost popiera to przygotowanie.

Zamiast usunięcia zmienna dostała w `.env.example` zdanie mówiące wprost, że
nic nie robi i że wpisanie tam DSN-u niczego nie włączy. **Skasowanie całej tej
instalacji jest osobną decyzją właściciela, nie sprostowaniem faktu** — i gdyby
zapadła, obejmuje wszystkie osiem miejsc naraz, nie trzy.

### Czego ta decyzja świadomie NIE rozstrzyga

- **Czy „usługa zewnętrzna" jest prawdą.** Z repozytorium nie da się sprawdzić,
  czy ktoś ma konto na Cloudflare. Wpisanie tego przy pakiecie PHP test
  przepuści — ale tabela kłamałaby wtedy JAWNIE, w jednym widocznym wierszu,
  zamiast po cichu przez zwykłą nazwę w kolumnie „Wybór". Żeby nie dało się
  uciszyć testu przepisaniem całej tabeli na tę wartość, test wymaga minimalnej
  liczby wierszy sprawdzalnych w repozytorium.
- **Wersji.** „Laravel 13" wobec `^13.0` nikt tu nie porównuje. Rozjazd wersji
  jest realny, ale to inna usterka i inna naprawa.
- **Losu samego Sentry.** To jest D-041 i nic tu się w nim nie zmienia. Warto
  jednak zapisać, że warunek wejścia z D-041 („działający `composer install`
  w środowisku pracy") jest dziś częściowo nieaktualny: `AGENTS.md` §10 opisuje
  działające obejście (`composer install --prefer-source`). Rozstrzygnięcie tego
  należy do D-041, nie tutaj.
- **Zdania w D-041, które odsyła po wybór docelowy „do tabeli w `AGENTS.md` §3".**
  Po tej zmianie tabela mówi o stanie, więc tamten odnośnik prowadzi już tylko
  do wiersza o webhooku. Wpisu D-041 nie przepisujemy — dziennik jest
  append-only — ale kto go czyta, ma wiedzieć, że wybór docelowy stoi w samym
  D-041 i w `docs/ROADMAP.md` §0.
- **Numeracji `docs/design/system-v3.1/`.** Tamten katalog prowadzi WŁASNY
  dziennik `D-1NN` i ma swoje D-104 („karta wpisu dostaje wariant zwarty").
  To dwie niezależne numeracje o tym samym kształcie; kolizja jest znana
  i opisana przy `OBCA_NUMERACJA` w `tests/Feature/NumeryDecyzjiMajaWpisyTest.php`.

### Jak to wycofać

Skasować `tests/Feature/TabelaStackuMowiPrawdeTest.php` i trzecią kolumnę
tabeli. Nic od tego nie zależy w kodzie produkcyjnym — cena wycofania to powrót
do stanu, w którym tabela może obiecywać rzeczy, których nie ma, i nikt tego
nie zauważy przez trzy tygodnie.

**Zmiana wymaga:** wskazania innego mechanizmu, który sprawdza tabelę
**maszynowo**. Powrót do „pilnujemy tego uważnością" nie jest zmianą tej
decyzji — jest powrotem do stanu, który już raz zawiódł, i to trzy razy
w jeden dzień.

📄 `AGENTS.md` §3 · `README.md` §Stack ·
`tests/Feature/TabelaStackuMowiPrawdeTest.php` · `docs/ROADMAP.md` §0 ·
`.env.example` (`SENTRY_LARAVEL_DSN`) · `app/Support/Wersja.php` ·
`config/kuking.php` (`kuking.wersja.commit`) ·
`resources/views/components/layout.blade.php` (stopka z wersją) ·
`app/Poczta/OdmowaEmailLabs.php` · `app/Console/Commands/BramkaR2.php` ·
issue #33, D-041, D-062, `docs/PULAPKI_TESTOW.md` §2
