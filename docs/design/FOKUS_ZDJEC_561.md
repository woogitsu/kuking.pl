# Powiększanie i fokus zdjęć — #561, Alfa 0.44 przygotowana

## Problem i poprawka

Link obejmujący całe wysokie zdjęcie miał obrys większy niż dostępny ekran.
Izolowany render karuzeli odtworzył 982 px wysokości przy oknie 900 px,
foncie korzenia 32 px i tekście 140%. Font 32 px nie jest zoomem 200%.

`photo.blade.php` rozdziela pole zdjęcia i pojedynczy link „Powiększ zdjęcie”.
Pseudo-element tego samego linku zachowuje kliknięcie i dotknięcie fotografii.
Adres dużego wariantu działa również bez JS. Nie dodano przystanku Tab ani
zmiany autoryzacji, danych czy mediów. `zoom=false` nadal nie dodaje linku.
Karuzela zachowuje kwadrat i `contain`; kolaż na telefonie naturalne proporcje.
Systemowy outline w forced-colors zastępuje niewidoczny tam box-shadow.

Pełna strona ujawniła dwa dodatkowe błędy:

- Po szybkim Escape/Enter opóźnione zdarzenie `close` usuwało `src` już
  ponownie otwartego zdjęcia. Handler pomija czyszczenie otwartego dialogu.
- Fokus zwykłego linku w `.notice` miał w ciemnym motywie kontrast 1,239:1.
  Outline korzysta teraz z tokena tekstu komunikatu, a w forced-colors
  z systemowego `Highlight`.

## Testy wykonane lokalnie

| Zakres | Wynik i granice |
|---|---|
| Build Vite, 72 pary kontrastu tokenów | PASS |
| PowiększanieZdjeciaTest, WygladZdjecWeWpisieTest, OdstepPodZdjeciemNaKarcieWpisuTest | 28 testów / 118 asercji PASS, osobna baza `kuking_561_tests`, UTC, port 55439 |
| `scripts/fokus-zdjec.mjs` | 300 konfiguracji PASS: 288 kombinacji dwóch orientacji, trzech układów, sześciu szerokości, dwóch motywów i czterech wariantów fontu/skali; 6 forced-colors; 6 bez JS |
| Pełne strony Laravel, prawdziwy zoom Chrome 200% | 12/12 PASS: trzy układy, szerokości 320/768, oba motywy, tekst 140%; wszystkie trzy zdjęcia otwarte i zamknięte w każdym wariancie |
| Kompletność Tab na pełnej stronie | zwykły wpis i kolaż 10/10, karuzela 15/15; mierzone zasłanianie i kontrast |
| Fizyczne kontrole ujemne | dawny link, brak rozszerzonego kliknięcia, brak forced-colors, brak ochrony opóźnionego close, brak koloru fokusu notice — każda wykryta |
| Przywrócenie źródeł | kopie poza repo, MD5 i mtime potwierdzone, dodatnie przebiegi po przywróceniu |
| Końcowy review statyczny | bez nowego blokera; sugestię oczekiwania na natywne close zamiast 50 ms wdrożono i ponownie sprawdzono negatywem |

Regresja renderuje rzeczywiste komponenty Blade z niezapisanych modeli,
ładuje skompilowany CSS i prawdziwy blok dialogu z `app.js`. Podmienia wyłącznie
obrazy na syntetyczne. Sprawdza Tab, Enter, Escape, powrót fokusu, mysz,
dotyk, przycisk Zamknij i brak poziomego overflow. Osobny odbiór pełnych
stron używa rzeczywistego MediaController oraz lokalnych mieszanych WebP.
Dane demonstracyjne powstały wyłącznie w `kuking_561_browser` na 55439.

W CI dodano osobny krok regresji, trzy filtry zmian oraz artefakt zrzutów.
Sama zmiana skryptu uruchamia właściwe kontrole. YAML sparsowano, fixture
przeszło Pint, konfiguracja przeszła kontrolę składni PHP.

## Dowody i ogląd

`evidence/fokus561/` zawiera fizyczne negatywy, dodatnie przebiegi,
`kontrole-ujemne.json`, `additional-negatives.json` i `zoom200.json`.
Logi rozróżniają wcześniejsze negatywy CSS/Blade od dodatkowych JS/notice.
Ostateczny test close oczekuje rzeczywistego zdarzenia oraz następnej klatki.

Obejrzano pionową karuzelę 320/dark przy foncie 32 px i tekście 140%,
pełną karuzelę 320/dark oraz zwykły wpis 768/light przy prawdziwym zoomie.
Kolorowe prostokąty z napisem „LOKALNY TEST” są obrazami pomiarowymi,
nie materiałami użytkowników ani projektem wyglądu zdjęć.

