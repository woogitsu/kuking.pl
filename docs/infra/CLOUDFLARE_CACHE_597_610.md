# Cache zdjęć i publicznych stron — #597 / #610

## Stan i granica odbioru

To przygotowanie aplikacji, reguł i kontroli, **nie wdrożenie Cloudflare**.
Plik `cloudflare-cache-rules-597-610.json` zawiera trzy wyłączone reguły
fazy `http_request_cache_settings`. Nie zastępuj nim całego rulesetu:
nadpisałoby to istniejące reguły. Operator dodaje je po przeglądzie obecnej
konfiguracji; dla stagingu zmienia dokładny host we wszystkich trzech.
Składnia i pola pochodzą z dokumentacji dostawcy; projektu nie wysłano
do API Cloudflare w celu walidacji ani zastosowania.

**#597:** anonimowe zdjęcie bez Cookie i Authorization może być cache'owane.
**#610 (24.09.2026): aplikacja jest gotowa, brzeg i TTL czekają na właściciela.**
Do 24.09 HTML gościa zawsze zakładał sesję, wystawiał `kuking-session`
i `XSRF-TOKEN` i niósł token CSRF w dwóch formularzach motywu — więc nie
wolno go było cache'ować. Teraz, **tylko gdy właściciel ustawi
`KUKING_HTML_EDGE_CACHE_SECONDS` > 0**, landing, przepis i profil publiczny
dla gościa bez żadnego ciasteczka renderują się bez sesji, bez tokenu
i z `public, max-age=0, s-maxage=N`. Domyślnie (0) wszystko jest jak
przed #610. Szczegóły, reguła do wklejenia i wycofanie:
rozdział „#610 — HTML gościa na brzegu” na końcu tego dokumentu.
Nie usuwaj `Set-Cookie`, tokenów ani `private/no-store` na innych trasach,
aby uzyskać HIT. Nie wyłączaj ochrony CSRF.

## Własny pomiar przed zmianą

Baza gałęzi: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, początkowo czyste drzewo.
`ZdjeciaChronioneNieWyciekajaTest`: **31 zaliczonych, 183 asercje**.
Nowa kontrola na niezmienionej aplikacji: **4 oblane, 1 zaliczony, 13 asercji**:

- anonimowy publiczny obraz miał `public` razem z `XSRF-TOKEN` i ciasteczkiem sesji;
- zalogowany na publicznym zdjęciu dostawał `public`;
- HTML z sesją nie miał `no-store`;
- błędny publiczny nagłówek wraz z nagłówkiem CDN nie był odcinany przez sesję.

Odmowa prywatnego zdjęcia i krótki podpis dla właściciela działały już wcześniej.
To pomiar aplikacji w izolacji, nie dowód wycieku na produkcji ani odczyt
konfiguracji brzegu. Nie mierzono ruchu, wydajności ani oszczędności żądań.

## Co chroni aplikacja

### Gdzie te klasy są wpięte — `bootstrap/app.php`

Bez tego wpięcia trzy klasy niżej są martwym kodem, a ten rozdział opisuje
zachowanie, którego aplikacja nie ma. Tak właśnie wyglądała gałąź do
21.09.2026 — patrz „Wpięcie, którego brakowało” w rozdziale o weryfikacji.
Wpięcie jest częścią tej zmiany, nie szczegółem wdrożeniowym:

| Klasa | Sposób rejestracji |
|---|---|
| `PreventSharedSessionCache` | `$middleware->prepend([...])` — TRZECIA w stosie globalnym, za `NormalizeForwardedFor` i `ApplySecurityHeaders` |
| `StartSessionExceptAnonymousMedia` | `$middleware->replaceInGroup('web', StartSession::class, ...)` |
| `PreventRequestForgeryExceptMediaCookie` | `$middleware->replaceInGroup('web', PreventRequestForgery::class, ...)` |

Dwie ostatnie DZIEDZICZĄ po klasach frameworka, więc muszą je **zastąpić**,
a nie dojść obok. Dopisane obok dawałyby dwie sesje i dwie ochrony CSRF
w jednym stosie, a rodzic i tak wystawiałby ciasteczko przy odczycie zdjęcia.

