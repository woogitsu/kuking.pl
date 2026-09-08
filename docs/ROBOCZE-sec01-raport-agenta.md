# SEC-01 — zaufanie do nagłówków proxy

Gałąź: `praca-sec01` (od `820e1c5`). Łatka: `sec01.patch`.
**Nic nie zostało wypchnięte.**

---

## 1. Czy dokument mówił prawdę? Częściowo — i mylił się w szczególe, który zmienia wnioski

`docs/legal/BRAMKA_BETY.md` §3 twierdził dwie rzeczy. Sprawdziłem obie
w faktycznym źródle frameworka (pobrałem `Illuminate\Http\Middleware\TrustProxies`
i `Symfony\Component\HttpFoundation\Request` — `vendor/` w tym środowisku jest
pusty), a nie z pamięci.

### 1.1. „Nagłówek od klienta decyduje o `$request->ip()`" — PRAWDA

Łańcuch wywołań jest taki:

1. `bootstrap/app.php` → `trustProxies(at: '*')`.
2. Laravel: `'*'` **nie** znaczy „ufaj całemu łańcuchowi". `TrustProxies::
   setTrustedProxyIpAddressesToTheCallingIp()` robi
   `setTrustedProxies([$request->server->get('REMOTE_ADDR')], …)` — ufa
   WYŁĄCZNIE maszynie, która właśnie otworzyła połączenie TCP.
3. Symfony: `getClientIp()` → `getClientIps()[0]`, a `getClientIps()` woła
   `normalizeAndFilterClientIps()`, która dokleja `REMOTE_ADDR` na koniec
   łańcucha, usuwa z niego adresy zaufane, **odwraca** resztę i oddaje
   pierwszy element.

Efekt: jeśli między klientem a kontenerem nie ma nic, co dopisuje do
`X-Forwarded-For`, to `$request->ip()` jest wartością wpisaną przez klienta.
Wtedy padają naraz: `throttle:` na trasach (klucz to `sha1(domena|ip)` dla
gościa), koszyki A i C z `App\Support\KluczeLimitow`, i `audit_log.ip_hash`
w 21 miejscach.

### 1.2. „Przy łańcuchu wygrywa PIERWSZY element" — NIEPRAWDA

Wygrywa **OSTATNI** (przez `array_reverse` w punkcie 3 wyżej). To nie jest
kosmetyka, bo zmienia ocenę ryzyka:

- Cloudflare **dopisuje** adres odwiedzającego na końcu i nie pozwala go
  nadpisać regułą transformacji (to samo repozytorium notuje ten fakt
  w `docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md` §4). Ruch idący **przez**
  Cloudflare dostawał więc poprawny adres także przed poprawką.
- Realnie podatny był ruch, **przed którym nic nie stało** — wejście wprost
  na `*.up.railway.app`, środowiska preview — oraz każda przyszła zmiana
  topologii, która dołożyłaby przeskok bez zmiany w kodzie.

Zdanie z §3 poprawiłem w dokumencie wprost, z uzasadnieniem, zamiast po cichu.
To samo zdanie („first element wins") stało w angielskim `docs/HANDOVER.md`
§4.1 — też poprawione.

### 1.3. `TRUSTED_PROXIES` w `.railway/railway.ts` była zmienną-atrapą

`TRUSTED_PROXIES: "*"` **nigdy nie było przez aplikację czytane.**
`bootstrap/app.php` ma `at: '*'` wpisane na sztywno, a jedyną inną nazwą,
której szuka `TrustProxies`, jest legacy `config('trustedproxy.proxies')` —
pliku `config/trustedproxy.php` w repozytorium nie ma. Ustawienie tam listy
adresów nie zmieniłoby zachowania ani o krok. To ten sam kształt błędu co
opisane w `config/kuking.php` martwe `kuking.media_disk` i usunięty limit
`'upload'`. Zmienną **usunąłem**, a nie przemianowałem.

---

## 2. Co jest po zmianie

### 2.1. Nowy middleware — liczba przeskoków zamiast listy adresów

`app/Http/Middleware/NormalizeForwardedFor.php`, wpięty przez
`$middleware->prepend()`, czyli **pierwszy w całym stosie globalnym**, przed
`TrustProxies`. Zostawia w `X-Forwarded-For` dokładnie jeden wpis: **n-ty od
końca** łańcucha, gdzie n = `config('proxy.zaufane_przeskoki')`.

Dlaczego tak, skoro adres brzegu Railway nie jest stały:

- **Lista adresów IP jest tu strukturalnie bezużyteczna.** Symfony porównuje
  listę zaufanych proxy z bezpośrednim peerem TCP, a tym peerem jest zawsze
  brzeg Railway z sieci prywatnej — nigdy adres Cloudflare. Zakresy Cloudflare
  byłyby listą, w którą nie trafi ani jedno żądanie.
- Zostaje jedyna własność tego nagłówka niezależna od adresów:
  **proxy dopisuje na końcu, klient może dopisywać tylko na początku.**
  Dlatego liczymy od prawej — dopisany prefiks przesuwa wyłącznie własne śmieci.

Dodatkowo, w tej samej klasie:

- **Sklejamy wszystkie wystąpienia nagłówka** (`headers->all()`), bo Symfony
  w `getTrustedValues()` czyta wyłącznie PIERWSZE wystąpienie — gdyby proxy
  dopisało własną linię zamiast doklejać do istniejącej, Symfony patrzyłoby
  tylko na linię od klienta.
- **Wpis nieczytelny jako adres = brak nagłówka**, a nie cofnięcie się o jeden
  wpis w lewo. Symfony w takiej sytuacji szuka dalej w lewo, czyli w obszarze
  wypełnianym przez klienta; jeden znak „x" na końcu wystarczyłby napastnikowi
  do odzyskania kontroli nad wynikiem.

### 2.2. Co się stanie, gdy Railway zmieni topologię

Uzasadnienie stoi w komentarzu klasy i w `config/proxy.php`, bo tam będzie go
szukał następny człowiek. Streszczenie — **obie pomyłki są jednostronne**:

| Sytuacja | Skutek |
|---|---|
| Dochodzi proxy dopisujące wpis (liczba za mała) | Aplikacja widzi adres tego proxy — jeden wspólny dla wielu osób. Limity zbyt ostre, **podszyć się nie da**. Odwracalne jedną zmienną i restartem |
| Proxy ubywa (łańcuch krótszy niż konfiguracja) | Nagłówek odrzucony w całości, zostaje adres połączenia TCP, w logu ostrzeżenie z samą DŁUGOŚCIĄ łańcucha (bez adresów — AGENTS.md §7) |
| Ktoś podniesie liczbę „z zapasem" | **Jedyny sposób, żeby przywrócić dziurę.** Odczyt wchodzi w obszar wypełniany przez klienta |

Domyślnie `1`, bo to minimum zgodne z każdą topologią, w której cokolwiek stoi
przed aplikacją. Czy brzeg Railway dopisuje drugi wpis — z kontenera nie da się
rozstrzygnąć; dokumentacja Railway nie wspomina o tym nagłówku ani słowem.
Recepta na POMIAR (wejść przez Cloudflare bez własnego `X-Forwarded-For`
i policzyć wpisy) stoi w `config/proxy.php` obok liczby.

### 2.3. Zestaw zaufanych nagłówków wypisany jawnie

`trustProxies(at: '*', headers: FOR|HOST|PORT|PROTO)`. Z domyślnego zestawu
frameworka wypadły:

- `X-Forwarded-Prefix` — doklejany do KAŻDEGO adresu generowanego przez
  `url()`; nic w naszym łańcuchu go nie wysyła, a był zaufany;
- `X-Forwarded-AWS-ELB` — zbiorczy bit dla platformy, na której nie stoimy
  (i tak zawiera tylko FOR|PROTO|PORT, więc usunięcie niczego nie zabiera).

`at: '*'` **zostaje** — bez zaufania do `X-Forwarded-Proto` `$request->secure()`
jest fałszem, `url()` generuje `http://`, a Cloudflare wpada w pętlę
przekierowań. Poprzednie ostrzeżenie „nie usuwać" było trafne.

