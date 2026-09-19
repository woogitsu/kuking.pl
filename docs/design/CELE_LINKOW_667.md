# Cele linków — #667

Stan: **scalone i wdrożone**, odbiór produkcji prawie domknięty.
PR #672 scalony 18.09.2026 jako `71549eb` (Alfa 0.65); produkcja stoi dziś na
`55877e2`, CI main 35331870558 i Deploy 35333641106: success. Zdanie „WIP, jeszcze
bez push, PR i wdrożenia” oraz baza `97d32ea` poniżej to snapshot sprzed scalenia.
Blokuje wyłącznie jeden punkt z sekcji „Odbiór produkcji — 18 września 2026”.

Rzeczywisty render Laravel potwierdził trzy błędy: okruszek „Przepisy”
prowadził do tablicy wpisów, a dwa puste stany zeszytu pod „Poszukaj przepisów”
również otwierały tę tablicę. Początkowa regresja: 4 testy, 13 asercji,
3 oczekiwane porażki; formularz wyszukiwania bez frazy działał poprawnie.

Zmiana: HTML i BreadcrumbList JSON-LD wspólnie używają nazwy „Świeżo z Kuking”
dla istniejącego /odkryj. Oba CTA pustego zeszytu prowadzą do
/szukaj?sekcja=przepisy. Wyszukiwarka zachowuje zakres i prosi o wpisanie frazy;
nie przedstawiamy jej jako katalogu wszystkich przepisów.

Po zmianie: 4 testy / 17 asercji PASS. Testy wskazują konkretne linki w main,
okruszki i JSON-LD; nie opierają się na globalnej obecności adresu.
Runtime /home/mateusz/kuking-667-tests, osobna baza kuking_667_tests,
127.0.0.1:55439, UTC. Nie modyfikowano produkcji.

Pozostało: fizyczne kontrole ujemne, regresje rodzin, formatowanie i analiza,
ogląd w przeglądarce, niezależne review, wersja po koordynacji Alfy 0.64
z PR671, zwykły hook/push, CI i odbiór wdrożenia.
## Dalsze kontrole lokalne

Cztery fizyczne mutacje prawdziwych źródeł Blade (osobno okruszek HTML,
JSON-LD oraz oba CTA) zostały wykryte. Kopie poza repo; po każdej mutacji
potwierdzono MD5 i mtime oraz dodatni przebieg testu. Dowód roboczy:
output/negative-results.json. Końcowe niezależne review: output/review667.md,
bez znalezionych blokerów, z jawnym brakiem odbioru wizualnego.

Szerszy przebieg LinkDestinationLabelsTest, SearchTest,
KompozycjaZeszytowMarkiTest i ZawartoscZeszytuTest: 25 testów /127 asercji PASS.
Pint początkowo wykrył brak końcowego LF nowego testu; dodano LF bez zmian
asercji, a powyższy przebieg wykonano już po tej korekcie.

## Odbiór przeglądarkowy — pierwszy etap

48 konfiguracji (6 szerokości 320/360/390/414/768/1440, oba motywy,
100/140%, przepis i pusty zeszyt) PASS: brak poziomego overflow, rzeczywiste
kliknięcie okruszka otwiera /odkryj, CTA otwiera /szukaj?sekcja=przepisy,
wpisanie „lokalny” i wysłanie formularza zachowuje zakres przepisów.
Pierwszy przebieg bez sesji zatrzymał się na wymaganym logowaniu do zeszytu;
po lokalnym uwierzytelnieniu właściciela powyższy pełny przebieg przeszedł.
Cookie tylko w runtime, bez sekretów w repo; poczta array.

Obejrzano na razie light-140-320-recipe i dark-140-320-collection.
Okruszek i CTA mieszczą się. Przy 320/140% istniejący długi tytuł przepisu
łamie niektóre słowa, a dolna nawigacja zajmuje dwa rzędy — to obserwacje
istniejącego układu, nie dowód nowej regresji #667. Pełnego wyglądu nie uznano
na tej podstawie za ukończony.

Wyniki output/browser667/results.json, helper output/ui667.mjs.
Pozostaje ogląd pozostałych zrzutów, rzeczywisty zoom200 i odbiór pustej
listy zeszytów (HTTP regresja obu pustych stanów jest zielona).

## Pusta lista i rzeczywisty zoom — domknięcie pomiaru

