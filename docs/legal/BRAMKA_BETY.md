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
| **W7-01** | P0 warunkowy | **CZĘŚCIOWE** | łatka SEC-01 | `PodrobionyNaglowekProxyTest` (8 testów) — **NIEURUCHOMIONYCH**, patrz §3 i uwaga pod tabelą. Aplikacja domknięta, granica zaufania (token krawędziowy) nie |
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

> **Uwaga do wiersza W7-01 — NIEAKTUALNA OD 8 WRZEŚNIA (PR #139).** Testy
> przebiegły na PostgreSQL 18 i są zielone; wiersz opisuje stan sprzed tego
> przebiegu i zostaje tu wyłącznie jako ślad, dlaczego łatka tak długo leżała.
> Oryginalna treść:
>
> **Uwaga do wiersza W7-01.** Łatka SEC-01 powstała w środowisku bez pełnego
> `vendor/`, więc PHPUnita **nie uruchomiono ani razu**. Sprawdzone zostało:
> składnia (`php -l`), zgodność z faktycznym źródłem `TrustProxies`
> i `Symfony\Component\HttpFoundation\Request`, oraz sam wybór wpisu
> z łańcucha — uruchomiony w PHP na dwunastu przypadkach, poza Laravelem.
> **Ośmiu testów Feature z `PodrobionyNaglowekProxyTest` nikt jeszcze nie
> widział na zielono, a obalenia (cofnąć poprawkę → test czerwony) nie
> zrobiono.** Do czasu przebiegu na PostgreSQL ten wiersz nie ma prawa
> awansować na ZAMKNIĘTE.

---

## 3. W7-01 — połowa aplikacyjna zamknięta, granica zaufania nadal otwarta

> Sekcja przepisana po SEC-01. Poprzednia wersja opisywała stan przed
> poprawką i przy okazji **myliła się w jednym szczególe**, który zostaje tu
> sprostowany, bo prowadził do złych wniosków.

### 3.1. Co było — i co poprzednia wersja tej sekcji podała źle

Pomiar sprzed poprawki był w zasadzie trafny: nagłówek od klienta decydował
o `$request->ip()`, kasował limity liczone po adresie i wybierał wartość
hashowaną do `audit_log.ip_hash`.

**Sprostowanie:** nieprawdą było zdanie „przy łańcuchu wygrywa PIERWSZY
element". Wygrywa **OSTATNI**. Widać to w źródle frameworka, nie w domysłach:

- `trustProxies(at: '*')` Laravel tłumaczy na
  `setTrustedProxies([REMOTE_ADDR], …)` — czyli „ufaj wyłącznie tej maszynie,
  która się właśnie połączyła", a nie „ufaj całemu łańcuchowi";
- dalej pracuje Symfony (`Request::normalizeAndFilterClientIps()`): dokleja
  `REMOTE_ADDR` na koniec łańcucha, usuwa z niego adresy zaufane, **odwraca
  resztę** i oddaje jej pierwszy element jako `getClientIp()`.

Różnica nie jest kosmetyczna. Przy regule „wygrywa ostatni" ruch idący
**przez Cloudflare** dostawał poprawny adres także przed poprawką — bo to
Cloudflare dopisuje na końcu adres odwiedzającego i nie pozwala go nadpisać
regułą transformacji. Realnie podatny był więc ruch, **przed którym nic nie
stało**, oraz każde przyszłe wdrożenie, w którym łańcuch urósłby o jeden
przeskok bez zmiany w kodzie.

### 3.2. Co jest po zmianie

Nowy middleware `App\Http\Middleware\NormalizeForwardedFor` stoi **pierwszy
w globalnym stosie**, przed `TrustProxies`, i zostawia w `X-Forwarded-For`
dokładnie jeden wpis: ten, który dopisała nasza infrastruktura, wyliczony
jako **n-ty od końca** łańcucha. Liczbę podaje `config/proxy.php`
(`KUKING_ZAUFANE_PRZESKOKI`, domyślnie `1`).

Podstawą jest jedyna własność tego nagłówka niezależna od adresów IP:
**proxy dopisuje na końcu, klient może dopisywać tylko na początku.** Dlatego
liczymy od prawej — dopisany prefiks przesuwa wyłącznie własne śmieci.

Dlaczego nie lista adresów IP: Symfony porównuje listę zaufanych proxy
z bezpośrednim peerem TCP, a tym peerem jest zawsze brzeg Railway z sieci
prywatnej — nigdy adres Cloudflare. Lista zakresów Cloudflare byłaby listą,
w którą nie trafi żadne żądanie, a adresów brzegu Railway nikt nie gwarantuje
(`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md` §3).

Przy okazji zestaw zaufanych nagłówków jest wypisany **jawnie** i skrócony:
zniknęły `X-Forwarded-Prefix` (doklejany do każdego adresu z `url()`) oraz
zbiorczy `X-Forwarded-AWS-ELB`. Nic w naszym łańcuchu ich nie wysyła.

### 3.3. Gdy Railway zmieni topologię

Obie możliwe pomyłki są **jednostronne** — mylą się w stronę zbyt ostrą,
nigdy w stronę zaufania klientowi:

| Co się dzieje | Skutek |
|---|---|
| Dochodzi proxy dopisujące wpis (liczba za mała) | Aplikacja widzi adres tego proxy: jeden wspólny adres dla wielu osób. Limity robią się za ostre, podszyć się nie da. Odwracalne jedną zmienną |
| Proxy ubywa (łańcuch krótszy niż konfiguracja) | Nagłówek jest odrzucany w całości, zostaje adres połączenia TCP, a w logu ląduje ostrzeżenie z samą DŁUGOŚCIĄ łańcucha (bez adresów — AGENTS.md §7) |
| Ktoś podniesie liczbę „z zapasem" | **To jedyny sposób, żeby przywrócić dziurę.** Odczyt wchodzi w obszar wypełniany przez klienta |

Wartość mierzy się, a nie zgaduje: wejść na produkcję przez Cloudflare bez
własnego `X-Forwarded-For` i policzyć, ile wpisów ma nagłówek, który dotarł
do aplikacji. Recepta stoi w `config/proxy.php`, obok liczby.

### 3.4. Co zostaje otwarte (i dlaczego nie da się tego zamknąć kodem)

**Żądanie z pominięciem Cloudflare.** Wejście wprost na `*.up.railway.app`
niesie łańcuch złożony wyłącznie z tego, co wpisał klient — licząc od prawej
trafiamy wtedy w jego własny ostatni wpis. Kod nie odróżni „przyszło przez
nasz brzeg" od „przyszło z pominięciem brzegu"; służy do tego token
krawędziowy `X-Kuking-Edge-Token` (Blok B w
`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`), którego bez panelu Cloudflare
wdrożyć się nie da. Środowiska preview **nie mają przed sobą Cloudflare**
w ogóle (`docs/infra/INFRA_DECISION.md`) — tam ten nagłówek pozostaje
fałszowalny i tak ma być traktowany.

**Ile wpisów dopisuje brzeg Railway.** Dokumentacja Railway nie wspomina
o `X-Forwarded-For` ani słowem. Do czasu pomiaru na żywej infrastrukturze
zostaje bezpieczna wartość `1`.

**`X-Forwarded-Host` NIE JEST JUŻ ZAUFANY — zamknięte 10 września 2026 (S2,
D-071).** Ten akapit mówił wcześniej, że nagłówek zostaje zaufany, a właściwym
zamknięciem byłby `TrustHosts` „w osobnej zmianie". Ta zmiana została zrobiona
i wygląda inaczej, niż tu zapowiadano — na dwa sposoby naraz:

1. `X-Forwarded-Host` **wypadł z bitmaski zaufanych nagłówków**
   (`bootstrap/app.php`). Nagłówka, którego aplikacja nie czyta, nie da się
   podstawić — to zamknięcie mocniejsze niż allowlista. Wolno było go wyjąć,
   bo `Host` przechodzi przez Cloudflare i brzeg Railway nietknięty.
2. `Host` przechodzi przez `TrustHosts` z jawną listą
   (`App\Support\ZaufaneHosty`), która **zawiera** `healthcheck.railway.app`
   — bez tego wpisu deploy pada na 400 i nigdy się nie kończy.

Zapowiedź o mniejszym praktycznym zasięgu była trafna, ale niepełna: reset
hasła, potwierdzenie adresu i logowanie linkiem faktycznie budują adres
w workerze z `APP_URL` (`ShouldQueue`), natomiast potwierdzenie **zmiany**
adresu e-mail powstawało w żądaniu HTTP i tam nagłówek wchodził do listu
wprost. Wszystkie cztery linki są teraz budowane z konfiguracji
(`App\Support\AdresKanoniczny`).

**`trustProxies(at: '*')` zostaje.** Wcześniejsze ostrzeżenie „nie usuwać"
jest nadal aktualne: bez zaufania do `X-Forwarded-Proto` `$request->secure()`
jest fałszem, `url()` generuje `http://`, a Cloudflare wpada w pętlę
przekierowań.

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
   `ci.yml` (`postgres:18-alpine` w dwóch jobach). Obrazy bazowe w `Dockerfile`
   (`node:22-bookworm-slim`, `dunglas/frankenphp:1-php8.4-trixie` ×2,
   `composer:2`) i `docker/kopia/Dockerfile` (`postgres:18`) są już przypięte
   do digestów i objęte Dependabotem (#952).

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

## 7b. Polityka prywatności — SZKIC BYŁ PUBLICZNIE SERWOWANY (ZAMKNIĘTE 2026-09-07, commit `d8e9334`)

**Ta sekcja opisuje stan sprzed poprawki. Zostaje w całości, bo powód, dla
którego to przeszło niezauważone, jest ważniejszy niż sama poprawka:
dokumenty prawne leżą w `resources/legal/*.md`, są renderowane pod publicznym
adresem i NIC NIGDY nie sprawdzało, co w nich stoi.** Teraz sprawdza
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` — 14 testów na trzech
dokumentach, w tym reguła, że każda liczba dni pozostawiona w polityce musi
zgadzać się z konfiguracją, która ją egzekwuje.

Co zostało usunięte z żywych stron `/prywatnosc`, `/regulamin` i `/zasady`:
nagłówek „SZKIC", placeholdery `[NAZWA OPERATORA]`, `[ADRES]`,
`[E-MAIL KONTAKTOWY]`, notatki redakcyjne `[Wariant A — jeśli wdrożony
baner:]`, Sentry i PostHog jako podprocesorzy (żadne nie jest wdrożone),
zdanie „każdy z tych dostawców ma podpisaną z nami umowę powierzenia"
(właściciel zapytany wprost: żadne nie są podpisane), obietnica odpowiedzi
na zgłoszenie „w ciągu 48 godzin" przy zerze kodu, który ten czas mierzy,
okresy przechowywania „orientacyjnie 12–24 miesiące" i „do 90 dni" dla danych,
których nic nie usuwa, oraz „hasło przechowywane w postaci zaszyfrowanej"
(hasła są HASZOWANE — szyfrowanie dałoby się odwrócić).

**Co ZOSTAJE otwarte i jest blokadą, której kodem nie zamknę:** tożsamość
i adres administratora. Dokumenty mówią o tym teraz wprost, zamiast pokazywać
nawias kwadratowy: „Imię, nazwisko i adres do korespondencji podamy w tym
miejscu, zanim otworzymy rejestrację dla wszystkich. Dopóki tego tu nie ma,
wiedz o tym, korzystając z serwisu: masz prawo znać tożsamość administratora
i możesz o nią poprosić e-mailem, a my ją podamy."

**Druga blokada, znaleziona przy tej samej okazji:** `MAIL_MAILER=log`, czyli
serwis nie wysyła ŻADNEJ poczty. Reset hasła nie dochodzi do nikogo, a przy
grupie 50+ znaczy to, że pierwsza osoba, która zapomni hasła, traci konto —
i nie ma jak nawet napisać, bo dostawcy poczty nie wybrano. Adres nadawcy
został ujednolicony do `kontakt@kuking.pl` w repozytorium (decyzja właściciela
z 7 września); zmienna `MAIL_FROM_ADDRESS` na Railway należy do właściciela.

Poniżej pierwotny zapis znaleziska.

### Pierwotny zapis (2026-09-07, przed poprawką)

Znalezione przy ocenie planu integracji AI. Nie jest to problem AI — jest
problemem dzisiejszym.

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

## 7a. ZAMKNIĘTE — limity na trasach zapisujących

**Stan wcześniejszy, przepisany tu w całości, bo liczba w nim była błędna:**

> **35 tras zapisujących nie ma limitu zapytań na poziomie trasy.** […]
> Nie dobrałem tych 35 limitów sam, bo dobranie liczby to decyzja produktowa
> (ile obserwowań na minutę to jeszcze człowiek, a ile już skrypt), a nie
> techniczna.

**Liczba była przybliżona i policzona inaczej, niż mówiła.** Przeliczone
ręcznie w `routes/web.php` na wierzchołku `main` (`820e1c5`): **67 definicji
tras `POST`/`PUT`/`PATCH`/`DELETE`** (licząc `Route::match(['get','post'], …)`
raz), z czego **31 miało już limit**, a **36 nie miało żadnego** — nie 35.
Nieprawdziwe było też zdanie z §6 tego samego przeglądu, jakoby limity miały
„tylko logowanie, rejestracja i reset hasła": kluczy w `config/kuking.php`
było jedenaście, a limit miały m.in. publikacja, komentarz, zgłoszenie,
odwołanie i tryb gotowania.

**Rozstrzygnięte: 35 z tych 36 tras dostało limit; jedna świadomie nie.**
Progi żyją w `config/kuking.php` (sekcja `limits`), pogrupowane WEDŁUG SZKODY,
jaką robi nadużycie, a nie według kontrolera. Każdy klucz ma tam przy sobie
uzasadnienie liczby.

| grupa (klucz) | próg | co obejmuje |
|---|---|---|
| `post` (bez zmian) | 20 / 10 min | publikacja i edycja treści publicznej |
| `comment` (bez zmian) | 10 / 1 min | komentarze i podziękowanie |
| `usuwanie` | 30 / 10 min | usunięcie wpisu, przepisu, „Ugotowałem", zeszytu |
| `obserwowanie` | 60 / 10 min | obserwowanie i odobserwowanie osoby oraz tagu |
| `masowe_obserwowanie` | 5 / 10 min | `/witaj/ludzie` — jedno żądanie, wiele powiadomień |
| `blokada` | 60 / 10 min | blokowanie i odblokowanie osoby |
| `zeszyt` | 60 / 10 min | zapis i wypisanie przepisu albo wpisu |
| `ustawienia` | 30 / 10 min | czytelność, prywatność, tagi, motyw, powiadomienia, zapis profilu, usunięcie zdjęcia profilowego |
| `ustawienia_profil` | 15 / 10 min | `POST /ustawienia/zdjecie` — jedyny ekran ustawień z plikiem |
| `eksport` | 10 / 60 min | paczka RODO |
| `confirm_password` (bez zmian) | 5 / 10 min | akcje proszące o hasło — teraz także wyłączenie 2FA i zgłoszenie usunięcia konta |
| `moderacja` | 120 / 10 min | cały panel `/admin` |

**ŚWIADOMIE BEZ LIMITU: `POST /logout`.** Wylogowanie unieważnia sesję i nic
nie tworzy; powtórzone nie robi nic. Za to 429 na tej trasie zostawia otwartą
sesję na wspólnym komputerze u kogoś, kto właśnie próbuje ją zamknąć. Zysk
zerowy, koszt realny. Wyjątek jest wpisany z nazwy w
`tests/Feature/LimityTrasZapisujacychTest.php`, więc druga taka trasa nie
powstanie po cichu.

**Dwie rzeczy naprawione przy okazji, obie zmierzone, nie wywnioskowane:**

1. `collections.save-post` i `collections.unsave-post` chodziły pod prefiksem
   `post`, czyli pod budżetem PUBLIKOWANIA. Wieczór spędzony na zapisywaniu
   cudzych wpisów do zeszytu odbierał prawo do opublikowania własnego — ten
   sam kształt usterki co zgłoszenie „429 przy pierwszym zdjęciu" (`ef4f6ca`),
   tylko na innej parze tras. Przeniesione pod `zeszyt`, z testem regresyjnym.
2. `POST /witaj/ludzie` przyjmował listę kont do obserwowania **bez limitu jej
   długości** (`'follow' => ['nullable','array']`), a pętla w kontrolerze
   tworzy powiadomienie dla każdej pozycji. Limit na trasie tego nie łapie, bo
   to jedno żądanie. Dodane `max:20` (ekran proponuje osiem osób). To samo
   przy `POST /witaj/zainteresowania`: tam każda pozycja tablicy uruchamiała
   osobne zapytanie `exists:tags,id` — dodane `max:50`.

**Komunikat po przekroczeniu limitu był już w porządku i nie wymagał zmiany.**
`resources/views/errors/429.blade.php` jest po polsku, w layoucie serwisu,
mówi ile czekać (zaokrąglone w górę z nagłówka `Retry-After`), mówi, że nic
nie przepadło, i daje dwa wyjścia. Pilnuje go
`StronyBleduPoPolskuTest::test_429_mowi_co_zrobic_po_polsku`, a
`bootstrap/app.php` zapisuje przy każdym 429 wzorzec trasy do logu.
Jedyna droga, którą zostaje angielskie „Too Many Requests", to odpowiedź JSON
(`shouldRenderJsonWhen`) — dziś nieosiągalna, bo repozytorium nie ma tras
`api/*`, a trasy Livewire'a nie mają naszych limitów. Zostawione bez zmiany
i zapisane tutaj jako rzecz do sprawdzenia w dniu, w którym powstanie
pierwszy endpoint JSON.

**Otwarte, drobne:** klucz `tag_suggest` w `config/kuking.php` nie jest
podpięty do żadnej trasy (podpowiedzi tagów liczy `TagSuggester` po stronie
serwera, bez własnego endpointu). To dokładnie ten sam kształt co opisany
w tym samym pliku, usunięty już klucz `upload`: martwy wpis jest gorszy niż
jego brak, bo następna osoba podniesie liczbę i uzna sprawę za załatwioną.
Zostawiony, bo jego usunięcie albo podpięcie to decyzja o zakresie SPEC §1.5,
a nie o limitach.


---

## 7c. Zamknięte 2026-09-07 — audyt zewnętrzny i pomiar DSA

Wszystko z dowodem: commit i nazwa testu regresji. Każda z tych rzeczy była
ZMIERZONA przed naprawą, nie wywnioskowana z czytania kodu.

| # | co | stan | dowód |
|---|---|---|---|
| **N01** | Kopia zdjęcia w starym, wciąż publicznym kubełku `r2_legacy` nie była w ogóle celem kasowania. Po migracji kubełków zostawała pod tym samym kluczem — czyli usunięcie zdjęcia przez człowieka nie usuwało zdjęcia. | **ZAMKNIĘTE** | `ad89067`, `KasowanieZdjeciaOdpornoscNaAwarieTest` (test N01 + dwie kontrole: brak kopii → nieszkodliwe, dysk niekonfigurowany → pominięty) |
| **N02** | `Cache-Control: no-store` stał na PRZEKIEROWANIU (302), a nie na odpowiedzi z bajtami zdjęcia — czyli na jedynej, którą pośrednik miałby co zapisać. | **ZAMKNIĘTE W APLIKACJI** | `f49ad2a`, `ZdjecieObiektuDostajeTenSamNoStoreCoPrzekierowanieTest`. Czy R2 honoruje `response-cache-control`, rozstrzyga wyłącznie pomiar na prawdziwym kubełku — zapisane w `docs/MEDIA_PIPELINE.md`, nie założone. |
| **N05** | Sygnał „nie udało się wgrać zdjęcia" powstawał tylko wtedy, gdy plik dotarł do warstwy domenowej. Odrzucenie na walidacji formularza i na limicie żądań — najprawdopodobniej NAJCZĘSTSZE drogi — były niewidoczne z Postgresa. | **ZAMKNIĘTE** | `09d03d2`, `SygnalNieudanegoWgraniaZFormularzaTest`. `post_max_size` PHP zostaje niemierzalny w procesie testowym i jest tak opisany, a nie zamalowany. |
| **#17** | Awaria storage w połowie pętli kasowania: ciche `false` kasowało wiersz `media` przy fizycznie istniejącym pliku, a wyjątek przerywał pętlę, więc kolejnych wariantów nawet nie próbowano. Konto już oznaczone jako wymazane nigdy nie wracało do kolejki. | **ZAMKNIĘTE** | `ad89067`, `KasowanieZdjeciaOdpornoscNaAwarieTest` (8 testów) |
| **D-018 / D-022** | „Tekst zostaje zanonimizowany" nie było prawdą: zanonimizowane konto oddawało 403 na przepisach, wpisach i profilu, a jego komentarze znikały z cudzych wątków. Test, który to „udowadniał", sprawdzał obecność wiersza w bazie. | **ZAMKNIĘTE** | `ad89067`, `UsunieteKontoTresciZostajaWidoczneTest` (10) + `UsuwanieKontaZakresTest` (14) |
| **DSA art. 20** | Termin odwołania 14 dni przy wymogu co najmniej sześciu miesięcy. Liczba pochodziła z playbooka, gdzie wzięła się z rozsądku operacyjnego, nie z przepisu. | **ZAMKNIĘTE** | `d8e9334`, `OdwolanieOdDecyzjiTest::test_po_szesciu_miesiacach_formularz_mowi_ze_termin_minal` (z kontrolą, że po miesiącu odwołanie NADAL działa) |
| **DSA art. 16 ust. 2 lit. c** | Zgłoszenie treści nielegalnej wymagało imienia, choć przepis z podania danych zwalnia — i to w najcięższych sprawach. `notifier_email` był już opcjonalny, z komentarzem cytującym dokładnie ten przepis: reguła istniała w kodzie w połowie. | **ZAMKNIĘTE** | `36d6579`, `ZgloszeniePrawneBezDanychTest` (8, w tym dwa na to, że baza NADAL wymaga uzasadnienia i dobrej wiary) |
| **DSA art. 17** | List do zgłaszającego obiecywał, że sprawę obejrzy „człowiek, który jej wcześniej nie prowadził". Serwis prowadzi jedna osoba i nic tego nie zapewnia. | **ZDANIE WYCOFANE** | `8548ada`, `OdpowiedzDlaZglaszajacegoMowiPrawdeTest::test_pouczenie_nie_obiecuje_innego_czlowieka`. Pozostałe braki art. 17 ust. 3 (podstawa decyzji, informacja o źródle, zdanie o braku automatyki) są otwarte — patrz `docs/decyzje/DSA_POMIAR.md`. |

**Nowy dokument, który powinien być czytany przed każdą zmianą w regulaminie:**
`docs/decyzje/DSA_POMIAR.md` — obowiązek → co jest w kodzie (plik:linia) → czy
to wystarcza → jakie zdanie wolno napisać, a jakiego nie wolno. CHECK-i
sprawdzone w żywej bazie, nie tylko w migracjach.

**Drugi:** `docs/decyzje/ADR_RETENCJE.md` — pięć tabel bez żadnego mechanizmu
usuwania (`audit_log`, `notifications`, `reports`, `appeals`,
`moderation_actions`), z rozstrzygnięciem trudności, których nie wolno
przemilczeć: skasowanie wiersza `account.data_erased` niszczy DOWÓD wykonania
prawa do usunięcia danych. Czeka na wybór okresów przez właściciela.

---

## 8. Decyzja o becie — czego brakuje

Zanim ktokolwiek powie „można otwierać":

1. **#120** — dowód, że produkcyjny bucket wariantów nie jest publicznie
   osiągalny. Bez tego W7-02 jest naprawione tylko w kodzie.
2. ~~**SEC-01 na stagingu** — rozstrzygnięcie W7-01.~~ **ZAMKNIĘTE 8 września
   (PR #139).** Dziewięć testów `PodrobionyNaglowekProxyTest` przebiegło na
   PostgreSQL 18 na zielono; uruchomienie wykazało przy okazji usterkę, której
   czytanie kodu nie pokazało (adres IPv4 zapisany jako IPv6 kasował cały
   nagłówek). Połowa aplikacyjna W7-01 jest zamknięta i **udowodniona
   przebiegiem, nie rozumowaniem**. Granica zaufania — token krawędziowy —
   zostaje otwarta i wymaga panelu Cloudflare.
3. **Fale 1–6 w tej macierzy** (§6).
4. **Ochrona `main` i bramka CI** (§5) — audyt wymienia to wprost w sekcji
   „Release safety".
5. ~~**Tożsamość i adres administratora** w dokumentach prawnych (§7b).~~
   **ZAMKNIĘTE 8 września.** Serwis prowadzi SAMSUFI sp. z o.o. z siedzibą
   w Knyszynie (KRS 0000901262). Dane stoją w regulaminie §1 i w polityce
   prywatności §1, a `config/kuking.php` jest ich źródłem — rozjazd między
   konfiguracją a dokumentem zapala `DokumentyPrawneNieKlamiaTest` na czerwono.
   Wcześniej oba dokumenty mówiły „serwis prowadzi osoba fizyczna" i obiecywały
   dane później, co przy RODO art. 13 ust. 1 lit. a było zaniechaniem.
6. ~~**Działająca skrzynka pocztowa** (§7b).~~ **ZAMKNIĘTE — sprawdzone
   na produkcji 20 września 2026.** Stało tu: „Dziś `MAIL_MAILER=log`: reset
   hasła nie dochodzi do nikogo". To zdanie opisywało `.env.example`, czyli
   ustawienie LOKALNE, i przestało być prawdą o produkcji. Odczyt
   `https://kuking.pl/health` pokazuje `poczta: ok`, a jedynym niezdrowym
   elementem jest `kolejka: zadania_nieudane`.

   Zostawiam ten punkt przekreślony, a nie skasowany, bo jest dowodem na to,
   po co ta bramka w ogóle powstała: **pozycja bramkująca, która blokuje na
   rozwiązanym problemie, szkodzi dokładnie tak samo jak pozycja o usłudze,
   której nigdy nie było**. Jedna każe czekać bez powodu, druga każe odhaczyć
   niemożliwe — obie uczą, że listy nie trzeba czytać serio.
7. ~~**Wybór okresów retencji**~~ — **ZAMKNIĘTE, poprawione 9 września.**
   Stało tu: „polityka prywatności nie podaje dziś żadnego okresu poza dwoma,
   które kod egzekwuje". To zdanie zostało z czasu sprzed `ADR_RETENCJE.md`
   i było już nieprawdziwe. Okresy są wybrane, wpisane w `config/kuking.php`
   i **egzekwowane przez siedem komend**, a nie przez dwie:

   | co | okres | komenda |
   |---|---|---|
   | powiadomienia | 3 miesiące | `kuking:sprzataj-powiadomienia` |
   | sygnały produktowe | 90 dni | `kuking:sprzataj-sygnaly` |
   | dziennik audytu | 12 miesięcy | `kuking:sprzataj-audyt` |
   | sprawy moderacyjne | 36 miesięcy | `kuking:sprzataj-sprawy-moderacyjne` |
   | paczki z danymi | 7 dni | `kuking:sprzataj-eksporty` |
   | zdjęcia nieprzypięte | — | `kuking:sprzataj-osierocone-zdjecia` |
   | konta po karencji | 30 dni | `kuking:usun-wygasle-konta` |

   Powiadomienia moderacyjne mają **własny, dłuższy** termin — sześć miesięcy
   na odwołanie z regulaminu §8 — i sprzątanie ich pomija; dlatego tamta
   komenda chodzi po wierszach jedno po drugim zamiast jednym `DELETE`.

   Znalazł to audyt zewnętrzny (§6.2). Ta pozycja bramki nie blokuje już
   niczego; zostaje przekreślona zamiast skasowana, bo bramka jest zapisem
   tego, co było do rozstrzygnięcia.
