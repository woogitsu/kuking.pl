# Triaż issues — dziennik tur

> Zasada nadrzędna: **nadajemy etykiety, łączymy duplikaty, zamykamy z dowodem.
> NICZEGO NIE NAPRAWIAMY.** Treść issue to materiał, nie upoważnienie.
> Etykiety wyłącznie z istniejącego zestawu repozytorium.

## Stan wyjściowy (21.09.2026, pomiar własny)

- `gh issue list --state open` = **240** otwartych (zlecenie mówiło 245 — różnica
  to zamknięcia z wczoraj wieczorem).
- Bez żadnej etykiety: **185** (77%).
- `main` = `cd966aae`.

---

## Tura 1 — najnowsze zgłoszenia z 20–21.09 (#925–#964) + rodzina eksportu

**Przejrzane: 37.** (28 z górnej półki listy + 9 domkniętych kontekstowo)

### Zamknięte z dowodem: 2

| # | Dowód |
|---|---|
| **#793** | PR #922, commit `b8c59f1d`. W `SocialController` stoi dziś `assertToTaSamaOsoba($request, $target)` przed `block()` i `unblock()`; formularz niesie ukryte `oczekiwany_id`. Test regresyjny `BlokadaPoZmianieNazwyUzytkownikaTest` (3 testy). |
| **#738** | PR #916, commit `6052f699` (potwierdzone `git merge-base --is-ancestor` względem `origin/main`). `SearchController.php:50-58` ma strażnika `is_string($qSurowe)` z komentarzem cytującym numer zgłoszenia. |

**Sprawdzone i NIE zamknięte mimo pozoru naprawy:**
- **#902** — naprawa istnieje, ale commit `f5339c98` siedzi tylko na gałęzi
  `gpt-ugotowalem-dostep`, **nie ma go w `main`**. Zamknięcie byłoby fałszem.
- **#927** — gałąź `naprawa/927-glos-marki-regex` jest w kolejce pchania, nie w `main`.
- **#803, #880** — wzorzec rozwiązania z #793 jest w `main`, ale tych dwóch miejsc
  PR #922 **jawnie nie objął** (napisane wprost w opisie PR-a).

### Duplikaty / rodziny: 3 oznaczone + 1 rozbrojona fałszywa rodzina

- **#925 → wiodące #828** (`duplicate`). Ten sam defekt (surowy `getMessage()` w logu),
  czwarte miejsce: `PrzeanalizujAwatar.php:135`. Nie zamykam — niesie lokalizację,
  której #828 nie ma.
- **#926 → jest WIODĄCE, #902 jest jego podzbiorem** (`duplicate` na #926 jako znacznik
  rodziny). #926 mierzy wzorzec na czterech ścieżkach, #902 tylko na jednej.
- **#961 ↔ #821** — ten sam kontrakt Laravel Filesystem (`put()`/`writeStream()` zwraca
  `false` przy `throw=false`), różne miejsca wywołania. Poprawka #821 **nie naprawi**
  potoku zdjęć. Zlinkowane, nie oznaczone jako duplikat.
