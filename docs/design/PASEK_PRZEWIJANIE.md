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
Odbiór Railway i rzeczywistego zachowania produkcji nadal w toku.
Pakiet bazuje na PR #572 (zeszyty); dostarczyć po jego zakończeniu.
Pełny port marki pozostaje CZĘŚCIOWO.

Dodatkowo RezerwaNadPaskiemTest: 3 testy / 27 asercji PASS.
Po przywróceniu źródeł ponownie 24 warianty PASS i reduced-motion PASS.
