## D-250 — Klucz API wychodzi tylko pod dokładny adres dostawcy: host, port i ścieżka z kodu (#991, 23 września 2026)

**Data:** 23 września 2026 · **Poprawka bezpieczeństwa** (P1) ·
Status: **obowiązuje**

### Co było

Adresy API EmailLabs (`EMAILLABS_ENDPOINT`), moderacji OpenAI
(`KUKING_MODEL_ENDPOINT`) i czyszczenia cache Cloudflare
(`CLOUDFLARE_PURGE_ENDPOINT`) przychodzą ze zmiennych środowiskowych.
Poczta sprawdzała tylko `https://`, moderacja i czyszczenie — nic. Klucze
i treść (cudze listy, cudze wpisy do oceny) szły pod każdy host, jaki stał
w zmiennej. Pierwsza wersja poprawki (lista hostów) nie patrzyła jeszcze na
ścieżkę, query ani fragment, a `/health` i `KlientOpenAI::oceniamy()` mówiły
„gotowe” przy obcym adresie.

### Decyzja

1. **Jedna granica: `App\Support\DozwolonyHostApi`.** Każda integracja
   podaje listę hostów i wzór CAŁEJ ścieżki, w kodzie, nie w `.env`:
   EmailLabs `api.emaillabs.io` + `/v2.1/email`, OpenAI `api.openai.com` +
   `/v1/moderations`, Cloudflare `api.cloudflare.com` +
   `/client/v4/zones/<strefa>/purge_cache` (strefa: litery, cyfry, łącznik).
2. **Każda część osobno, parserem Guzzle:** tylko `https`, bez danych
   logowania i bez `@` gdziekolwiek, host bajt w bajt (końcowa kropka, IDN
   i punycode odpadają), port brak/443, ścieżka pełnym dopasowaniem
   (`..`, `%xx`, ukośnik na końcu odpadają), **zero query i fragmentu**,
   żadnych białych, sterujących znaków ani odwrotnego ukośnika.
3. **Ścieżka dokładna, nie prefiks `/v1/`.** Klient buduje żądanie
   w kształcie jednej metody API; prefiks otwierałby inne metody tego
   samego konta (np. płatne) tym samym kluczem.
4. **Zły adres jest nazwany, nie przemilczany i nie wklejony.** Komunikat
   niesie nazwę zmiennej i nazwę złej części (`DozwolonyHostApi::powod()`),
   nigdy wartość — zmienna bywa wklejana razem z tokenem. Komendy
   `kuking:sprawdz-model` i `kuking:sprawdz-poczte` drukują z adresu sam
   host. `/health` ma kod `czyszczenie_cdn_zly_adres`; poczta wychodzi już
   jako `poczta_nie_wysyla` (transport się nie buduje);
   `KlientOpenAI::oceniamy()` jest `false`, a `bladKonfiguracji()` podaje
   zdanie dla operatora.
5. **Jedno zgłoszenie na godzinę, nie na treść.** Obcy adres modelu daje
   `Log::error` (kanał `blad_webhook`) raz na okno
   `KlientOpenAI::OKNO_ZGLOSZENIA_SEKUND` (`Cache::add`, atomowe).
6. **Stały błąd konfiguracji w kolejce = `fail()` od razu.**
   `PurgePublicMediaCache` nie robi pięciu prób z czymś, co nie mija samo.

### Czego świadomie NIE robimy

- **Nie przypinamy adresów IP ani certyfikatów.** Tożsamość hosta
  potwierdza TLS z systemowym magazynem CA i systemowy DNS. Przypinanie
  psułoby się przy każdej rotacji po stronie dostawcy, a atak na DNS/CA
  kontenera jest poza zasięgiem tej granicy (ona chroni przed błędną
  i podmienioną KONFIGURACJĄ).
- **Nie ma przełącznika „zaufaj innemu hostowi” w `.env`.** Nowy host lub
  ścieżka dostawcy to zmiana w kodzie z testem.
- **Nie dodajemy osobnej sondy `/health` dla moderacji.** Obcy adres modelu
  zgłasza się sam na kanale alarmowym (pkt 5) i w `kuking:sprawdz-model`;
  brak klucza moderacji też nie ma sondy, więc to zostaje spójne.
- **Testy nie rozluźniają granicy.** `Http::fake()` działa na kanonicznych
  adresach; żaden tryb testowy nie przepuszcza obcego hosta.

### Jak to jest zmierzone

`tests/Feature/SekretyTylkoDoDostawcyTest.php` (odmowa + `Http::assertNothingSent()`
+ brak sekretu i adresu w wyjątku/logu/wyjściu; kontrole dodatnie dla adresów
kanonicznych), `tests/Feature/SondaCzyszczeniaCacheCdnTest.php`
(`czyszczenie_cdn_zly_adres`) i dwie mutacje w
`scripts/kontrole-negatywne-alfa08.py` (strażnik osłabiony do samego
`https://` oraz bez sprawdzenia ścieżki muszą oblać test).

📄 `app/Support/DozwolonyHostApi.php`, `app/Moderacja/KlientOpenAI.php`,
`app/Moderacja/OcenaModelem.php`, `app/Providers/PocztaServiceProvider.php`,
`app/Poczta/BrakKonfiguracjiEmailLabs.php`, `app/Jobs/PurgePublicMediaCache.php`,
`app/Http/Controllers/HealthController.php`,
`app/Console/Commands/SprawdzModel.php`, `app/Console/Commands/SprawdzPoczte.php`