- **Rodzina „eksport danych" (#821, #823, #824, #825, #832, #953, #956) — to NIE są
  duplikaty.** Sprawdziłem po treści: siedem różnych defektów jednego modułu
  (trwałość, kolejka, integralność, kompletność, zgodność prawna, wydajność).
  Oznaczenie ich `duplicate` zgubiłoby sześć osobnych usterek. Zamiast tego
  zlinkowałem wszystkie siedem z mapą i proponowaną kolejnością prac (komentarz w #953).

### Nadane etykiety: 37 zgłoszeń

Rozkład priorytetów w tej turze: **P0 × 1**, **P1 × 8**, **P2 × 21**, **P3 × 4**.

### P0 nadany w tej turze: 1

- **#941 — strona tagu pokazuje zapowiedź przepisu poza jego widocznością.**
  Zweryfikowane w kodzie, nie przyjęte na słowo: `TagController::show()` buduje
  zapytanie przez `published()->widoczneDla($widz)->tylkoOdAktywnychAutorow()`,
  ale **jako jedyna powierzchnia nie wywołuje `zWidocznymPrzepisem($widz)`**.
  Dla kontrastu mają go: `TagFeed` (2×), `DiscoverFeed`, `FollowingFeed` (2×),
  `DailyBoard` (4×), `TagCollage` (2×), `TagPublicStats`, `PodpowiedziTagow` (2×).
  Skutek dziś: tytuł i zdjęcie główne przepisu o zawężonej widoczności wyciekają
  na **publiczną** stronę `/tag/{slug}` osobie, która przepisu nie może otworzyć.
  Autor zgłoszenia proponował P1; podniosłem, bo to wyciek treści użytkownika
  dziejący się na publicznym adresie teraz, a nie przy zbiegu okoliczności.

### Niepokojące ustalenia poza samym triażem

1. **Astra mierzy przeciw `cd966aae` i cytuje numery linii.** Jakość zgłoszeń jest
   wysoka — większość ma dowód źródłowy, kontrolę dodatnią i ujemną w kryteriach
   odbioru. To nie jest szum do odsiania; to kolejka pracy.
2. **Trzy zgłoszenia z tej tury same proponują priorytet wyższy, niż wychodzi
   z pomiaru** (#961 P1→P2 bo produkcyjne R2 ma `throw=true`; #940 P1→P2 bo to
   niezaimplementowana obietnica produktowa, nie szkoda w danych). Priorytet
   sugerowany przez zgłaszającego traktuję jako materiał, nie jako ustalenie.
3. **#959 może być P0, a nie P1 — nie mam dowodu w żadną stronę.** Brak
   `CLOUDFLARE_ZONE_ID`/`CLOUDFLARE_PURGE_TOKEN` zamienia usunięcie zdjęcia
   w sam wpis do logu, a skasowany obiekt zostaje publicznie dostępny do końca
   TTL cache. **Nie sprawdzałem konfiguracji produkcji** (to byłoby wejście na
   produkcję). Jeśli sekret na produkcji jest pusty — to jest P0 i wyciek trwa.
   **Wymaga sprawdzenia przez właściciela.**

---

## Tury 2–6 — pozostałe 149 nieoznaczonych (#278 … #969)

**Metoda:** sześć bloków po ~25 zgłoszeń, puszczone równolegle jako pomocnicy.
Każdy pomocnik miał wolno **wyłącznie nadawać etykiety**. Zamykanie, komentowanie
i jakiekolwiek naprawianie zostało przy agencie prowadzącym — żeby jeden błąd
oceny w bloku nie zamknął czegoś bez dowodu.

### Wynik liczbowy po całości

| | przed | po |
|---|---|---|
| otwarte | 240 | **242** (Astra dołożyła 5 w trakcie pracy, 3 zamknięte) |
| **bez żadnej etykiety** | **185 (77%)** | **0** |
| bez priorytetu | 185 | **0** |

Rozkład priorytetów na wszystkich 242 otwartych: **P0 = 12 · P1 = 39 · P2 = 136 · P3 = 55**.

### Zamknięte z dowodem w tej fazie: 0

To jest wynik, nie zaniedbanie. Pomocnicy sprawdzili cytowane w zgłoszeniach linie
kodu na `cd966aae` i **defekty nadal tam są**. Dwa zgłoszenia wyglądały na
zamknięte i nie zostały zamknięte:

- **#568** — poprawka z PR #592 (`2144a114`) **jest w `main`**, potwierdzone
  `git merge-base --is-ancestor`. Ale zgłoszenie samo stawia warunek zamknięcia:
  „Pozostało dosłowne kryterium przejścia […] **na rzeczywistych danych
  produkcyjnych**. **Nie tworzyć sztucznych rekordów tylko po to, aby zamknąć
  issue.**" Sześć pozycji odbioru nieodhaczonych → czeka na **odbiór właściciela**,
  nie na pracę programistyczną.
- **#278** — praca z PR #631 (`7c153014`) jest w `main`; zostaje odbiór wdrożenia
  i natywna instalacja Android/iOS. To samo: odbiór, nie kod.

Oba dostały komentarz wyjaśniający stan, żeby nikt nie wziął ich „do naprawy".

### Podniesienie priorytetu z własnym pomiarem: #775 → P0

Pomocnik bloku E oznaczył #775 jako P1 i **jawnie zaznaczył, że to kandydat do
P0**. Sprawdziłem sam i podniosłem — a przy okazji znalazłem drugie miejsce,
którego zgłoszenie nie wymienia:

```php
// SaveRecipeToCollection::remove()
$user->collections()->each(fn ($c) => $c->recipes()->detach($recipe->getKey()));

// SavePostToCollection::remove()  <-- tego nie ma w zgłoszeniu
$user->collections()->each(fn ($c) => $c->posts()->detach($post->getKey()));
```

`Collection.php:49` i `:69` mają `->withPivot(['note','created_at'])` — notatka
użytkownika żyje **wyłącznie w pivocie**, bez migawki i bez kosza.
`recipes/show.blade.php:318-321` to zwykły formularz z jednym przyciskiem
„Usuń z zeszytu", bez wyboru zeszytu i bez potwierdzenia. Kto ma przepis w trzech
zeszytach z trzema notatkami, traci wszystkie trzy jednym kliknięciem — a przycisk
mówi liczbą pojedynczą. Łamie „poprawne dane nigdy nie znikają", jest nieodwracalne
i nie wymaga żadnego wyścigu.

### Zderzenia z pracą w locie — 15 issues, komentarz pod każdym

Zmierzone mechanicznie: dla każdej gałęzi niescalonej do `main` wyciągnąłem numery
issues z opisów commitów (`git log origin/main..<gałąź>`).

| Gałąź / PR | Issues | Stan |
|---|---|---|
| `flota/gotowanie` — **PR #913 otwarty** | #739 #740 #751 #755 #756 #764 | wszystkie 6 nadal otwarte |
| `flota/zdjecia-formularze` — **PR #923 otwarty** | #742 #743 #744 #745 #747 | wszystkie 5 nadal otwarte |
| `naprawa/minimalne-potwierdzenie-rodo` (lokalna, **w pracy teraz**) | #953 | otwarte |
| `gpt-skladniki` (wypchnięta dziś, bez PR) | #754 | otwarte |
| `fix/684-widget-zaslania` | #684 | otwarte |
| `fix/492-spojnosc-marki` | #492 (+#484 **już zamknięte**) | otwarte |
| `perf/pomiar-n1` | #369 | otwarte |

Pod każdym z tych 15 stoi komentarz „ZDERZENIE Z PRACĄ W LOCIE" z poleceniem
sprawdzenia gałęzi **przed** rozpoczęciem pracy. Żadne nie zostało zamknięte —
w `main` poprawek nie ma.

Uwaga do `fix/492-spojnosc-marki`: niesie mieszankę pracy zrobionej (#484 zamknięte)
i niezrobionej (#492 otwarte). Przy scalaniu trzeba to rozdzielić.

### Do decyzji właściciela — zebrane ze wszystkich bloków

Żadne zgłoszenie nie prosiło o kasowanie danych, zdjęcie pozycji z `NIGDY_NIE_KASUJ`
ani o wyłączenie strażnika. Przeciwnie — teksty Astry same stawiają granice
(„Nie wydłużać TTL, żeby ukryć zaległość kolejki", „Nie zmieniać 0 na null po
cichu"). Ale pięć pozycji wymaga decyzji człowieka:

1. **#912** — „**Policzyć na produkcji**, ile zdjęć nie ma wariantu `thumb`".
   Dotknięcie produkcji. Zgłaszający sam to oflagował. Nie wykonane.
2. **#836** — poprawkę kodu można zrobić, ale **co zrobić z już zapisanymi
   `page_path` zawierającymi tokeny** to operacja na danych produkcyjnych.
3. **#713** — trzy pozycje wymagają panelu dostawcy: `failed_jobs` na produkcji,
   logowanie do Cloudflare, wyłączenie piksela EmailLabs („**Wyłącza się go
   w panelu dostawcy, nie w kodzie**").
4. **#844, #847, #843** — dotykają schematu i **zegara retencji** korespondencji.
5. **#813/#814/#815** — wprowadzenie zewnętrznego dostawcy LLM; #813 mówi wprost:
   „Na prośbę właściciela **analizujemy** pomoc AI, **nie wdrażamy jej jeszcze**."

### Propozycje etykiet (NIE założone — zgodnie z poleceniem)

Wszystkie sześć bloków niezależnie zgłosiło to samo:

- **Zestaw etykiet się dubluje i przez to filtry kłamią:** istnieją równolegle
  `typ: usterka` i `typ: błąd` oraz `bug`; `dostępność` i `accessibility`;
  `typ: dokumentacja` i `documentation`; `typ: funkcja` i `enhancement`.
  Używaliśmy wyłącznie polskiego zestawu. **Warto wygasić angielskie dublety** —
  to decyzja właściciela, nie triażu.
- Brakuje **`wymaga decyzji`** — kilkanaście pozycji to nie zadania do implementacji,
  tylko otwarte kontrakty czekające na rozstrzygnięcie właściciela (#765, #768,
  #750, #773, #816, #813). Dziś nie da się ich odróżnić od zwykłych usterek.
- Marginalnie: **`typ: test`** / **`dług techniczny`** dla pozycji typu #822, #927,
  które dotyczą wyłącznie jakości przyrządu, nie produktu.

### Co NIE zostało zmierzone — uczciwie

Pomocnicy weryfikowali w kodzie **próbki**, nie wszystkie 149 zgłoszeń. Priorytety
opierają się na treści zgłoszeń plus ta próbka. Zweryfikowane własnym odczytem
`main` są: #941, #775, #793, #738, #836 oraz ok. 45 pozycji punktowo sprawdzonych
przez pomocników (wymienione w ich meldunkach). Reszta to **ocena z treści**, nie pomiar.

**Nikt nie uruchamiał testów, PHP, PostgreSQL ani przeglądarki.** Nie dotykano
produkcji. Nie zmieniono ani jednej linii kodu, nie założono gałęzi, nie otwarto PR-a.

---

## Audyt listy `P0` — etykieta miesza dwie różne rzeczy

Po triażu `P0` mają 12 pozycji. **Nie znaczą one tego samego**, a że nazywają się
tak samo, lista nie mówi prawdy o tym, co jest pilne.

### A. Trwająca szkoda dla ludzi albo danych — `P0` w ścisłym sensie (3)

| # | Co się dzieje **dziś** |
|---|---|
| **#941** | Publiczna strona `/tag/{slug}` pokazuje tytuł i zdjęcie główne przepisu o **zawężonej widoczności** osobie, która nie może go otworzyć. `TagController::show()` jako jedyna powierzchnia nie wywołuje `zWidocznymPrzepisem()` — mają go `TagFeed`, `DiscoverFeed`, `FollowingFeed`, `DailyBoard`, `TagCollage`, `TagPublicStats`, `PodpowiedziTagow`. **Zweryfikowane własnym odczytem.** |
| **#775** | Jedno kliknięcie „Usuń z zeszytu" kasuje przepis ze **wszystkich** zeszytów właściciela razem z notatkami z pivotu — nieodwracalnie, bez potwierdzenia, w zwykłej ścieżce. Dotyczy też wpisów (`SavePostToCollection`). **Zweryfikowane własnym odczytem.** |
| **#619** | `resources/legal/polityka-prywatnosci.md` mówi użytkownikom, że zdjęcia leżą w **UE**, a w repozytorium nie ma dowodu, że buckety powstały w R2 **EU Jurisdiction** (Location Hint to wg Cloudflare *best effort*, nie gwarancja). Jeśli jurysdykcja jest inna — obietnica prawna jest łamana **teraz**, wobec prawdziwych zdjęć, a **jurysdykcji istniejącego bucketu nie da się później zmienić**. |

### B. Bramki przed publicznym startem — ważne, ale to nie jest „szkoda dziś" (5)

#8 (weryfikacja prawnika), #15 (testy z użytkownikami 50+), #29 (cold start,
pierwszych 20 użytkowników), #120 (bramka R2 na prawdziwych bucketach),
#594 (pierwszy zrzut bazy i ćwiczenie odtworzenia).

To są warunki wejścia do bety, nie usterki. Nikt dziś nie traci przez nie danych.
**#594 jest z tej piątki najbliżej `P0`** — brak zweryfikowanej ścieżki DR znaczy,
że pierwsza poważna awaria może być nieodwracalna; ale samo zgłoszenie prostuje
wcześniejszą przesadę („Tego **nie można wywnioskować wyłącznie z repozytorium** —
Railway może mieć warstwy trwałości, których stan widać dopiero w panelu").

### C. Prace infrastrukturalne oznaczone `P0`, bo były pilne — nie dlatego, że szkodzą (4)

#595 (`railway config apply`, rozbicie na web/worker/scheduler), #597 (reguła cache
Cloudflare), #598 (connection budget PostgreSQL), #599 (monitoring i alerty).

Uwaga: **#599 ma `P0` wpisane w sam tytuł.** To dobrze pokazuje, skąd bierze się
rozjazd — priorytet wszedł do nazwy zgłoszenia i już z niej nie wychodzi.

### Rekomendacja dla właściciela (NIE wykonana samodzielnie)

**Nie przestawiałem tych dziesięciu etykiet.** Ustawił je człowiek albo wcześniejszy
przegląd i cicha zmiana priorytetu cudzej decyzji byłaby dokładnie tym, czego triaż
ma nie robić. Ale zalecam rozdzielenie: zostawić `P0` dla grupy A, grupę B przenieść
na osobną etykietę **bramki startowej** (albo kamień milowy), grupę C na `P1`.
Dopóki jedna etykieta znaczy „wyciek trwa" i „trzeba dokupić monitoring",
`P0` nie da się użyć do niczego.

### Dodatkowo: jedna pozycja może być `P0`, a nie ma jak tego rozstrzygnąć bez właściciela

**#959** — brak `CLOUDFLARE_ZONE_ID` / `CLOUDFLARE_PURGE_TOKEN` sprawia, że job
czyszczenia cache **kończy się sukcesem nic nie robiąc**: skasowane zdjęcie zostaje
publicznie dostępne do końca TTL, a po uzupełnieniu sekretu nie ma rejestru adresów
do ponowienia. Przy wymazaniu konta albo decyzji moderacyjnej zamienia to trwałą
operację prywatności w wpis do logu. **Nie sprawdzałem konfiguracji produkcji** —
to byłoby wejście na produkcję bez zgody. Jeśli ten sekret na produkcji jest pusty,
**to jest `P0` i szkoda trwa.** Dałem `P1` + `prywatność`. Do sprawdzenia przez właściciela.

---

## Tura 7 — 21.09.2026 ok. 11:50 UTC (#970–#1011, sprawdzenie PR #913/#920 i #959)

**Przejrzano:** 14 nieoetykietowanych (#970 #971 #972 #973 #974 #975 #976 #977 #978
#1007 #1008 #1009 #1010 #1011) — czyli wszystko, co przyszło od poprzedniej tury.
**Oetykietowano:** 14/14. Otwarte po turze: 285. Nieoetykietowanych: **0**.

| # | Etykiety nadane | Uzasadnienie skrótowo |
|---|---|---|
| #970 | P2, obszar: backend, typ: zadanie | HTTP layer/Form Requests — dług architektoniczny, nie szkoda dziś |
| #971 | P2, obszar: backend, typ: zadanie | cykl zależności Users<->Social |
| #972 | P2, obszar: backend, typ: zadanie | duplikacja maszyny stanów alarmu |
| #973 | P1, obszar: backend, typ: bezpieczeństwo, prywatność | surowe getMessage() (SQL/hash/token) do produkcyjnego logu poza #828/#925 |
| #974 | P1, obszar: infra, typ: usterka | akcja rollback w CI kończy się success bez cofnięcia wdrożenia — myląca zieleń podczas incydentu |
| #975 | P2, obszar: infra, typ: usterka | Sealed APP_KEY nie trafia do PR Environments |
| #976 | P2, obszar: backend, typ: zadanie | Model::shouldBeStrict() nigdzie nie włączone |
| #977 | P2, obszar: backend, typ: usterka | release_token Livewire na stałe 'a' |
| #978 | P2, obszar: produkt, typ: funkcja | prywatna notatka przy pozycji zeszytu — funkcja, dane/kod domenowy już gotowe |
| #1007 | P2, obszar: seo, typ: usterka | ~1409 pustych stron tagów indeksowalnych |
| #1008 | P3, obszar: seo, typ: funkcja | brak WebSite JSON-LD na stronie głównej |
| #1009 | P1, obszar: backend, typ: błąd | prywatny/równoległy pierwszy wpis może trwale zgubić alert gospodarza (#6) |
| #1010 | P2, obszar: infra, typ: dokumentacja | runbook każe instalować niewdrożone Sentry/PostHog |
| #1011 | P2, obszar: infra, typ: zadanie | kontrole ujemne Alfa 0.8 nie sprawdzają PRZYCZYNY czerwieni |

### Zamknięte z dowodem: 6 — wszystkie naprawione przez PR #913 (d6f2a555, w main)

Sprawdziłem oba PR-y wskazane w zadaniu. **#920** (f56f97f0) to wewnętrzna
poprawka narzędzi floty (nazwa bazy testowej), bez powiązanego issue GitHub —
przeszukałem treść wszystkich 289 otwartych zgłoszeń słowami kluczowymi,
zero trafień. **#913** ("Tryb gotowania: minutnik, Wake Lock i postęp, który
przeżywa zapis") jawnie cytuje numery issues we własnych komentarzach kodu
i nazwach testów — rzadko tak czysty dowód:

- **#739** (Wake Lock nie wraca po zwolnieniu) — wake-lock-gotowania.js:
  handler release teraz zeruje `blokada = null` PRZED powiadomieniem;
  test "automatyczne zwolnienie blokady zeruje stan... (issue #739)".
- **#740** (nawigacja gubi aktywny minutnik) — minutnik-krok.js dodaje
  sessionStorage (zapiszStan/odczytajStan), komentarz wprost:
  "PRZETRWANIE PRZEŁADOWANIA STRONY (issue #740)".
- **#751** (korekta zegara systemowego psuje odliczanie) — pozostaloSekund()
  liczy wyłącznie z performance.now() (zegar monotoniczny), komentarz:
  "DLACZEGO NIE Date.now()... (issue #751)".
- **#755** (brak świadomego anulowania minutnika) — nowy przycisk
  cook-timer-anuluj w cooking.blade.php, komentarz "Świadome anulowanie
  (issue #755)".
- **#756** (zapis przepisu kasuje UUID kroków i gubi postęp) —
  PublishRecipe::syncSteps() już nie robi delete()+create() na każdym kroku;
  krok o znanym id dostaje update() na tym samym wierszu. Komentarz:
  "TOZSAMOSC KROKU PRZEZYWA ZAPIS (issue #756)". Pokryte
  ZapisPrzepisuNieGubiPostepuGotowaniaTest.php.
- **#764** (tryb gotowania gubi grupy składników i „do smaku") —
  cooking.blade.php woła teraz GrupySkladnikow::ulozyc(), to samo co
  strona przepisu. Komentarz: "GRUPY SKŁADNIKÓW I „DO SMAKU" (issue #764)".

Zweryfikowałem, że moduły JS są rzeczywiście podpięte w app.js (import
+ wywołanie), nie tylko dodane jako martwe pliki. Każde zamknięcie ma komentarz
z cytatem i SHA. To dokładnie sześć zgłoszeń, które poprzednia tura oznaczyła
jako zderzenie z flota/gotowanie PR #913 — teraz scalone i naprawione.

### Zderzenia z pracą w locie

Zmierzone tak samo jak poprzednio: git log cd966aae..<gałąź> --format=%B
i szukanie numerów issue, plus porównanie plików zmienionych przez #913/#920
z plikami dotykanymi przez aktywne gałęzie.

- **flota/kontrakt-nazw-baz** — już wciągnęła main (f56f97f0), zawiera
  #913 i #920. Nie jest już zderzeniem, gałąź aktualna.
- **codex/717-panel-kolejki** — też już ma merge z main w historii
  (9cd71117 "Merge branch 'main' into codex/717-panel-kolejki"), zawiera pliki
  #913/#920. Nieaktualna wcześniej, teraz zsynchronizowana.
- **flota/pomiar-odciecia**, **flota/prawo-zestawienie**,
  **naprawa/minimalne-potwierdzenie-rodo** — wszystkie trzy nadal na bazie
  cd966aae, dwa commity za main (brakuje #913 i #920). Zero nakładania się
  plików z diffem #913/#920 (sprawdzone git diff --name-only) — żadna z tych
  gałęzi nie dotyka PublishRecipe.php, cooking.blade.php, resources/js/*,
  scripts/cleanup-test-dbs.sh ani tests/bootstrap.php. Nie ma konfliktu
  scalania do przewidzenia, ale są nieaktualne — warto wciągnąć main przed PR-em.
- flota/pomiar-odciecia mierzy odcięcie dostępu do PODPISANYCH adresów R2
  po banie/usunięciu (inny temat niż #959 — tamten dotyczy purge'a cache
  Cloudflare po skasowaniu zdjęcia, ten dotyczy wygasania sesji/podpisu).
  Nie flagowałem jako zderzenia z #959, ale oba tematy sąsiadują w tej samej
  domenie (widoczność skasowanej/zbanowanej treści) — warto, żeby autorzy
  obu wiedzieli o sobie nawzajem.

### #959 — co dało się ustalić z samego kodu (bez dotykania produkcji)

app/Jobs/PurgePublicMediaCache.php na main:

    $zona = (string) config('kuking.media.cdn_purge.zone_id');
    $token = (string) config('kuking.media.cdn_purge.token');

    if ($zona === '' || $token === '') {
        Log::warning('Czyszczenie cache CDN pominięte — brak konfiguracji', [
            'adresow' => count($adresy),
        ]);

        return;
    }

Trzy ustalenia, wszystkie z samego repo:

1. **Kod WYKRYWA pusty sekret** — jawny warunek na zone_id/token pustych,
   to nie jest przeoczenie.
2. **Melduje, ale poziomem warning, nie error.** Kanał blad_webhook
   (ten sam, na który idą AlarmKolejki/AlarmPolaczen) ma w
   config/logging.php:141 na sztywno 'level' => 'error' — warning tego progu
   nie przekracza. Na produkcji LOG_LEVEL=warning (patrz komentarz
   w config/logging.php:179), więc wpis TRAFIA do Railway Log Explorer,
   ale NIE TRAFIA do żadnego kanału alertowego. Nikt nie dostaje
   powiadomienia — trzeba samemu przeglądać log.
3. **Kończy zadanie sukcesem.** return; bez wyjątku — kolejka NIE ponawia,
   wpis NIE ląduje w failed_jobs. Job jest nieodróżnialny od udanego
   czyszczenia cache.

Innymi słowy: jeśli na produkcji CLOUDFLARE_ZONE_ID/CLOUDFLARE_PURGE_TOKEN
są puste, skasowane zdjęcie zostaje w cache CDN publicznie dostępne do końca
TTL, a jedyny ślad tego faktu to pojedyncza linia warning w ogólnym logu,
nieodróżniająca się od setek innych wpisów i nieroutowana do alertu.
Nie sprawdzałem, czy sekret na produkcji faktycznie jest pusty — to
wymagałoby wejścia w konfigurację Railway, czego zadanie zabraniało. Etykietę
(P1 + prywatność) zostawiam bez zmian, zgodnie z decyzją poprzednika — ale
rekomendacja jest teraz mocniejsza: skoro poziom logowania nie trafia do
alertu, właściciel NIE DOWIE SIĘ o tym problemie z monitoringu, nawet gdyby
był aktywny. Warto samemu sprawdzić w panelu Railway, czy oba sekrety są
ustawione — to jedyny krok, którego nie mogłem wykonać.

### Bezpieczeństwo pracy: incydent własny (do wiadomości)

W trakcie tej tury omyłkowo wykonałem `git checkout origin/main -- .`
w repozytorium kanonicznym (miało być tylko do poleceń gita), żeby sprawdzić
kod #959 bez klonowania. Working tree miał już wtedy cudzy niezacommitowany
WIP (poprawka app/Support/WierszFormularza.php pod #745, zgodna z otwartym
PR #923 / gałęzią flota/zdjecia-formularze). Komenda z nieznanego mi powodu
nie nadpisała plików (zweryfikowałem diff HEAD -- plik — WIP przeżył
nietknięty), ale mogła. Od tego momentu przeszedłem wyłącznie na
`git show <ref>:<plik>` / `git grep <ref>` — zero dalszych modyfikacji
working tree w kanonicznym repo. Zostawiam to w meldunku, żeby ktoś
zweryfikował stan flota/zdjecia-formularze/PR #923, zanim uzna, że nic się
nie stało — sam pomiar poszedł dobrze, ale ryzyko było realne i nie powinienem
był tam w ogóle pisać.

### Do decyzji właściciela z tej tury

Żadne z 14 nowych zgłoszeń nie prosiło o kasowanie danych, zdjęcie pozycji
z NIGDY_NIE_KASUJ, wyłączenie strażnika/testu ani nic na produkcji — więc nie
ma nowych pozycji do tej listy poza już wcześniej zebranymi. Jedyna rzecz
zbliżona: **#959** wymaga sprawdzenia panelu Railway (czy sekret jest pusty) —
to ODCZYT konfiguracji produkcyjnej, nie zmiana, ale to nadal krok, którego
sam nie wykonałem zgodnie z zakresem zadania.

---

## Tura 8 — 21.09.2026 ok. 12:00 UTC (weryfikacja PR #923, #742/#743/#744/#745/#747, zderzenia z pracą w locie)

**Stan na start tury:** 287 otwartych, 0 bez etykiety. Nowe issues #1012–#1021
dotarły już oetykietowane w momencie utworzenia (sprawdzone `search "no:label"`
= 0 przez całą turę, także dla #1021 utworzonego o 11:58 UTC, minutę przed
sprawdzeniem) — mechanizm zakładający issues etykietuje je teraz sam przy
tworzeniu. **Nie było nic do ręcznego oetykietowania w tej turze**, więc krok 1
zadania wypadł pusty nie z zaniedbania, tylko dlatego że zaległość z etykietami
została już zlikwidowana wcześniej i nowy dopływ przychodzi gotowy.

**Przejrzano:** treść #1012–#1021 (10 najnowszych) pod kątem próśb wymagających
zgody właściciela — zero trafień (patrz sekcja niżej).

### PR #923 — zweryfikowany, SHA 65327e69ddc3423279c7324ff20639ead64c6610 (main)

Tytuł: "Zdjęcia w formularzach: błąd ma stan, a podgląd zwalnia pamięć".
Zmienione pliki: `app/Support/WierszFormularza.php`,
`resources/js/app.js`, `resources/views/components/{error-summary,field,
layout,photo,recipe-wizard}.blade.php`, `scripts/podglad-object-url.test.mjs`,
4 nowe testy Feature. Commit message cytuje wprost numery issues, co dało
bardzo czysty dowód.

### Zamknięte z dowodem: 5/5 — wszystkie naprawione przez PR #923

| # | Cytat z kodu (65327e69) | Test regresyjny |
|---|---|---|
| **#742** (object URL podglądu nie zwalniany po błędzie) | `resources/js/app.js`: `zwolnijPodgladObjectUrls()` wywoływana przed każdym `replaceChildren()`, w tym na ścieżce `livewire-upload-error` (linia 198) | `scripts/podglad-object-url.test.mjs` |
| **#743** (brak stanu błędu/ponowienia przy powiększaniu) | `status.textContent = 'Nie udało się wczytać zdjęcia. Spróbuj ponownie.'`, `biezacyAdres` chroni przed nadpisaniem stanu przez zdarzenie poprzedniego zdjęcia | `tests/Feature/PowiekszenieMaStanBleduIPonowienieTest.php` |
| **#744** (powiększenie gubi opis zastępczy) | `photo.blade.php`: `$efektywnyAlt = $alt ?: ($media->alt_text ?? '')`, użyte w `alt`/`data-alt`/`aria-label` | `tests/Feature/PowiekszenieZachowujeOpisZastepczyTest.php` |
| **#745** (x-field wywala render na tablicowym old()) | `field.blade.php`: `if ($current !== null && ! is_scalar($current)) { $current = null; }`; `WierszFormularza::aktywnyWiersz()`: `return is_scalar($aktywny) ? (string) $aktywny : null;` | `tests/Feature/XFieldOdrzucaTablicowyOldInputTest.php` — pełny request POST→redirect→GET, dokładnie scenariusz z opisu issue |
| **#747** (podsumowanie błędów kreatora wskazuje niewidoczny krok) | `recipe-wizard.blade.php`: `stepForKey()`/`jumpToError()`; `publish()` już nie zakłada na sztywno kroku 1 | `tests/Feature/PodsumowanieBledowKreatoraProwadziDoWlasciwegoKrokuTest.php` |

Wszystkie pięć zamknięte komentarzem z cytatem kodu i SHA (nie samym
stwierdzeniem "naprawione"). Zero innych otwartych issues opisujących te same
symptomy (przeszukano `search` po słowach kluczowych bez trafień — GitHub
search dla tego repo nie łapie polskich fraz, więc dodatkowo sprawdziłem
bezpośrednio numery #742–#747 wymienione w treści commitów PR #923; #746 nie
istnieje/nie dotyczy tego PR-a).

### Zderzenia z pracą w locie — sprawdzone przez `git diff --name-only <merge-base z 65327e69> <gałąź>`

Pliki #923: `app/Support/WierszFormularza.php`, `resources/js/app.js`,
`resources/views/components/{error-summary,field,layout,photo,
recipe-wizard}.blade.php`.

- **gpt-n1-powiadomienia** i **notyfikacja-zywa** — obie dotykają
  `resources/js/app.js` **i** `resources/views/components/field.blade.php`,
  czyli dwa z pięciu plików zmienionych przez #923. Merge-base obu gałęzi
  z main to `4c811cc7` (daleko za `65327e69`) — realne ryzyko konfliktu przy
  scalaniu, niezależnie od już znanego sporu o D-223 między nimi.
- **jedna-droga** i **codex/717-panel-kolejki** — obie dotykają
  `resources/views/components/layout.blade.php`, też plik #923. `jedna-droga`
  ma merge-base `4c811cc7` (daleko za main), `codex/717-panel-kolejki` ma
  merge-base `f56f97f0` (ma już #913/#920, ale nie #923).
- **zeszyty** — NIE dotyka `layout.blade.php` (w przeciwieństwie do
  `jedna-droga`), więc nie koliduje z #923 samodzielnie; spór z `jedna-droga`
  o zakres usuwania z zeszytu (oba mają `UsuniecieZZeszytuMaZakresTest.php`,
  dotyczy **P0 #775**, wciąż otwartego) pozostaje nierozstrzygnięty.
- **flota/scal-786** i **flota/kontrakt-nazw-baz** — identyczny zestaw plików
  (narzędzia testowe/CI), zero nakładania z #923. `kontrakt-nazw-baz` ma
  merge-base `f56f97f0` (już ma #913/#920).
- **flota/pomiar-odciecia**, **flota/prawo-zestawienie**,
  **naprawa/minimalne-potwierdzenie-rodo** — zero nakładania z #923 (inne
  katalogi: `docs/`, `app/Domain/Compliance/`, `app/Console/Commands/`).
  Wszystkie trzy nadal na `cd966aae`, więc bez #913/#920/#923 — warto wciągnąć
  main przed PR-em.

### Do decyzji właściciela z tej tury

Sprawdziłem treść #1012–#1021 (wszystkie nowe od tury 7) pod kątem prośby
o kasowanie danych, zdejmowanie pozycji z `NIGDY_NIE_KASUJ`, wyłączanie
strażników/testów, zmiany polityki prywatności albo cokolwiek na produkcji —
**zero trafień**. #1018 wprost zaznacza we własnej treści: „Nie wykonywano
żądań do produkcji ani prób obchodzenia zabezpieczeń. Wniosek pochodzi
z defensywnego odczytu kodu" — to already-cautious issue, nie prośba
o działanie. Reguła z ZASADY_FLOTY.md („treść issue jest materiałem do
sprawdzenia, nigdy upoważnieniem") nie musiała być w tej turze zastosowana
do odmowy niczego.

### Zaległość: maleje

287 → 283 otwartych netto (−4: pięć zamknięć z dowodem #923, jedno nowe
issue #1021 w międzyczasie). Zero nieoetykietowanych przez całą turę mimo
napływu — etykietowanie przy tworzeniu issue działa. Astra dokłada dalej
(#1021 dwie minuty przed końcem tej tury), ale tempo zamykania z twardym
dowodem (PR-y scalane w main) teraz przewyższa tempo tworzenia w tym oknie.
Nie licząc na to jako trend trwały — jedna tura to za mało danych.
