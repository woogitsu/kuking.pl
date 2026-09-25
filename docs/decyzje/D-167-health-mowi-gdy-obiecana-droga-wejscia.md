## D-167 · `/health` mówi, gdy obiecana droga wejścia nie istnieje

**Data:** 12 września 2026 · PR #418 · issues #258, #259 · Status: **obowiązuje** ·
wykonanie D-069 i D-113

### Problem

Wejście kontem Google (D-069) i kontem Facebooka (D-113) były w `main` w całości —
z kodem, ekranami, polityką prywatności i 86 testami. I żadnego z nich nie dało
się wdrożyć, bo wdrożenie nie kończyło się niczym, co by sprawdziło, czy funkcja
naprawdę stanęła:

1. `DEPLOYMENT_RUNBOOK.md` nie miał kroku dla Facebooka (Google miał 8D);
2. `.railway/railway.ts` nie przepuszczał `FACEBOOK_*` do serwisu, więc klucze
   wpisane w Shared Variables nie docierały do aplikacji;
3. `/health` nie znał **żadnego** z dwóch dostawców.

**Trzecia jest najgorsza i ona nazywa klasę błędu.** Bez kluczy przycisku po
prostu nie ma na ekranie — czyli wdrożenie, w którym obiecana droga wejścia **nie
istnieje**, wygląda identycznie jak wdrożenie, na którym właściciel świadomie jej
nie chciał. Dwie pierwsze luki odkrywa się, próbując wdrożyć. Trzeciej nie
odkrywa nikt.

### Rozstrzygnięcie

**1. `/health` oddaje `degraded` z powodem `google_bez_kluczy` albo
`facebook_bez_kluczy`**, gdy `APP_ENV=production`, funkcja jest włączona
w `config/kuking.php`, a kluczy nie ma. Pytamy o **rozjazd między obietnicą
a rzeczywistością**, nie o sam brak kluczy: `KUKING_WEJSCIE_*=false` znaczy „nie
chcę tej drogi" i nie jest awarią. Inaczej jedynym sposobem uciszenia sygnału
byłoby wpisanie byle czego w klucze — czyli nauczenie właściciela kłamania
konfiguracji.

**2. HTTP 200, nie 503.** Żadna z tych kontroli nie jest `KRYTYCZNE`. Healthcheck
oddający 503 już raz położył ten serwis; serwis bez jednej z trzech dróg wejścia
działa, serwis w pętli restartów nie działa wcale. Monitoring pilnuje **treści**
odpowiedzi.

**3. Osobny powód na dostawcę**, nie wspólne `oauth_bez_kluczy`. Naprawa każdego
z nich to inny panel i inna czynność człowieka — Google Cloud Console to nie jest
panel Meta.

**4. Publicznie wychodzi sam kod.** Trasa `/health` nie ma `auth` i mieć nie może.
Zdanie dla właściciela (z nazwami zmiennych i odnośnikiem do runbooka) idzie
wyłącznie do serwerowego logu, jak przy każdym innym powodzie.

**5. Poza produkcją cisza.** Brak kluczy jest tam stanem normalnym — tak stoi
w `.env.example`, tak chodzi CI, tak chodzą **wszystkie** środowiska preview (Meta
nie przyjmuje wieloznaczników w adresach powrotu). Stały `degraded` byłby szumem,
który uczy ignorować to pole.

### To jest D-053 widziane z drugiej strony

D-053 zabrania martwych przycisków. Tu przycisku nie ma wcale, a obietnica
została — i to jest ta sama krzywda, tylko cichsza: człowiek, któremu powiedziano
„wejdziesz kontem Facebooka", nie ma gdzie tego zobaczyć, a my nie mamy skąd się
dowiedzieć, że tak jest.

### Dowód

`tests/Feature/WdrozenieWejsciaFacebookiemTest.php` (13 testów) pilnuje trzech
twierdzeń naraz: runbook opisuje krok Facebooka **i mówi, jak sprawdzić, że
działa**; `railway.ts` przepuszcza te zmienne; `/health` mówi prawdę o ich braku.
Nazwy zmiennych czytane z `config/kuking.php`, żeby runbook i `railway.ts` nie
mogły zacząć mówić o nazwie, której aplikacja nie czyta.

### Dług, świadomie zostawiony

`config/kuking.php:937–945` nadal twierdzi, że sygnału `google_bez_kluczy`
„jeszcze nie ma". Od tego wpisu to zdanie jest nieprawdziwe — do poprawienia przy
najbliższym dotknięciu tego pliku.

📄 `app/Http/Controllers/HealthController.php` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` KROK 8E · `.railway/railway.ts` ·
`tests/Feature/WdrozenieWejsciaFacebookiemTest.php` ·
D-053 · D-069 · D-104 · D-113 · issues #258, #259
