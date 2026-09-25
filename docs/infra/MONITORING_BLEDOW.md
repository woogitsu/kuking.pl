# Monitoring błędów — webhook na Slack/Discord (i docelowo Sentry)

> Aktualizacja 20.09.2026: [odbiór lokalny #598/#599](MONITORING_ODBIOR_2026_09_20.md)
> zawiera pomiary, próbę rzeczywistego transportu, naprawę alarmu przy awarii
> cache oraz instrukcję potwierdzenia odbiorcy. Opis stanu produkcji poniżej
> jest historyczny; w tej sesji nie odczytano jej konfiguracji.

## Kod żądania i zadania — issue #1040

`CorrelateRequest` nadaje losowy UUID v4, niezależny od nagłówka klienta,
konta, sesji, IP i treści formularza. W trakcie żądania `request_id` trafia
do kontekstu otwartych oraz później tworzonych kanałów logowania.
Webhook pokazuje go jako `żądanie: …`, odpowiedź jako `X-Request-ID`,
a zwykła strona 500 jako „Kod błędu: … Podaj go, gdy do nas napiszesz.”.
Obsługa może szukać dokładnego kodu w logu. Dotychczasowy ośmioznakowy
`odcisk` nadal grupuje rodzaj awarii; nie zastępuje kodu żądania.
Kilka wpisów logu tego samego żądania ma ten sam kod.

Nagłówek otrzymują także odpowiedzi poprawne, JSON oraz 500 zwrócone
bez wyjątku. Sam status 500 nie uruchamia nowego alarmu: dotychczasowe
reguły raportowania pozostają bez zmian. Widok nie wymaga bazy ani
manifestu assetów; kod jest zwykłym tekstem, bez przycisku wymagającego JS.
Webhook dopuszcza tylko pełny kształt UUID v4 w tym nowym polu;
pozostałych danych kontekstu nadal nie serializuje.

### Jawna granica HTTP

Middleware jest czwarty w stosie globalnym, po `NormalizeForwardedFor`,
`ApplySecurityHeaders` i `PreventSharedSessionCache`. Zachowujemy ich
istniejącą kolejność.
Korelacja obejmuje raportowanie i renderowanie wyjątku wewnątrz dalszego
pipeline Laravela. Awaria rozruchu aplikacji albo wcześniejszych warstw
nie otrzymuje sztucznego kodu: strona 500 zachowuje instrukcję kontaktu,
ale kod i nagłówek mogą być nieobecne. Nie jest to dowód sprawności
monitoringu całego procesu PHP.

`finally` usuwa wyłącznie `request_id` z istniejących kanałów oraz ze
współdzielonego kontekstu przyszłych kanałów; inne pola zostają.
Atrybut żądania pozostaje dostępny przy późniejszym bezpośrednim
renderowaniu strony. **Po wyjściu z tego middleware** (np. wyjątek podczas
odwijania wcześniejszej warstwy, callback zakończenia odpowiedzi albo
streaming) nie obiecujemy pełnego łańcucha log–alarm–nagłówek–widok.

### Kolejka: żądanie, zadanie i osobna próba

`CorrelationServiceProvider` rejestruje hook tworzenia payloadu oraz
słuchaczy `JobProcessing` i `JobAttempted`. Nie trzeba dopisywać middleware
do każdego joba osobno. Koperta `kuking:correlation` przenosi wyłącznie
poprawny UUID `request_id` z aktywnego kontekstu — nigdy całą sesję,
kontekst logu ani atrybuty użytkownika. Zwykły UUID payloadu, już losowany
przez Laravel, jest `job_id`: nie dublujemy go drugą losową wartością.
Przy retry pozostaje stały. `attempt_id` jest nowym losowym UUID każdej
próby, aby dwa takie same błędy tego samego zadania dało się rozróżnić.

W logach zadania wszystkie trzy pola są w kontekście, a webhook nazywa
je `żądanie`, `zadanie` i `próba`. Zadanie wysłane z CLI nie ma
`request_id`; nie przejmuje go od poprzedniego zadania workera. Zadanie
wysłane podczas innego zadania dziedziczy bezpieczny kod źródłowego HTTP,
ale dostaje własne `job_id`. Payloady spoza standardowego mechanizmu
Laravela, bez poprawnego UUID zadania, dostają losowy kod bieżącego
wykonania; nie gwarantujemy w nich stałego `job_id` pomiędzy retry.

`QueueCorrelation` przywraca poprzedni kontekst w `JobAttempted`, także
po błędzie i przy zagnieżdżonym `sync`. Jest tu ważna kolejność Laravel 13:
worker najpierw emituje `JobAttempted`, **potem** raportuje wyjątek.
Dlatego same bezpieczne ID zostają przy obiekcie wyjątku w `WeakMap`.
Standardowy handler dodaje je do logu i jawnego kanału alarmu już po
sprzątnięciu kontekstu. Mapa nie utrzymuje wyjątku przy życiu i nie niesie
referencji do zadania, sesji ani requestu w swoich wartościach.

Wbudowany `Context` Laravela hydratuje całą własną kopertę do `extra`
rekordu przy `JobProcessing`. Nie zapewnia naszej wąskiej listy pól,
przywrócenia zagnieżdżonego zakresu przy `JobAttempted` ani zachowania
korelacji wyjątku raportowanego po tym zdarzeniu. Dlatego nie włączamy
automatycznego kopiowania całego kontekstu do naszej koperty.

Granica kolejki: obsługujemy zwykły cykl `database` workera i `sync`.
Nie obiecujemy alarmu po zabiciu procesu, OOM ani błędzie, który uniemożliwił
rozruch frameworka. Nie dodajemy nowych kolumn, retencji, APM ani usług.

### Weryfikacja i wycofanie

`KodBleduLaczyZadanieZAlarmemTest` przechodzi przez prawdziwy kernel HTTP,
czyta zapisany lokalnie log i przechwytuje wysyłkę przez `Http::fake()`.
Dwa wyjątki z tego samego miejsca mają różne UUID, ale jednakowy odcisk.
Test obejmuje kanał otwarty później, podrobiony nagłówek, JSON, 500 bez
wyjątku, granicę przed middleware i renderowanie bez bazy/manifestu.
Żadna próba nie wysyła alarmu produkcyjnego.

Kontrole ujemne (`scripts/kontrola-ujemna.sh`): usunięcie `$correlation`
z treści alarmu oblewa `BRAK_KORELACJI_ALARMU`; usunięcie
`Log::flushSharedContext()` oblewa `WYCIEK_KONTEKSTU_HTTP`. Po przywróceniu
oba testy znów przechodzą. Wycofanie tej zmiany kodu usuwa nowe pole,
nagłówek i akapit; nie ma migracji ani zmiany retencji danych.

`KorelacjaKolejkiTest` używa PostgreSQL, prawdziwie serializowanego joba
i **tego samego `Illuminate\Queue\Worker` przez kilka `runNextJob()`**:
błąd, inne zadanie, retry, końcowa porażka oraz zagnieżdżony `sync`.
Nie podstawia zdarzeń kolejki i nie używa `Queue::fake()`. Czyta plik
logu i przechwytuje rzeczywisty payload alarmu przez `Http::fake()`.
Sprawdza alarm po `JobAttempted`, brak ID w logach między zadaniami,
nowy `attempt_id` przy retry oraz zwolnienie wyjątku po usunięciu referencji
testowego kolektora Laravel. To pomiar workera w procesie PHP testu,
nie uruchomienie produkcyjnego workera ani pomiar wielu procesów.
Kontrole ujemne osobno usuwają propagację, mapę wyjątku i sprzątanie;
każda musi dać PASS → FAIL właściwej asercji → PASS po przywróceniu.

Rollback części kolejkowej: wycofać provider, kopertę i formatowanie tych
pól razem. Istniejące payloady pozostają wykonywalne: stary kod ignoruje
dodatkową kopertę; nowy obsługuje payload bez niej. Nie usuwać rekordów
`jobs` ani `failed_jobs` w ramach wycofania.

---

Ten dokument jest dla **właściciela**. Zakłada, że masz dostęp do panelu
Railway i konto na Discordzie (albo Slacku) — i nic więcej. Nie zakłada
znajomości Sentry, Monologa ani tego, jak Laravel loguje wyjątki.

## 0. Co dokładnie jest zepsute dziś

Gdy komuś wywali się strona (błąd 500), **nikt po naszej stronie się o tym
nie dowiaduje**. Pierwszym sygnałem byłaby wiadomość od użytkownika — a
audytorium 50+ częściej po prostu zamyka kartę, niż pisze zgłoszenie.
`docs/ROADMAP.md` §0 nazywa monitoring błędów fundamentem, nie ulepszeniem,
a serwis ma zaraz przyjąć pierwsze osoby.

**Docelowym wyborem jest Sentry** (`AGENTS.md` §3, tabela stacku).
Nie ma go dziś w kodzie z jednego, czysto technicznego powodu: w środowisku,
w którym ta funkcja powstała, `composer install` odbija się od proxy na
paczkach z GitHuba, więc nie da się uczciwie zaktualizować `composer.lock`
o `sentry/sentry-laravel`. To NIE jest decyzja produktowa przeciwko Sentry —
pełne uzasadnienie i warunek, kiedy to się zmieni: `docs/DECISIONS.md` D-041.

**Co jest zamiast tego:** kanał `blad_webhook` (`config/logging.php`).
Każdy prawdziwy błąd 500 (i każdy inny wyjątek, którego Laravel normalnie
nie ignoruje — 404, 419, „za dużo prób" i inne odpowiedzi 4xx **nie** trafiają
tutaj, bo Laravel z zasady ich nie raportuje) wysyła jedną wiadomość na
webhook, który przyjmuje format Slacka. Discord ma taki webhook wbudowany za
darmo, bez karty płatniczej — stąd instrukcja niżej.

**Zero nowej zależności Composera.** Monolog i klient HTTP są już częścią
Laravela — to jest dokładnie ten sam powód, dla którego dało się to zrobić
mimo zablokowanego `composer install`.

---

## 1. Załóż webhook na Discordzie (5 minut, bez karty płatniczej)

Jeśli już masz serwer Discorda dla siebie/zespołu, zacznij od kroku 2.

1. **Załóż serwer** (jeśli nie masz): w aplikacji Discord kliknij **+** na
   lewym pasku → „Utwórz własny" → „Tylko dla mnie". Nazwij go np. „Kuking —
   alerty". To jest prywatny serwer, nikt inny go nie widzi, dopóki nie
   wyślesz zaproszenia.
2. **Utwórz kanał** na tym serwerze, np. `#bledy-produkcja` (przycisk **+**
   przy liście kanałów).
3. Kliknij **ikonę koła zębatego przy nazwie kanału** → **Integracje** →
   **Webhooki** → **Nowy webhook**.
4. Nadaj mu nazwę (np. „Kuking — błędy") i skopiuj **adres webhooka**
   (przycisk „Kopiuj adres URL webhooka"). Wygląda tak:
   `https://discord.com/api/webhooks/1234567890/AbCdEf...`
5. **Dopisz `/slack` na końcu tego adresu.** To jest CAŁA różnica —
   Discord ma wbudowaną zgodność z formatem, którego używa Slack, i ten
   kanał z niej korzysta, żeby nie dokładać osobnej integracji. Adres
   końcowy wygląda tak:
   `https://discord.com/api/webhooks/1234567890/AbCdEf.../slack`
6. W panelu Railway: **Environment → Variables → Shared Variables** →
   dodaj `LOG_BLAD_WEBHOOK_URL` z wartością ze skrzynki 5, dla środowiska
   `production` (i osobno dla `staging`, jeśli tam też chcesz powiadomienia
   — może to być inny kanał Discorda, żeby nie mylić alarmów z produkcji
   z szumem testowym).
7. Zastosuj konfigurację (`railway config apply` — patrz
   `.railway/railway.ts`) i **zrestartuj serwis `web`**. Tak jak przy poczcie
   (`docs/infra/POCZTA_URUCHOMIENIE.md`): zmienna wczytuje się przy starcie
   kontenera (`php artisan optimize`), więc bez restartu wygląda, jakby
   działała, a nie działa.

Masz już swój prawdziwy Slack zamiast Discorda? Pomiń kroki 1–5 i wklej
**„Webhook URL"** z ekranu **Incoming Webhooks** swojej przestrzeni Slacka
(`api.slack.com/apps` → Twoja aplikacja → Incoming Webhooks) — bez żadnego
dopisywania na końcu.

### Sprawdzenie, że działa

Z powłoki produkcyjnej (`railway ssh`, tak jak przy sprawdzaniu poczty):

```bash
php artisan tinker --execute="report(new \RuntimeException('Test kanału błędów — zignoruj.'));"
```

Na kanale Discorda powinna pojawić się wiadomość w kilka sekund. Jeśli jej
nie ma: sprawdź, czy zmienna naprawdę doszła do kontenera
(`railway variables`) i czy serwis był zrestartowany PO jej ustawieniu.

---

## 2. Co zobaczysz

Jedna wiadomość na błąd, mniej więcej tak:

````
[Kuking/production] RuntimeException
app/Domain/Media/Actions/ProcessUploadedImage.php:88
POST /wpisy/{post}/zdjecia
odcisk: 7f1a3c92

Treść komunikatu zostaje w logu serwera — na webhook nie wychodzi.
```
app/Domain/Media/Actions/ProcessUploadedImage.php:88 App\Domain\Media\Actions\ProcessUploadedImage::wariant()
app/Jobs/ProcessUploadedImage.php:34 App\Jobs\ProcessUploadedImage::handle()
...
```
````

**KOMUNIKATU WYJĄTKU TU NIE MA i to jest celowe — poprawka z 9 września.**
Do tego dnia wiadomość niosła `$e->getMessage()`. Przy `QueryException`
komunikat buduje jednak STEROWNIK i wkłada w niego SQL razem z wartościami:

```
SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates
unique constraint "users_email_unique"
DETAIL: Key (email)=(ktos@example.com) already exists.
(Connection: pgsql, SQL: insert into "users" ("email","password", …)
 values (ktos@example.com, $2y$12$…, …))
```

Czyli adres e-mail i hash hasła — do usługi, nad którą nie mamy kontroli.
Znalazł to audyt zewnętrzny (A6-01). Kanał nigdy nie był włączony na
produkcji, więc nic nie wyciekło. Odfiltrowywanie danych z takiego tekstu
byłoby zgadywaniem, więc treść budujemy z LISTY DOZWOLONYCH PÓL.

**`odcisk`** to osiem znaków policzonych z klasy, pliku i linii. Ten sam błąd
ma zawsze ten sam odcisk, więc widać, czy to nowa awaria, czy dziesiąte
powtórzenie tej samej — i po czym szukać wpisu w logu serwera.

To wystarcza do pierwszej diagnozy — klasa błędu, kod (przy błędach bazy
SQLSTATE, np. `kod: 23505`), dokładna linia i WZORZEC trasy (nie prawdziwy
adres, patrz §3). **To NIE jest pełny log.**
Pełny wpis, z pełnym śladem stosu, dalej leży tam, gdzie zawsze — w logach
Railway (serwis → zakładka Logs), bo `LOG_CHANNEL=stderr`
(`.railway/railway.ts`). Webhook mówi „coś się zepsuło, sprawdź logi" —
niczego więcej nie zastępuje.

### Czego ten kanał NIE robi (żeby nie było niespodzianek)

- **Nie grupuje powtórzeń.** Ten sam błąd wywalający się 50 razy na minutę
  (np. zepsute zapytanie na często odwiedzanej stronie) to 50 wiadomości.
  Sentry grupuje i pokazuje „x50" — to jest jeden z powodów, dla których jest
  docelowym wyborem, nie tymczasowym.
- **Nie ma dashboardu ani historii poza tym, co zostaje na kanale Discorda.**
- **Nie łapie błędów JavaScriptu w przeglądarce** — tylko wyjątki po stronie
  serwera PHP.
- **Nie mówi, ilu ludzi to dotknęło** ani czy to jest ten sam człowiek, czy
  stu różnych.

### Od 10 września 2026: ten sam kanał dzwoni też o awariach, których żaden błąd 500 nie wywoła

Do tego dnia kanał dostawał wiadomość WYŁĄCZNIE przy nieobsłużonym wyjątku
(błąd 500 na żywej trasie). To zostawiało dziurę: brak kluczy Turnstile,
poczta bez transportu i zaległe zadania w `failed_jobs` **nie rzucają
żadnego wyjątku nigdzie w serwisie** — jedynym miejscem, które je w ogóle
widziało, była odpowiedź JSON pod `/health`, którą trzeba było otworzyć
samemu. `HealthController` teraz woła ten sam kanał wprost
(`Log::channel('blad_webhook')`), gdy któreś z jego sprawdzeń nie przejdzie.

Taka wiadomość wygląda inaczej niż przy błędzie 500 — krócej, bez śladu
stosu:

```
/health: kontrola „kolejka” nie przeszła (powód: zadania_nieudane).
```

**Ograniczenie częstotliwości.** Zewnętrzny monitoring (sekcja 6 niżej)
odpytuje `/health` co kilka minut — bez ograniczenia jedna trwająca awaria
zasypałaby kanał identycznymi wiadomościami. Ten sam rodzaj awarii dzwoni
najwyżej raz na 30 minut, a powrót do zdrowia od razu zeruje ten limit, więc
KOLEJNA awaria (nawet inna) dzwoni znowu natychmiast. To NIE jest grupowanie
w stylu Sentry (patrz akapit wyżej) — to jest wyłącznie zabezpieczenie przed
spamem z jednego, wciąż trwającego problemu.

**Które sprawdzenia to dziś:** `poczta` (czy `MAIL_MAILER` ma na produkcji
czym wysłać — ta sama klasa `App\Support\Poczta`, co `kuking:sprawdz-poczte`),
`kolejka` (czy w `failed_jobs` coś leży), `listy` (czy przepadł komuś list —
issue #234, D-062) i `turnstile` (D-050). `database` i `migrations` też
dzwonią — wcześniej ich 503 nie docierało na webhook w ogóle, bo
`HealthController` łapie ten wyjątek sam, w środku, i nigdy nie oddawał go
dalej do mechanizmu, który normalnie woła ten kanał.

**GDY DZWONEK NIE ZADZWONI, WIDAĆ TO W DZIENNIKU SERWERA.** Wysyłka na
webhook nie ma prawa rzucić (inaczej człowiek na stronie zamiast błędu 500
dostawałby wyjątek z samego mechanizmu powiadamiania) — ale do 10 września
2026 znaczyło to, że nie wiedział o tym NIKT, i to podwójnie: pusty `catch`
połykał wyjątek połączenia, a nieudane żądanie HTTP wyjątku nawet nie rzuca
(Discord z odwołanym webhookiem odpowiada 401/404 jako zwykłą odpowiedź).
Kanał wyciszony i kanał sprawny wyglądały identycznie. Teraz
`WebhookBleduHandler` zapisuje sam fakt niedodzwonienia się do dziennika
serwera („Nie udało się zadzwonić na webhook błędów. Wiadomość przepadła.",
kanał `single` — nigdy ten kanał, bo to byłaby pętla), a `/health` **oddaje
wtedy swój 30-minutowy odstęp**, więc następne odpytanie dzwoni jeszcze raz.
Jedna sekunda niedostępności Discorda nie kupuje pół godziny ciszy
o trwającej awarii.

### Co przychodzi na ten kanał z poczty (issue #234, D-062)

**Najpierw sprostowanie, bo tu było napisane za dużo.** Pierwsza wersja tej
sekcji obiecywała na tym kanale także wiadomość „Poczta: list przepadł i nikt
go już nie wyśle". Nieprawda: to jest zwykłe `Log::error()`, a ten kanał **nie
jest częścią stosu domyślnego** (`config/logging.php`: `stack` →
`LOG_STACK`, a `.env.example` ustawia `LOG_STACK=single`). Woła się do niego
JAWNIE — z `bootstrap/app.php` przy raportowaniu wyjątku, z
`App\Domain\Contact\DzwonekOperatora`, z ostrzeżenia o suficie poczty
i z `HealthController::powiadomWebhook()`. Sam poziom `error` nikogo nie
budzi.

Co więc naprawdę przychodzi tu z poczty:

| Wiadomość | Kiedy | Co zrobić |
|---|---|---|
| „Poczta: sufit «…» zużyty w N%…" | Zużycie dobowego sufitu przekroczyło `KUKING_POCZTA_PROG_OSTRZEZENIA` (domyślnie 80%) — raz na dobę na funkcję | Sprawdź, czy plan u dostawcy nadal wystarcza — `docs/decyzje/POCZTA.md` §4 |
| „/health: kontrola «listy» nie przeszła…" | Sonda `/health` zobaczyła nieodhaczony wiersz w `mail_failures` (`HealthController::powiadomWebhook()`, sekcja wyżej) | `php artisan kuking:nieudane-listy` — kategoria odmowy mówi, czy powtarzać |

A gdzie jest sam „list przepadł": w **dzienniku serwera** (`Log::error`
z `App\Poczta\ZapiszNieudanyList`) i — trwale — w **wierszu tabeli
`mail_failures`** oraz w polu `checks.listy` w `/health`, które trzyma
`degraded`, dopóki ktoś nie odhaczy.

**Ten kanał NIE JEST i nie może być jedynym śladem takiej awarii**: jest
warunkowy (`LOG_BLAD_WEBHOOK_URL`), a przy wyczerpanej puli listów pocztowy
alarm i tak by nie wyszedł. Dlatego obowiązkowe są wiersz w bazie i `/health`.
Uzasadnienie: **D-062 §3**.

### Dlaczego to nie ma własnego numeru decyzji

Bo nie jest nową decyzją, tylko wykonaniem czterech już podjętych: **D-041**
wybrało ten kanał zamiast Sentry, **D-057 §4** zapisało, że `failed_jobs` nie
widzi nikt, **D-050** że brak kluczy Turnstile musi być widoczny z zewnątrz,
a **D-062** że przepadły list zapala `/health`. Brakowało jednego połączenia:
`/health` wiedział o tych awariach i nie mówił o nich nikomu, kto sam nie
otworzył JSON-a. Nowy numer sugerowałby, że coś tu rozstrzygnięto na nowo —
a rozstrzygnięte było wszystko poza tym, gdzie postawić jedno wywołanie.
Jedyną prawdziwą decyzją z tej pracy jest **D-063** (PostHog: nie teraz),
i ona swój numer ma.


---

## 3. Bezpieczeństwo — czego w wiadomości NIE MA

AGENTS.md §7 zakazuje danych osobowych w logach, a to jest **jedyny log
w całym serwisie, który wychodzi do usługi zewnętrznej** (Discorda albo
Slacka) — dlatego to była najważniejsza część tej pracy, nie dodatek.

Wiadomość buduje `App\Logging\WebhookBleduHandler` ręcznie, z jawnie
wybranych pól wyjątku. W treści **nigdy** nie ma:

- treści formularza, ciasteczek, sesji ani nagłówków żądania,
- adresu IP,
- identyfikatora ani nazwy konta,
- **rzeczywistego adresu URL** — tylko wzorzec trasy z nazwami parametrów
  (`/wpisy/{post}/zdjecia`, nie `/wpisy/9f1c.../zdjecia`), dokładnie ta sama
  zasada, którą stosuje log limitu zapytań w `bootstrap/app.php`,
- **argumentów wywołań ze stosu.** To jest najbardziej podstępne miejsce na
  wyciek: `$wyjątek->getTrace()` w PHP potrafi zawierać dokładne wartości
  przekazane do funkcji po drodze — adres e-mail, hasło podane wprost, treść
  wiadomości. Ten handler buduje ślad stosu WYŁĄCZNIE z pliku, linii i nazwy
  funkcji — nigdy z argumentów. To jest też jedyny powód, dla którego kanał
  NIE korzysta z wbudowanego w Laravela sterownika `slack`: ten łączy się
  z pominięciem klienta HTTP Laravela i domyślnie dokleja do wiadomości cały
  kontekst logu, łącznie z obiektem wyjątku i jego pełnym śladem.

Test `tests/Feature/BladTrafiaNaWebhookBezDanychOsobowychTest.php` dowodzi
tego na żywych przykładach. Ma cztery przypadki, z których jeden wywołuje
PRAWDZIWE naruszenie unikalności w PostgreSQL przez prawdziwą trasę HTTP
i sprawdza, że ani adres e-mail, ani hash hasła, ani SQL zapytania nie
znajdują się w bajtach faktycznie wysłanych na webhook.

Ten przypadek jest wart osobnego zdania, bo pokazuje, jak wygląda test,
który niczego nie sprawdza. Trzy starsze przypadki rzucały
`RuntimeException` z komunikatem, który sami napisaliśmy — i dlatego przez
trzy dni nie wykryły niczego. Zakładały to, co stało w komentarzu klasy:
„komunikat wyjątku to tekst napisany przez kogoś z nas w kodzie". Przy
błędzie bazy pisze go sterownik.

**Czego to nadal nie kontroluje:** ślad stosu niesie nazwy klas i funkcji.
Gdyby ktoś nazwał klasę imieniem i nazwiskiem człowieka, poszłoby to dalej.
To jest teoretyczne, ale uczciwie: nie każde pole jest przez nas pisane.

**Zanim włączysz to na produkcji na stałe:** potwierdź z osobą odpowiedzialną
za dokumenty prawne, czy techniczna telemetria bez danych osobowych (jak
wyżej) wymaga wpisu w tabeli podprocesorów polityki prywatności. `D-024`
i `D-038` w `docs/DECISIONS.md` pilnują, żeby ta tabela nigdy nie mijała się
z prawdą — w żadną stronę. Sam adres webhooka traktuj jak sekret niezależnie
od odpowiedzi na to pytanie: kto go pozna, może pisać na Twój kanał.

---

## 4. Jak wyłączyć

Usuń (albo wyczyść) `LOG_BLAD_WEBHOOK_URL` w panelu Railway i zrestartuj
serwis. Bez tej zmiennej kanał jest **całkowicie martwy** — żadnego żądania
HTTP, żadnego wyjątku z samego mechanizmu powiadamiania. To jest ten sam stan,
w jakim serwis pracuje dziś, lokalnie i w każdym teście: żadna zmienna, żadna
zmiana zachowania.

Nie trzeba nic zmieniać w kodzie ani wdrażać nowej wersji — to jest
wyłącznik jednym kliknięciem w panelu, tak samo jak `KUKING_KLUCZ_WYSLANIA`
czy `KUKING_ZAUFANE_PRZESKOKI`.

---

## 5. Docelowo: Sentry

Sentry zostaje wyborem docelowym (`AGENTS.md` §3), bo webhook wyżej
nie robi trzech rzeczy, które przy większym ruchu zaczynają boleć:

1. **Grupowanie.** Jeden zepsuty fragment kodu wywalający się tysiąc razy
   dziennie to w Sentry jeden wpis z licznikiem, a na webhooku — tysiąc
   osobnych wiadomości zalewających kanał (i realnie: rate limit Discorda).
2. **Historia i wyszukiwanie.** Sentry pamięta błąd sprzed tygodnia, pokazuje
   trend i pozwala go przypisać do konkretnego wydania (`SENTRY_RELEASE`
   w `.railway/railway.ts` jest już przygotowany na SHA commita).
3. **Śledzenie wydajności** (traces) — częściowo przygotowane
   (`SENTRY_TRACES_SAMPLE_RATE`), zupełnie poza zasięgiem prostego webhooka.

### Jak przejść, gdy `composer install` znów zadziała

1. `composer require sentry/sentry-laravel` — to zaktualizuje
   `composer.lock` NAPRAWDĘ, przez Composera, a nie ręczną edycją.
2. `php artisan sentry:publish --dsn=<DSN z panelu Sentry>` — publikuje
   `config/sentry.php` i dopisuje wpis do `bootstrap/app.php`.
3. Zmienne `SENTRY_LARAVEL_DSN`, `SENTRY_ENVIRONMENT`,
   `SENTRY_TRACES_SAMPLE_RATE`, `SENTRY_PROFILES_SAMPLE_RATE` i
   `SENTRY_RELEASE` już istnieją w `.railway/railway.ts` — trzeba tylko
   ustawić `SENTRY_LARAVEL_DSN` jako zmienną sharedową (tak jak
   `LOG_BLAD_WEBHOOK_URL` w §1).
4. **Zaktualizuj `resources/legal/polityka-prywatnosci.md`** — dopisanie
   Sentry jako realnego podprocesora, TERAZ prawdziwe (D-024 usunęło je
   stamtąd, gdy było nieprawdą; wpisanie go z powrotem, gdy stanie się
   prawdą, jest tą samą zasadą, w drugą stronę).
5. **Kanał `blad_webhook` może zostać.** To jest tani, niezależny od
   trzeciej usługi kanał zapasowy — gdyby Sentry akurat nie odpowiadał albo
   ktoś nie sprawdzał dashboardu, telefon i tak zadzwoni. Nie ma powodu go
   kasować; jeśli okaże się zbędny, wystarczy wyczyścić
   `LOG_BLAD_WEBHOOK_URL` (§4) — kod może zostać bez użycia.

Pełne uzasadnienie decyzji „webhook zamiast Sentry na dziś" i warunek jej
zmiany: `docs/DECISIONS.md`, D-041.

---

## 6. Zewnętrzny monitoring dostępności (5 minut, bez karty płatniczej)

**To jest jedyny krok z tego dokumentu, którego repozytorium nie ma prawa
zrobić za Ciebie** — wymaga konta w usłudze, nad którą Kuking nie ma
kontroli, a `docs/DECISIONS.md` zakazuje dodawania dostawców bez Twojej
wyraźnej decyzji. Wszystko inne w tej sekcji (trasa `/health`, jej kody HTTP,
kanał `blad_webhook`) już działa — to jest wyłącznie klikanie w panelu.

### Dlaczego to jest obowiązkowe, nie „miło mieć”

**Railway monitoruje `/health` TYLKO przy wdrożeniu** — jeśli produkcja
padnie o 3 w nocy tydzień po ostatnim deployu, Railway się o tym nie
dowie i Ciebie nie powiadomi (`docs/infra/INFRA_DECISION.md` §11). Kanał
`blad_webhook` (§1 wyżej) dzwoni tylko wtedy, gdy KTOŚ akurat trafi na
zepsutą stronę i wywoła błąd 500 — awaria, na którą nikt akurat nie trafił
(bo strona w ogóle nie odpowiada), nie wygeneruje żadnego wyjątku do
zgłoszenia. Zewnętrzny monitor to jedyna rzecz, która pyta serwis, gdy NIKT
inny go nie odwiedza.

### Krok 1 — załóż konto (UptimeRobot, bez karty płatniczej)

1. Wejdź na **uptimerobot.com** → **Sign Up** → e-mail i hasło. Plan
   **Free** wystarczy w zupełności na jeden serwis.
2. Potwierdź adres e-mail (link w skrzynce).

Masz już konto **Better Stack** (dawniej Better Uptime) albo wolisz je od
UptimeRobot? Kroki 2–4 są tam analogiczne — nazwy przycisków się różnią,
sens nie.

### Krok 2 — dodaj monitor na `/health`

1. **+ Add New Monitor**.
2. **Monitor Type:** `HTTP(s)`.
3. **Friendly Name:** `Kuking — produkcja`.
4. **URL (or IP):** `https://kuking.pl/health`
   — **z `https://kuking.pl`, nie z adresu Railway** (`*.up.railway.app`).
   Monitorowanie przez `kuking.pl` sprawdza CAŁY łańcuch: DNS → Cloudflare →
   Railway → aplikację → bazę. Monitorowanie samego Railway pominęłoby
   awarię DNS-u albo Cloudflare, czyli dokładnie tę część, nad którą masz
   NAJMNIEJ bezpośredniej kontroli.
5. **Monitoring Interval:** najkrótszy, jaki plan Free oferuje w chwili
   zakładania konta (w przeszłości było to 5 minut w UptimeRobot). Jeśli
   zależy Ci na alarmie poniżej 2 minut od awarii (patrz Krok 4), sprawdź
   Better Stack — jego plan Free bywał szybszy.

### Krok 3 — jeden adres e-mail na wszystkie alerty

1. **Alert Contacts** → **Add Alert Contact** → typ `E-mail`.
2. Wpisz **jeden** adres, ustalony z osobą prowadzącą serwis — najlepiej
   ten sam, który `docs/infra/DEPLOYMENT_RUNBOOK.md` (KROK 0.4) już
   proponuje jako `alerty@kuking.pl`. Jeden adres, nie skrzynka prywatna
   pomieszana z kanałem Discorda z §1 — łatwiej pilnować JEDNEGO miejsca,
   w którym mają się pojawiać wszystkie alerty produkcyjne.
3. Włącz ten kontakt na monitorze z Kroku 2 (**Select Alert Contacts to
   Notify** przy edycji monitora).
4. Chcesz też SMS albo powiadomienie na telefon? UptimeRobot ma to na
   planach płatnych; **Better Stack ma bezpłatne powiadomienia push** przez
   swoją aplikację mobilną — rozważ to, jeśli e-mail w środku nocy nie
   obudzi nikogo.

### Krok 4 — próg alarmu: 2 minuty niedostępności

1. Przy monitorze: **Advanced Settings** (UptimeRobot) albo **Escalation
   Policy** (Better Stack).
2. Ustaw **liczbę potwierdzeń przed alertem („confirmations”) na 1**, nie
   na wartość domyślną wyższą niż 1 — każde potwierdzenie to jeden pełny
   interwał sprawdzania doczekany na nowo, więc 2–3 potwierdzenia przy
   interwale 5 minut oznaczałoby alarm dopiero po 10–15 minutach, a issue
   #33 prosi o alarm przy niedostępności **dłuższej niż 2 minuty**.
3. **Uczciwie o granicy tego kroku:** przy interwale sprawdzania co 5 minut
   (typowe minimum na darmowym planie) alarm w praktyce przyjdzie w oknie
   „chwilę po tym, jak minęły 2 minuty” do „za nieco ponad 5 minut od
   awarii”, zależnie od tego, w którym momencie cyklu sprawdzania awaria
   się zaczęła — nie da się tego obejść bez płatnego planu z krótszym
   interwałem. To wciąż nieporównywalnie lepiej niż stan dzisiejszy (zero
   alarmu, kiedykolwiek).

### Krok 5 — sprawdź, że działa, PRZED pierwszą prawdziwą awarią

1. W panelu monitora poczekaj na pierwszy zielony wynik (**Up**).
2. Wyłącz na chwilę serwis w Railway (**Settings → Sleep** albo krótki
   redeploy ze świadomie złą komendą startową na środowisku **staging**,
   nigdy na produkcji) i odczekaj jeden pełny interwał sprawdzania.
3. Alert powinien przyjść na adres z Kroku 3. Jeśli nie przyszedł: sprawdź,
   czy kontakt alertowy jest naprawdę PODPIĘTY do monitora (to jest osobny
   krok od samego jego dodania) i czy e-mail nie wylądował w „Promocjach”
   albo „Spamie”.
4. Włącz serwis z powrotem i poczekaj na **Up** — dobre monitory wysyłają
   też potwierdzenie powrotu do zdrowia; jeśli Twój to robi, powinno przyjść
   też ono.

### Co monitorować OPCJONALNIE: stan `degraded` (kod 200, nie 503)

Krok 2–5 wyżej łapie WYŁĄCZNIE całkowitą niedostępność (`/health` nie
odpowiada, DNS padł, kod inny niż 2xx/3xx) i krytyczne awarie bazy/migracji
(kod 503 — patrz „Co zwraca `/health`” niżej). **Nie łapie** stanu
`degraded` — Turnstile bez kluczy, poczta bez transportu, zaległe
`failed_jobs` — bo te sprawdzenia CELOWO zwracają kod 200 (patrz komentarz
klasy `HealthController`: healthcheck oddający 503 za każdą niekrytyczną
usterkę już raz położył ten serwis pętlą restartów). Te awarie i tak dzwonią
na kanał `blad_webhook` z §1 (jeśli `LOG_BLAD_WEBHOOK_URL` jest ustawiony) —
to jest wystarczające minimum. Jeśli chcesz mieć to WIDOCZNE też
w UptimeRobot/Better Stack:

1. Dodaj **drugi** monitor typu **Keyword** (nie HTTP(s)) na ten sam adres.
2. Ustaw słowo kluczowe na `"status":"ok"` i tryb **„Alert when keyword NOT
   found”** — `/health` zawsze zwraca to dokładne pole, gdy WSZYSTKO jest
   zdrowe (patrz przykład odpowiedzi niżej), więc jego zniknięcie znaczy
   `status: degraded`.
3. Ten monitor jest dodatkiem, nie zamiennikiem Kroku 2 — zostaw oba.

### Co zwraca `/health` — sprawdzone tym audytem, żeby dało się to bezpiecznie monitorować z zewnątrz

Odpowiedź jest **publiczna i bez uwierzytelnienia** (Railway i zewnętrzny
monitoring muszą móc ją odpytać, zanim cokolwiek się zaloguje) — dlatego to
poniżej zostało celowo sprawdzone testami (`tests/Feature/
HealthNieZdradzaSzczegolowTest.php`), nie tylko przeczytane w kodzie:

- **Kod HTTP:** `200` gdy wszystko działa albo gdy awaria dotyczy tylko
  rzeczy niekrytycznych (Turnstile, poczta, kolejka, zdjęcia — pole
  `status` mówi wtedy `degraded`); **`503`** wyłącznie gdy baza nie
  odpowiada albo migracje nie zostały dokończone. To jest kod, po którym
  ma alarmować monitor HTTP(s) z Kroku 2.
- **Czego w odpowiedzi NIE MA, nawet przy awarii:** adresu hosta bazy,
  portu, nazwy bazy, nazwy użytkownika, hasła, kodu SQLSTATE, ścieżek na
  dysku serwera ani treści żadnego wyjątku. Pole `error` przy każdej
  awarii to jeden z zamkniętego zbioru krótkich kodów
  (`HealthController::POWODY`) — coś w rodzaju `baza_nie_odpowiada` albo
  `turnstile_bez_kluczy`. Szczegół techniczny zostaje wyłącznie w logu
  serwera, do którego dostęp ma tylko właściciel.
- Przykład zdrowej odpowiedzi: `{"status":"ok","app":"Kuking",
  "environment":"production","time":"…","checks":{"database":{"ok":true},
  "migrations":{"ok":true},"media":{"ok":true},"turnstile":{"ok":true},
  "poczta":{"ok":true},"kolejka":{"ok":true}}}`.

---

## 7. Inwentarz alertów — co dzwoni dziś, a co tylko istnieje (issue #599)

**Sprawdzone 17 września 2026.** Ten rozdział istnieje, bo dokument opisujący
mechanizm i mechanizm faktycznie działający to dwie różne rzeczy — dokładnie
ta pomyłka, przed którą `AGENTS.md` §3 ostrzega przy tabeli stacku (wiersz
„Monitoring" opisywał Sentry, którego w projekcie nigdy nie było).

Kolumna „dowód" mówi, skąd wiadomo. Puste pole znaczyłoby `NIE WIEMY`,
a `NIE WIEMY` jest nieprzejściem bramki, nie sukcesem (`docs/OTWARCIE.md`).

| Sygnał | Mechanizm | Stan na 17.09.2026 | Dowód |
|---|---|---|---|
| błąd 500 na produkcji | kanał `blad_webhook`, wołany jawnie z `bootstrap/app.php` | **KOD JEST, NIE DZWONI** | w usłudze produkcyjnej **nie ma zmiennej `LOG_BLAD_WEBHOOK_URL`** — odczyt listy zmiennych przez API Railway, `sealedVariableNames` puste, więc brak nazwy znaczy brak zmiennej |
| awaria bazy / niedokończone migracje | `/health` → HTTP 503 | DZIAŁA, ale **nikt z zewnątrz nie pyta** | `/health` sprawdzone testami; zewnętrznego monitora nie ma (`docs/OTWARCIE.md` wiersz 11) |
| awaria niekrytyczna (poczta, zdjęcia, Turnstile) | `/health` → `status: degraded` | DZIAŁA, ale **stoi na czerwono na stałe** — patrz niżej | log wdrożenia `fa8f012e`, 17.09.2026 19:58:19 UTC |
| brak aktualnej kopii bazy | `kuking:sprawdz-kopie`, codziennie 06:15 | **WYŁĄCZONA Z POWODU BRAKU KONFIGURACJI** — komenda kończy się sukcesem i milczy | w usłudze produkcyjnej nie ma `AWS_KOPIE_BUCKET`; umowa „brak zmiennej = zero efektu" jest opisana w `config/kuking.php` |
| nieudany przebieg kopii | alarm z serwisu `kopia-bazy` | **SERWISU NIE MA** | inwentaryzacja środowiska production: dwie usługi, `Postgres` i `kuking.pl` |
| martwe zadania (świeże `failed_jobs`) | `kuking:sprawdz-kolejke`, co 15 min | **NOWE** (ten dokument) — dostarczenie sprawdzone lokalnie | §7.2 niżej |
| opóźnienie kolejki / martwy worker | `kuking:sprawdz-kolejke`, co 15 min | **NOWE** — wcześniej nie mierzyło tego NIC | §7.2 niżej |
| wyczerpywanie połączeń PostgreSQL | `kuking:budzet-polaczen`, co godzinę | **NOWE** — progi i wyprowadzenie w `docs/DATABASE.md` | §7.2 niżej |
| awaria całej aplikacji (strona nie odpowiada) | zewnętrzny monitor `/health` | **NIEZROBIONE** | §6 wyżej opisuje, jak to założyć; to jest czynność właściciela |
| stojący harmonogram (milkną wszystkie czujki) | `kuking:puls-harmonogramu` co 5 min → zewnętrzny monitor *heartbeat* | **KOD JEST, WYŁĄCZONY** bez `KUKING_PULS_HARMONOGRAMU_URL` (dopisane 25.09.2026) | [`MONITORING_599_KROKI.md`](MONITORING_599_KROKI.md) §B3 |

### 7.1. Dlaczego `/health` przestał odróżniać awarię od jej braku

Pole `kolejka` w `/health` liczy **wszystkie** wiersze w `failed_jobs`
i przy liczbie większej od zera stawia serwis w `degraded`. W tabeli leżą
cztery zadania z **9 września 2026** (wszystkie `UstawienieNowegoHasla`:
jeden `UnsupportedSchemeException`, dwa `TimeoutExceededException`, jeden
`TransportException` — odczyt z produkcji zapisany w komentarzu do #599).

Skutek: od tamtego dnia `/health` jest w `degraded` **nieprzerwanie**. Widać
to w logu wdrożenia z 17.09.2026 19:58:19 UTC — ten sam komunikat, ta sama
czwórka, tydzień później. **Piąte zadanie, które padnie dziś w nocy, nie
zmieni w tej odpowiedzi ani jednego znaku.** Sygnał, który świeci zawsze,
nie niesie informacji, a monitor zewnętrzny z §6 nauczyłby się go ignorować,
zanim w ogóle powstał.

To NIE jest usterka `/health` — pole odpowiada dokładnie na pytanie, które
zadaje („czy w tabeli coś leży"). Usterką jest brak drugiego sygnału, który
pyta o **zdarzenie**. Dlatego powstała czujka z §7.2, a `/health` zostaje
bez zmian.

**Czego NIE robimy przy okazji:** nie ponawiamy i nie kasujemy tych czterech
zadań. Żeton resetu hasła wygasa `config/auth.php` → `expire` minut od
wystawienia, więc zbiorowe `queue:retry` po tygodniu wysłałoby czterem
osobom martwy link. Rozliczenie tabeli jest osobną czynnością na produkcji
(`php artisan kuking:martwe-zadania`, bez `--skasuj` niczego nie usuwa)
i należy do właściciela, nie do tej zmiany. **Dopisek z 25.09.2026:** od tego
dnia wiersze starsze niż 30 dni kasuje harmonogram (`queue:prune-failed
--hours=720`, decyzja właściciela), więc te cztery zadania znikną same około
10 października 2026 — rozliczenie z odbiorcami trzeba zrobić przed tą datą.

### 7.2. Trzy nowe czujki i jak sprawdzono, że naprawdę wysyłają

Wszystkie trzy używają **tego samego kanału** co błędy 500 (`blad_webhook`,
D-041). Świadomie nie dokładamy drugiej platformy monitoringu — drugie
miejsce do patrzenia jest drugim miejscem do niepatrzenia.

| Komenda | Częstość | Kiedy dzwoni |
|---|---|---|
| `kuking:sprawdz-kopie` | codziennie 06:15 | brak świeżej kopii (dziś: wyłączona brakiem bucketu) |
| `kuking:budzet-polaczen` | co godzinę, minuta 25 | zajętych backendów powyżej progu (50 / 125) |
| `kuking:sprawdz-kolejke` | co 15 minut | zaległość ≥ 600 s, zawieszona rezerwacja, albo zadanie, które padło w ostatnich 3 h |

**Dostarczenie sprawdzone na prawdziwym odbiorniku HTTP**, nie na atrapie
w teście — lokalny serwer zapisujący każde żądanie, baza `kuking_599_odbiornik`
na `127.0.0.1:55439`, 17.09.2026:

| Próba | Oczekiwane | Wynik |
|---|---|---|
| kolejka pusta | cisza | 0 wiadomości, kod wyjścia 0 |
| zadanie czeka 900 s | jedna wiadomość | 1 wiadomość, kod wyjścia 1 |
| ta sama awaria drugi raz | cisza (okno ciszy) | nadal 1 wiadomość |
| kolejka wraca do normy | **jedna** wiadomość odwołująca | 2 wiadomości, kod wyjścia 0 |
| kolejny spokojny przebieg | cisza | nadal 2 wiadomości |
| połączenia w normie | cisza | nadal 2 wiadomości |
| połączenia powyżej progu | jedna wiadomość | 3 wiadomości, kod wyjścia 1 |

Dwa wiersze dopisane 18.09.2026, na tym samym rodzaju odbiornika
(`127.0.0.1`), po poprawce wyciszania z warstwy 5 (§7.4):

| Próba | Oczekiwane | Wynik |
|---|---|---|
| odbiornik oddaje HTTP 404 | żadnego wyciszenia | 1 żądanie, czujka mówi „nie doszło”, pamięć bez dostarczenia |
| ten sam stan minutę później | bez ponawiania w pętli | nadal 1 żądanie |
| ten sam stan 6 minut później, odbiornik już oddaje 200 | wiadomość dochodzi | 2 żądania, czujka mówi „przyjęte” |
| ten sam stan zaraz po przyjęciu | cisza (okno 6 h) | nadal 2 żądania |

Ten sam pomiar na kodzie sprzed poprawki: **jedno żądanie, HTTP 404,
czujka zamilkła** — sprawny dzwonek trzy przebiegi później już nie wyszedł.

Kontrola ujemna treści na tych samych dostarczonych wiadomościach: nie ma
w nich nazwy klasy zadania z `payload`, adresu e-mail z `payload`, treści
`exception` ani nazwy bazy.

**Ta sama próba wykryła usterkę, której nie widział żaden test z `Http::fake`:**
`WebhookBleduHandler` sam dokleja nagłówek `[nazwa/środowisko]`, a klasy
alarmu doklejały go drugi raz — dostarczana wiadomość zaczynała się od
`[Kuking/local] [Kuking/local] …`. Asercje typu „treść zawiera X" przechodzą
przy podwojeniu bez mrugnięcia. Naprawione w `AlarmPolaczen` i `AlarmKolejki`,
a pilnuje tego teraz asercja liczby wystąpień nagłówka w treści, która
naprawdę poszła.

`App\Domain\Kopie\AlarmKopii` miał dokładnie tę samą usterkę i nie był
naprawiony w tej zmianie, bo pracował nad tym plikiem równoległy pakiet
#193/#594. **Naprawiono go tam** — razem z tą samą asercją na liczbę
wystąpień nagłówka w `CichyBrakKopiiBazyDajeAlarmTest`. Wszystkie trzy klasy
alarmu wysyłają dziś nagłówek dokładnie raz, i każda ma na to strażnika.

Warto zapamiętać sam wzorzec, bo nie dotyczy on wyłącznie nagłówka:
**test na atrapie klienta HTTP sprawdza, co program CHCIAŁ wysłać, a nie co
dotarło.** Dopóki asercje mają kształt „treść zawiera X", podwojenie,
obcięcie i zła kolejność przechodzą przez nie bez śladu.

### 7.3. Czego ten rozdział NIE dowodzi

**Że na produkcji zadzwoni cokolwiek.** Dopóki `LOG_BLAD_WEBHOOK_URL` nie
istnieje w usłudze, wszystkie trzy czujki liczą, zapisują w dzienniku
i **nie wysyłają nic** — świadomie, zgodnie z umową „brak zmiennej = zero
efektu". Wszystkie pomiary wyżej są lokalne.

Kolejność zamykania tej bramki:

1. właściciel zakłada webhook (§1 tego dokumentu) i wpisuje
   `LOG_BLAD_WEBHOOK_URL` w panelu Railway — **z restartem usługi**,
   bo konfiguracja jest zapiekana przy starcie kontenera;
2. `railway ssh -- php artisan kuking:sprawdz-alarm` — jedno polecenie,
   jedna wiadomość próbna, jasna odpowiedź na pytanie „czy DOCHODZI";
3. rozliczenie czterech zadań z 9 września, żeby `/health` wyszedł
   z `degraded` i znowu coś znaczył;
4. zewnętrzny monitor `/health` z §6.

Do wykonania kroku 1 **nie ogłaszamy działającego alarmu produkcyjnego** —
ani tutaj, ani w `docs/OTWARCIE.md`, ani w opisie Pull Requesta.

### 7.4. Pięć warstw, które łatwo pomylić ze sobą

To jest jedyny powód, dla którego ten rozdział ma tyle zastrzeżeń. Monitoring
tego serwisu składa się z pięciu rzeczy i **każda działa albo nie działa
osobno**:

| # | Warstwa | Stan na 18.09.2026 | Czym udowodniona |
|---|---|---|---|
| 1 | kod czujki | działa | testy + kontrole ujemne |
| 2 | konfiguracja produkcji | **brak** | w usłudze nie ma `LOG_BLAD_WEBHOOK_URL` |
| 3 | faktyczne wywołanie | działa | log produkcji: `Running [kuking:budzet-polaczen] … DONE`, 17.09 23:25:20 UTC |
| 4 | **odebranie wiadomości** | **niesprawdzone na produkcji** | lokalnie: `Http::fake()` oraz lokalny odbiornik HTTP na 127.0.0.1 — kanał PRZYJĄŁ (2xx), co nie dowodzi, że człowiek to zobaczył |
| 5 | wyciszanie duplikatów i powrót do normy | **poprawione, zmierzone lokalnie** | testy + kontrole ujemne. Cisza (`cisza_godzin`) należy się WYŁĄCZNIE wiadomości, którą kanał potwierdził odpowiedzią 2xx. Do 18.09.2026 było inaczej: `AlarmPolaczen` i `AlarmKolejki` uznawały wysyłkę za udaną na sam brak wyjątku, więc jedna odpowiedź 404 zapisywała pamięć wyciszania i zagłuszała następny, SPRAWNY dzwonek o wciąż trwającej awarii. Poprawione i zmierzone na odbiorniku na 127.0.0.1 (§7.2) — na produkcji nie zmienia to nic, dopóki warstwa 2 jest pusta |

Zielona warstwa 1 i 3 przy pustej 2 daje dokładnie to, co produkcja ma dziś:
czujkę, która sumiennie chodzi co godzinę i **nie ma dokąd zadzwonić**.
Najłatwiejszy błąd w tym miejscu to uznać wdrożenie kodu za wdrożenie alarmu.

**Co po poprawce znaczy „cisza” w warstwie 5.** Okno ciszy (`cisza_godzin`)
liczy się od chwili, w której kanał POTWIERDZIŁ przyjęcie wiadomości — nie od
chwili, w której próbowaliśmy ją wysłać. Próba, której kanał nie potwierdził,
daje wyłącznie kilkuminutową przerwę między żądaniami: tyle, żeby martwy webhook
nie dostawał żądania w pętli, i za mało, żeby pominąć choć jeden przebieg czujki.
Dzięki temu pierwszy dzwonek po powrocie kanału do życia dochodzi, zamiast
wpaść w ciszę kupioną przez porażkę. Tak samo traktowane jest odwołanie
„wróciło do normy”: nieprzyjęte nie kasuje pamięci alarmu, a alarm, który do
nikogo nie doszedł, nie dostaje odwołania w ogóle.

**Doprecyzowanie po odbiorze #687 — 18 września 2026.** Pamięć w wersji 2
rozdziela obserwowany stan, ostatnią próbę (stan i czas), ostatni przyjęty
alarm (stan i czas) oraz termin ciszy bieżącego epizodu. Odrzucona eskalacja
nie usuwa wcześniejszego przyjętego ostrzeżenia: późniejsze odwołanie nazywa
właśnie ten przyjęty stan. Zaobserwowany spokój kończy ciszę także przy
wyłączonym kanale albo nieprzyjętym odwołaniu. Nawrót jest nową informacją;
jego odrzucona próba nie przywraca ciszy poprzedniego epizodu. Po nieudanej
próbie ponowienie tego samego stanu czeka pięć minut; po przyjęciu alarmu
obowiązuje długa cisza. Zmiana stanu jest wysyłana od razu.

Stare `o` oznacza tylko próbę, nigdy przyjęcie. Format z `dostarczony_o`
zachowuje przyjęty alarm do odwołania i krótki odstęp między próbami, ale
nie odtwarza długiej ciszy: historyczny zapis nie rozróżniał poprawnie
nawrotu od trwającej awarii. Migracja pamięci może więc dać jedną dodatkową
wiadomość. Czyszczenie lub wygaśnięcie cache nadal może zgubić odwołanie;
równoczesne wysyłki nie są serializowane. Nie dodano trwałej kolejki doręczeń.

Lokalny dowód tego uzupełnienia: `EpizodyAlarmowTest` sprawdza 12 scenariuszy
dla obu klas (24 testy), a wraz z `NieudanyDzwonekNieKupujeCiszyTest`:
**46 testów / 237 asercji**. Transport jest atrapą `Http::fake()`, cache
działa w pamięci; przebieg nie używa bazy i nie wysyła prawdziwego webhooka.
Osiem fizycznych kontroli ujemnych (cztery zmiany osobno w każdej klasie)
obaliło właściwą asercję: utrata przyjętego alarmu, odtworzenie starej ciszy,
uznanie starej próby za przyjęcie oraz pomijanie obserwacji przy wyłączonym
kanale. Po każdej przywrócono bajty i mtime z kopii poza repo; po całej serii
24 nowe testy przeszły ponownie. To nie jest odbiór infrastruktury ani dowód,
że człowiek otrzymał alarm produkcyjny. Pełny hook i CI tego uzupełnienia
pozostają do wykonania przez koordynatora.

**I to nadal jest tylko przyjęcie.** Kod 2xx znaczy „usługa przyjęła
wiadomość”, nie „człowiek ją zobaczył”. Tej drugiej rzeczy nie sprawdza ani ten
mechanizm, ani żaden test — zależy od tego, na który kanał Discorda albo
Slacka wskazuje webhook i kto go obserwuje.

**Warstwę 4 domyka jedno polecenie — o tyle, o ile kanał potwierdzi przyjęcie:**

```bash
php artisan kuking:sprawdz-alarm
```

Wysyła JEDNĄ wiadomość, jawnie oznaczoną jako próba, ze znacznikiem czasu —
tym samym kanałem, którym poszedłby prawdziwy alarm. Nie dotyka bazy, nie
czyta kolejki i **nie zapisuje pamięci wyciszania**, więc nie zagłusza
prawdziwego alarmu, który mógłby przyjść zaraz po niej. Przy pustej zmiennej
kończy się błędem i mówi wprost, czego brakuje, zamiast milczeć.

Wcześniejsza wersja tej listy kazała w kroku 3 „wywołać kontrolowaną awarię
przez zaniżenie progu na produkcji". To był zły pomysł: zaniżony próg zostaje
w zmiennych, a prawdziwy alarm ginie potem w szumie. Osobna komenda robi
dokładnie jedną rzecz i nie zostawia po sobie stanu.

`--bez-wysylki` odpowiada wyłącznie na pytanie, czy kanał jest skonfigurowany.
**Sama konfiguracja nie jest dowodem dostarczenia** — to jest właśnie różnica
między warstwą 2 a 4.

### 7.5. Droga ODCZYTU: `/admin/kolejka` (issue #599)

Wszystko wyżej w tym rozdziale opisuje **wysyłanie**: czujkę, która dzwoni.
Ten punkt opisuje rzecz odwrotną — pytanie zadane z własnej woli, wtedy,
kiedy ktoś już wie, że coś jest nie tak.

**Problem, który to zamyka.** `/health` mówi `degraded` z powodem
`zadania_nieudane` i nie podaje ani liczby, ani klasy zadania: publiczna
odpowiedź niesie sam kod (`HealthController::sprawdzKolejke()`). Odpowiedź
na pytanie „KTÓRE zadanie padło" miały wyłącznie `kuking:martwe-zadania`
i `kuking:kto-nie-dostal-listu`, czyli komendy z **powłoki serwera**.
Na Railway powłoki nie ma (`proc_open` wyłączony w `docker/php.ini`),
a dostępu do bazy produkcyjnej nie ma nikt. Stan trwał od 9 września 2026
i nikt nie umiał powiedzieć, co go trzyma — nie z braku narzędzia, tylko
dlatego, że jedyne narzędzie stało po drugiej stronie ściany.

**Co to jest.** Jeden ekran `GET /admin/kolejka`, bez `{parametru}` w adresie
i bez jednej metody `POST`. Pokazuje:

| Blok | Skąd | Odpowiada na pytanie |
|---|---|---|
| ramka górna | `App\Domain\Kolejka\StanKolejki` (§7.2) | czy coś psuje się TERAZ i czy worker żyje |
| lista grup | `App\Domain\Kolejka\NieudaneZadania` | co zalega w `failed_jobs` i co je przewróciło |

W grupie: nazwa klasy zadania, **nazwa klasy wyjątku**, liczba wierszy,
najstarsza i najnowsza data.

**Kto wchodzi.** Rola `admin`, przez `UserPolicy::diagnozujKolejke()`.
Middleware `auth` + `moderator` + `moderator.2fa` pilnuje wejścia do panelu,
ale **autoryzacją jest Policy** — moderator z potwierdzonym 2FA dostaje tu
403. Ekran mówi, co psuje się w infrastrukturze, a to jest praca osoby
prowadzącej wdrożenie, nie osoby moderującej treści (D-039).

**Czego tam nie ma i nie będzie.** Ładunku zadania i treści wyjątku.
W `failed_jobs.payload` leży **żywy żeton** logowania albo resetu hasła,
a `exception` to ślad stosu, który w Laravelu potrafi nieść argumenty
wywołań — czyli ten sam żeton i adres e-mail (audyt A6-01). Z kolumny
`exception` odcinane jest **wszystko po pierwszym dwukropku**, zanim
cokolwiek innego się z nią stanie; obie nazwy przechodzą jeszcze przez filtr
kształtu nazwy klasy PHP, więc cokolwiek innego wychodzi jako `?`.
Pilnuje tego `tests/Feature/PanelKolejkiZadanTest.php` — z kontrolą dodatnią,
czyli asercją, że żeton i znacznik śladu stosu NAPRAWDĘ leżą w bazie.

**Ekran wyłącznie czyta.** Żadnego „ponów" i żadnego „skasuj": zbiorcze
`queue:retry` na starym żetonie resetu hasła wysyła człowiekowi martwy link,
a skasowany wiersz to skasowany jedyny ślad po awarii. Obie decyzje zostają
w `kuking:martwe-zadania`, gdzie podejmuje je człowiek po zobaczeniu, kogo
dotyczą. Wyjątkiem są wiersze starsze niż 30 dni: te od 25.09.2026 kasuje
harmonogram (`queue:prune-failed --hours=720`, decyzja właściciela,
`docs/DECISIONS.md`, sekcja „TOKEN W BAZIE LEŻY WYŁĄCZNIE JAKO SKRÓT”).

**Dlaczego nie log.** Bo `LOG_LEVEL` na produkcji bywa ustawiony na
`warning`, a wszystko na poziomie `info` przepada po drodze. Przyrząd oparty
o dziennik byłby przyrządem, który milczy. Ten ekran czyta bazę przy każdym
wejściu i nie zależy od poziomu logowania ani od `LOG_BLAD_WEBHOOK_URL`.

**Czego to NIE rozwiązuje.** Nie mówi, KOGO dotyczyły te zadania — imię,
adres i liczba różnych osób zostają w komendach, bo tam wymagają decyzji
człowieka i nie wychodzą do przeglądarki. Nie rozlicza też tabeli: `/health`
będzie mówić `degraded`, dopóki ktoś świadomie tych wierszy nie usunie.

---

## 8. Sonda `alarmy_moderacji` — pilny alarm moderacyjny nie dotarł (issue #1051)

Sprawa **pilna** to oznaczenie automatu w jednej z dwóch kategorii, przy
których doba zwłoki jest realną szkodą: treść seksualna i cokolwiek
dotyczącego dziecka (D-055). O takiej sprawie ma natychmiast wyjść list na
`KUKING_MODEL_ALARM_EMAIL`. Gdy nie wyszedł, wiersz w `reports` ma
`alarm_pilny_stan` = `zalegly` / `nieudany` / `bez_adresu`
i pusty `alarm_pilny_zlecony_at`, a `/health` zwraca `status: degraded`
(kod 200 — sonda nie jest krytyczna i nie restartuje serwisu).

| Kod w `checks.alarmy_moderacji.error` | Co znaczy | Co zrobić |
|---|---|---|
| `kanal_alarmowy_wylaczony` | Jest otwarta pilna sprawa bez alarmu, a `KUKING_MODEL_ALARM_EMAIL` jest **pusty** — kanał alarmowy nie istnieje. To nie jest błąd kodu i nie naprawi go ponowienie. | Wpisz adres w zmiennych usługi na Railway i wdróż. **Nic więcej:** komenda `kuking:doslij-pilne-alarmy` (co godzinę, minuta 35) dośle zaległe listy sama, najpóźniej w godzinę, a sonda zgaśnie po jej przebiegu. Do tego czasu kod zmieni się na `pilny_alarm_nie_dotarl` — to znaczy „adres jest, list jeszcze czeka na przebieg", nie nowa awaria. Obejrzyj sprawy w `/admin/sygnaly` — nie czekaj na list. |
| `pilny_alarm_nie_dotarl` | Adres jest ustawiony, a pilna sprawa nadal nie ma zleconego alarmu: worker zginął między zapisem sprawy a listem (`zalegly`) albo zlecenie listu rzuciło wyjątkiem (`nieudany`, wyjątek poszedł do `report()`). | Otwórz `/admin/sygnaly` i obejrzyj sprawę od razu. Komenda ponawia co godzinę; jeśli kod trzyma się dłużej niż godzinę, zlecenie listu pada za każdym razem — przebieg harmonogramu kończy się wtedy błędem (kod 1) i trafia do zgłaszania błędów. Sprawdź pocztę (`checks.poczta`, `checks.listy`) i log. Ręcznie: `php artisan kuking:doslij-pilne-alarmy`. |
| `slad_alarmow_niesprawdzalny` | Nie dało się zapytać o ślad — najczęściej kolumn `reports.alarm_pilny_*` jeszcze nie ma (kod wdrożony przed migracją). | Dokończ migrację. |

### Kiedy sonda gaśnie — reguła

Liczy się wyłącznie sprawa, która jest jednocześnie:

1. **otwarta** (`open`, `triage`, `reviewing`) — zamknięcie sprawy w panelu
   (rozstrzygnięcie albo „to nic takiego") gasi sondę. Człowiek ją obejrzał,
   pytanie „czy ktoś o niej wie" ma odpowiedź. Ślad, że kanał wtedy nie
   zadziałał, zostaje w bazie (`Report::pilneBezAlarmu()`);
2. **młodsza niż 72 godziny** od powstania
   (`kuking.moderation.model.alarm_sonda_godzin`). 72, nie 24 — sprawa
   z piątku wieczorem świeci jeszcze w poniedziałek rano. Starsza sprawa
   nadal leży w kolejce panelu i w porannym podsumowaniu automatu, a komenda
   nadal próbuje ją dosłać; sonda przestaje tylko trzymać `degraded`.
   Światło, którego nie da się zgasić inaczej niż SQL-em, operator uczy się
   ignorować.

Komenda i sonda patrzą na ten sam zbiór (`Report::pilneDoDoslania()`),
żeby nie było sprawy, przy której sonda świeci, a komenda jej nie ruszy.
Kod powodu wynika z **bieżącej** konfiguracji, nie z zapisanego stanu —
po wpisaniu adresu sonda nie odsyła już do ustawień, które są poprawione.

### Dlaczego nie wyjdą dwa listy o tej samej sprawie

`AlarmujModeratora::doslij()` zajmuje wiersz warunkowym `UPDATE` i zleca
list w tej samej transakcji (kolejka `database` — zadanie wysyłki to wiersz
w `jobs` tej samej bazy). Dwa przebiegi naraz (stary i nowy kontener przy
wdrożeniu) — dostaje go dokładnie jeden. Awaria zlecenia cofa zajęcie
i zostawia stan `nieudany` do następnego przebiegu. Limit partii:
`kuking.moderation.model.alarm_partia` (50) — żeby dzień z długą awarią
kanału nie zjadł dobowego limitu poczty.

Testy: `tests/Feature/DosylaniePilnychAlarmowTest.php`,
`tests/Feature/PilnyAlarmModeracyjnyNieGinieTest.php`.