---

## 3. Drugi, niezależny problem w limitach logowania (punkt 3 zadania)

Sprawdziłem `App\Support\KluczeLimitow`, `LoginController::store()`,
`TwoFactorChallengeController` i sekcje `login_limits` / `limits`
w `config/kuking.php`.

### 3.1. Znalezione i NAPRAWIONE: klucz zależał od białych znaków

`konto()` i `para()` normalizowały login przez samo `mb_strtolower()`, bez
`trim()`. `User::findByLogin()` robi `trim()` — więc `„ basia@example.com"`
i `„basia@example.com"` to jedno konto, ale **dwa różne koszyki B**. Każdy
wariant zapisu dostawałby świeży licznik, czyli koszyk B (ten, który
w ogóle powstał po to, żeby zatrzymać atak rozproszony po adresach) dałoby się
obejść samym dopisywaniem spacji.

Poprawka: jedna prywatna metoda `login()` = `mb_strtolower(trim(…))`, używana
przez oba koszyki. Test w `LimitLogowaniaNaKontoTest`.

**Uczciwe zastrzeżenie do wagi tej usterki:** globalny `TrimStrings` Laravela
i tak obcina spacje z pól formularza, więc praktyczna droga wejścia była dziś
zamknięta z drugiej strony. Naprawiam mimo to, bo klucz limitera nie ma prawa
zależeć od middleware'u zrobionego do zupełnie innego celu, którego lista
wyjątków może kiedyś urosnąć.