Pusta lista zeszytów: dodatkowe 24 konfiguracje z rzeczywistym kliknięciem CTA
oraz wysłaniem wyszukiwania, PASS (output/browser667/index-results.json).
Łącznie zwykły odbiór obejmuje 72 konfiguracje trzech powierzchni.
Rzeczywisty zoom200 przy tekście140: 6 scen (3 powierzchnie ×2 motywy),
innerWidth320 i DPR2, brak poziomego overflow. Oba CTA kliknięte przy zoomie
prowadzą do właściwego adresu. Wynik output/browser667/zoom-results.json.

Nieudane próby po pierwszym pomiarze ujawniły HTTP429 z /motyw: automatyczny
odbiór z częstą zmianą ustawień wyczerpał limit lokalnego IP. Dodatkowo zapis
wyglądu używa fetch, więc oczekiwanie pełnej nawigacji było błędem harnessu.
Skrypt czeka teraz na odblokowanie kontrolek i kontroluje status zapisu.
Wyczyszczono wyłącznie cache plikowy izolowanego runtime667, nie produkcję.
Końcowy pełny pomiar zwrócił 16 zapisów wyglądu HTTP200 i 6 scen PASS.
Nie wyłączono limitu ani nie zmieniono kodu zabezpieczenia aplikacji.

## Ogląd końcowy i dowody do PR

Obejrzano wszystkie 18 końcowych zrzutów (trzy powierzchnie, oba motywy,
układy desktop/wąski/zoom) w zestawieniach oraz wybrane osobno.
Poprawiane linki mieszczą się i są osiągalne po przewinięciu również przy
zoom200/tekście140. Wybrane zrzuty, wyniki i review zapisano w
 docs/design/evidence/links667/. Odbiór dotyczy zmienionych odnośników,
nie oznacza zatwierdzenia całego istniejącego układu nawigacji ani tytułów.
Brak nowego CSS, JS, migracji czy zmiany uprawnień.

## Integracja po #666

PR671 scalony po12/12CI: main bdc56b8cf9b664eda104b628d85149b08d84d8d9.
Zintegrowano go bez konfliktów z gałęzią667 (merge lokalny e5b0d798).
Przygotowano Alfę0.65 i changelog; jeszcze niewysłane. Następne kontrole
muszą używać tej zintegrowanej wersji, nie poprzedniego runtime.

