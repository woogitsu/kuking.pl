# Issue 1013 — sekrety per usługa: web, worker, scheduler

Stan na 24 września 2026. Źródło prawdy: `.railway/railway.ts`
(zestawy `appEnv`, `magazynEnv`, `kopieOdczytEnv`, `pocztaEnv`, `wejscieEnv`
i złożone z nich `webEnv`, `workerEnv`, `schedulerEnv`, `allEnv`).
Pilnuje tego `tests/Feature/SekretyPerUslugaTest.php`.

## 1. Problem

Do tej zmiany `web`, `worker` i `scheduler` rozwijały ten sam `...appEnv`
z 27 referencjami do Shared Variables. Worker, który dekoduje nieufne pliki,
dostawał sekret OAuth Google i Facebooka oraz Turnstile. Scheduler dostawał
to samo plus klucze poczty. Web dostawał token odczytu bucketa kopii bazy,
choć kopię sprawdza wyłącznie harmonogram. Dopisanie sekretu do `appEnv`
poszerzało dostęp wszystkich trzech ról i nie oblewało żadnego testu.

Nie chodzi o wyciek. Deklarowany w IaC zakres dostępu był szerszy niż potrzeby
kodu.

## 2. Macierz „zmienna → rola → konsument w kodzie”

Kolumna „zmienna” to nazwa w kontenerze, czyli nazwa czytana przez `config/`.
Nazwa Shared Variable w panelu stoi w nawiasie, jeśli jest inna.

| Zmienna | web | worker | scheduler | Konsument / powód |
|---|:-:|:-:|:-:|---|
| `APP_KEY` | ✔ | ✔ | ✔ | szyfrowanie sesji, 2FA, zaszyfrowanych zadań kolejki |
| `LOG_BLAD_WEBHOOK_URL` | ✔ | ✔ | ✔ | kanał `blad_webhook` (`config/logging.php`), błąd może paść w każdej roli |
| `DB_URL` (referencja do usługi Postgres) | ✔ | ✔ | ✔ | baza, sesje, cache, kolejka |
| `AWS_ACCESS_KEY_ID` (`R2_ACCESS_KEY_ID`) | ✔ | ✔ | ✔ | dyski `r2`, `r2_publiczne`, `r2_eksporty` |
| `AWS_SECRET_ACCESS_KEY` (`R2_SECRET_ACCESS_KEY`) | ✔ | ✔ | ✔ | jw. |
| `AWS_BUCKET` (`R2_BUCKET`) | ✔ | ✔ | ✔ | oryginały: upload Livewire (web), `ProcessUploadedImage` (worker), sprzątanie (scheduler) |
| `AWS_PUBLIC_BUCKET` (`R2_PUBLIC_BUCKET`) | ✔ | ✔ | ✔ | warianty: podpisane adresy trasy `media.show` (web), zapis (worker), kasowanie (scheduler) |
| `AWS_EXPORTS_BUCKET` (`R2_EXPORTS_BUCKET`) | ✔ | ✔ | ✔ | paczki RODO: pobranie (web), budowa (worker), `kuking:sprzataj-eksporty` (scheduler) |
| `AWS_ENDPOINT` (`R2_ENDPOINT`) | ✔ | ✔ | ✔ | endpoint wszystkich dysków R2, także `r2_kopie` |
| `AWS_KOPIE_BUCKET` (`R2_KOPIE_BUCKET`) | — | — | ✔ | `kuking:sprawdz-kopie` → `StanKopiiBazy`; uruchamia go tylko harmonogram |
| `AWS_KOPIE_ACCESS_KEY_ID` (`R2_KOPIE_ODCZYT_ACCESS_KEY_ID`) | — | — | ✔ | jw. |
| `AWS_KOPIE_SECRET_ACCESS_KEY` (`R2_KOPIE_ODCZYT_SECRET_ACCESS_KEY`) | — | — | ✔ | jw. |
| `EMAILLABS_APP_KEY` | ✔ | ✔ | — | web: `OdpowiedzNaWiadomosc` idzie synchronicznie, a `Poczta::dziala()` sprawdza konfigurację przed wysłaniem linku; worker: listy z kolejki |
| `EMAILLABS_SECRET_KEY` | ✔ | ✔ | — | jw. |
| `EMAILLABS_SMTP_ACCOUNT` | ✔ | ✔ | — | jw. |
| `TURNSTILE_SITE_KEY` | ✔ | — | — | widget na formularzach publicznych |
| `TURNSTILE_SECRET_KEY` | ✔ | — | — | `KlientTurnstile` w regule `TurnstileJestPotwierdzony`, tylko żądania HTTP |
| `GOOGLE_CLIENT_ID` | ✔ | — | — | `GoogleLoginController` |
| `GOOGLE_CLIENT_SECRET` | ✔ | — | — | `KlientGoogle` wołany z `GoogleLoginController` |
| `FACEBOOK_CLIENT_ID` | ✔ | — | — | wejście kontem Facebooka |
| `FACEBOOK_CLIENT_SECRET` | ✔ | — | — | wejście kontem Facebooka i podpis żądania usunięcia danych; to trasy web |
| `CLOUDFLARE_ANALYTICS_TOKEN` | ✔ | — | — | beacon w HTML-u stron (`AnalitykaCloudflare`), sprawdzany też przez `/health` |