Podmiana idzie przez `replaceInGroup`, nie `replace`: `replace()` obsługuje
wyłącznie stos GLOBALNY, a obie klasy frameworka siedzą w grupie `web` —
`replace()` po prostu by w nie nie trafił i nie zgłosił tego.

Kolejność w stosie nie zmienia się. `Kernel::$middlewarePriority` wymienia
klasy frameworka, ale `SortedMiddleware::middlewareNames()` sprawdza też
`class_parents()`, więc podklasa dziedziczy pozycję rodzica. Lista wyjątków
CSRF z `validateCsrfTokens()` działa dalej, bo trzyma ją WŁAŚCIWOŚĆ STATYCZNA
`PreventRequestForgery`, wspólna dla podklasy.

`PreventSharedSessionCache` stoi trzeci, a nie pierwszy, bo
`NormalizeForwardedFor` ma zostać pierwszy (W7-01 / SEC-01). Na odpowiedzi
kolejność jest odwrotna niż na żądaniu, więc „trzeci od góry” znaczy „trzeci
od końca” — ta klasa i tak dotyka odpowiedzi po grupie `web`, po routerze
i po module wyjątków. Nad nią zostają dwie klasy, z których żadna nie dokłada
ciasteczka ani nagłówka cache.

### Co robią same klasy

Zewnętrzny `PreventSharedSessionCache` ustawia `private, no-store` dla sesji,
Cookie, Authorization, zalogowanego, odpowiedzi ustawiającej cookie i błędów.
Usuwa nadrzędne nagłówki CDN/Surrogate, które mogłyby unieważnić zakaz.
Obejmuje również odpowiedzi przed grupą `web`. Nie usuwa ciasteczek.

Tylko `GET/HEAD media.show` bez żadnych ciasteczek i Authorization może
ominąć trwałą sesję. Dostaje pusty magazyn w pamięci potrzebny middleware
błędów; nie czyta ani nie zapisuje sesji w PostgreSQL. Ochrona CSRF działa
jak dotąd; jej rozszerzenie jedynie nie wystawia cookie tokenu dla takiego
odczytu zdjęcia. Formularze nadal dostają sesję i token.

Policy rodzica jest sprawdzana przed podpisem. Gdy widz jest zalogowany,
nawet publiczne zdjęcie ma `private, no-store` zarówno na 302, jak i w
podpisanym parametrze `response-cache-control` R2. Błędne/obce cookie również
wyklucza cache. Nie ma nowej kolumny widoczności ani cache decyzji Policy.

## Przyjęta decyzja właściciela: godzina dla wcześniej publicznego zdjęcia

20.09.2026 właściciel wybrał: „Zaakceptuj godzinę dla wcześniej publicznego
zdjęcia”. Rozróżnienie wynika z aktualnej decyzji `dlaAnonima`, nie z tego,
czy zalogowany widz jest właścicielem.

| Rodzaj w chwili wydania podpisu | Podpis R2 | Cache anonimowego 302 i bajtów |
|---|---:|---:|
| publiczny | 60 minut | 30 minut |
| dostępny wyłącznie prywatnie | 5 minut | `private, no-store` |

Konfiguracja pozwala skrócić oba okna; kod ogranicza je odpowiednio do 60 i 5
minut. `KUKING_MEDIA_PUBLIC_SIGNED_URL_MINUTES` jest nową opcją, istniejąca
`KUKING_MEDIA_SIGNED_URL_MINUTES` dotyczy zdjęć chronionych.

`/zdjecia/{uuid}/{wariant}` **nie jest podpisany** — przechodzi przez Policy.
Podpisany i ograniczony w czasie jest dopiero adres w `Location` odpowiedzi
302 (`X-Amz-Date`, `X-Amz-Expires`, `X-Amz-Signature`). Test używa prawdziwego
podpisywania SDK z fikcyjnymi kluczami, bez wysyłania żądania do R2.

