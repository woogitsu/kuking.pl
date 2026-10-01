## D-041 · Błędy 500 dziś idą webhookiem na Slack/Discord, nie Sentry

**Data:** 8 września 2026 · Status: **obowiązuje do czasu, aż `composer
install` znów zadziała w środowisku pracy**

`docs/ROADMAP.md` §0 nazywa monitoring błędów fundamentem, a do dziś strona
mogła wywalić się na 500 i nikt po naszej stronie by się o tym nie dowiedział
— pierwszy sygnał dostawałby użytkownik. Docelowym wyborem jest **Sentry**
(patrz tabela w `AGENTS.md` §3 i `docs/infra/MONITORING_BLEDOW.md`), ale w środowisku,
w którym ta praca powstała, `composer install` odbija się od proxy na
paczkach z GitHuba — nie da się więc uczciwie zaktualizować
`composer.lock`, żeby dodać `sentry/sentry-laravel`. Instalowanie pakietu
bez działającego `composer install` (np. ręczne dopisanie do `composer.lock`)
zostało odrzucone: taki lock plik kłamie o tym, co naprawdę zostało
rozwiązane przez Composera, i pęka przy pierwszym prawdziwym `composer
install` kogokolwiek innego.

**Co wybrano zamiast tego.** Kanał `blad_webhook` w `config/logging.php`:
`$exceptions->report()` w `bootstrap/app.php` wysyła każdy realnie
raportowany wyjątek (Laravel i tak pomija 4xx/419/429 — patrz komentarz przy
`ThrottleRequestsException`) na webhook zgodny z formatem Slacka, na który
Discord odpowiada pod końcówką `/slack` — czyli darmowe powiadomienie na
telefon bez zakładania jakiegokolwiek konta płatnego. Zero nowych zależności
Composera: Monolog i klient HTTP są już częścią Laravela.

**Dlaczego to NIE jest wbudowany sterownik `slack` Laravela.**
`Monolog\Handler\SlackWebhookHandler` łączy się przez `curl_init()`
z pominięciem klienta HTTP Laravela — nie da się tego przechwycić
`Http::fake()`, więc nie dałoby się TESTEM dowieść, że treść wysyłana na
zewnątrz nie niesie danych osobowych. Domyślnie dokleja też do wiadomości
CAŁY kontekst rekordu logu, czyli m.in. obiekt wyjątku z argumentami wywołań
ze stosu. `App\Logging\WebhookBleduHandler` buduje treść ręcznie, z jawnie
wybranych pól (klasa, komunikat, plik:linia, wzorzec trasy, ślad BEZ
argumentów) i wysyła przez `Illuminate\Support\Facades\Http` — w pełni
testowalne, w pełni pod kontrolą co do treści. AGENTS.md §7 zakazuje PII
w logach, a to jest jedyny log w serwisie, który wychodzi do usługi, nad
którą nie mamy żadnej kontroli.

**Czego ta decyzja świadomie NIE rozstrzyga.** Czy sam fakt wysyłania
(nawet pozbawionej PII) telemetrii błędów do Discorda/Slacka wymaga wpisu
w tabeli podprocesorów polityki prywatności — D-024 i D-038 pilnują, żeby
ta tabela nigdy nie mijała się z prawdą, w żadną stronę. Projekt tego kanału
zakłada, że nic osobowego tam nie trafia (i to jest przetestowane —
`tests/Feature/BladTrafiaNaWebhookBezDanychOsobowychTest.php`), więc na dziś
nic nie zmieniono w `resources/legal/polityka-prywatnosci.md`. Właściciel
powinien to potwierdzić przed włączeniem `LOG_BLAD_WEBHOOK_URL` na
produkcji — patrz `docs/infra/MONITORING_BLEDOW.md`.

**Zmiana wymaga:** działającego `composer install` w środowisku pracy (żeby
dało się dodać `sentry/sentry-laravel` i zaktualizować `composer.lock`
uczciwie) ORAZ decyzji właściciela o założeniu konta Sentry. Kanał
webhookowy zostaje jako zapasowa, tania sieć bezpieczeństwa nawet po
wdrożeniu Sentry — nie ma powodu go kasować.

📄 `config/logging.php` (kanał `blad_webhook`) · `bootstrap/app.php` ·
`app/Logging/WebhookBleduLogger.php` · `app/Logging/WebhookBleduHandler.php` ·
`tests/Feature/BladTrafiaNaWebhookBezDanychOsobowychTest.php` ·
`docs/infra/MONITORING_BLEDOW.md` · `docs/ROADMAP.md` §0
