# Pasek gościa podczas przewijania — Alfa 0.37

Prośba właściciela z 15 września 2026: pasek z logo, logowaniem i
rejestracją ma znikać przy przewijaniu w dół i wracać w górę.

Zakres: wspólny pasek niezalogowanej osoby. Po minięciu wysokości paska
8 px ruchu określa kierunek. Przy początku strony, fokusie wewnątrz lub
otwartym menu pozostaje widoczny. Przesunięcie nie zmienia przepływu
strony. Bez JS pozostaje poprzedni przypięty pasek. Istniejące reguły
odpinania przy zbyt małym oknie/dużym tekście nadal obowiązują.
Cleanup obsługuje nawigację Livewire. Ograniczenie ruchu respektowane.

## Wykonane lokalnie

- Build Vite i 72 pary kontrastu PASS.
- 24 konfiguracje: 320/360/390/414/768/1440, oba motywy, tekst100/140.
  Pomiar geometrii po ruchu w dół, w górę i na początek; brak overflow.
- Osobno rzeczywisty zoom200% przy szerokości390 i tekście140%:
  tabs.getZoom=2, innerWidth=390; schowanie i powrót PASS. Shift+Tab
  z pierwszego linku treści dochodzi do paska i odsłania fokus.
- Obejrzano zrzuty schowanego i widocznego paska oraz fokusu z zoomem.
- Dwa fizyczne negatywy: usunięcie transformacji CSS oraz powrotu JS.
  Oba wykryte; kopia poza repo, przywrócone MD5 i mtime.
- Niezależne readonly review: brak blokera. Nie zastępuje pomiarów.

Regresja scripts/pasek-przewijany.mjs podłączona do port-projektu.mjs.
Dowody w evidence/pasek-przewijany. Zoom to osobny pomiar lokalny.
Nie wykonano odbioru na fizycznym telefonie, czytniku ani pełnego cyklu
Livewire. Kontrola reduced-motion akceptuje globalne repo 0,01 ms,
zamiast wymagać dokładnie 0 s.

## Dostarczenie

Zwykły hook przeszedł. PR #573: CI 34970650611, wszystkie 10 zadań success.
Scalono normalnie jako 91c8b4106fb96b4447ecc5db8ae39eb94996b49d.
CI main 34975700310 przekroczyło limit 25 minut zadania portu marki
(104402800738); pozostałe dziewięć zadań przeszło. Railway 6460017818
ma stan inactive. Alfa 0.37 nie ma potwierdzonego wdrożenia ani odbioru
produkcyjnego zachowania. Naprawę podziału pomiarów opisuje
PODZIAL_POMIAROW_CI_577.md. Ostatnia potwierdzona produkcja to Alfa 0.36.
Pakiet bazuje na PR #572 (zeszyty); dostarczyć po jego zakończeniu.
Pełny port marki pozostaje CZĘŚCIOWO.

Dodatkowo RezerwaNadPaskiemTest: 3 testy / 27 asercji PASS.
Po przywróceniu źródeł ponownie 24 warianty PASS i reduced-motion PASS.

## Zakończony odbiór wdrożenia

Alfa0.38/0e1bdbe potwierdzona na produkcji: mainCI34986762320
11/11success, Railway6462041446 i Deploy34989544692 success.
[Szczegóły, zakres i ograniczenia odbioru](ODBIOR_PRODUKCJI_ALFA_038.md).
Powyższe oczekiwanie na main/produkcję jest stanem historycznym.

## Rozszerzenie na osoby zalogowane — 19 września 2026

Zgłoszenie właściciela: na telefonie pasek chował się przy przewijaniu,
a potem przestał. Odczyt kodu: nic się nie zepsuło — atrybut
`data-pasek-przewijany` stał w `layout.blade.php` pod `@guest` **od
pierwszego commita tej funkcji** (`bbfe5658`) i nikt go potem nie ruszał.
Potwierdzone `git log -S`. Chowanie działało więc wyłącznie przed
zalogowaniem, zgodnie z zakresem zapisanym wyżej, a zgłaszający patrzył
wcześniej na serwis jako gość.

Potwierdzone także na żywej produkcji: w HTML-u dla niezalogowanego
nagłówek nadal ma ten atrybut.

**Decyzja właściciela: pasek ma chować się także po zalogowaniu.**
Warunek `@guest` usunięty.

Po zalogowaniu pasek niesie WIĘCEJ niż u gościa — wyszukiwarkę, licznik
powiadomień i menu konta — więc na telefonie zabiera odpowiednio więcej
ekranu i tym bardziej warto go oddać treści. Nic nie znika bezpowrotnie:
ruch w górę przywraca pasek.

Trzy zabezpieczenia w `resources/js/pasek-przewijany.js` działają bez
zmian i to one czynią rozszerzenie bezpiecznym: pasek nie chowa się przy
początku strony, przy fokusie wewnątrz (czyli podczas nawigacji Tabem)
ani przy otwartym menu — a menu konta to właśnie `details[open]`.

### Co zmierzone

- `PasekChowaSieTakzePoZalogowaniuTest`: 3 testy, 6 asercji, PASS.
  Pilnuje tego, czego pomiar przeglądarkowy przegapi, jeśli ktoś
  przywróci warunek: **czy atrybut w ogóle dochodzi do obu widoków**.
  Pomiar bez atrybutu nie oblewa — on po prostu nie ma czego mierzyć.
- **Fizyczna kontrola ujemna**: przywrócenie `@guest` → dwa testy
  oblewają, w tym asercja, że atrybut siedzi na `header.topbar`.
  Plik przywrócony, MD5 zgodne (`adc76b46dbf83d59727dfe7913a71701`).
- Testy sąsiednie (layout, pasek, topbar, nawigacja): 35 PASS.
- `scripts/pasek-przewijany.mjs` mierzy teraz DWA stany zalogowania.
  Stan zalogowany idzie na trzech szerokościach (320/390/768) w dwóch
  skalach pisma zamiast pełnych 24 konfiguracji: mechanizm CSS i JS jest
  wspólny, a pełna macierz kupowałaby minuty CI za tę samą wiedzę.
- Dołożony przypadek, którego u gościa NIE MA: **otwarte menu konta**.
  Skrypt nie ma prawa schować paska, gdy człowiek ma w nim otwarte menu.
  Pilnował tego `details[open]` w JS, ale nikt tego dotąd nie mierzył,
  bo gość menu konta nie ma.

### Czego NIE zmierzono

Fizycznego telefonu ani czytnika ekranu. Produkcji po tej zmianie —
odbiór dopiero po wdrożeniu. Nie sprawdzono też, czy chowanie paska nie
przeszkadza w trybie gotowania ani w panelu moderacji: oba mają własne
układy i nie były przedmiotem tego pomiaru.