Na zintegrowanych źródłach Alfy0.65: 33 testy /207 asercji PASS (również
CookedCountsUnitsTest z #666). Powtórzone cztery fizyczne kontrole ujemne
prawdziwego Blade również PASS; zaktualizowano dowód MD5/mtime.
Synchronizacja wstrzymała się najpierw na lokalnych plikach i sesjach;
zachowano kopię runtime poza repo, ciasteczka wykluczono wyłącznie lokalnie
w .git/info/exclude. Żadnych metadanych Git nie przenoszono do Windows.
Serwer8073 zatrzymany przed testami; żaden test/push nie pozostaje aktywny.


## Odbiór produkcji — 18 września 2026

**Warstwa dowodu (dopisane 18.09.2026 wieczorem).** Wszystkie cztery pliki
w `evidence/links667/` pochodzą z runtime lokalnego — nie ma w nich ani jednego
wystąpienia adresu produkcyjnego. Cytaty HTML z tej sekcji są więc prozą
przebiegu, nie zapisem maszynowym. Maszynowe potwierdzenie kodów odpowiedzi
i wersji dla tych samych adresów — dla **wieczornego** stanu produkcji — jest
w [`evidence/produkcja/odczyt-20260918T1854Z.json`](evidence/produkcja/odczyt-20260918T1854Z.json).

Odbiór wyłącznie odczytowy: GET-y HTTP bez sesji, bez zapisu i bez danych
demonstracyjnych. **Odczyt HTML, nie interakcja w przeglądarce.**

### Okruszek na stronie przepisu — potwierdzony

`https://kuking.pl/przepisy/bigos-z-cukinii` → HTTP 200, stopka `Alfa 0.65` / `55877e2`.
Okruszek w HTML, cytat dosłowny:

```html
<ol class="okruchy">
    <li><a href="https://kuking.pl">Start</a></li>
    <li><a href="https://kuking.pl/odkryj">Świeżo z Kuking</a></li>
</ol>
```

`BreadcrumbList` w JSON-LD zgadza się co do nazwy i celu:

```json
{"@type":"ListItem","position":2,"name":"Świeżo z Kuking","item":"https://kuking.pl/odkryj"}
```

Etykieta odpowiada celowi: `https://kuking.pl/odkryj` → HTTP 200, `<h1>Świeżo z kuKING</h1>`.
Słowo „Przepisy” nie występuje już w okruszku ani w danych strukturalnych tej strony.

### Cel „Poszukaj przepisów” — potwierdzony, sam przycisk nie

`https://kuking.pl/szukaj?sekcja=przepisy` → HTTP 200. Zakres jest zachowany
w formularzu (`<input type="hidden" name="sekcja" value="przepisy">`), a przy pustej
frazie strona prosi o wpisanie zamiast udawać pusty katalog:

```html
<p class="meta">Wpisz coś w pole powyżej i kliknij „Szukaj”.</p>
```

Strona zachowuje się jak wyszukiwarka, nie jak lista wszystkich przepisów —
zgodnie z zakresem issue. Potwierdza to też brak katalogu: `https://kuking.pl/przepisy`
zwraca **404**, więc nie powstała ukryta nowa funkcja.

### Granica dowodu: puste stany zeszytu

Obu przycisków „Poszukaj przepisów” **nie da się zobaczyć bez sesji**:
`https://kuking.pl/zeszyt` → **302** na `https://kuking.pl/login`. Konta na produkcji
nie zakładamy i logowania nie obchodzimy, więc pusty zeszyt i pusty folder na koncie
produkcyjnym pozostają nieodebrane. Potwierdzony jest **cel** obu przycisków
(`/szukaj?sekcja=przepisy` działa i zachowuje zakres), niepotwierdzone jest ich
**wyrenderowanie w pustych stanach** na produkcji. Lokalny odbiór obu stanów
opisano wyżej w tym raporcie i to jedyne, co dziś je pokrywa.

## Ponowny odczyt produkcji — 18 września 2026, 18:54 UTC

Poprzednia sekcja opisuje `55877e2` (Alfa 0.65) i zostaje z tą datą. Ten
odczyt wykonano wieczorem, GET-ami bez sesji, gdy produkcja stała na
`3f315b3` (Alfa 0.67, wydanie 18 września 2026, 20:53). Wszystkie ustalenia
#667 **utrzymały się po zmianie wdrożenia**:

| Rzecz | Stan o 18:54 UTC |
|---|---|
| widoczny okruszek na stronie przepisu | `<ol class="okruchy">`, pozycje `Start` → `Świeżo z Kuking` → bieżący przepis |
| `BreadcrumbList` w JSON-LD | `position 1 "Kuking"`, `position 2 "Świeżo z Kuking"`, `position 3 "Bigos z cukinii"` — zgodne z listą widoczną |
| cel etykiety „Świeżo z Kuking” | `/odkryj` → 200, `<h1>Świeżo z <span class="kuking-word">…` |
| zakres wyszukiwarki | `/szukaj?sekcja=przepisy` → 200, `<input type="hidden" name="sekcja" value="przepisy">`, treść `Wpisz coś w pole powyżej i kliknij „Szukaj”.` |
| ukryty katalog przepisów | `/przepisy` → **404**, czyli nie powstał |
| CTA zeszytu | `/zeszyt` → **302** na `/login` — nadal niewidoczne bez sesji |

### Brakujący dowód #667 — jeden, z kryterium

- **Scenariusz:** wyrenderować oba puste stany zeszytu i sprawdzić, że każdy
  z dwóch przycisków „Poszukaj przepisów” prowadzi na
  `/szukaj?sekcja=przepisy`, a nie na nieistniejący katalog.
- **Potrzebne uprawnienie:** zalogowana sesja. Na produkcji **nie zakładamy
  konta i nie logujemy się** — scenariusz należy wykonać na lokalnym runtime
  z pustym zeszytem, a produkcyjnie dopiero wtedy, gdy właściciel udostępni
  sesję do odczytu.
- **Kryterium zaliczenia:** w obu pustych stanach (zeszyt bez przepisów
  i zeszyt bez zapisanych wpisów) `href` przycisku kończy się na
  `sekcja=przepisy`, a kliknięcie ląduje na stronie wyszukiwarki z zachowanym
  zakresem — nie na 404.
