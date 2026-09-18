# Liczniki wykonań — #666

Stan: **scalone i wdrożone**, odbiór produkcji niedomknięty.
PR #671 scalony 18.09.2026 jako `bdc56b8` (Alfa 0.64); produkcja stoi dziś na
`55877e2` (Alfa 0.65), CI main 35331870558 i Deploy 35333641106: success.
Zdanie o niewysłanej Alfie 0.64 i baza `397a742` poniżej to snapshot sprzed
scalenia. Blokuje wyłącznie sekcja „Granica dowodu produkcji — 18 września 2026”.

## Problem i zmiana

Rzeczywisty render Laravel na poprzednim main pokazał „4 osoby ugotowały”
dla czterech wykonań dwóch osób. Jedna osoba może wielokrotnie gotować
z przepisu i przy każdym wykonaniu odpowiedzieć inaczej.

Zachowujemy zdarzenia, zapytania, filtry widoczności, paginację i próg trzech
odpowiedzi. Zmieniają się podpisy: „4 wykonania”, „Zrobię ponownie: 2 z 4
odpowiedzi” oraz „50% odpowiedzi: „Zrobię ponownie””. Licznik „Ugotowane 4 ×”
i userInteractionCount w JSON-LD nadal opisują wykonania. Brak migracji.

## Lokalny dowód

- Nowa regresja na starej implementacji: 7 przypadków, 6 oczekiwanych
  porażek związanych z jednostką; pusty stan prawidłowo przeszedł.
- Po poprawce: 33 testy / 180 asercji PASS, wraz z czterema istniejącymi
  rodzinami kontroli widoczności, paginacji i progu opinii.
- Wielokrotne wykonania jednego konta, odmiana 0/1/2/5/12/22, sprzeczne
  odpowiedzi dwóch osób oraz brak odpowiedzi niezwiększający progu.
- Cztery fizyczne mutacje prawdziwego Blade: jednostka, podpis odpowiedzi,
  procent i próg trzech odpowiedzi. Każda wykryta; kopia poza repo,
  przywrócone MD5 i mtime, dodatni wynik po każdym przywróceniu.
- Odczyt review wykrył asercję sprawdzającą stary napis pod progiem;
  poprawiona na oba aktualne napisy i potwierdzona przez recenzenta.
  Review nie obejmowało oglądu UI ani ostatniego dodatkowego testu progu.

Runtime: /home/mateusz/kuking-666-tests, baza kuking_666_tests wyłącznie
127.0.0.1:55439, UTC. Poczta array. Dowody robocze: output/negative-results.json,
output/review666.md i helpery output/check.py, output/negative.py w worktree666.
Nie wykonano nowych operacji na danych produkcyjnych.

## Pozostało

Ogląd obu motywów i małych szerokości, końcowe review, pełne wymagane kontrole,
zwykły hook i push, PR/CI, merge oraz odbiór produkcji. Nie zamykać #666
na podstawie samego lokalnego wyniku. Pełny port marki nadal CZĘŚCIOWO.

## Odbiór przeglądarkowy — 18 września, dalszy przebieg

Na rzeczywistym lokalnym Laravel i oddzielnej bazie kuking_666_browser:
96 konfiguracji (320/360/390/414/768/1440 px, oba motywy, tekst 100/140%,
0/1/4/22 wykonania) przeszło kontrolę jednostek i poziomego overflow.
Wariant 4 potwierdza 2 z 4 odpowiedzi i 50%, wariant 22 — 12 z 22.
Wyniki: output/browser666/results.json; skrypt: output/ui666.mjs.
Zapisano 16 zrzutów. Obejrzano dotąd dwa: light-140-320-4 oraz
 dark-100-1440-22; pozostałe wymagają oglądu. Test nie zastępuje rzeczywistego
zoomu 200% ani fizycznego telefonu. Dane i aktywności są wyłącznie lokalne.
Build Vite przeszedł wraz z 72 parami kontrastu i 7 testami JS.
Pozostają końcowe review, zoom, wymagane kontrole i droga publikacji opisana wyżej.

## Końcowy odbiór lokalny

Rzeczywisty zoom Chromium 200% (chrome.tabs.setZoom/getZoom), z tekstem
aplikacji 140%, przeszedł 8 scen: oba motywy × 0/1/4/22 wykonania.
Potwierdzono innerWidth 320 przy oknie 640 oraz DPR 2; brak poziomego overflow.
Obejrzano wszystkie 24 zrzuty w zestawieniach, a dwa układy i jeden zoom
również osobno. Nowe podpisy mieszczą się i poprawnie zawijają.
Nie jest to odbiór fizycznego telefonu ani czytnika ekranu.

Końcowe niezależne review nie znalazło blokerów, obejmuje też ostatni test
progu odpowiedzi. Dowody wybrane do repo: docs/design/evidence/counts666/.
Pint wskazał CRLF w pięciu przeniesionych plikach; sformatowano je i
przeniesiono same pliki do worktree, bez metadanych Git. Pozostają pełny hook,
push, PR/CI, merge i rzeczywisty odbiór produkcji. Alfa 0.64 nadal lokalna.