Zmiana wpisu na prywatny zamyka **nowe** żądanie originu, ale nie unieważnia
wydanego podpisu. Kopia bajtów pobrana przed końcem godziny może być świeża
jeszcze przez 30 minut, zatem granica wynikająca z podpisu i cache wynosi
**do 90 minut od wydania podpisu**. Nie jest to obietnica skasowania kopii,
którą ktoś wcześniej pobrał. Najszerszy rodzic nadal wygrywa (D-020): jeśli
to samo zdjęcie ma innego publicznego rodzica, pozostaje publiczne.

## Reguły do ręcznego przygotowania

1. Zachowaj istniejące reguły statycznych assetów. Usuń sprzeczne instrukcje
   „Cache Everything” dla domeny bucketu — D-020 nadal obowiązuje.
2. Przygotuj regułę zdjęć z JSON. Ustaw **Eligible for cache**, Edge TTL:
   **Use cache-control header if present, bypass cache if not**
   (`bypass_by_default`), Browser TTL: **Respect origin**.
   Nie ustawiaj liczbowego Edge TTL ani Status Code TTL. Zachowaj Origin
   Cache Control, nie usuwaj Set-Cookie, nie ignoruj query string w kluczu.
3. Reguła ochronna BYPASS ma być **ostatnia** po wszystkich regułach eligible.
   Pomija każde cookie (także remember-me i wygląd), Authorization, query
   string, metody inne niż GET/HEAD i wskazane trasy konta. To świadomie
   szersza granica niż sama nazwa sesji. Nazwa z konfiguracji to zwykle
   `kuking-session`; stary przykład `kuking_session` nie jest wiarygodny.
4. HTML włączaj dopiero według rozdziału #610 niżej: najpierw zmienna
   środowiskowa na stagingu, potem reguła, potem sonda. Sam BYPASS bez
   pozytywnego pomiaru publicznego wariantu nie daje zgody na produkcję.
5. Przejrzyj również Workers, Page Rules i reguły odpowiedzi — zewnętrzny
   override może unieważnić nagłówki aplikacji. Kod PHP tego nie wykryje.
6. Do pomiaru na stagingu właściciel włącza najpierw ochronny BYPASS, potem
   regułę zdjęć; dopiero dodatni odbiór pozwala powtórzyć to na produkcji.
   Porażka sondy oznacza wyłączenie eligible i purge, nie podnoszenie TTL.

