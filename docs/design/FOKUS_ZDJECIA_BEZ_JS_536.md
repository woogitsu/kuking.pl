# Fokus wyboru zdjęcia bez JavaScriptu — #536

## Potwierdzony problem

W CI PR #535, przebieg `34831916909`, zadanie `103937020115`, tylko pomiar
wyboru zdjęcia bez JavaScriptu zakończył się błędem: 22 naciśnięcia Tab,
pole `inline-block`, obrys etykiety `none 3px`. Pozostały zakres tego
zadania: axe 44/44 ekranów i układ 49/49, bez zgłoszonych naruszeń.
To nie oznacza sukcesu całego zadania — jego kod wyjścia poprawnie wyniósł 1.

Wcześniejszy zielony PR #531, zadanie `103908932142`, używał tego samego
Chromium 153.0.8010.12: 7 Tabów, pole `block`, obrys `solid 3px`.

## Przyczyna i poprawka

Na prawdziwej lokalnej stronie `/dodaj/zdjecie`, przy wyłączonym JS,
opóźnienie odpowiedzi CSS o 3 sekundy dokładnie odtworzyło czerwony wynik.
`DOMContentLoaded` nie czekał na arkusz, a `document.fonts.ready` rozwiązało
się, zanim CSS zadeklarował fonty. Tab przechodził po jeszcze nieostylowanej
stronie. Fokus dokumentu, `:focus` i `:focus-visible` były prawdziwe.
Nie potwierdzono błędu samego komponentu ani aktywności karty.

Ten jeden GET w `scripts/dostepnosc.mjs` czeka teraz na `load`, następnie
jak dotąd na fonty. Nie czeka na oczekiwany styl i nie ponawia pomiaru do
sukcesu. Test stale opóźnia arkusz o 3 sekundy i wymaga, by przechwycił
co najmniej jeden CSS. Zachowano rzeczywisty Tab, jedną etykietę,
dostępność pola i widoczny obrys. Oczekiwania odpowiedzi kończą się przed
zamknięciem kontekstu. Brak arkusza lub błąd nadal nie daje zielonego wyniku.

## Regresja rzeczywistego źródła

Izolowana kopia `/tmp/kuking-notice534-exec`, baza `kuking_port_notice534`
na porcie 55439, Chromium 153, 360×740, demonstracyjne konto Ani.
Wykonano rzeczywisty blok `wyborZdjeciaBezJs` wraz z jego końcową bramką,
wycięty z aktualnego pliku, bez przepisywania algorytmu i asercji.

- Dodatni: jeden opóźniony CSS, 7 Tabów, `block`, `solid 3px`, kod 0.
- Fizyczny powrót źródła JS do `domcontentloaded`: 22 Taby, `inline-block`,
  `none 3px`, kod 1. Po przywróceniu ponownie kod 0.
- Fizyczne usunięcie reguły fokusu w `resources/css/ekran-dodawania.css`
  i rzeczywisty build: 7 Tabów, `block`, `none 3px`, kod 1.
  Po przywróceniu i rebuildzie ponownie `solid 3px`, kod 0.

Kopie `cp -p` poza repo, kontrola MD5 i mtime po każdej mutacji:
JS `9560ed1ff23ab457e758b8fbbf17b40d`, mtime_ns `1789382042794566867`;
CSS `a809037e9289f5912af0267d175716f0`, mtime_ns `1789378734000000000`.
Zrzut widocznego pierścienia obejrzano. Serwer i przeglądarki zamknięto;
produkcja nie była modyfikowana. Niezależny review nie stwierdził osłabienia
bramki. Składnia Node i diff przechodzą.

Pełny port marki dla wcześniejszego head `a83496e` przeszedł, w tym
24 konfiguracje nowego kontrastu i trzy negatywy CSS. PHP: 3794 testy /
76279 asercji. Nie przenosimy tego wyniku automatycznie na poprawkę #536;
wymaga ona nowego pełnego hooka i CI przed scaleniem PR #535.