## Dostarczenie — 18 września 2026

PR #671 scalony po12/12 zielonych kontrolach PR. Main
`bdc56b8cf9b664eda104b628d85149b08d84d8d9`, Alfa0.64.
CI main35319937744:12/12success. Railway6519736990:success.
Końcowy Deploy35322112089:success. Strona główna i publiczny przepis
[Bigos z cukinii](https://kuking.pl/przepisy/bigos-z-cukinii) zwracają HTTP200, Alfę0.64 orazbdc56b8.
Dowody: evidence/counts666/main-ci.json oraz production-http.json.

Publiczny przepis ma pustą listę wykonań. To poprawny pusty stan, ale nie
produkcyjny dowód niezerowych podpisów. Issue666 pozostaje otwarte wyłącznie
do reprezentatywnego odbioru niezerowych liczników na rzeczywistych danych.
Nie tworzono produkcyjnego fixture. Wielokrotne wykonania, odmiany,
odpowiedzi i prywatność mają zakres lokalny/CI opisany powyżej.

Produkcję obejrzano także w rzeczywistym Chromium jako gość,1440×1000,
jasny motyw: przepis Bigos z cukinii, sekcja Komu wyszło i pusty stan.
Zrzut production-empty666.png obejrzany, production-browser.json potwierdza
wersję i SHA. Bez zmiany danych. Nie rozszerza to odbioru na niezerowe liczniki.


## Granica dowodu produkcji — 18 września 2026

Odbiór wyłącznie odczytowy: GET-y HTTP bez sesji, bez tworzenia wykonań,
odpowiedzi i kont. **Odczyt HTML, nie interakcja w przeglądarce.**

### Ile w ogóle jest publicznych przepisów

Jeden. Sprawdzone trzema drogami zamiast przyjęcia założenia:

- `/szukaj?sekcja=przepisy` z realnymi zapytaniami: `bigos` → `Znaleziono 1 przepis.`
  i jedyny odnośnik `https://kuking.pl/przepisy/bigos-z-cukinii`; `rolada`, `kawowa`,
  `ciasto`, `zupa`, `placki`, `obiad` → `Nic nie znaleźliśmy` (HTTP 200 w każdym przypadku),
- zakładki `?zakladka=przepisy` profili `/@izazpodlasia`, `/@on_the_plate`, `/@ulalala`:
  tylko `@ulalala` ma przepis i jest to ten sam Bigos z cukinii,
- `/`, `/odkryj` i trzy strony wpisów: jedyne odnośniki `/przepisy/…` prowadzą do bigosu.

**Korekta wcześniejszego założenia:** „Rolada kawowa” Ewy Kapicy **nie jest przepisem**,
tylko wpisem — `https://kuking.pl/wpisy/01a0a6af-4aea-7230-9b41-8c6d4b694eee`.
`https://kuking.pl/przepisy/rolada-kawowa` zwraca **404**. Nie ma więc drugiego
przepisu, na którym można by szukać niezerowych liczników.

### Co pokazuje jedyny publiczny przepis

`https://kuking.pl/przepisy/bigos-z-cukinii` → HTTP 200, stopka `Alfa 0.65` / `55877e2`.
Sekcja wykonań w HTML, cytat dosłowny:

```html
<h2 id="komu-wyszlo" class="m-0">Komu wyszło</h2>
...
<p class="empty-state-title">Jeszcze nikt tego nie gotował</p>
<p class="empty-state-opis"><p class="mb-0">Twoje wykonanie będzie pierwsze.</p></p>
```

Zero wykonań. Zakładki `?zakladka=ugotowane` wszystkich trzech profili publicznych
zwracają `Brak wykonań`. W całym pobranym HTML produkcji (strona główna, `/odkryj`,
trzy wpisy, trzy profile z zakładkami, sześć stron wyszukiwania, strona przepisu)
**nie występuje ani jedna etykieta z niezerową liczbą** — ani `N wykonań`,
ani `N osób`, ani `N z M odpowiedzi`, ani `Ugotowane N ×`.

### Co to znaczy dla odbioru

Pozytywnie: oba publicznie widoczne puste stany nazywają **zdarzenie**, nie osobę —
`Twoje wykonanie będzie pierwsze.` oraz `Brak wykonań`. Zgodnie z zakresem #666.

Granica: **zero wykonań na całej produkcji** oznacza, że niezerowych podpisów
(`4 wykonania`, `2 z 4 odpowiedzi`, `50% odpowiedzi`, odmiana 0/1/2/5/12/22)
nie da się dziś obejrzeć na rzeczywistych danych. Brak danych **nie jest wynikiem
pozytywnym** — to granica dowodu, a nie potwierdzenie. Nie tworzyliśmy wykonań
ani fixture na produkcji, żeby ją obejść.

Warunek domknięcia pozostaje jeden: gdy na produkcji pojawi się przepis z co
najmniej jednym wykonaniem, potwierdzić na nim etykietę liczby wykonań, a przy
co najmniej trzech odpowiedziach również podpis odpowiedzi i procent.