W aktualnej dokumentacji Cloudflare ostatnia pasująca reguła wygrywa dla
sprzecznych ustawień. `bypass_by_default` odmawia cache przy braku nagłówka;
`respect_origin` ma w tej sytuacji domyślny fallback i nie jest tym samym.
Źródła sprawdzone 20.09.2026:
[kolejność](https://developers.cloudflare.com/cache/how-to/cache-rules/order/),
[ustawienia](https://developers.cloudflare.com/cache/how-to/cache-rules/settings/),
[Set-Cookie](https://developers.cloudflare.com/cache/concepts/cache-behavior/).

## Odbiór stagingu przez istniejącą sondę

Zależność w gałęzi: `cb560b3d46b70d1d66cbcd75934d3d6d1349934f` z
`gpt/sonda-wdrozenia`. Używamy jej parsera kodu HTTP i kompletnych nagłówków
z jednego wywołania curl (#806), bez powielania przyrządu. Nowy tryb używa
GET bez podążania za 302. Nie wypisuje cookie, podpisów ani Location.

Na izolowanym stagingu przygotuj publiczny i prywatny wpis ze zdjęciami
jednego konta testowego. Wyeksportuj jego działającą sesję do lokalnego pliku
cookies curl/Netscape, poza repo; nadaj plikowi dostęp tylko właściciela.
Nie podawaj wartości sesji w poleceniu ani w raporcie.

```bash
CACHE_KIND=media \
CACHE_PUBLIC_PATH=/zdjecia/UUID_PUBLICZNEGO/feed \
CACHE_PRIVATE_PATH=/zdjecia/UUID_PRYWATNEGO/feed \
CACHE_COOKIE_FILE=/bezpieczny/katalog/cookies.txt \
bash scripts/sprawdz-wdrozenie.sh staging.kuking.pl --cache-gate
```

Sonda najpierw porównuje anonimowe i uwierzytelnione `/ustawienia`, żeby
nie zaliczyć wygasłej sesji. Potem wymaga `private` **i** `no-store` oraz
BYPASS/DYNAMIC na obu treściach dla zalogowanego, 404 dla anonima na
prywatnej treści, braku **jakiegokolwiek** Set-Cookie na publicznej odpowiedzi
i HIT przy drugim jej pobraniu. Na koniec ponawia zalogowanego pod adresem
już ogrzanym przez anonima. Brak transportu, nagłówka, dodatniego TTL albo
HIT kończy się kodem 1, nie ostrzeżeniem. To odbiór konkretnych próbek,
nie matematyczny dowód wszystkich konfiguracji brzegu.
Przy zdjęciach sonda wymaga także HTTPS Location z sygnaturą, datą i terminem
do 3600 sekund oraz cache do 1800 sekund, krótszym niż termin podpisu.
Nie weryfikuje kryptograficznie sygnatury i nie pobiera bajtów z R2.

Dla HTML użyj `CACHE_KIND=html`, publicznej ścieżki treści i prywatnego
przepisu widocznego właścicielowi (200) oraz niewidocznego gościowi (403 pod
bieżącym adresem przepisu albo 404 — sonda przyjmuje oba dla HTML, dla zdjęć
tylko 404). Bez `KUKING_HTML_EDGE_CACHE_SECONDS` > 0 ten przebieg **ma
odmówić** odbioru — to jest kontrola, że flaga naprawdę coś przełącza.

Osobno zmierz odpowiedź z prawdziwego R2: podpis i nagłówki bajtów, wygaśnięcie,
zmianę publiczny → prywatny, blokadę, ukrycie przez moderatora, usunięcie oraz
purge istniejącego `PurgePublicMediaCache`. Nie przekazuj cookies Kuking do R2.
Jednoplikowe purge może zależeć od warunków reguły i klucza; nie zakładaj,
że zadziałało, bez kolejnego pobrania. Sama sonda GET nie wykonuje tych mutacji.

Zwykły tryb sondy nadal wymaga `SESSION_COOKIE` (patrz
`SONDA_WDROZENIA_805_808.md`). Jego dawnych porad o domenie CDN nie stosuj:
bucket wariantów ma pozostać bez domeny publicznej (D-020).

## Wycofanie i ograniczenia

Najpierw wyłącz reguły eligible, zachowaj końcowy BYPASS i wyczyść istniejące
wpisy cache. Dopiero potem cofaj commit aplikacji przez `git revert`.
Brak migracji. Skrócenie konfiguracji wpływa tylko na nowe podpisy, nie na
już wydane. Purge Cloudflare nie czyści cache przeglądarek ani zapisanych kopii.

Nie zmieniono Cloudflare, DNS, Railway ani R2. Nie wykonano push ani PR.
Nie potwierdzono produkcyjnego HIT, obsługi parametrów przez R2, działania
purge ani kompletności obecnych reguł. Odbiór infrastruktury zostaje dla
właściciela. Blokada #610 przez sesyjny HTML jest zdjęta w aplikacji
(24.09.2026, za flagą); dopuszczalne opóźnienie ukrycia HTML pozostaje
decyzją właściciela — patrz rozdział #610.

## Weryfikacja lokalna — 20 września 2026

> **Te liczby nie opisują gałęzi, na której leżą.** Powstały na drzewie
> stanowiska `gpt-cloudflare-cache`, które MIAŁO wpięte middleware
> w `bootstrap/app.php`; commit odzyskujący tę pracę tego pliku nie przeniósł.
> Pomiar gałęzi w jej rzeczywistym stanie — i to, czego brakowało — stoi
> w rozdziale „Wpięcie, którego brakowało” niżej.

Wszystkie poniższe pomiary wykonałem w tym zadaniu, na PostgreSQL
`127.0.0.1:55439`, w bazie `kuking_flota_gpt-cloudflare-cache`:

- Nietknięte drzewo `4c811cc7`: istniejąca ochrona zdjęć — 31 testów,
  183 asercje, zielone. Nowa kontrola prywatności przed poprawką —
  4 porażki i 1 zaliczenie (13 asercji). Wykazała cookies publicznego zdjęcia,
  publiczny cache zalogowanego i brak wymuszonego zakazu cache sesji.
- Po zmianach: pełny końcowy przebieg — 4467 zaliczonych, 1 porażka,
  83994 asercje, 439,32 s. Jedyną porażką był skrót ścieżki zdjęć
  w dokumentacji, rozpoznany jako martwa trasa. Po zastąpieniu go rzeczywistą
  trasą z parametrami kontrola dokumentacji przeszła: 3 testy, 49 asercji.
  Całego zestawu po tej zmianie wyłącznie dokumentacyjnej nie powtarzano.
- Cztery kontrole ujemne: każda przeszła sekwencję zielony → czerwony po
  celowym zepsuciu → zielony po odtworzeniu. Sprawdzono odtworzenie bajtów
  i czasu plików. Dowód: [kontrole-ujemne.txt](evidence/cache597-610/kontrole-ujemne.txt).
- `vendor/bin/pint`: 1161 plików, wynik poprawny. PHPStan: brak błędów.
  Sprawdzenie składni czterech skryptów Bash: poprawne.

Z pełnego przebiegu jawnie wyłączono `ProbaOdtworzeniaTest`, zgodnie
z instrukcją właściciela o współdzielonej bazie tego testu. Pozostają także
standardowe wyłączenia grup skonfigurowane przez projekt. Nie przebudowywano
assetów: zmiana nie dotyczy interfejsu ani zasobów frontendowych.

## Wpięcie, którego brakowało — 21 września 2026

Powyższy rozdział „Co chroni aplikacja” opisywał do dziś zachowanie, którego
aplikacja **nie miała**. W commicie odzyskującym pracę tego stanowiska
(`fbaad9bf`) weszły trzy klasy middleware, ale `bootstrap/app.php` **nie
wszedł wcale**. Trzy klasy nie były zarejestrowane nigdzie: jedyne trafienie
grepa po ich nazwach w całej gałęzi było w TEJ dokumentacji, która opisywała
je tak, jakby działały. To był martwy kod plus dokumentacja mówiąca nieprawdę
o stanie repozytorium — gorsza kombinacja niż sam brak kodu, bo czytelnik nie
miał powodu sprawdzać.

Własny pomiar na gałęzi `gpt-cloudflare-cache` (worktree
`gpt-cloudflare-cache-ODZYSK`, runtime WSL, PostgreSQL `127.0.0.1:55439`,
baza `kuking_flota_gpt-cloudflare-cache-ODZYSK`):

| | `CloudflareCachePrivacyTest` | Pełny zestaw |
|---|---|---|
| przed wpięciem | **7 oblanych, 5 zaliczonych, 40 asercji** | **11 oblanych, 4458 zaliczonych, 83966 asercji**, 317,36 s |
| po wpięciu | **12 zaliczonych, 65 asercji** | **4469 zaliczonych, 0 oblanych, 84006 asercji**, 297,54 s |

Z 11 porażek zestawu przed zmianą 7 to ta kontrola prywatności. Pozostałe
cztery NIE należą do tego wpięcia i nie zostały nim naprawione: trzy to
`SondaWdrozeniaTest::test_usuwanie_preview_nie_ukrywa_bledu_cli`, domknięte
osobnym commitem `18054b5d` (sonda, nie cache), a jedna to
`ProbaOdtworzeniaTest`, który dzieli bazę z innymi stanowiskami i jest
w tym rozdziale od początku opisany jako wyłączany.

Zestaw uruchomiono po zmianie dwa razy. Drugi przebieg dał **1 oblany,
4468 zaliczonych, 84001 asercji, 297,92 s** — jedyną porażką był
`ProbaOdtworzeniaTest`, ten sam współdzielący bazę. Odtworzony w izolacji:
**1 zaliczony, 7 asercji**. To kontencja, nie regresja; podaję oba przebiegi,
żeby liczba w tabeli nie wyglądała na wybraną.

`vendor/bin/pint`: 1161 plików, bez uwag. PHPStan: `No errors`.

**Co to wpięcie zmienia w aplikacji poza zdjęciami.** Jedna rzecz, i to
w nagłówkach, nie w zachowaniu: każda odpowiedź niosąca stan klienta —
sesję, ciasteczko, `Authorization`, zalogowanego widza — oraz każda odpowiedź
4xx/5xx dostaje teraz `Cache-Control: private, no-store` i traci nagłówki
`CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control` i `Surrogate-Control`.
W praktyce obejmuje to cały HTML serwisu, bo grupa `web` zakłada sesję także
gościowi. Nic z tego nie było wcześniej publicznie cache'owane, więc żadna
strona nie przestaje działać — zmienia się to, że zakaz jest teraz wypisany,
a nie domyślny.

Logowanie, formularze i CSRF działają bez zmiany i jest to zmierzone, nie
założone: podmieniona jest wyłącznie metoda `addCookieToResponse()`
(wystawianie ciasteczka `XSRF-TOKEN`), a walidacja tokenu zostaje w rodzicu.
Odczyt zdjęcia bez żadnego ciasteczka jest jedynym żądaniem, które nie zakłada
trwałej sesji. Pełny zestaw — łącznie z `PolitykaBezpieczenstwaTest`,
`EkranyBleduMajaNaglowkiBezpieczenstwaTest`, testami logowania, 419 i 429 —
jest zielony w całości.

Ustalenie W7-01 / SEC-01 zostało nietknięte: `NormalizeForwardedFor` jest
nadal pierwszy, `ApplySecurityHeaders` drugi. Pilnuje tego
`EkranyBleduMajaNaglowkiBezpieczenstwaTest::test_normalize_forwarded_for_zostaje_pierwszy…`,
który czyta stos globalny po indeksie — i dlatego `PreventSharedSessionCache`
stoi trzeci.

Nie zmieniono Cloudflare, DNS, Railway ani R2. Nie wykonano push ani PR.
Wszystko, co ten rozdział mówi o brzegu, pozostaje nieodebrane.

Sondę przyjęto jako zależność z commita
`cb560b3d46b70d1d66cbcd75934d3d6d1349934f` gałęzi `gpt/sonda-wdrozenia`.
Jej testy uruchomiono ponownie lokalnie; historycznych pomiarów autora sondy
nie przedstawiono tutaj jako własnych. Testy podpisu używają SDK bez połączenia
z R2, a testy bramki — atrapy transportu. Nie zastępują odbioru infrastruktury.

## #610 — HTML gościa na brzegu

Stan wyjściowy zmierzony 24.09.2026 jednym `GET https://kuking.pl/` bez
ciasteczek (wartości ciasteczek nie zapisano): `HTTP/2 200`,
`Cache-Control: no-store, private`, `Set-Cookie: XSRF-TOKEN`,
`Set-Cookie: kuking-session`, `Vary: Accept-Encoding`,
`CF-Cache-Status: DYNAMIC`. Każde wejście gościa idzie więc do Laravela
i zakłada wiersz w `sessions`.

### Co robi aplikacja (kod w repozytorium)

Jedna reguła, `App\Support\PublicznyHtmlGoscia`, czytana przez trzy
middleware i dwa formularze motywu:

| Warunek | Dlaczego |
|---|---|
| `KUKING_HTML_EDGE_CACHE_SECONDS` > 0, obcięte do 300 s | domyślnie 0 = zachowanie sprzed #610; górna granica niezależna od zmiennej |
| trasa `landing`, `recipes.show`, `profile.show` | strony z ruchem z wyszukiwarki; `/odkryj`, `/szukaj`, tryb gotowania, listy obserwujących — nie |
| GET/HEAD, **pusty query string** | paginacja i `?q=` zostają dynamiczne; ta sama granica jest w regule brzegu |
| **żadnego** ciasteczka, `Authorization`, zalogowanego | motyw, skala tekstu, remember-me i sesja zmieniają HTML |
| odpowiedź 200, bez `Set-Cookie`, bez `name="_token"` i `csrf-token` w treści | ostatni bezpiecznik: dopisany kiedyś formularz z tokenem daje `no-store`, nie wyciek |

Gdy wszystko się zgadza: brak sesji w bazie, brak `XSRF-TOKEN`, formularze
motywu bez `_token`, nagłówki `Cache-Control: public, max-age=0, s-maxage=N`
oraz `Vary: Cookie, Authorization`. W każdym innym przypadku
`PreventSharedSessionCache` wysyła `private, no-store` jak dotąd.
`max-age=0` znaczy, że przeglądarka nie trzyma strony gościa u siebie —
po zalogowaniu nie zobaczy jej z dysku.

Świadomy koszt: w odpowiedzi z brzegu nonce CSP (nagłówek i znaczniki
`<script nonce>`) oraz `X-Request-Id` są te same dla wszystkich gości przez
N sekund. Nagłówek i treść pochodzą z jednej odpowiedzi, więc CSP działa;
nonce przestaje być tajemnicą tylko wobec kogoś, kto i tak widzi tę samą
publiczną stronę, a wstrzyknąć musiałby do niej treść, zanim powstała.
Nie jest to zamiana CSP na słabszą politykę, ale warstwa obronna jest
cieńsza na tych trzech trasach.

Przełącznik motywu na stronie z brzegu nie ma tokenu. Zapis przechodzi
sprawdzeniem pochodzenia: `Sec-Fetch-Site: same-origin` (wbudowane
w `PreventRequestForgery` Laravela 13) albo — tylko dla `theme.update`
i tylko bez `Sec-Fetch-Site` (Safari przed 16.4) — `Origin` równym naszemu
hostowi. Obcy `Origin`, `cross-site` i brak obu nagłówków dają 419.

Testy: `tests/Feature/CacheHtmlGosciaTest.php` (macierz gość / zalogowany /
ciasteczko / `Authorization` × strona publiczna / strona z formularzem /
query / 403 / 404 / przekierowanie; flaga wyłączona jako kontrola, że bez
decyzji właściciela nic się nie zmienia; kontrole dodatnie bezpiecznika na
trasach-atrapach). Kontrole ujemne wykonane ręcznie 24.09.2026, każda
czerwona po zepsuciu i zielona po przywróceniu: ignorowanie ciasteczek,
usunięcie bezpiecznika tokenu, bezwarunkowe `@csrf` w panelu wyglądu,
wyjątek pochodzenia bez zawężenia do motywu, publiczny TTL dla odpowiedzi
innej niż 200, sonda bez przyjęcia 403.

### Okno nieświeżości — do świadomej akceptacji

Przepis przełączony na prywatny, ukryty przez moderację, usunięty albo
profil zamknięty (w tym usunięcie konta z RODO) może być widoczny z brzegu
**do N sekund** od ostatniego pobrania przez gościa. Proponowane N = **120**.
Aplikacja nie wysyła `stale-while-revalidate` ani `stale-if-error`.
Czyszczenie cache HTML przy edycji przepisu (punkt 4 issue) **nie jest
zrobione** — krótki TTL jest jedynym ograniczeniem. To temat na osobne
zadanie, jeśli 120 s okaże się za dużo.

**Do potwierdzenia w panelu Cloudflare** (z repozytorium tego nie widać):

- czy „Always Online” albo „Serve stale content” nie wydłużają okna ponad N
  przy awarii originu;
- czy Bot Fight Mode ustawia ciasteczko `__cf_bm` zwykłym przeglądarkom —
  wtedy od drugiego żądania reguła brzegu i aplikacja widzą ciasteczko i HIT
  dostaje tylko pierwsze wejście (roboty wyszukiwarek zwykle ciasteczek nie
  trzymają, więc główny cel zostaje osiągnięty); 24.09.2026 zwykłe `GET /`
  go nie dostało;
- czy `Vary: Cookie` nie wyłącza cache w Cloudflare (według dokumentacji
  dostawcy `Vary` poza `Accept-Encoding` jest ignorowane — stąd warunek na
  ciasteczko musi być w regule, a nie tylko w nagłówku); rozstrzyga to
  sonda: drugie pobranie musi dać HIT.

### Reguła do wklejenia (Caching → Cache Rules)

Jest w `cloudflare-cache-rules-597-610.json` jako druga, wyłączona reguła.
Ręcznie w panelu:

- **Nazwa:** `Kuking: HTML goscia bez ciasteczek (#610)`
- **Wyrażenie (Edit expression):**

```text
(http.host eq "kuking.pl"
 and http.request.method in {"GET" "HEAD"}
 and (http.request.uri.path eq "/"
      or starts_with(http.request.uri.path, "/przepisy/")
      or starts_with(http.request.uri.path, "/@"))
 and http.request.uri.query eq ""
 and http.cookie eq ""
 and not any(http.request.headers["authorization"][*] ne ""))
```

- **Cache eligibility:** Eligible for cache.
- **Edge TTL:** *Use cache-control header if present, bypass cache if not*
  (`bypass_by_default`). **Nie** wpisuj liczby — o TTL decyduje
  `s-maxage` z aplikacji, a `no-store` na trasach spoza listy (np.
  tryb gotowania i listy obserwujących pod profilem) musi dalej wygrywać.
- **Browser TTL:** *Respect origin*.
- **Nie** włączaj „Ignore query string”, „Cache deception armor” zostaw
  domyślnie, nie usuwaj `Set-Cookie`.
- **Kolejność:** przed końcową regułą BYPASS (trzecia w JSON), która musi
  zostać **ostatnia**. Ostatnia pasująca reguła wygrywa.

Warunek bezwzględny: `http.cookie eq ""`. To szerzej niż „brak ciasteczka
sesji” — obejmuje też remember-me, motyw i skalę tekstu, które zmieniają
HTML. Końcowy BYPASS powtarza to samo z drugiej strony.

### Kolejność włączenia

1. Staging: `KUKING_HTML_EDGE_CACHE_SECONDS=120`, deploy. Bez reguły brzegu
   nic się nie cache'uje (Cloudflare nie trzyma HTML bez reguły), a nagłówki
   można obejrzeć: `curl -s -o /dev/null -D - https://staging.kuking.pl/`
   — ma być `public, max-age=0, s-maxage=120` i **brak** `Set-Cookie`.
2. Staging: reguła z hostem `staging.kuking.pl`, potem sonda
   `CACHE_KIND=html` (rozdział „Odbiór stagingu” wyżej) dla przepisu
   i osobno dla profilu i landingu.
3. Produkcja: zmienna, deploy, reguła, ta sama sonda.

### Wycofanie

Każdy krok cofa się niezależnie i w kolejności od najszybszego:

1. **Wyłącz regułę HTML** w panelu (sekundy). Końcowego BYPASS nie ruszaj.
2. **Caching → Configuration → Purge Cache → Custom Purge** po prefiksach
   `kuking.pl/przepisy/`, `kuking.pl/@` i adresie `kuking.pl/` — albo
   „Purge Everything”, jeśli podejrzewasz wyciek. (Purge po prefiksie może
   zależeć od planu — do potwierdzenia; „Purge Everything” działa zawsze.)
3. **`KUKING_HTML_EDGE_CACHE_SECONDS=0`** i redeploy — aplikacja wraca do
   sesji i `private, no-store` na wszystkich stronach.
4. Dopiero jeśli coś jest nie tak w samym kodzie: `git revert` commita #610.
   Brak migracji, brak danych do odtworzenia.