### 3.2. Znalezione i ŚWIADOMIE NIENAPRAWIONE: e-mail i nazwa użytkownika to dwa koszyki

Klucz koszyka B liczy się z tego, **co człowiek wpisał**, a nie z konta, które
z tego wyszło. Logować można się e-mailem ALBO nazwą użytkownika
(`User::findByLogin()`), więc to samo konto ma dziś dwa koszyki B —
napastnik znający oba zapisy dostaje **2 × 15 prób / 15 min zamiast 15**.

Dlaczego tego nie ruszyłem, mimo że wiem jak:

1. Naprawa to liczenie klucza z identyfikatora konta, czyli `findByLogin()`
   **przed** sprawdzeniem limitu — zmiana kolejności w przepływie logowania.
2. Wprowadza wyrocznię enumeracji: dla konta istniejącego oba zapisy nasycają
   jeden koszyk, dla nieistniejącego dwa. Przy 15 próbach to wolna i głośna
   wyrocznia, ale serwis celowo unika ujawniania istnienia konta (jednakowy
   komunikat błędu, jednakowy komunikat limitu).
3. **Nie mogę uruchomić testów.** Przestawianie kolejności w kontrolerze
   logowania bez ani jednego przebiegu PHPUnita to zły interes: zysk jest
   dwukrotnym zawężeniem budżetu w wąskim scenariuszu, koszt potencjalny —
   zepsute logowanie.

Zapisałem to w docbloku `KluczeLimitow` jako nazwane, znane ryzyko z powodem —
do osobnej decyzji.

### 3.3. Sprawdzone i czyste

- `Skrot::hmac()` — `hash_hmac` z `APP_KEY`, nie `hash()` z doklejoną solą.
  Adres e-mail ani surowy IP nie trafiają do klucza cache'u.
- Czyszczenie przy poprawnym logowaniu: koszyki `para` i `konto`, **nigdy**
  `adres`. Zgodne z uzasadnieniem w kodzie i pilnowane testem.
- `TwoFactorChallengeController` — limit liczony po `user->getKey()` z sesji,
  nie po niczym, co przychodzi w żądaniu. Bez zarzutu.
- Drobiazg do odnotowania, nie naprawiony: `(string) $request->ip()` daje `''`,
  gdyby `ip()` zwróciło `null`; wtedy wszyscy dzielą jeden koszyk C. Na
  produkcji `REMOTE_ADDR` istnieje zawsze, więc to ścieżka teoretyczna.

---

## 4. Test regresyjny

`tests/Feature/PodrobionyNaglowekProxyTest.php` — 8 testów:

1. wpis dopisany przez infrastrukturę wygrywa z wpisem od klienta
   (+ **kontrola**: aplikacja nadal w ogóle widzi adres z nagłówka — bez tej
   asercji test przechodziłby też, gdyby nagłówek przestał działać zupełnie,
   a to jest inna awaria, nie naprawa);