Rola `all` (staging, preview i produkcja bez podziału usług) wykonuje pracę
wszystkich trzech ról w jednym kontenerze, więc dostaje sumę tych kolumn.
Nie dostaje żadnej zmiennej spoza tej sumy.

Usługa `kopia-bazy` ma własną, zamkniętą listę bez żadnego zestawu aplikacji
(#193, `KopiaBazyPozaRailwayemTest`). Ta zmiana jej nie dotyka.

### Dlaczego scheduler zachowuje zapis do bucketów zdjęć i eksportów

Kryterium z issue brzmi: „jeśli żadna komenda harmonogramu ich nie używa”.
Komendy harmonogramu tych bucketów używają. `Harmonogram::artisan()` wykonuje
komendę **w procesie schedulera** (`Schedule::call()`, nie w workerze):

- `kuking:usun-wygasle-konta` → `EraseAccountData` → `KasujZdjecie`
  (`Storage::disk(...)->delete()`);
- `kuking:sprzataj-osierocone-zdjecia` → `KasujZdjecie`;
- `kuking:sprzataj-eksporty` → `CleanUpDataExports` (dysk `r2_eksporty`).

Odebranie tych poświadczeń wymagałoby przeniesienia kasowania do zadań
kolejki. To zmiana zachowania kasowania danych osobowych (RODO), a nie zmiana
konfiguracji. Poza zakresem #1013.

### Dlaczego scheduler nie ma kluczy poczty

Harmonogram tylko **kolejkuje** listy: `kuking:wyslij-podsumowania` przez
`Mail::queue()`, a `kuking:pilnuj-terminow-odwolan`
i `kuking:podsumowanie-automatu` przez `notify()` na powiadomieniach
`ShouldQueue`. Transport buduje dopiero worker. W całym `app/` nie ma
`notifyNow()` ani `Notification::sendNow()`.

## 3. Usunięte z runtime'u

| Zmienna | Powód |
|---|---|
| `SENTRY_LARAVEL_DSN` | Sentry nie jest zainstalowany (`TabelaStackuMowiPrawdeTest`), więc DSN nie czytała żadna linijka |
| `POSTHOG_KEY` | w kodzie nie ma klienta PostHoga |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | `MAIL_MAILER` to `emaillabs`, a SMTP na planach Free i Hobby jest zablokowane (D-116). Jeśli poczta przejdzie na SMTP, te cztery wracają do `pocztaEnv`, nie do rdzenia |

Niesekretne `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`, `POSTHOG_HOST`
i `MAIL_SCHEME` zostają w rdzeniu bez zmian. `MAIL_SCHEME` ma swojego
strażnika (`SchematPocztyJestObslugiwanyTest`).

Niesekretne zmienne wszystkich usług są identyczne jak przed zmianą.
Sprawdzono to wykonaniem `railway.ts` przez `tsx` z atrapą `ctx`
dla `production` i `staging` i porównaniem z `origin/main`.

## 4. Czego nie da się zrobić w repozytorium: instrukcja dla właściciela

`railway config apply` nie było jeszcze uruchomione. Produkcja działa na
zmiennych ustawionych ręcznie w panelu, więc ta zmiana **sama niczego na
produkcji nie zmienia**. Nie ma też poniżej żadnych wartości, tylko nazwy.

**A. Jeśli produkcja jest nadal jedną usługą `web` w roli `all`:**
nic nie rób. Ta rola potrzebuje sumy wszystkich kolumn. Możesz jedynie
usunąć z usługi `web` referencje do `SENTRY_LARAVEL_DSN`, `POSTHOG_KEY`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` i `MAIL_PASSWORD` (punkt D).

**B. Przy wdrażaniu podziału (#595) przez `railway config apply`:**

1. `railway config plan` i przeczytaj **nazwy** zmiennych usług `worker`
   i `scheduler` w planie. Wartości nie są potrzebne.
2. Porównaj z tabelą w §2. Worker: 11 referencji `ctx.shared`,
   scheduler: 11, web: 18.
3. Dopiero wtedy `railway config apply`.

**C. Przy podziale zakładanym ręcznie w panelu:**

1. Railway → projekt → środowisko **production** → usługa **worker** →
   **Variables** → dodaj referencje do Shared Variables **tylko** z kolumny
   „worker” w §2 (**Add Reference** → Shared Variable).
2. To samo dla **scheduler**, według kolumny „scheduler”.
3. Usługa **web** po podziale: usuń referencje `AWS_KOPIE_BUCKET`,
   `AWS_KOPIE_ACCESS_KEY_ID` i `AWS_KOPIE_SECRET_ACCESS_KEY`.
4. Sprawdzenie bez odczytu wartości: w zakładce **Variables** każdej usługi
   porównaj listę **nazw** z §2. Nie klikaj „pokaż wartość”.
5. Po zmianie zmiennych Railway robi redeploy usługi. Sprawdź `/health`
   (web) i to, że w logach schedulera `kuking:sprawdz-kopie` nie melduje
   `NIEDOSTEPNY`.

**D. Sprzątanie Shared Variables (opcjonalne):** `SENTRY_LARAVEL_DSN`
i `POSTHOG_KEY` nie mają konsumenta. Można je usunąć ze środowiska. Zmienne
`MAIL_*` SMTP możesz zostawić w Shared Variables jako zapas, bo żadna usługa
już się do nich nie odwołuje.

Ręczne `kuking:sprawdz-kopie` po podziale uruchamiasz na schedulerze:
`railway ssh --service scheduler -- php artisan kuking:sprawdz-kopie`.
Na web ta komenda nie ma poświadczeń bucketa kopii.

## 5. Styk z #1014 (brakujące referencje zmiennych)

#1014 dopisuje do `railway.ts` referencje, których dziś tam brakuje. Nie
trafiają one do wspólnego `appEnv`. Każda idzie do zestawu roli, która ją
czyta, a potem do `MACIERZ` w `SekretyPerUslugaTest`. Inaczej ten test
obleje, i tak ma być. Konsumenci według kodu na `main`:

- `OPENAI_MODERATION_KEY` (`config/kuking.php`, `moderacja.model.klucz`) →
  `App\Jobs\PrzeanalizujTresc` → `OcenaModelem`, czyli **worker**
  (i ewentualnie komenda ręczna `SprawdzModel`);
- `CLOUDFLARE_PURGE_TOKEN`, `CLOUDFLARE_ZONE_ID` →
  `App\Jobs\PurgePublicMediaCache`, czyli **worker**. Uwaga: `/health` (web)
  mierzy, czy ten job ma z czym pójść do Cloudflare. #1014 musi więc
  zdecydować, czy web dostaje tylko niesekretny `CLOUDFLARE_ZONE_ID`,
  czy `/health` przestaje to mierzyć na web. Sekretnego tokenu web nie
  powinien dostawać.

Obie gałęzie zmieniają `.railway/railway.ts`. Ta, która wejdzie druga,
musi rozwiązać konflikt według zasady z tego punktu, a nie przez
dopisanie do `appEnv`.
