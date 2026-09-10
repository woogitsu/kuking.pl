# Monitoring błędów — webhook na Slack/Discord (i docelowo Sentry)

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
pełne uzasadnienie i warunek, kiedy to się zmieni: `docs/DECISIONS.md` D-040.

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
POST /wpisy/{post}/zdjecie
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
| „/health: kontrola «listy» nie przeszła…" | Sonda `/health` zobaczyła nieodhaczony wiersz w `mail_failures`. **Dzwoni dopiero z PR #255** (`HealthController::powiadomWebhook()`) | `php artisan kuking:nieudane-listy` — kategoria odmowy mówi, czy powtarzać |

A gdzie jest sam „list przepadł": w **dzienniku serwera** (`Log::error`
z `App\Poczta\ZapiszNieudanyList`) i — trwale — w **wierszu tabeli
`mail_failures`** oraz w polu `checks.listy` w `/health`, które trzyma
`degraded`, dopóki ktoś nie odhaczy.

**Ten kanał NIE JEST i nie może być jedynym śladem takiej awarii**: jest
warunkowy (`LOG_BLAD_WEBHOOK_URL`), a przy wyczerpanej puli listów pocztowy
alarm i tak by nie wyszedł. Dlatego obowiązkowe są wiersz w bazie i `/health`.
Uzasadnienie: **D-062 §3**.

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
  (`/wpisy/{post}/zdjecie`, nie `/wpisy/9f1c.../zdjecie`), dokładnie ta sama
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
zmiany: `docs/DECISIONS.md`, D-040.