Ograniczenie zrzutów przewiniętego viewportu przy zoomie: obraz zawierał
pusty pas mimo `elementsFromPoint` wskazującego zdjęcia, bez otwartego dialogu.
Odczekanie 1200 ms go nie usunęło. Przy scrollY 880,5 pas miał około 881 px;
zrzut po scrollY=0 pokazał poprawną treść. Podejrzenie dotyczy przechwytywania
obrazu; nie zaliczono tych zrzutów jako kompletnego odbioru kompozycji ani
nie zmieniano na ich podstawie CSS. Dowody `settled-*` i `top-*` zachowano.

## Status dostarczenia i pozostałe bramki

Stan: gałąź wysłana po pełnym hooku (commit `9fe0229`), bez PR i wdrożenia.
Alfa 0.44 jest przygotowana. Dodatkowy odbiór mieszanych orientacji oraz
przycisków karuzeli myszą/dotykiem zakończono; wyniki poniżej.
Korekta pomiaru istniejącej karuzeli bez JS wymaga jeszcze wysyłki.
Następnie PR, wymagane CI, normalne scalenie i odbiór produkcji.

Gałąź `fix/561-fokus-zdjec` bazuje na `3a4156d` z PR #616 (Alfa 0.43).
PR #592 (Alfa 0.42) scalono jako `2144a1143c51808cdc1639c984df99d5ad586fbc`;
jego wdrożenie nie było jeszcze potwierdzone przy tym zapisie. Nie scalać
#561 przed #616. Pełny port marki nadal **CZĘŚCIOWO**.

Rollback: revert pakietu i ponowny build assetów. Brak migracji danych.

Dodatkowy odbiór pełnych stron ukończono: **108/108 PASS**. Trzy układy,
sześć szerokości 320/360/390/414/768/1440, dwa motywy i tekst 70/100/140%.
Sprawdzono naturalne proporcje, kwadrat i contain tam, gdzie przewiduje je
projekt, dekodowanie wszystkich zdjęć oraz brak overflow. W karuzeli
przejścia 1→2→3→2→1 wykonano naprzemiennie myszą i dotykiem. Kliknięcie
zdjęcia otworzyło podgląd. Dowody: `interakcje.json`, `interakcje/` oraz
lokalny skrypt pomiaru (wymaga odtworzenia osobnej bazy i pliku jej adresów).
Ta bramka jest zakończona; hook, PR, CI i produkcja pozostają przed nami.

Pierwszy push zatrzymał hook na dwóch testach przywiązanych do dawnej struktury:
`GaleriaMieszanychOrientacjiTest` szukał kwadratu na zewnętrznym linku zamiast
polu obrazu; `KompozycjaHeroPrzepisuTest` wymagał bezpośredniego rodzica `a`.
Po dostosowaniu lokalizatorów zachowano reguły proporcji i min-height oraz
adres dużego wariantu; dodano kontrolę jednego linku i jego widocznego podpisu.
Celowane 7 testów / 55 asercji PASS. Trzy fizyczne negatywy (aspect-ratio,
min-height, adres large→feed) wykryte, MD5/mtime przywrócone, dodatni przebieg
PASS. Dowody `php-negatives.json` i logi `php-*`.

Oryginalny scenariusz #561 na pełnym landing: font Chromium32, tekst140%,
1440×900, oba motywy — PASS35/35 kontrolek, 9 linków zdjęć. Dowód
`landing-original.json`. Dodatkowe 320×900 zatrzymało się przed zdjęciami na
przycisku rejestracji wysokości962px. Taką samą wysokość potwierdzono na
produkcji Alfa0.41/ae0068a; problem zgłoszono osobno, testu nie osłabiono.

Istniejący pomiar `scripts/fixtures/karuzela-mieszana.mjs` dostosowano do
wewnętrznego pola `.photo-zoom-media`: podpis powiększenia nie jest częścią
kwadratowej ramki obrazu. Osiem rzeczywistych przebiegów bez JS (320/390 px,
zwykły tekst, 140%, font Chromium 32 px i rzeczywisty zoom 200%) PASS.
Zachowano progi kwadratu, contain, stabilnej wysokości, kontrolek 48 px,
braku overflow i widoczności fokusu oraz rzeczywiste przejścia klik/Tab/Enter.
Fizyczne usunięcie `aspect-ratio` z CSS wykryto jako „utracona ramka D-191”.
Kopię wykonano poza repo; po przywróceniu zweryfikowano MD5 i mtime,
przebudowano assety i ponownie uzyskano 8/8 PASS. Dowody `karuzela-*`.
Obejrzano także zrzut 320 px przy zoomie 200%: podpis i kontrolki mieszczą
się w szerokości. Cztery testy integralności raportu karuzeli PASS.