2. dwadzieścia podrobionych wpisów niczego nie zmienia;
3. nieczytelny ostatni wpis **nie** cofa odczytu w lewo;
4. łańcuch krótszy niż konfiguracja → adres połączenia TCP;
5. dwa przeskoki czytają wpis przedostatni;
6. `zaufane_przeskoki = 0` wyłącza nagłówek całkowicie;
7. **skutek**: osiem prób logowania ze zmiennym podrobionym prefiksem kończy
   się blokadą (przed poprawką: „NIE BLOKUJE ANI RAZU w ośmiu próbach");
8. **kontrola do (7)**: osiem prób z ośmiu różnych adresów blokady nie wywołuje
   — inaczej (7) mierzyłby limit konta, a nie limit adresu.

Do tego komentarz w `ZaufaneProxyTest` rozstrzygający zarzut z przeglądu R5
§2.3 („ten test utrwala bypass"): jeden wpis w nagłówku to na produkcji wpis
od Cloudflare i aplikacja **musi** go widzieć; że wpisowi nie wolno wierzyć
niezależnie od długości łańcucha, pilnuje nowy plik.

---

## 5. Czego NIE zrobiłem i nie sprawdziłem

**Nie uruchomiłem PHPUnita — ani razu.** `vendor/` jest w tym środowisku
niekompletny, `composer install` nie przechodzi. Nie wiem więc, czy nowe testy
są zielone, i nie zrobiłem obalenia (cofnąć poprawkę → test czerwony), którego
`BRAMKA_BETY.md` §1 wymaga do stanu ZAMKNIĘTE. Dlatego wiersz W7-01
w macierzy dostał **CZĘŚCIOWE**, a pod tabelą stoi jawna uwaga, że tych ośmiu
testów nikt jeszcze nie widział na zielono.

**Co faktycznie wykonałem:**

- `php -l` na wszystkich zmienionych plikach PHP — czysto;
- pobrałem i przeczytałem faktyczne źródło `TrustProxies` (laravel/framework)
  oraz `Request::getClientIps()` / `normalizeAndFilterClientIps()`
  (symfony/http-foundation) — na nich opieram §1, nie na pamięci;
- **uruchomiłem** samą logikę wyboru wpisu z łańcucha w PHP, poza Laravelem
  (refleksja na prywatnej metodzie), na 12 przypadkach: pojedynczy wpis,
  łańcuchy 2- i 3-elementowe, przeskoki 0/1/2, port przy IPv4, IPv6
  w nawiasach, IPv6 gołe, wartość nieczytelna, pusty nagłówek. Wszystkie
  zgodne z oczekiwaniem;
- `node --check .railway/railway.ts` — bez błędu (zmiana to komentarz i jedno
  pole tekstowe).

**Czego nie tknąłem:**

- `npm run build` — nie ma zmian we front-endzie;
- Pinta nie ma w `vendor/bin`, więc formatowania **nie sprawdziłem narzędziem**
  — pisałem ręcznie w stylu reszty repozytorium;
- nie zmieniałem `LoginController` (patrz §3.2);
- nie włączałem `TrustHosts` i nie odbierałem zaufania `X-Forwarded-Host`
  (patrz §6);
- nie dotykałem 21 miejsc zapisujących `audit_log.ip_hash` — nie trzeba,
  poprawka jest na granicy frameworka, więc `$request->ip()` naprawia się
  wszędzie naraz. To był główny powód, żeby nie iść drogą osobnej klasy
  `ClientAddressResolver` z przeglądu R5: taką klasę trzeba pamiętać
  w każdym nowym wywołaniu, a middleware'u nie.

---

## 6. Ryzyko wdrożeniowe

### 6.1. Gdy `KUKING_ZAUFANE_PRZESKOKI` będzie ustawione źle

- **Za mało (np. 1, a realnie 2).** Aplikacja zobaczy adres Cloudflare zamiast
  odwiedzającego. Skutek: limity liczone po adresie robią się wspólne dla
  wszystkich za tym samym węzłem CF — ludzie dostają „Za dużo prób" bez
  powodu, a `audit_log.ip_hash` przestaje rozróżniać osoby. **Bezpieczeństwo
  nie cierpi, dostępność tak.** Naprawa: jedna zmienna + restart.
- **Za dużo (np. 2, a realnie 1).** Odczyt wchodzi w obszar wypełniany przez
  klienta i **wraca dokładnie ta podatność, którą ta łatka zamyka** — ciszej
  niż przedtem, bo wygląda na skonfigurowaną. To jedyny naprawdę groźny
  wariant i dlatego domyślną wartością jest minimum, a nie „z zapasem".
- **Zero.** Nagłówek ignorowany, wszyscy dostają adres brzegu. Awaryjny
  wyłącznik, gdyby coś poszło nie tak — sensowny na kilka godzin, nie na stałe.

Sygnał, że coś nie gra, jest w logu: `X-Forwarded-For krótszy niż zaufane
przeskoki albo nieczytelny` z liczbą wpisów. Ostrzeżenie **nie zawiera
adresów** (AGENTS.md §7).

### 6.2. Ryzyko samego wdrożenia łatki

- `TRUSTED_PROXIES` znika z `railway.ts`, więc po `railway config apply`
  zmienna zniknie ze środowiska. Nic jej nie czyta — sprawdzone grepem po
  całym repozytorium. Ryzyko: żadne poza tym, że ktoś zobaczy brak i się
  zdziwi; w pliku stoi komentarz, dlaczego jej nie ma.
- `KUKING_ZAUFANE_PRZESKOKI` wchodzi z wartością `1`. Jeśli zmienna nie
  dojedzie na środowisko, `config/proxy.php` i tak daje `1` — więc brak
  zmiennej nie zmienia niczego.
- Nowy plik `config/proxy.php` jest łapany przez `php artisan config:cache`
  w entrypoincie razem z resztą. Nic do zrobienia ręcznie.
- `.env.example` dostaje `KUKING_ZAUFANE_PRZESKOKI=0` — bo lokalnie
  (`php artisan serve`) żadnego proxy nie ma i wtedy `0` jest poprawną
  wartością. Testy tego nie czytają (`phpunit.xml` ma własne env), więc
  domyślne `1` z `config/proxy.php` obowiązuje w testach.

### 6.3. Czego ta łatka NIE zabezpiecza

Żądanie, które **omija Cloudflare** (wejście wprost na `*.up.railway.app`),
niesie łańcuch złożony wyłącznie z tego, co wpisał klient — licząc od prawej
trafiamy w jego własny ostatni wpis. Kod tego nie rozstrzygnie, bo nie ma jak
odróżnić „przyszło przez nasz brzeg" od „przyszło z pominięciem brzegu".
Do tego służy token krawędziowy `X-Kuking-Edge-Token` (Blok B w
`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`) i bez panelu Cloudflare nie da się
go wdrożyć. Środowiska preview nie mają Cloudflare przed sobą w ogóle.

Osobno: **`X-Forwarded-Host` zostaje zaufany.** Jest podrabialny tak samo jak
reszta i wpływa na host w adresach z `url()`. Nie odebrałem mu zaufania,
bo nie mam jak sprawdzić, czy brzeg Railway nie polega na nim przy ustalaniu
hosta — a zła odpowiedź na to pytanie to zepsute adresy w mailach na
produkcji, czyli awaria cichsza i gorsza niż sama luka. Właściwym zamknięciem
jest `TrustHosts` z `healthcheck.railway.app` na liście (inaczej deploy pada na
400) i to jest osobna decyzja. Praktyczny zasięg dziś jest mniejszy, niż
wygląda: link „ustaw nowe hasło" buduje **zakolejkowane** powiadomienie
(`UstawienieNowegoHasla implements ShouldQueue`), czyli worker bez żądania
HTTP, który bierze host z `APP_URL`.

---

## 7. Zmienione pliki

| Plik | Co |
|---|---|
| `app/Http/Middleware/NormalizeForwardedFor.php` | **nowy** — cała poprawka |
| `config/proxy.php` | **nowy** — liczba zaufanych przeskoków + recepta na pomiar |
| `bootstrap/app.php` | `prepend()` middleware'u, jawny zestaw nagłówków, przepisany komentarz |
| `app/Support/KluczeLimitow.php` | `trim()` w normalizacji loginu + opis znanego ryzyka z §3.2 |
| `.railway/railway.ts` | usunięta martwa `TRUSTED_PROXIES`, dodana `KUKING_ZAUFANE_PRZESKOKI` |
| `.env.example` | nowa sekcja z opisem, jak tę liczbę zmierzyć |
| `docs/legal/BRAMKA_BETY.md` | §3 przepisana, wiersz W7-01 → CZĘŚCIOWE, uwaga o nieuruchomionych testach |
| `docs/HANDOVER.md` | §4.1 przepisana (to samo sprostowanie po angielsku), §7.3 oznaczone jako zdezaktualizowane |
| `docs/infra/DEPLOYMENT_RUNBOOK.md` | fragment kodu, lista zmiennych, blok env, wiersz 26 tabeli |
| `tests/Feature/PodrobionyNaglowekProxyTest.php` | **nowy** — 8 testów regresyjnych |
| `tests/Feature/ZaufaneProxyTest.php` | komentarz rozstrzygający zarzut z przeglądu R5 §2.3 |
| `tests/Feature/LimitLogowaniaNaKontoTest.php` | dwie asercje na normalizację klucza |

---

## 8. Zanim to scalisz

1. `APP_BASE_PATH=$(pwd) php artisan test` na PostgreSQL — **to nie zostało
   zrobione ani razu.** Szczególnie: `PodrobionyNaglowekProxyTest`,
   `ZaufaneProxyTest`, `LimitLogowaniaNaKontoTest`, `DwuetapowaWeryfikacjaTest`
   (ten używa `REMOTE_ADDR` bez `X-Forwarded-For`, więc nowy middleware jest
   dla niego no-opem, ale to trzeba zobaczyć, nie założyć).
2. `vendor/bin/pint` — formatowania nie zweryfikowałem narzędziem.
3. Obalenie: zakomentować `$middleware->prepend(...)` i sprawdzić, że testy
   2, 3, 5, 6 i 7 z nowego pliku naprawdę czerwienieją.
4. Pomiar `KUKING_ZAUFANE_PRZESKOKI` na stagingu **przed** produkcją —
   recepta w `config/proxy.php`.
