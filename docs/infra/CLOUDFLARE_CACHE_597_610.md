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
**#610: NIE WŁĄCZAĆ.** Dziś HTML gościa uruchamia sesję, wystawia ciasteczka
i zawiera formularze z CSRF. Landing, przepis i profil nie stają się statyczne
od tego, że widz nie jest zalogowany. Reguła HTML jest projektem do późniejszego
odbioru, nie gotową optymalizacją. Nie usuwaj `Set-Cookie`, tokenów ani
`private/no-store`, aby uzyskać HIT. Nie wyłączaj ochrony CSRF.

Przed dopuszczeniem HTML potrzebna jest osobna implementacja renderowania
bez sesji i bez danych klienta, zachowująca działanie formularzy, wygląd
gościa, komunikaty walidacji oraz CSP. Następnie ta sama bramka musi przejść
dla landingu, przepisu i profilu osobno. Proponowane 120 sekund cache HTML
i opóźnienie ukrycia treści wymagają decyzji właściciela; w tym zadaniu nie
nadano HTML publicznego TTL i nie zapisano tej propozycji jako wymagania testu.

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
4. HTML pozostaw wyłączony. Sam BYPASS bez pozytywnego pomiaru publicznego
   wariantu nie daje zgody na włączenie cache HTML.
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

Po przygotowaniu bezsesyjnego HTML użyj `CACHE_KIND=html`, publicznej ścieżki
treści i prywatnego przepisu widocznego właścicielowi (200) oraz niewidocznego
gościowi (404). W dzisiejszym stanie ten przebieg **ma odmówić** odbioru.

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
właściciela. #610 pozostaje zablokowane technicznie przez sesyjny HTML;
osobną decyzją jest dopuszczalne opóźnienie ukrycia HTML.

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