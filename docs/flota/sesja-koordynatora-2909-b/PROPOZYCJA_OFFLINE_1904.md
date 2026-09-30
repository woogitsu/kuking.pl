# Propozycja wdrożenia #1904 „Wybrane przepisy dostępne do czytania offline w PWA"

Stan: odczyt `origin/main` (po `git fetch`), issue #1904 i komentarz właściciela przez MCP github. Nic nie zmieniałem w repo, nie tworzyłem issues ani komentarzy. Oznaczenia: [kod] to sprawdzone w kodzie, [wiedza] to moja wiedza o przeglądarkach spoza repo, do potwierdzenia na urządzeniu.

## 0. Status i bramka

- #1904 leży na liście „V2, ale nie teraz" (`docs/FEATURES.md`). D-331 odblokowała pięć innych pozycji, a #1904 wymienia wprost jako „zostają zakazane bez nowej decyzji". Komentarz właściciela z 26.09 mówi „V2 i teraz jej nie budujemy". [kod]
- D-333 (tabela z 29–30.09) nie ma wiersza o #1904. Są tam tylko #2016 (synchronizacja postępu, odhaczone kroki, minutniki wprost poza zakresem), #27 etap 2 (lista zakupów, „offline poza zakresem") i F1–F6.
- Issue samo wymaga „najpierw przetestować z użytkownikami, czy potrzebują tego częściej niż wydruku lub paczki danych".
- **Zanim powstanie linijka kodu, potrzebna jest nowa decyzja właściciela (D-335 albo kolejny numer), która przenosi #1904 z „nie teraz" do „wolno budować".** Wpis powinien też przenieść pozycję w `docs/FEATURES.md`, tak jak zrobiono przy D-331. Lista „Nie wcześnie" (DM, live, marketplace, payouts, punkty, masowy import) nie jest tu dotknięta. Propozycja jej nie narusza.
- `docs/product/MATERIALY_OFFLINE_OBIETNICA_30.md` §2.2 mówi, że „pełne cudze przepisy i zdjęcia offline wymagają osobnego zgłoszenia i decyzji o własności, zgodach i późniejszym ukryciu". Ta propozycja odpowiada na te trzy punkty w §4 i §5.
- Offline nie jest kopią zapasową i nie może wzmacniać claimu „Twoje przepisy nie zginą". D-333 zabrania tego claimu do czasu odtworzenia bazy i zdjęć. W tekstach nie używamy słowa „kopia zapasowa".

## 1. Co jest dziś (stan kodu)

**Service worker** `public/sw.js` (`WERSJA = 'kuking-alfa-013'`) [kod]:
- W `install` robi precache `/offline.html`, `/icons/kuking-mark.svg`, `/icons/kuking-icon-192.png`.
- `activate` **kasuje każdy cache o nazwie innej niż `WERSJA`**.
- Nawigacje: `fetch(request).catch(() => caches.match('/offline.html'))`. Nie ma timeoutu, więc przy słabym zasięgu (który jest właśnie scenariuszem z issue) przeglądarka po prostu czeka.
- `/build/*` jest cache-first, `/icons/*` i `manifest` network-first z zapasem w cache. Reszta idzie do sieci bez dotykania. Tylko GET i tylko same-origin.
- Push (`push`, `notificationclick`, D-303) jest w tym samym pliku.

**Rejestracja**: `resources/js/service-worker.js` czyta `<meta name="kuking-service-worker" content="/sw.js?v=<commit>">` z `layout.blade.php`. Scope `/`, `updateViaCache: 'none'`. Caddy daje `/sw.js` nagłówek `no-store`. Dokument `docs/design/AKTUALIZACJA_PWA_ALFA_013.md` opisuje realny incydent: CDN utrzymywał stary `sw.js` przez `max-age=14400`. **Każdy nowy plik statyczny, który SW precache'uje, dostaje tę samą chorobę.**

**`public/offline.html`**: statyczny, inline `<style>`, bez JS. „Spróbuj ponownie" ma `href=""` (ponawia bieżący adres, #749). Testy: `scripts/offline-ponowienie.test.mjs` i `scripts/service-worker-marka.test.mjs`. Oba uruchamiają prawdziwy `sw.js` w `node:vm` z atrapą `caches`. Oba **asercją wymuszają**, że `/przepisy/rosol-fixture` offline daje `nowe:/offline.html`. Tę asercję trzeba będzie świadomie zmienić.

**Manifest** `public/manifest.webmanifest`: standalone, scope `/`, skróty „Dodaj zdjęcie" i „Mój zeszyt" (`/zeszyt`). Bez zmian w MVP. Opcjonalnie nowy skrót „Przepisy bez internetu".

