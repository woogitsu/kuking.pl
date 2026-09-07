# Bramka bety — macierz zamknięcia fali 7

> Audyt fali 7 kończy się jednym poleceniem: zamiast fali 8 zrobić **jedno
> przejście domykające z dowodami** po każdym ustaleniu P0/P1, z oznaczeniem
> ZAMKNIĘTE / CZĘŚCIOWE / OTWARTE / PRZYJĘTE, numerem commita i nazwą testu
> regresyjnego. To jest ten dokument.
>
> Cytat z audytu, po co: *„This prevents »commit title says fixed« from becoming
> the acceptance criterion"*.

Stan na commit `1e9dd5c`, 6 września 2026. **979 testów przechodzi**, PHPStan
czysty (poziom 1 + Larastan), Pint czysty, automat dostępności bez naruszeń.

---

## 1. Jak czytać tę tabelę

- **ZAMKNIĘTE** — poprawka jest w kodzie, ma test regresyjny i test został
  sprawdzony przez OBALENIE: poprawkę cofnięto, test naprawdę zaczerwieniał,
  poprawkę przywrócono. Nie „powinien oblać", tylko „obalił się, sprawdziłem".

  Dotyczy to również czterech ustaleń z poprzedniej sesji, których nie
  domykałem osobiście. Obalenie dla nich zrobiłem przy pisaniu tej macierzy,
  bo „poprzednia sesja mówi, że naprawiła" nie jest dowodem:

  ```text
  cofnięte: OdzyskanyFormularz, EnsureAccountIsActive, OdzyskiwalneDane
  → 5 testów czerwonych (W7-03, W7-04, W7-12):
      test_zawieszone_konto_nie_odklada_hasla_do_sesji
      test_ekran_419_nie_oddaje_kodu_zapasowego_do_2fa
      test_ekran_419_nie_oddaje_niczego_z_logowania_ani_ze_zmiany_hasla
      test_nawet_na_trasie_tresci_pole_z_sekretem_nie_wraca
      test_419_nie_odklada_niczego_z_logowania

  cofnięte: PublishComment + trzy kontrolery
  → BlokadaObowiazujeTakzePrzyOdpowiadaniuTest czerwony (W7-06)
  ```

  Zastrzeżenie do ostatniego: bez poprawki test kończy się BŁĘDEM
  („Undefined array key »parent_id«"), a nie czystą nieudaną asercją — stara
  ścieżka kodu nie znała tego pola. Łapie regresję, ale nie tak elegancko,
  jak gdyby asertował zachowanie.
- **CZĘŚCIOWE** — aplikacja domknięta, coś poza nią nie.
- **OTWARTE** — nie zrobione, z podanym powodem.
- **PRZYJĘTE** — świadoma decyzja, że tak zostaje.

Kolumna „dowód" podaje plik testu, nie tylko commit. Commit mówi, co ktoś
CHCIAŁ zrobić; test mówi, co zostanie sprawdzone przy następnej zmianie.

---

## 2. Macierz

| ID | Waga | Stan | Commit | Dowód |
|---|---|---|---|---|
| **W7-01** | P0 warunkowy | **OTWARTE** | — | Zmierzone, nie naprawione. Patrz §3. |
| **W7-02** | P0 | **CZĘŚCIOWE** | `58ed626`, scalone `a8b2861`, poprawka cache `ddfc79a` | `ZdjeciaChronioneNieWyciekajaTest` (23 testy). Patrz §4. |
| **W7-03** | P1 | **ZAMKNIĘTE** | `79d4919` | `SekretyNieWracajaNaEkranTest` (6 testów) |
| **W7-04** | P1 | **ZAMKNIĘTE** | `79d4919` | `SekretyNieWracajaNaEkranTest`, `StronyBleduPoPolskuTest` |
| **W7-05** | P1 | **ZAMKNIĘTE** | `80c37c7` | `ZgloszenieNieUjawniaPrywatnejTresciTest` (11 testów) |
| **W7-06** | P1/P2 | **ZAMKNIĘTE** | `79d4919` | `BlokadaObowiazujeTakzePrzyOdpowiadaniuTest` (3 testy) |
| **W7-07** | P2 | **ZAMKNIĘTE** | `18842de` | `DataExportTest::test_powod_niepowodzenia_eksportu_nigdy_nie_zawiera_surowego_komunikatu_wyjatku` + `DataExportFailureReasonMigrationTest` |
| **W7-08** | P1/P2 | **OTWARTE** | — | Poza repozytorium. Patrz §5. |
| **W7-09** | P2 | **CZĘŚCIOWE** | `1e37019` | Zmierzone, nie oszacowane: `npm ls --all` 106 pakietów, z `--omit=dev` 41. Patrz §5. |
| **W7-10** | P1 utajony | **PRZYJĘTE** | `80c37c7` | `ZgloszenieNielegalnejTresciTest::test_dwa_zgloszenia_tej_samej_tresci_od_dwoch_osob_nie_scalaja_sie` (test SEC-08 z audytu) |
| **W7-11** | P2 | **ZAMKNIĘTE** | `fba0a39` | `CaddySpojnyZNaglowkamiLaravelaTest` (2 testy) |
| **W7-12** | P2 | **ZAMKNIĘTE** | `79d4919` | `SekretyNieWracajaNaEkranTest` — `App\Support\OdzyskiwalneDane` jest jedyną odpowiedzią na „które pola wolno pokazać z powrotem", i jest to BIAŁA LISTA nazw tras, nie czarna lista nazw pól |

---

## 3. W7-01 — jedyne P0, które zostaje otwarte

**Połowa aplikacyjna jest zmierzona, nie domniemana.** Przez prawdziwy stos
middleware tego repozytorium:

```text
bez nagłówków              ip=127.0.0.1     (prawdziwy REMOTE_ADDR)
X-Forwarded-For pojedynczy ip=203.0.113.7   (wartość od klienta)
X-Forwarded-For łańcuch    ip=203.0.113.7   (pierwszy element wygrywa)
XFF + CF-Connecting-IP     ip=203.0.113.7   (CF-Connecting-IP ignorowany)
XFF + X-Forwarded-Proto    secure()=true    (i dlatego trustProxies jest potrzebne)

limit logowania bez XFF        → blokada przy 6. próbie (limit to 5/min)
limit logowania ze zmiennym XFF → NIE BLOKUJE ANI RAZU w ośmiu próbach
```

Czyli w aplikacji nagłówek od klienta w całości decyduje o `$request->ip()`,
kasuje każdy limit liczony po adresie i wybiera wartość hashowaną do
`audit_log.ip_hash`.

**Czego z kontenera nie da się rozstrzygnąć:** czy Cloudflare albo Railway
czyszczą podstawiony `X-Forwarded-For`, zanim dojdzie do kontenera. To jest test
SEC-01 z audytu i wymaga stagingu.

**Czego NIE robić:** usuwać `trustProxies(at: '*')`. Komentarz w
`bootstrap/app.php` ma rację co do `X-Forwarded-Proto` — bez niego
`$request->secure()` jest fałszem, `url()` generuje `http://`, a Cloudflare
wpada w pętlę przekierowań. Bezpieczniejszy projekt ufa kanonicznemu,
jednowartościowemu nagłówkowi zamiast dowolnego łańcucha, ale ma niewiadomą
infrastrukturalną: środowiska preview (`*.up.railway.app`) **nie mają przed sobą
Cloudflare** (`docs/infra/INFRA_DECISION.md`), więc `CF-Connecting-IP` byłby tam
równie fałszowalny. To jest decyzja właściciela.

---

## 4. W7-02 — dlaczego CZĘŚCIOWE, a nie ZAMKNIĘTE

W aplikacji zamknięte i dobrze przetestowane: adresem zdjęcia jest trasa, która
pyta Policy treści nadrzędnej i przekierowuje na krótko podpisany adres.
Odmowa to 404 nieodróżnialne od braku. Domyka to również starsze ustalenie
**W5-05** (ukrycie moderacyjne nie odbierało bajtów z CDN).

**Poza aplikacją NIE.** Usunięcie `url` i `AWS_URL` z konfiguracji **nie
zdejmuje `cdn.kuking.pl` z bucketu wariantów po stronie Cloudflare**. Dopóki ta
domena tam wskazuje, każdy wcześniej skopiowany adres działa i wyciek trwa.
To jest **issue #120** i należy do właściciela. Do tego czasu W7-02 nie wolno
raportować jako zamknięte.

Drugi otwarty wątek: `r2_legacy` jest nadal publiczny, celowo, do zakończenia
`kuking:przenies-zdjecia`. Po tej zmianie to jedyny publiczny adres zdjęć
w serwisie.

---

## 5. W7-08 i W7-09 — co da się z repozytorium, a co nie

**Zrobione w repozytorium** (`1e37019`): `npm audit` przestał audytować pustą
listę. `package.json` nie ma sekcji `dependencies` w ogóle — Vite, Tailwind
i `laravel-vite-plugin` siedzą w `devDependencies` i to one produkują CSS oraz
JS, który dostaje przeglądarka. Flaga `--omit=dev` zostawiała 41 pakietów ze
106, a jedynym pakietem najwyższego poziomu był `@laravel/multiplex`. Krok
zawsze świecił na zielono — najgorszy rodzaj kontroli, bo wygląda jak działająca.

**Świadomie NIE zmienione:** `continue-on-error: true` na audycie zależności.
Uzasadnienie z nagłówka joba jest trafne (publikacja CVE w zależności
tranzytywnej nie może zablokować pilnego hotfiksa), a zmiana polityki
blokowania to decyzja właściciela, nie agenta.

**Poza repozytorium, do zrobienia przez właściciela:**

1. ochrona gałęzi `main` (GitHub nie trzyma tego w plikach repo — nie ma
   `.github/settings.yml` ani rulesets-as-code);
2. wymóg Pull Requesta dla gałęzi naprawczych (konsekwencja punktu 1);
3. `KUKING_WAIT_FOR_CI=true` faktycznie zastosowane w Railway — kod
   (`.railway/railway.ts`) tylko deklaruje tę wartość;
4. przypięcie akcji GitHub i obrazów bazowych do SHA/digestów — wymaga dostępu
   do rejestrów, którego ten kontener nie ma. Lista wszystkich wystąpień:
   `ci.yml` (`checkout@v7`, `setup-php@v2`, `cache@v6`, `setup-node@v7`,
   `upload-artifact@v7`, `setup-buildx-action@v4`, `build-push-action@v7`),
   `deploy.yml` (`action-release@v3`), `railway-iac.yml` (`config@v1`),
   `Dockerfile` (`node:22-bookworm-slim`, `dunglas/frankenphp:1-php8.4-trixie`
   ×2, `composer:2`), `ci.yml` (`postgres:18-alpine` w dwóch jobach).

---

## 6. Czego ta macierz NIE obejmuje

**Fale 1–6.** Audyt prosi o przejście domykające po każdym P0/P1 ze WSZYSTKICH
siedmiu fal. W sesji, która to pisała, dostępny był wyłącznie plik audytu fali 7
— pozostałych sześciu nikt nie przesłał i nie ma ich w repozytorium. Wypisanie
ich z pamięci albo z drugiej ręki byłoby dokładnie tym, przed czym audyt
ostrzega. **Poproś właściciela o fale 1–6 i dopisz je tutaj.**

Znane, niezamknięte pozostałości z tamtych fal (z notatki przekazania, jako
lista do sprawdzenia, nie jako ocena): W3-03, W3-06, W3-07, W3-10, W3-11,
W3-15..W3-18; W5-03, W5-04, W5-06, W5-07, W5-10..W5-25; W6-03, W6-04, W6-08,
W6-09, W6-10, W6-11, W6-13..W6-17. **W5-05 jest zamknięte** przez W7-02.

---

## 7. Znalezione przy domykaniu, poza zakresem audytu

Rzeczy, których fala 7 nie wymieniła, a które wyszły przy jej zamykaniu.
Wszystkie naprawione i przypięte testami, ale warto je znać, bo pokazują, gdzie
ten kod pęka:

- **Telemetria mogła wywrócić wgrywanie zdjęcia.** Samo `try/catch` wokół
  zapisu sygnału nie chroni operacji nadrzędnej na PostgreSQL: nieudany INSERT
  w trakcie otaczającej transakcji zatruwa ją całą. Naprawione przez
  `DB::transaction()` (SAVEPOINT).
- **Cache przekierowania do zdjęcia** miał `max-age` równy ważności podpisu
  w adresie docelowym, więc 302 wyjęte z cache pod koniec okna prowadziło pod
  adres już wygasły. Teraz połowa okna.
- **Testy w `git worktree` nie wykonywały nowego kodu.** Dowiązany `vendor`
  plus `optimize-autoloader` znaczy zamrożoną mapę klas ze ścieżkami
  bezwzględnymi do głównego katalogu. Naprawione w `tests/bootstrap.php`.
  Skutek dla tej macierzy: część wcześniejszych „zielonych" przebiegów agentów
  była bezwartościowa i **wszystkie testy w tabeli wyżej zostały puszczone
  ponownie po tej naprawie**.
- **Sześć naruszeń kontrastu w motywie ciemnym było artefaktem pomiaru** —
  axe czytał kolory w połowie animacji przełączenia motywu. Paleta nie została
  ruszona. Zapisane tutaj, bo następny automat równie łatwo zgłosi to samo.
- **CHECK chronił bazę, a kod wkładał te same dane do logu.** `ZapiszSygnal`
  logował `$e->getMessage()`, a komunikat odrzucenia z Postgresa zawiera cały
  odrzucony wiersz („DETAIL: Failing row contains…") plus doklejone przez
  Laravela „SQL: insert into … values (…)". Czyli dokładnie wtedy, gdy ochrona
  prywatności DZIAŁAŁA, przenosiła chronione dane z tabeli do pliku z logami.
  Naprawione (klasa wyjątku i SQLSTATE zamiast komunikatu), przypięte testem.
- **Wspomnienia gubiły wpisy opublikowane po północy.** Zapytanie robiło
  `extract(day from published_at)` na kolumnie `timestamptz`, czyli czytało
  dzień w UTC, i porównywało go z dniem czytelnika. Wpis z 00:30 czasu
  polskiego miał rocznicę przesuniętą o dobę wstecz. Naprawione przez
  `at time zone`; test ma zamrożony zegar, bo to jest błąd o porze doby.
- **Kilkanaście ekranów pokazywało człowiekowi adres bazy i całe zapytanie.**
  Warstwa domenowa rzucała zwykłym `RuntimeException` z komunikatem dla
  człowieka, a kontrolery robiły `catch (RuntimeException)` i wkładały
  `$e->getMessage()` do worka błędów formularza. Wzorzec dobry — tyle że
  `PDOException` DZIEDZICZY po `RuntimeException`, więc ten sam `catch` łapał
  też `QueryException` z dowolnego zapytania w środku bloku `try`. Zmierzone:
  po ukryciu tabeli `follows` kliknięcie „Obserwuj" wypisało w formularzu
  `SQLSTATE[42P01] … (Connection: pgsql, Host: …, Port: 5432, Database: …,
  SQL: select exists(… "follows"."follower_id" = 01a079be-… ))`, czyli adres
  bazy, jej nazwę, schemat zapytania i identyfikatory obu kont. Dotyczyło
  wpisów, przepisów, komentarzy, wykonań, zgłoszeń, odwołań, moderacji,
  awatara i usuwania konta. Naprawione znacznikiem
  `App\Exceptions\BladDlaCzlowieka`: na ekran idzie wyłącznie to, co ktoś
  świadomie napisał dla człowieka, reszta leci do procedury obsługi błędów.
  **Skutek widoczny dla człowieka:** awaria techniczna daje teraz stronę 500
  zamiast komunikatu przy polu formularza. Tak ma być — to nie jest nic,
  co on mógłby poprawić, i nie ma prawa wyglądać jak jego pomyłka.
  Dwa miejsca połykały taki wyjątek CAŁKIEM (`RegisterController`
  i `OnboardingController` przy obserwowaniu po rejestracji, `ResolveAppeal`
  przy cofaniu decyzji) — tam awaria bazy nie zostawiała żadnego śladu.
- **Ta sama pomyłka ze strefą czasu była jeszcze w dwóch miejscach.**
  Po `Wspomnieniach` przeszukałem kod pod kątem tej samej klasy błędu
  („dzień/rok liczony z `timestamptz` w UTC, pokazywany człowiekowi lokalnie")
  i znalazłem dwa kolejne, oba potwierdzone pomiarem, zanim je ruszyłem:
  **(1) archiwum profilu** — `extract(year from published_at)` bez
  `at time zone`, więc wpis z 1 stycznia 00:30 czasu polskiego lądował pod
  poprzednim rokiem, a jego własna karta pokazywała przy nim „1 stycznia"
  nowego roku; lista lat miała ten sam błąd, więc obie strony ekranu
  odpowiadały na to samo pytanie zgodnie i zgodnie źle.
  **(2) „Kuking na dziś"** — `shown_on` to zwykła kolumna `date`, a zapis
  (`now()->toDateString()`) i odczyt (`whereDate('shown_on', now())`) liczyły
  „dziś" w UTC. Tablica zmieniała się o 02:00 czasu polskiego, a gospodarz
  układający ją PO PÓŁNOCY zapisywał ją pod datą wczorajszą — widział ją
  jeszcze godzinę-dwie i znikała mu tego samego dnia. Naprawione nowym
  `Czas::dzisiajData()`, żeby „dziś człowieka" miało jedno źródło.
- **ZNALEZIONA PRZYCZYNA ZGŁOSZENIA „429 PRZY PIERWSZYM DODANIU ZDJĘCIA".**
  Wszystkie limity zapytań dzieliły JEDEN licznik na osobę.
  `ThrottleRequests::resolveRequestSignature()` buduje klucz wyłącznie
  z identyfikatora zalogowanego konta (dla gościa: z domeny i IP) — nazwy
  trasy w kluczu NIE MA. Rozróżnia je dopiero TRZECI parametr middleware'u,
  którego nie przekazywało ani jedno z 32 wywołań `throttle:` w
  `routes/web.php`. Każda trasa nabijała wspólne wiadro i porównywała jego
  stan ze SWOIM maksimum.
  Zmierzone: 25 zapytań o warianty zdjęć (limit tej trasy to 600/min, więc
  żadne nie odbiło) i **pierwsza** próba publikacji (limit 20/10 min)
  dostawała 429 z `Retry-After: 59` — słowo w słowo to, co zobaczył
  właściciel. **Dlaczego wyszło dopiero teraz:** trasa
  `/zdjecia/{media}/{wariant}` powstała razem z zamknięciem W7-02. Wcześniej
  zdjęcia szły prosto z CDN-u i nie przechodziły przez limiter w ogóle —
  poprawka bezpieczeństwa wpuściła kilkadziesiąt zapytań na KAŻDE otwarcie
  strony do licznika współdzielonego z publikacją.
  Naprawione prefiksem równym kluczowi limitu (`throttle:20,10,post`), co
  ZACHOWUJE świadome współdzielenie budżetu `post` przez wpis, przepis
  i „Ugotowałem". Dwa testy pilnują reguły: że każda nasza trasa ma prefiks
  ORAZ że jeden prefiks to zawsze ten sam limit — bez drugiego pierwszy dałby
  się przejść, dając dwóm różnym limitom tę samą nazwę.
  Znalezione przez agenta przy niezależnej weryfikacji W7-02, potwierdzone
  osobnym pomiarem.
- **Zakładka „Ugotowane" na cudzym profilu zdradzała tytuł przepisu.**
  Nad filtrem, który miał tego pilnować, stało zdanie „Wyciek przez tytuł to
  nadal wyciek" — reguła była więc UZNANA, a sprawdzenie obejmowało wyłącznie
  kolumnę `visibility`. Zmierzone: obcy widział tytuł przepisu ukrytego przez
  moderację ORAZ przepisu autora zbanowanego, mimo że i adres wykonania,
  i adres przepisu dawały mu 403. Trzeci przypadek subtelniejszy: filtr
  dostawał jako „właściciela" KUCHARZA, a `visibility: followers` dotyczy
  relacji z AUTOREM PRZEPISU — kto obserwował kucharza, ale nie autora,
  widział tytuł przepisu „tylko dla obserwujących" tego autora.
  Naprawione przez USUNIĘCIE ręcznego filtra, nie jego rozbudowę:
  `Recipe::scopeWidoczneDla()` odpowiada na to pytanie i ma własną macierz
  testów, a filtr w kontrolerze był drugą implementacją tej samej reguły.
  Znalezione przez agenta audytującego komentarze, z pomiarem; plik był poza
  jego zakresem, więc zgłosił go z gotowym patchem.
- **Trzecie miejsce z tą samą luką: szyna „Mój zeszyt" na stronie głównej.**
  `FeedController::home()` budował ją zapytaniem z `widoczneDla($user)`, a nad
  tym zapytaniem stał komentarz kończący się zdaniem „Jedna granica, jeden
  scope, wszędzie". To zdanie nie było prawdą: `widoczneDla()` liczy blokady
  i widoczność, a NIE liczy statusu konta autora — to osobna granica
  `User::scopeDostepnyJakoAutor()` (W5-08: dwie reguły, obie obowiązkowe).
  `CollectionController::show()` miał obie od tamtego audytu, ta szyna jedną.
  Skutek: przepis autora zbanowanego albo `pending_delete` znikał z ekranu
  zeszytu i JEDNOCZEŚNIE stał na stronie głównej z tytułem, nazwiskiem autora
  i miniaturą. Znalezione przez agenta przy ocenie specyfikacji, potwierdzone
  pomiarem (trzy testy czerwone przed poprawką), naprawione tą samą granicą,
  którą ma ekran zeszytu. Trzeci test pilnuje REGUŁY: ekran zeszytu i szyna
  muszą odpowiadać tak samo.
- **Wpis zbanowanego autora dalej stał w feedzie obserwowanych.**
  `PostPolicy::view()` dawał 403 pod adresem wpisu, a `FollowingFeed::
  paginate()` tej reguły nie miał — mimo że `isEmptyFor()`, kilkanaście
  linijek niżej W TYM SAMYM PLIKU, stosowało ją od początku. Ten sam wpis
  znikał więc spod własnego adresu i jednocześnie stał w feedzie każdego,
  kto tę osobę obserwował, ze zdjęciem, nazwą i treścią; strona główna
  zalogowanej osoby pokazuje ten sam feed, więc leciało na dwa ekrany.
  To jest dokładnie usterka W5-08 (zeszyt, mapa strony) w miejscu, do
  którego tamta poprawka nie dotarła. `DiscoverFeed`, `TopicFeed`,
  `CollectionController` i `SitemapController` regułę miały — zmierzone,
  nie założone. Naprawione wąskim progiem `tylkoOdAktywnychAutorow()`, tym
  samym, którego używa `isEmptyFor()`: te dwie metody muszą się zgadzać,
  inaczej feed złożony wyłącznie z wpisów osoby ZAWIESZONEJ meldowałby
  „pusto" i jednocześnie coś pokazywał. Poluzowanie progu do granicy
  z polityki (czyli wpuszczenie zawieszonych) to osobna decyzja, nie
  poprawka luki.
- **Trzecie miejsce z tą strefą było w metrykach — i psuło je inaczej.**
  `date_trunc('week', activity_at)` w `WeeklyActiveCooks`
  i `CookRetentionCohorts` obcinał tydzień w strefie SESJI Postgresa, której
  to repozytorium NIGDZIE nie ustawia (`config/database.php` nie ma klucza
  `timezone` dla `pgsql`). Poza znanym już przesunięciem o dwie godziny
  znaczyło to, że **te same dane dają inny WAC na innym serwerze bazy**, bez
  jednej zmiany w kodzie. Zmierzone wprost: samo `SET TIME ZONE` w sesji
  przesunęło wynik o cały tydzień. Naprawione przez
  `Czas::wStrefieCzlowieka()`, a test przestawia strefę w locie i wymaga, żeby
  liczba się nie ruszyła. Przy okazji: nagłówek `WeeklyActiveCooksTest`
  twierdził, że strefę sesji ustawia `config/database.php` — nieprawda, i to
  dlatego nikt tego nie widział: daty w testach były dobrane tak, żeby omijać
  granicę tygodnia.
- **`/health` pokazywał surowy komunikat wyjątku CAŁEMU INTERNETOWI.** To ten
  sam błąd co W7-07 (`failure_reason` eksportu RODO), tylko na trasie bez
  `auth` i bez limitu zapytań — bo mieć ich nie może: Railway odpytuje ją
  z zewnątrz przy każdym wdrożeniu. Przy awarii bazy pole `error` niosło
  komunikat PDO, czyli adres hosta, port, nazwę bazy i nazwę użytkownika;
  przy awarii dysku — ścieżkę na serwerze. Naprawione tym samym wzorcem:
  pole `error` to teraz kod z zamkniętego zbioru `HealthController::POWODY`,
  a pełna treść wyjątku idzie wyłącznie do logu, pod tym samym kodem, żeby
  dało się jedno połączyć z drugim. `HealthNieZdradzaSzczegolowTest` pilnuje
  obu połów naraz: że w odpowiedzi nie ma szczegółu ORAZ że w logu jest —
  „naprawa" polegająca na oślepieniu monitoringu oblałaby ten test.

## 7b. Polityka prywatności — SZKIC JEST PUBLICZNIE SERWOWANY

Znalezione 2026-09-07 przy ocenie planu integracji AI. Nie jest to problem
AI — jest problemem dzisiejszym.

`resources/legal/polityka-prywatnosci.md` zaczyna się zdaniem
„**SZKIC — wymaga weryfikacji prawnika przed publikacją**" i zawiera
placeholdery `[NAZWA OPERATORA]`, `[ADRES]`, `[E-MAIL KONTAKTOWY]`.
`StaticPageController::privacy()` renderuje ten plik DOSŁOWNIE
(`app/Http/Controllers/StaticPageController.php:41-53`), a trasa
`/prywatnosc` jest publiczna i bez logowania (`routes/web.php`). Czyli
każdy, kto tam wejdzie, czyta dokument, który sam o sobie mówi, że nie jest
gotowy do publikacji, plus trzy nieuzupełnione pola.

**Druga rzecz, ważniejsza dla decyzji o AI.** Ten sam dokument obiecuje:
„Dane przechowujemy na serwerach w Unii Europejskiej". Dziś infrastruktura
tę obietnicę TRZYMA — Railway `europe-west4-drams3a` (Amsterdam), kubełek R2
w UE, Sentry w regionie EU (`docs/infra/INFRA_DECISION.md`,
`docs/infra/DEPLOYMENT_RUNBOOK.md`). Wysyłanie treści wpisu albo zdjęcia do
modelu firmy trzeciej poza UE tę obietnicę ŁAMIE, a dostawca domyślnie
trzyma treść żądania przez 30 dni na potrzeby monitorowania nadużyć.

**Kolejność jest więc wymuszona, nie do wyboru:** dokument musi być
skończony i sprawdzony przez prawnika (z listą podprocesorów i podstawą
przekazywania poza EOG) ZANIM włączy się jakąkolwiek integrację AI na
treściach użytkownika. To nie jest formalność do odhaczenia po wdrożeniu —
to jest obietnica złożona człowiekowi na stronie, której kod ma dotrzymać.

Sam tekst prawny nie jest do napisania przez agenta: nazwa operatora, adres
i adres kontaktowy są danymi właściciela, a tabela podstaw prawnych wymaga
prawnika. Sam dokument zresztą tak mówi w dwóch miejscach.

## 7a. Zmierzone, NIE naprawione — do decyzji właściciela

**35 tras zapisujących nie ma limitu zapytań na poziomie trasy.** AGENTS.md §7
mówi, że każdy endpoint przechodzi przez pięć pytań, w tym „rate limit", a
limity mają mieszkać w `config/kuking.php`. Pełna lista wychodzi z
`php artisan route:list --json` (filtruj po `ThrottleRequests` w middleware —
uwaga, słowo „throttle" małą literą tam nie występuje i łatwo o tym pomyłkę).

**Ocena wagi, uczciwie: to dryf polityki, nie otwarta dziura.** Sprawdziłem
najgroźniej wyglądający przypadek — `POST /ustawienia/twoje-dane/eksport`
kolejkuje ciężkie zadanie w tle, ale kontroler ma WŁASNĄ bramkę i odrzuca
kolejne żądanie, gdy poprzednia paczka jeszcze się robi. Usunięcie konta
wymaga hasła. Reszta to w większości tanie zapisy jednego wiersza za `auth`
(obserwowanie, zapis do zeszytu, oznaczenie powiadomień).

Nie dobrałem tych 35 limitów sam, bo dobranie liczby to decyzja produktowa
(ile obserwowań na minutę to jeszcze człowiek, a ile już skrypt), a nie
techniczna. Warto rozstrzygnąć przed betą, bo `follow`/`unfollow` generuje
powiadomienia u drugiej osoby.


---

## 8. Decyzja o becie — czego brakuje

Zanim ktokolwiek powie „można otwierać":

1. **#120** — dowód, że produkcyjny bucket wariantów nie jest publicznie
   osiągalny. Bez tego W7-02 jest naprawione tylko w kodzie.
2. **SEC-01 na stagingu** — rozstrzygnięcie W7-01.
3. **Fale 1–6 w tej macierzy** (§6).
4. **Ochrona `main` i bramka CI** (§5) — audyt wymienia to wprost w sekcji
   „Release safety".
