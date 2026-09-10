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
wybrało ten kanał zamiast Sentry, **D-042** zapisało, że `failed_jobs` nie
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