**Tryb gotowania** `CookingModeController` (`/przepisy/{slug}/gotuj`) [kod]:
- Każdy krok to **osobne żądanie serwera** (`?krok=N`). Nie ma SPA, więc offline wymaga osobnego czytnika renderowanego po stronie klienta.
- Odhaczenia siedzą w sesji Laravela (klucz `gotowanie.<id>.zrobione`).
- Opcjonalnie lądują na koncie w `cooking_progress` (#2016, wygasają po 24 h).
- Minutniki i Wake Lock to `sessionStorage` tej karty. Bez JS strona ma zdanie „Ustaw sobie kuchenny minutnik na X".
- Wejście przez `RecipePolicy::view` plus `DziennikWgladu` (wgląd moderatora w przepis niewidoczny dla roli zostawia ślad w `audit_log`, D-333).

**Policy i zdjęcia** [kod]:
- `RecipePolicy::view`: szkic, `hidden` i `removed` widzi autor lub moderator. Są warunki: konto autora `banned`/`pending_delete` (`jestDostepnyJakoAutor`), blokada w obie strony, widoczność `public`/`followers`/`private`.
- `MediaController::show` (`/zdjecia/{media}/{wariant}`, `DostepDoZdjecia`) odpowiada **302 na podpisany adres R2** (5 min dla treści chronionej, 60 min dla publicznej). Odmowa to 404. Bajty nie idą przez PHP (celowo, ochrona puli FPM).
- Nagłówki `private, no-store` dla chronionych. Warianty: `thumb` 320, `feed` 960, `large` 1600 px. `media.metadata.variants[*].bytes` istnieje, więc rozmiar da się policzyć przed pobraniem.
- `recipes.source_scan_media_id` (skan odręcznej kartki z nazwiskami) to najgorszy przypadek opisany w `DostepDoZdjecia`. **Nigdy offline.**

**Nagłówki** [kod]:
- `PreventSharedSessionCache` daje `private, no-store` każdej odpowiedzi z Cookie/user/Authorization/4xx/5xx. Publiczny cache (#610, `s-maxage`) dostaje tylko strona z `PublicznyHtmlGoscia`, bez sesji. Nowe endpointy `auth` dostaną `no-store` automatycznie.
- CSP (`ApplySecurityHeaders`) ma `default-src 'self'`, `worker-src 'self'`, **`connect-src 'self'`** (plus Turnstile/analityka), `img-src 'self' data: blob: https:`. Policy jest wymuszana i dublowana w Report-Only.
- Pliki statyczne z `public/` (np. `offline.html`) idą z Caddy **bez CSP** (celowo, CSP tylko z Laravela).

**Wylogowanie**: `POST /logout`, komponent `x-wyloguj` ma `form[data-wyloguj]` z hookiem JS w `powiadomienia-push.js`. Hook wypisuje Web Push, potem `formularz.submit()`. **Gotowy precedens do czyszczenia kopii.** Sesja trwa 10080 min (7 dni).

**Zeszyt i „Zapisane"**: `collections`/`collection_items` (przepisy i wpisy), `is_default`, wspólne zeszyty (D-302), „wyjmij niedostępne" (#773, `removeUnavailable`). Licznik „ile osób zapisało" (D-081) ma ścisłe granice.

**API**: `RecipeResource` (`/api/v1/przepisy/{uuid}`, Sanctum, wyłączone flagą) to istniejący kształt JSON z Policy. Sesyjna PWA go nie użyje (brak tokenu), ale nadaje się na wzór.

**Skrypty przeglądarkowe**: `scripts/przegladarka/*.test.mjs` (node:test + Playwright Chromium, serwują fixture z `http.createServer`, część z `serviceWorkers: 'block'`). Dalej `scripts/service-worker-aktualizacja.mjs`, `nawigacja-zoom.mjs` i `menu-konta.mjs` (te używają `context.serviceWorkers()`).

## 2. Ocena „co wolno trzymać offline"

| Element | Wolno? | Uwaga |
|---|---|---|
| Tytuł, opis, porcje, czas, składniki (grupy, notatki, „Zamiast tego"), kroki | Tak | Z `RecipePolicy::view` + status opublikowany |
| Autor (nazwa wyświetlana, `@login`), adres źródła jako tekst | Tak | Podpis „Przepis: … na Kuking", bez avatara |
| Zdjęcie główne i zdjęcia kroków, **wariant `feed` 960 px** | Tak, opcjonalnie | Nigdy `large` ani oryginał. Bez skanu kartki |
| Skan kartki, komentarze, „Ugotowałem" innych, liczniki zapisów, notatki z Zeszytu, e-mail, dane innych kont | **Nie** | Minimalizacja, D-081, prywatność |
| Wartości odżywcze, alergeny | Nie w MVP | To szacunek AI/tabel, łatwo się starzeje. Dopisek „sprawdź aktualny przepis" zamiast nieaktualnego szacunku |
| Przepis `followers` cudzy | Nie | Relacja może zniknąć bez sposobu odebrania kopii offline |
| Przepis `hidden`/`removed`/szkic | **Nigdy** (także autorowi i moderacji) | Ominąłoby `DziennikWgladu` i zdjęcie z urzędu |
| Prywatny przepis **własny** | Tak (rekomendacja, D2) | Patrz argument niżej |

**Argument za własnymi prywatnymi.** Na współdzielonym urządzeniu osoba B widzi te same dane online, dopóki sesja A żyje (cookie). Kopia offline nie dodaje ekspozycji w tym czasie. Nowe ryzyko powstaje dopiero po wylogowaniu, wygaśnięciu sesji albo zmianie konta, czyli dokładnie w momentach, które czyszczą kopie (§5).

## 3. Architektura MVP

**Magazyn**: jedna baza **IndexedDB `kuking-offline`**. Sklepy: `przepisy` (rekord JSON plus metadane), `zdjecia` (Blob), `meta` (hash konta, wersja schematu, `ostatnieSprawdzenie`).
- Dlaczego nie Cache API: obecny `activate` kasuje każdy cache o innej nazwie niż `WERSJA`, więc **każde wdrożenie wyczyściłoby kopie użytkownika**. Jeden `deleteDatabase` to jedna ścieżka czyszczenia, którą łatwo przetestować.
- Zdjęcia jako Blob w IDB i `URL.createObjectURL` (CSP `img-src blob:` już jest). SW nie uczestniczy w obrazach.
- Issue (pkt 2) mówi wprost „oddzielny, jawnie zarządzany magazyn, nie rozszerzać ogólnego cache HTML". To spełnia ten wymóg.

**Czytnik**: statyczna powłoka (bez danych konta, taka sama dla wszystkich, jak `offline.html`), np. `/bez-internetu` z własnym `czytnik.css` i `czytnik.js`. Powłoka ma `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'self'; style-src 'self'; img-src blob: 'self'; connect-src 'self'">`, `noindex` i wpis w `robots.txt`.
- Dlaczego statyczna: nie ma w niej nonce, sesji ani CSRF, więc jest bezpieczna w precache. Testuje się ją w vm jak dziś.
- Dlaczego nie Blade + Vite: hashowane nazwy wymagałyby czytania `manifest.json` w SW, a layout niesie dane zalogowanego.
- Czytnik ma dwa widoki: lista „Przepisy na tym urządzeniu" i pojedynczy przepis w układzie kroków (duży tekst, „Poprzedni krok"/„Następny krok", checklista składników, Wake Lock wzorowany na istniejącym). Minutniki w MVP tylko jako tekst „Ustaw kuchenny minutnik na X", jak w bazie trybu gotowania.
- Odhaczone kroki zostają **lokalnie na urządzeniu** w IDB i nie synchronizują się z kontem. Opis w UI: „Odhaczenia są tylko na tym urządzeniu". To stan interfejsu, nie zapis na serwerze, więc nie łamie „tylko do odczytu".

**Service worker** (zmiany, `WERSJA` → `kuking-alfa-014`):
1. Precache powłoki i jej plików. Żądania `cache.addAll` z `{cache: 'reload'}`, nazwy plików z wersją, dodatkowo nagłówek `no-cache` w Caddy dla `/bez-internetu*` (lekcja z `sw.js` i CDN).
2. W gałęzi `navigate`, dla `^/przepisy/[^/]+(/gotuj)?$` i `/bez-internetu`: przy **błędzie** sieci odpowiedź to powłoka czytnika zamiast `offline.html`. Powłoka sama sprawdza IDB. Jeśli kopii brak, pokazuje ten sam komunikat i ten sam „Spróbuj ponownie" (zachowuje #749).
3. **Timeout dla słabego zasięgu** (D9): tylko jeśli SW potwierdzi w IDB, że kopia tego slugu istnieje, `Promise.race([fetch, timeout N s → powłoka])`. Powłoka pokazuje pas „Pokazujemy zapisaną wersję z … Połączenie jest słabe. [Spróbuj pobrać aktualną]". Bez kopii SW czeka na sieć jak dziś.
4. Nigdy nie cache'ujemy odpowiedzi `/offline-api/*` ani HTML-a zalogowanego (reguła z nagłówka `sw.js` zostaje).
5. SW czyta IDB tylko do jednego pytania „czy jest kopia slugu X" (kilkanaście linii surowego IDB API).

**Nowe endpointy serwera** (Laravel, `auth`, limit z `config/kuking.php` → `offline.*`; kod w `app/Domain/Recipes/Offline/`, bez nowych cykli modułów):
- `GET /offline-api/przepisy/{uuid}?porcje=` zwraca dedykowany **`OfflinePrzepisResource` z białą listą kluczy** (nie cały `RecipeResource`, żeby nowe pole API nie wyciekło offline). Bramka: nowa metoda `RecipePolicy::saveOffline()` = `view()` AND `isPublished()` AND (`public` OR autor własny). Nie powielamy warunków widoczności, tak jak robi to `DostepDoZdjecia`. Odmowa to 404. Zwraca też listę zdjęć z `bytes` (do pokazania „zajmie około…") i `hash` treści.
- `GET /offline-api/zdjecia/{media}/{wariant}` **tylko `feed`**, tylko zdjęcie kroku lub główne tego przepisu, przez `DostepDoZdjecia`. Strumień bajtów przez PHP, `Cache-Control: private, no-store`, limit żądań. Uzasadnienie w §6.
- `POST /offline-api/sprawdz` przyjmuje `[{id, hash}]` (max = limit kopii) i zwraca dla każdego `aktualny | zmieniony | niedostepny` (plus stan zdjęć). Nieznane i zabronione to zawsze `niedostepny`, ten sam kształt, bez ujawniania powodu.
- **Bez migracji.** Hash liczony z JSON-a. Dla ≤20 przepisów to wystarczy (później ewentualnie z `recipe_versions`). Zatem AGENTS §6 (migracja, `DATABASE.md`, rollback) nie zachodzi, co warto wpisać w PR.
- `ApplySecurityHeaders::shouldNotIndex` dopisać prefiks `offline-api`/`bez-internetu`, `Caddy @dynamic` rozszerzyć o `/offline-api/*`.

**Punkty wejścia UI** (wszystkie za JS, patrz §7):
- Strona przepisu, w pasku akcji obok „Drukuj przepis": **„Zachowaj do gotowania bez internetu"**. Po kliknięciu panel z wyborem rozmiaru (§7).
- Strona trybu gotowania: ten sam mały link/stan.
- Zeszyt (`/zeszyt`, „Zapisane" i inne): przy pozycji tekst „Dostępny bez internetu" (wstawiany przez JS z IDB) oraz na górze link „Przepisy na tym urządzeniu (N)".
- Opcjonalnie skrót w manifeście. Pozycji nie dokładamy do nawigacji mobilnej (limit 5).

## 4. Schemat unieważniania

**Kiedy sprawdzamy** (tylko gdy jest sieć i JS):
- po załadowaniu dowolnej strony zalogowanego (idle, co najwyżej raz na `sprawdzanie_co_godzin`, domyślnie 6 h);
- przy otwarciu czytnika z siecią;
- po zalogowaniu.

Bez Background Sync i Periodic Sync (iOS ich nie ma [wiedza], a nie chcemy kolejki zapisów).

| Zdarzenie | Jak wykrywamy | Skutek na urządzeniu |
|---|---|---|
| Autor zmienił treść | `zmieniony` (hash) | Plakietka „Jest nowsza wersja", przycisk „Pobierz jeszcze raz". **Nie podmieniamy po cichu** (ktoś może właśnie gotować) |
| Autor cofnął do szkicu, usunął, moderacja ukryła lub zdjęła, zdjęcie z urzędu | `niedostepny` | Kopia usuwana od razu, w liście komunikat (§7) |
| Przepis cudzy zmienił widoczność na nie-publiczną, blokada w którąkolwiek stronę, autor `banned`/`pending_delete` | `niedostepny` (to samo `view`) | j.w. |
| Samo zdjęcie zdjęte/zmienione | osobny stan zdjęcia w odpowiedzi | Blob usuwany, tekst zostaje z dopiskiem |
| Wylogowanie | `form[data-wyloguj]` (hook jak `push_endpoint`) | `deleteDatabase` **zanim** formularz pójdzie, z limitem czasu ~1,5 s. Bez czekania na nieudane czyszczenie |
| Inne konto albo gość | Każda strona HTML niesie (dla zalogowanego) `<meta name="kuking-konto" content="HMAC(user_id)">`. Skrypt porównuje z `meta.konto` w IDB | Niezgodność lub brak meta = pełne czyszczenie. To zabezpieczenie niezależne od hooka wylogowania |
| Sesja unieważniona, „wyloguj inne", wygasła, konto do usunięcia/zbanowane | Następne wejście z siecią to strona gościa, więc brak meta | Pełne czyszczenie |
| Upływ ważności kopii (D5) | Data pobrania/ostatniego potwierdzenia w IDB | Po 7 dniach pas ostrzeżenia, po progu twardym odczyt zablokowany do odświeżenia lub usunięcia |
| Nowa wersja schematu offline | `wersjaSchematu` w `meta` | Migracja lub ponowne pobranie |

**Granice, które trzeba powiedzieć wprost** (polityka prywatności, jedno zdanie, D-327; `docs/MODERATION.md`):
- Bez sieci nie da się cofnąć kopii. Decyzja moderacyjna (także DSA, D-251) dochodzi do urządzenia przy następnym połączeniu albo po upływie ważności.
- To samo dotyczy usunięcia konta. Nie dajemy gwarancji „natychmiast".
- Push (D-303) nie jest kanałem unieważniania (opcjonalny, per urządzenie).

## 5. Prywatność: urządzenia współdzielone 50+

Założenie (D6): **dla wylogowania i zmiany konta czyścimy wszystko**, także kopie publicznych przepisów. Prostota i przewidywalność są ważniejsze niż oszczędność pobrania. W tekście przy wylogowaniu nie dokładamy okna potwierdzenia (komponent `x-wyloguj` celowo go nie ma). Zamiast tego informacja jest w panelu przy zapisie: „Po wylogowaniu przepisy zapisane na tym urządzeniu są usuwane."
- Loginu, e-maila ani surowego `user_id` nie trzymamy w IDB (tylko HMAC z sekretem aplikacji).
- Lista i czytnik nie pokazują niczego z konta poza tytułami przepisów (które i tak są treścią kopii).
- Goście (D7): rekomendacja, że **bez konta nie oferujemy zapisu**. Nie ma wtedy czym związać czyszczenia (brak wylogowania i hash konta). Gościa kierujemy na istniejące „Drukuj przepis".
- Statystyki: `Media`/`Collections` bez zmian. Zapis offline **nie jest** „Zapisuję" do Zeszytu, nie dotyka `collection_items` i nie zasila licznika z D-081 ani żadnego doboru treści (AGENTS §8).

## 6. Limity miejsca, CSP, nagłówki

**Limity** (w `config/kuking.php`, klucz `offline`):
- `max_przepisow` = 20, `max_zdjec_na_przepis` = 12, wariant `feed` (960 px; komentarz w configu mierzy 173 kB dla zdjęcia 12 Mpx), łączny budżet zdjęć ≈ 40 MB miękko.
- Typowy przepis z 6 zdjęciami ≈ 1 MB, tekst sam ≈ 10–40 kB. 20 przepisów to realnie 5–25 MB. Przed zapisem pokazujemy „Zajmie około X" z sum `bytes`.
- `navigator.storage.estimate()` służy do ostrzeżenia, gdy wolne miejsce jest < 2× potrzebny rozmiar.
- Zapis atomowy: najpierw blobs, rekord przepisu na końcu. Przy błędzie (QuotaExceeded) sprzątamy niepełne zdjęcia, a wcześniej zapisane przepisy zostają nietknięte.

**iOS Safari** [wiedza, do potwierdzenia na telefonie]:
- Karta w Safari: zapisane dane witryny (IDB, Cache API, localStorage) mogą zostać usunięte po ok. 7 dniach bez wizyty w witrynie. Aplikacja dodana do ekranu początkowego jest z tego zwolniona.
- Zainstalowana PWA na iOS ma **osobny magazyn** od karty Safari. Kopie zrobione w karcie nie pojawią się w aplikacji z ekranu początkowego.
- Limity quota są nieprzejrzyste, a `navigator.onLine` nie wykrywa słabego Wi-Fi (stąd timeout w SW).
- Wniosek UX: na iOS poza trybem standalone pokazujemy jedno zdanie: „W Safari telefon może usunąć zapisane przepisy, jeśli tydzień nie wejdziesz na Kuking. Dodaj Kuking do ekranu początkowego, żeby je zachować." (D15). Przed pierwszym wydaniem obowiązkowy test na fizycznym iPhonie (karta vs ekran początkowy, tryb samolotowy).
- Nie polegamy na `storage.persist()`. Wywołujemy je najwyżej jako ulepszenie.

**CSP**:
- **MVP nie zmienia CSP** w aplikacji (fetch same-origin, `img-src blob:`, `worker-src 'self'` już są).
- Powłoka statyczna dostaje własny `<meta>` CSP, bo Caddy nie dokłada CSP do plików statycznych.
- Dlaczego osobny endpoint zdjęć (D8): zwykłe `fetch('/zdjecia/…')` idzie za 302 na podpisany adres R2, a `connect-src 'self'` tego **zablokuje**. Dodatkowo R2 musiałoby zwracać CORS (nie wiem, czy bucket ma taką konfigurację, to odczyt po stronie właściciela Cloudflare). Przez PHP przechodzi ≤12 zdjęć na jawne kliknięcie, z limitem. Kosztem jest obciążenie FPM, którego `MediaController` unika przy feedzie. Do sprawdzenia w pomiarze #605. Alternatywa (R2 w `connect-src` plus CORS) poszerza politykę i wymaga testu w stylu `PolitykaCspDopuszczaPowrotTurnstileTest`.

**Nagłówki / #610**:
- Endpointy `/offline-api/*` są `auth`, więc `PreventSharedSessionCache` ustawi `private, no-store` automatycznie. Test ma to pilnować oraz `no-store` na endpoincie zdjęć (ustawiane jawnie, jak w `MediaController`).
- Cloudflare Cache Rules muszą omijać `/offline-api/*`.
- Powłoka (`/bez-internetu*`) nie zawiera danych konta, więc może iść przez CDN, ale z krótkim TTL i nazwami z wersją (bo CF domyślnie cache'uje `.js`/`.css`).
- Do `PublicznyHtmlGoscia` **niczego nie dodajemy**.

## 7. UX 50+ i JavaScript (D-053)

- Przycisk „Zachowaj do gotowania bez internetu" jest w HTML-u z atrybutem `hidden`, a JS odsłania go tylko gdy działają `indexedDB`, `serviceWorker` i bezpieczny kontekst. To jest istniejący wzorzec (pas synchronizacji trybu gotowania: „bez skryptu ten pas zostaje ukryty, D-053: żadnego martwego przycisku"). Bez JS funkcji po prostu nie ma na stronie, a obok są istniejące „Drukuj przepis" i eksport.
- Gdy JS działa, ale przeglądarka nie pozwala na zapis (IDB zablokowane): jedno zdanie „Ta przeglądarka nie pozwala zapisywać przepisów na urządzeniu. Możesz wydrukować przepis." + link „Drukuj przepis". Bez przycisku, który milczy.
- Stan jest zawsze **tekstem**, nigdy samą ikoną: „Dostępny bez internetu. Pobrano 30 września 2026" (data w formacie polskim, z godziną w szczegółach). Przyciski mają ≥48 px, tekst ≥18 px, etykiety widoczne, ikona co najwyżej obok słów.
- Panel przed zapisem (`role="dialog"` lub zwykła sekcja, bez hover i bez gestów): dwa równorzędne przyciski „Zapisz ze zdjęciami (około 1,2 MB)" i „Zapisz bez zdjęć (około 30 kB)" oraz „Nie teraz". `aria-live="polite"` dla stanów „Pobieram… 3 z 7 zdjęć".
- Usuwanie: „Usuń z tego urządzenia" przy każdej pozycji i „Usuń wszystkie" odsunięte na dole. Potwierdzenie to zwykły ekran z dwoma przyciskami: „Usuń z tego urządzenia" / „Zostaw". W treści: „Na Kuking nic nie znika".
- Czytnik: bez skryptu nie istnieje (to jedno wejście w ramach D-053). Jeśli powłoka załaduje się, a JS nie wystartuje (częsty przypadek słabego zasięgu), `<noscript>` z konkretną instrukcją: „Żeby zobaczyć zapisane przepisy, włącz w przeglądarce JavaScript i odśwież stronę." Plus „Spróbuj ponownie".
- Czytnik pokazuje w stałym miejscu ramkę: „Zapisana kopia z 30 września 2026. Może być nieaktualna." (status wyświetla także wiek kopii). Akcje, których offline nie ma, są **tekstem**, nie wyłączonymi przyciskami: „Ugotowałem, komentarze i zapisy są dostępne, gdy masz internet."

## 8. Teksty po polsku (do wklejenia; zgodne z COPY_STYLE, bez emoji i bez form z „-łeś")

- Przycisk: **Zachowaj do gotowania bez internetu**
- Stan: **Dostępny bez internetu. Pobrano {data}.**
- Panel: *Przepis zapisze się w tej przeglądarce i będzie działał bez sieci. Po wylogowaniu zniknie z tego urządzenia.* / **Zapisz ze zdjęciami (około {MB})** / **Zapisz bez zdjęć (około {kB})** / Nie teraz
- Postęp: *Pobieram zdjęcia: {n} z {m}.* / Gotowe: *Przepis jest zapisany na tym urządzeniu.*
- Brak miejsca: *Brakuje miejsca na tym urządzeniu. Usuń jeden z zapisanych przepisów albo zrób miejsce w telefonie i spróbuj jeszcze raz.*
- Limit: *Masz już zapisane {N} przepisów, to najwięcej. Usuń któryś, żeby dodać nowy.*
- Lista, nagłówek: **Przepisy na tym urządzeniu** · pusta: *Nic jeszcze nie zapisałeś do gotowania bez internetu. Zrobisz to przyciskiem „Zachowaj do gotowania bez internetu" przy przepisie.* (forma neutralna, np. „Nic tu jeszcze nie ma. …")
- Ramka kopii: *Zapisana kopia z {data}. Może być nieaktualna.* · po 7 dniach: *Ta kopia ma już {n} dni. Gdy będzie internet, pobierz ją jeszcze raz.*
- Słabe połączenie: *Połączenie jest słabe, więc pokazujemy zapisaną wersję z {data}.* / Spróbuj pobrać aktualną
- Nowsza wersja: *Autor zmienił ten przepis. Jest nowsza wersja.* / **Pobierz jeszcze raz**
- Przepis zniknął: *Ten przepis nie jest już dostępny, więc usunęliśmy go z tego urządzenia.*
- Zdjęcie zniknęło: *Jedno zdjęcie nie jest już dostępne, więc go tu nie ma.*
- Wygasła kopia: *Ta kopia jest starsza niż {X} dni i jest zablokowana. Połącz się z internetem, żeby ją odświeżyć albo usunąć.*
- Usuń wszystkie: *Usunąć {n} przepisów z tego urządzenia? Na Kuking nic nie znika.* / **Usuń z tego urządzenia** / Zostaw
- Wylogowanie: *Przepisy zapisane na tym urządzeniu zostały usunięte.* (po zalogowaniu na innym koncie)
- Offline w czytniku: *Ugotowałem, komentarze i zapisywanie do zeszytu są dostępne, gdy masz internet.*
- iOS poza standalone: *W Safari telefon może usunąć zapisane przepisy, jeśli przez tydzień nie wejdziesz na Kuking. Dodaj Kuking do ekranu początkowego, żeby je zachować.*
- Brak przeglądarkowej obsługi: *Ta przeglądarka nie pozwala zapisywać przepisów na urządzeniu. Możesz wydrukować przepis.*
- `<noscript>` w czytniku: *Żeby zobaczyć zapisane przepisy, włącz w przeglądarce JavaScript i odśwież stronę.*

## 9. Testy

Zgodnie z AGENTS §10: każdy test z kontrolą ujemną, wpisy w `scripts/kontrole-negatywne-alfa08.py` i `scripts/kontrole_oczekiwana_przyczyna.py` dla testów czytających źródła. `./scripts/check.sh` przed PR.

**PHP (PostgreSQL)**:
1. Macierz Policy dla `GET /offline-api/przepisy/{uuid}`: gość, autor, obcy; przepis `public`/`followers`/`private`; szkic, `hidden`, `removed`; autor `banned`/`pending_delete`/`erased`; blokada w obie strony; moderator wobec ukrytego (403/404, bez wpisu w `audit_log`). Odmowa wygląda jak brak (404).
2. Dokładny zestaw kluczy JSON (biała lista): brak e-maila, komentarzy, `source_scan_media_id`, pól moderacyjnych i cudzych identyfikatorów.
3. `POST /offline-api/sprawdz`: `aktualny/zmieniony/niedostepny` po edycji, ukryciu, zmianie widoczności, blokadzie, `banned`. Kształt odpowiedzi identyczny dla „nie istnieje" i „zabronione".
4. Zdjęcia: tylko `feed`, tylko zdjęcie danego przepisu, nigdy skan kartki, nigdy zdjęcie niedostępne widzowi (`DostepDoZdjecia`).
5. Nagłówki: `private, no-store` na wszystkich `/offline-api/*`. Żadna odpowiedź z tych tras nie trafia do `PublicznyHtmlGoscia`. Test strażnikowy zostawia listę `PublicznyHtmlGoscia` bez zmian.
6. `<meta name="kuking-konto">` jest na stronie zalogowanego i nie ma go dla gościa (HMAC, nie surowy id). Nagłówek CSP bez zmian (test porównujący, że MVP niczego nie poszerza).
7. Limity (`config/kuking.php`) i `throttle`.
8. Test czytający szablon: przycisk ma `hidden`, a `<noscript>`/zdanie zastępcze istnieje (strażnik D-053).
9. `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`: wpis `[nowa funkcja]` w CHANGELOG i akapit w `resources/nowosci/tresc.md`; podbicie numeru wersji.

**Service worker (Node `vm`, jak dziś)**:
- offline `/przepisy/x` i `/przepisy/x/gotuj` dają powłokę czytnika, a inne nawigacje `offline.html`;
- POST nadal nieprzechwytywany, `/offline-api/*` nigdy w cache, prywatny HTML nadal nie trafia do cache;
- `activate` nie dotyka IDB;
- timeout: z kopią w IDB `race` oddaje powłokę po N s, bez kopii dalej czeka;
- zaktualizować asercje w `scripts/offline-ponowienie.test.mjs` i `scripts/service-worker-marka.test.mjs` (z kontrolą ujemną), zachować #749.

**Playwright Chromium** (nowe pliki w `scripts/przegladarka/`, node:test, fixture HTTP jak `skladniki-gotowania.test.mjs`; wpiąć w job przeglądarkowy CI razem z filtrem grep):
- zapis → `context.setOffline(true)` → wejście na `/przepisy/slug` → czytnik pokazuje kopię, datę i zdjęcie z blob;
- kroki, checklista składników, Wake Lock (z degradacją), brak aktywnych przycisków zapisujących;
- wylogowanie czyści IDB; zmiana konta czyści; strona gościa z kopiami czyści;
- `niedostepny` po ukryciu przepisu usuwa kopię i pokazuje komunikat; `zmieniony` pokazuje pas bez podmiany;
- QuotaExceeded (wstrzyknięty wyjątek): komunikat, brak niepełnych rekordów;
- limit 20; wygaśnięcie ważności; migracja schematu;
- dostępność klawiaturą, 320 px, 200% zoom, tekst 140% (wzór: `nawigacja-zoom.mjs`), axe, kontrasty nowego CSS czytnika (dodać do kontroli 72 par);
- Playwright WebKit jako dodatkowy przebieg tam, gdzie się da (IDB, offline). Eviction i limity iOS są **niemierzalne w CI**, więc lista ręczna na iPhonie (karta vs ekran początkowy, tryb samolotowy, po tygodniu) i jawny zapis w PR, że nie wykonano, jeśli nie wykonano.

## 10. Zakres etapów

**MVP (etap 1)**: publiczne przepisy (każdy autor) + własne, także prywatne, tylko opublikowane; ≤20 przepisów; `feed` 960; ważność i unieważnianie z §4; czytnik (lista, kroki, składniki); czyszczenie przy wylogowaniu/zmianie konta; tylko konta zalogowane; bez minutników w aplikacji; bez skalowania porcji offline (zapis dla wybranej liczby porcji z `?porcje=`).

**Etap 2 (dopiero po pomiarze użycia)**: minutniki w czytniku, zbiorcze „Zachowaj cały zeszyt" (do limitu), automatyczne odświeżanie nieotwartych kopii, własne kopie `followers`.

**Poza zakresem**: zapisy offline (komentarze, „Ugotowałem", zmiany), kolejka synchronizacji, cache feedu i stron prywatnych, lista zakupów offline (#27, osobna decyzja), głos (#1906), push jako kanał unieważniania.

## 11. Pomiary (issue pkt 5)

Zdarzenia serwerowe (`App\Domain\Analytics`, `snake_case` po angielsku), bez treści przepisów i bez slugu: `offline_copy_saved` (z `with_photos: bool`, liczba zdjęć), `offline_copy_removed` (`reason`: `user|logout|unavailable|expired`), `offline_reader_opened` jako licznik z IDB wysyłany zbiorczo przy następnym połączeniu. Bez zdarzeń per przepis. Do decyzji D14.

## 12. Dokumentacja i wdrożenie formalne

Nowy wpis w `docs/DECISIONS.md` (D-335+) z wycofaniem (usunięcie przycisku i powłoki, SW wraca do `offline.html`; IDB użytkowników można wyczyścić kodem w nowej wersji SW). Zmiany w `docs/FEATURES.md` (przeniesienie #1904), `docs/ARCHITECTURE.md`/`docs/MODERATION.md` (granica unieważniania), `docs/design/` (opis czytnika i dowody przeglądarkowe), `CHANGELOG.md` `[nowa funkcja]` + `resources/nowosci/tresc.md`, podbicie `kuking.wersja.etykieta`, jedno zdanie w polityce prywatności (D-327, drobna zmiana przy braku prawdziwych użytkowników, jak D-332). Bez migracji, czyli bez `DATABASE.md`.

## 13. DECYZJE dla właściciela (opcje i rekomendacja)

**D1. Czy i kiedy odblokować #1904?**
(a) Zbudować MVP teraz (zakres §10). (b) Najpierw krótki test z 5–8 osobami 50+ na realnym scenariuszu „słaby zasięg w kuchni" vs „Drukuj przepis" i eksport, potem (a). (c) Zostawić na „nie teraz".
Rekomendacja: **(b), potem (a)**. Issue wprost tego wymaga, a koszt MVP to duże L. Bez atrapy funkcji dla użytkowników (nie dokładać nieczynnego przycisku).

**D2. Jakie przepisy można zachować offline?**
(a) Tylko publiczne. (b) Publiczne + własne (także prywatne), tylko opublikowane. (c) (b) + cudze „dla obserwujących".
Rekomendacja: **(b)**. Rodzinne przepisy własne są sednem produktu, ekspozycja jest taka jak przy żyjącej sesji, a czyszczenie jest domknięte. (c) odrzucić: relacji nie da się cofnąć offline.

**D3. Zdjęcia**
(a) Bez zdjęć w ogóle. (b) Opcjonalnie, `feed` 960 px, główne + kroków (≤12), bez skanu. (c) `large` 1600 px.
Rekomendacja: **(b)**. Skan kartki wykluczony bezwarunkowo.

**D4. Limit liczby i rozmiaru**
(a) 10 przepisów, 20 MB. (b) 20 przepisów, 40 MB miękko. (c) 50 przepisów, bez limitu MB.
Rekomendacja: **(b)**, z `storage.estimate()` jako ostrzeżeniem. (c) bezpieczne tylko po teście eviction na iOS.

**D5. Ważność kopii**
(a) 7 dni (jak sesja) z twardą blokadą. (b) 14 dni: po 7 ostrzeżenie, po 14 blokada odczytu do odświeżenia lub usunięcia. (c) 30 dni, samo ostrzeżenie po 7.
Rekomendacja: **(b)**. Kompromis między „kucharz na działce bez internetu" a prywatnością po wygaśnięciu sesji.

**D6. Wylogowanie i wygaśnięcie sesji**
(a) Czyścić wszystko przy wylogowaniu, zmianie konta i braku sesji. (b) Przy wygaśnięciu sesji czyścić tylko kopie niepubliczne. (c) Zostawiać do jawnego wylogowania.
Rekomendacja: **(a)**. Prosta reguła, jeden test. (b) to optymalizacja na później, (c) łamie wymóg z issue.

**D7. Goście (bez konta)**
(a) Tylko zalogowani. (b) Goście też, publiczne przepisy, z ręcznym „Usuń wszystkie". (c) Goście tylko z wyraźnym ostrzeżeniem o wspólnym urządzeniu.
Rekomendacja: **(a)**. Dla gościa czyszczenie po wylogowaniu nie istnieje. Gość ma „Drukuj przepis".

**D8. Droga zdjęć (CSP)**
(a) Osobny endpoint `/offline-api/zdjecia/...` przez PHP, bez zmiany CSP. (b) R2 w `connect-src` + CORS na buckecie. (c) Bez zdjęć w MVP (zależnie od D3).
Rekomendacja: **(a)** plus pomiar #605. (b) dopiero przy dowodzie, że PHP jest wąskim gardłem.

**D9. Zachowanie przy słabym zasięgu**
(a) Tylko gdy sieć zupełnie padnie (błąd `fetch`). (b) Dodatkowo timeout 6 s, gdy jest kopia, z pasem „Pokazujemy zapisaną wersję". (c) Timeout 3 s.
Rekomendacja: **(b)**. Bez tego funkcja nie spełni swojej głównej obietnicy (Wi-Fi w kuchni zwykle „wisi", a nie pada). Wartość do strojenia w teście.

**D10. Powłoka czytnika**
(a) Statyczna, własny mały CSS/JS w `public/`, precache w SW. (b) Blade + Vite (spójność z aplikacją, ale hashowane pliki i dane zalogowanego w precache). (c) Precache aktualnego `app.css` z `manifest.json` w SW.
Rekomendacja: **(a)**, spójna z `offline.html`, testowalna w vm, bez CSRF i nonce. Koszt: rozjazd wyglądu, łagodzony kontrolą kontrastu/marki.

**D11. Minutniki i skalowanie porcji offline**
(a) Poza MVP: tekst „Ustaw kuchenny minutnik". (b) Minutniki w czytniku od razu. (c) Minutniki i skalowanie.
Rekomendacja: **(a)**, etap 2 po pomiarze. Skalowanie: zapis dla jednej wybranej liczby porcji.

**D12. Polityka prywatności i regulamin**
(a) Jedno zdanie o lokalnej kopii i granicy unieważniania, jako zmiana drobna (D-327). (b) Nic. (c) Osobna sekcja, zmiana istotna.
Rekomendacja: **(a)**. Zgodne z tym, jak zrobiono D-332/#2270 (brak prawdziwych użytkowników). Przegląd prawny zostaje w #8.

**D13. Moderacja i DSA**
(a) Usuwanie kopii przy następnym połączeniu i po terminie ważności, jawnie zapisane w `MODERATION.md` i polityce. (b) Dodatkowo cichy push z poleceniem usunięcia (opcjonalny, per urządzenie). (c) Zakaz zapisu treści zgłoszonych lub ze sprawą moderacyjną.
Rekomendacja: **(a)**. (c) wymaga sygnału per przepis na kliencie (wyciek stanu moderacji), więc nie.

**D14. Pomiary**
(a) Bez pomiarów. (b) Trzy zdarzenia zbiorcze z §11. (c) Zdarzenia per przepis.
Rekomendacja: **(b)**. Bez treści i bez identyfikatorów przepisów.

**D15. Komunikat o iOS**
(a) Zawsze. (b) Tylko iOS w karcie Safari (nie standalone). (c) Wcale.
Rekomendacja: **(b)**, po potwierdzeniu faktów na iPhonie.

## 14. Szacunki (S / M / L; efekt pracy agenta z przeglądem, bez stroików kosztu człowieka)

| Część | Rozmiar | Uwagi |
|---|---|---|
| Decyzje + wpis D-xxx + FEATURES/CHANGELOG/nowości/polityka | S | Zależy od D1 |
| Endpoint przepisu, `saveOffline`, `OfflinePrzepisResource`, `/sprawdz`, limity, nagłówki, testy PHP | M | Brak migracji |
| Endpoint zdjęć przez PHP + testy | S do M | Zależy od D8 i pomiaru #605 |
| Moduł klienta: IDB, zapis atomowy, quota, unieważnianie, hash konta, hook wylogowania | M | Precedens `data-wyloguj` |
| Powłoka i czytnik (lista, kroki, składniki, Wake Lock, `<noscript>`, CSS, dostępność) | L | Największy element, nowy ekran (skill `kuking-ekran`) |
| Zmiany SW (powłoka, timeout, sprawdzenie IDB) + aktualizacja testów vm | S do M | Dwa istniejące testy trzeba świadomie zmienić |
| Integracja UI (strona przepisu, tryb gotowania, Zeszyt, lista) | S do M | |
| Testy Playwright + kontrole ujemne + CI filtry + przegląd iOS ręczny | M do L | iOS eviction tylko ręcznie |
| **Całość MVP** | **L** | Etap 2 to osobne M |

Największe ryzyka: (1) różnice iOS (storage w karcie vs aplikacji, eviction) i brak możliwości zmierzenia ich w CI; (2) rozjazd wyglądu statycznej powłoki względem aplikacji; (3) obciążenie PHP przy pobieraniu zdjęć; (4) oczekiwanie „natychmiastowego" cofnięcia kopii, którego offline nie da. To trzeba powiedzieć wprost w tekstach i w polityce.

Kluczowe pliki: `public/sw.js`, `public/offline.html`, `public/manifest.webmanifest`, `resources/js/service-worker.js`, `resources/js/powiadomienia-push.js` (wzorzec `data-wyloguj`), `resources/views/components/wyloguj.blade.php`, `app/Http/Controllers/CookingModeController.php`, `app/Http/Controllers/MediaController.php`, `app/Domain/Media/DostepDoZdjecia.php`, `app/Policies/RecipePolicy.php`, `app/Http/Middleware/ApplySecurityHeaders.php`, `app/Http/Middleware/PreventSharedSessionCache.php`, `app/Http/Resources/Api/V1/RecipeResource.php`, `docker/Caddyfile`, `scripts/offline-ponowienie.test.mjs`, `scripts/service-worker-marka.test.mjs`, `scripts/przegladarka/skladniki-gotowania.test.mjs` (wzór testu w Chromium).