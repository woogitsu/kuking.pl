## D-071 · Granica zaufania do nagłówka `Host` jest zamknięta z dwóch stron: `X-Forwarded-Host` wypada z zaufanych nagłówków, a `Host` przechodzi przez `TrustHosts`

**Data:** 10 września 2026 · **Znalezisko:** S2 (P1) z `docs/research/audyt-2026-09-10/02_BEZPIECZENSTWO_APLIKACJI.md`

### Co było zmierzone PRZED zmianą — bo od tego zależy, jak to nazwać

Pomiar, nie założenie (tymczasowy test na `origin/main` @ `e3cf6ab`):

| Żądanie | Odpowiedź | `url('/przepisy')` |
|---|---|---|
| bez nagłówków | 200 | `http://localhost:8000/przepisy` |
| `Host: attacker.invalid` | 200 | host z żądania |
| `X-Forwarded-Host: attacker.invalid` | 200 | **`http://attacker.invalid/przepisy`** |

Linki w listach przy `X-Forwarded-Host: attacker.invalid`, gdy adres powstaje
w żądaniu HTTP — wszystkie cztery wychodziły na `http://attacker.invalid/…`:
reset hasła, potwierdzenie adresu, logowanie linkiem, potwierdzenie zmiany
adresu e-mail.

**Ale to nie znaczy, że wszystkie cztery były na produkcji do wykorzystania,
i nie wolno tego tak sprzedać.** Trzy pierwsze powiadomienia są `ShouldQueue`,
a produkcyjna kolejka to `database` (`.railway/railway.ts`), więc adres
powstaje w WORKERZE. Zmierzone w kontekście konsoli: `url()` zwraca
`https://kuking.pl/…`, bo `SetRequestForConsole` buduje żądanie z `APP_URL`.
Dla resetu hasła, potwierdzenia adresu i logowania linkiem to była więc
granica **formalnie otwarta, praktycznie zasłonięta** przez asynchroniczną
kolejkę — czyli **hardening, nie naprawa dziury**.

Jedno miejsce nie miało tej osłony: `RequestEmailChange::linkPotwierdzajacy()`
buduje podpisany adres **w żądaniu HTTP**, przed zakolejkowaniem listu. Tam
`X-Forwarded-Host` wchodził do treści listu wprost i to jest **realnie otwarta
droga**, nie hipoteza.

### Decyzja

**1. `Request::HEADER_X_FORWARDED_HOST` wypada z bitmaski `trustProxies()`.**
To jest zamknięcie mocniejsze niż allowlista, bo nagłówka, którego aplikacja
nie czyta, nie da się podstawić żadną wartością. Wolno go było wyjąć, bo
w naszym łańcuchu **nikt go nie wystawia i nikt nie przepisuje `Host`**:
Cloudflare w trybie proxy przekazuje `Host` na origin nietknięty (routing po
nim właśnie działa), a brzeg Railway kieruje ruch po `Host`/SNI i też go
zachowuje — inaczej nie odróżniłby `kuking.pl` od `staging.kuking.pl` na tym
samym koncie. Aplikacja ma oryginalny host w `Host` i drugiego źródła nie
potrzebuje.

**2. `Host` przechodzi przez `TrustHosts` z jawną listą** — `App\Support\ZaufaneHosty`,
`subdomains: false`. Na liście: `kuking.pl`, `www.kuking.pl`,
`healthcheck.railway.app`, host z `APP_URL`, pętla zwrotna
(`localhost`/`127.0.0.1`/`[::1]`) i pusty domyślnie zawór
`config('proxy.dodatkowe_hosty')`. Uzasadnienie każdego wpisu — i tego, co się
stanie po jego pominięciu — stoi w komentarzu tamtej klasy.

**3. Adresy w listach budowane z konfiguracji, zawsze** — `App\Support\AdresKanoniczny`.
Dla linku, który **daje sesję** (`LinkDoLogowania`, D-056 — nasza główna droga
wejścia dla osób 60+), „host był na liście dozwolonych" jest gwarancją słabszą
niż „host w ogóle nie zależał od żądania": lista ma kilka pozycji, kanoniczny
adres jest jeden.

### Czego świadomie NIE zrobiliśmy

**Produkcyjnego `*.up.railway.app` nie ma na liście.** Wejście na origin
z pominięciem Cloudflare to znalezisko **S1** — osobne, większe, wymaga zmian
w panelu Cloudflare i decyzji właściciela. Ta zmiana go NIE rozstrzyga.
Skutkiem ubocznym jest to, że ten host przestaje być drogą do zbudowania
adresu na cudzej domenie, ale **to nie jest zamknięcie S1** i nie wolno tak
raportować. Gdyby właściciel potrzebował wejść na origin wprost, służy do tego
zawór z punktu 2.

**Nie ruszaliśmy `NormalizeForwardedFor`** ani liczby `zaufane_przeskoki`
(SEC-01, W7-01) — to jest `X-Forwarded-For`, inna granica.

### Ryzyko wdrożeniowe — jedyne, które tu jest, i jak je zamknięto

`TrustHosts` bez `healthcheck.railway.app` oddaje healthcheckowi Railwaya 400,
a wtedy **deploy nigdy się nie kończy i nie ma jak wypchnąć poprawki**, bo
poprawka też idzie deployem. Dlatego: host jest na liście, pilnuje go test
`ZaufaneHostyTest::test_healthcheck_railwaya_przechodzi` (oblewa po usunięciu
wpisu — sprawdzone), a na wypadek zmiany po stronie Railwaya istnieje zawór
`KUKING_ZAUFANE_HOSTY`, którym da się naprawić produkcję **bez deployu**.
Brak tej zmiennej jest stanem domyślnym, bezpiecznym i działającym — nie trzeba
jej ustawiać, żeby serwis wstał.

**Zmiana wymaga:** dowodu z produkcji, że coś w łańcuchu przepisuje `Host`
(objaw: adresy w serwisie wskazują wewnętrzną domenę platformy). Wtedy wraca
`HEADER_X_FORWARDED_HOST` — ale razem z zapisanym pomiarem, nie „na wszelki
wypadek".

📄 `bootstrap/app.php` · `app/Support/ZaufaneHosty.php` ·
`app/Support/AdresKanoniczny.php` · `config/proxy.php` ·
`app/Notifications/UstawienieNowegoHasla.php` ·
`app/Notifications/PotwierdzenieAdresu.php` ·
`app/Notifications/LinkDoLogowania.php` ·
`app/Domain/Users/Actions/RequestEmailChange.php` ·
`tests/Feature/ZaufaneHostyTest.php` ·
`.railway/railway.ts` · `docs/legal/BRAMKA_BETY.md` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md`
