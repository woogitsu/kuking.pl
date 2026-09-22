# Panel moderacji #581 — ponowny odbiór floty

Stan źródeł: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź
`gpt/panel-moderacji-marka`, 20 września 2026.

## Dlaczego nie powstaje drugi port

Odczyt aktualnego issue #581 i kodu wykazał, że port kompozycji jest już
wdrożony w tej podstawie. Obecny zakres issue to odbiór pozostałych stanów.
Nowa rama jest w `resources/css/marka-panel.css`, importowanym przez
`app.css`; layout nadaje `data-marka-panel` i opakowuje treść klasą
`marka-panel-tresc`. Nawigacja używa `panel-menu-przelacznik` oraz
`panel-menu-tresc`; bez JavaScriptu lista pozostaje rozwinięta.

[pomiar cudzy: aktualny opis https://github.com/woogitsu/kuking.pl/issues/581]
PR #587 przeniósł kompozycję, #637 dostarczył zwijane menu. Podane tam
wyniki CI i produkcji nie są pomiarami wykonanymi w tej sesji.

Decyzje D-218 i D-220 nadal obowiązują. Nie zmieniono Policy, ról, tras,
kontrolerów, widoczności danych, schematu ani znaczenia decyzji moderacyjnych.

## Środowisko i metoda własnego pomiaru

- Worktree: `C:\Users\matma\Documents\kuking-flota\gpt-panel-moderacji-marka`.
- Runtime przeglądarki: `/home/mateusz/flota/gpt-panel-moderacji-marka-run`.
- Pełne testy PHP: `/home/mateusz/flota/gpt-panel-moderacji-marka-php-run`,
  osobna kopia mediów i zależności, aby testy nie niszczyły fixture przeglądarki.
- PostgreSQL: **127.0.0.1:55439**, właściciel `kuking`.
- Testy PHP: baza `kuking_flota_gpt-panel-moderacji-marka`.
- Przeglądarka: nowa baza
  `kuking_flota_gpt_panel_moderacji_marka_browser_20260920`, strefa UTC.
- Własne kopie zależności, osobny APP_KEY, poczta `array`, lokalne media.
- Rzeczywiste logowanie fixture i kod TOTP; sesje oraz poświadczenia poza repo.
- Macierz używa istniejących mierników projektu, bez zmian ich asercji.
  Wyłącznie w runtime pięć plików oprzyrządowania otrzymało lokalną nazwę
  bazy zamiast historycznych nazw z listy dopuszczonych. Lokalny runner
  wykonał też etap walidacji normalnie uruchamiany tylko w CI. Port,
  kontrola środowiska i mailer pozostały wymagane. To adapter wykonawczy,
  nie zmiana aplikacji ani dowód wykonania GitHub Actions.

## Strażniki: zakres i granice

| Strażnik | Co obejmuje | Czego nie dowodzi |
|---|---|---|
| `PanelUzywaTypografiiMarkiTest` | Wprowadzenia Sygnałów i Tagów mają `text-lead`, a arkusze zawierają token | Wyliczonego fontu całego panelu |
| `PanelModeracjiMaCalaSzerokoscTest`, `PanelSzerokiTelefonTest` | Reguły ramy, brak prawej szyny, lokalne przewijanie tabeli, atrybuty HTML | Wyniku wszystkich nadpisań nowego arkusza |
| `OdstepMiedzyDrogamiWejsciaTest` | Skan wszystkich `resources/css/*.css`, w tym panelu; jawny, wąski wyjątek jego rytmu; kontrola liczby plików | Pełnej kaskady i geometrii przeglądarki |
| `MinimalnyRozmiarTekstuTest` | Wybrane selektory i tokeny w `app.css` i `tokens.css` | Ogólnego skanu wszystkich reguł `marka-panel.css` |
| `GlosMarkiOpisujeArkuszPrawdziwieTest` | Kontrakt dokumentów i reguł zapisu nazwy w `app.css` | Portu panelu; jego parser nie jest silnikiem kaskady |
| `panel-marki.mjs` | Puste/pełne ekrany, sześć szerokości, motywy, skala, dane, geometria, Tab i radio | Wszystkich błędnych POST i wszystkich zamkniętych sekcji |
| `panel-details.mjs`, `panel-details-zoom.mjs` | Otwarte potwierdzenia i pomoc wiadomości, obrys, cały tekst, prawdziwy zoom | Poprawnego zapisu decyzji i wysyłki wiadomości |
| `panel-validation.mjs` | Dziewięć błędnych POST, stare wartości, izolacja wierszy, niezmienność siedmiu tabel | Natywnej walidacji przeglądarki i kompletnego odbioru wszystkich formularzy |

Nie zmieniano `app.css`, więc nie korygowano liczby reguł pod parser
`GlosMarkiOpisujeArkuszPrawdziwieTest`. Test przeszedł na podstawie zadania.

## Wyniki

Wszystkie niżej opisane pomiary wykonano samodzielnie na kodzie podstawy,
przed zmianą aplikacji. Porównanie SHA-256 potwierdziło zgodność **597 plików**
`app`, `resources`, `routes` i `config` między worktree a runtime przeglądarki.
Nie powstała poprawka błędu, nowy strażnik ani nowa asercja produktowa.

### Kompozycja i wymiary

**600/600 konfiguracji**: 288 pustych i 312 pełnych; szerokości
320/360/390/414/768/1440 px, dwa motywy, tekst 100/140%.
Zestaw obejmuje też ekran wymagający 2FA i widoki szczegółowe przewidziane
przez fixture. Klawiatura, radio, widoczność fokusu, minimalne cele
48 × 48 px i brak przepełnienia strony przeszły istniejący miernik.

Przykład zmierzony na pełnych Zgłoszeniach, jasny motyw:

| Szerokość | Skala tekstu | Tekst bazowy | Nawigacja | Treść | Odstęp |
|---|---|---|---|---|---|
| 320 px | 100% | 18 px | 296 px | 296 px | 24 px, układ pionowy |
| 320 px | 140% | 25,2 px | 296 px | 296 px | 24 px, układ pionowy |
| 1440 px | 100% | 18 px | 256 px | 1112 px | 24 px, dwie kolumny |
| 1440 px | 140% | 25,2 px | 358,39 px | 1009,61 px | 24 px, dwie kolumny |

Nawigacja ma promień 26 px i powierzchnię `rgb(255, 255, 255)` w jasnym
motywie; pomiar sprawdza zgodność z tokenem także w ciemnym. Główna treść
zajmuje pozostałą szerokość, bez pustej prawej szyny. Brak odtworzonego
rozjazdu wymagającego nowego portu. Mobilną nawigację miernik otwiera przed
pomiarem wszystkich odsyłaczy — dlatego na części zrzutów jest rozwinięta.

### Bez JavaScriptu

Dodatkowy odczyt **16/16 konfiguracji**: 320 px, oba motywy i obie skale,
kolaż, tablica na dziś, zgłoszenia i odwołania. Kontekst przeglądarki miał
wyłączone skrypty strony. Aparatura ustawiła tylko motyw i skalę oraz
odczytała geometrię. Żądania inne niż GET/HEAD były blokowane; licznik
prób mutacji wyniósł zero.

Potwierdzenia czyszczenia używają natywnego `details.confirm`: właściwy
przycisk jest ukryty przed rozwinięciem i dostępny po nim, także klawiaturą.
Formularze są serwerowe i mają CSRF. Nie wykonano czyszczenia ani skutecznej
decyzji. Pola miały minimum 18 px tekstu; przyciski w tej próbie minimum
50,5 px wysokości przy skali 100% oraz 59,5 px przy 140%. Cała strona
pozostawała szeroka na 320 px. Sprawdzenie to nie dowodzi poprawnego zapisu
każdej operacji — ten zakres należy do testów domenowych i HTTP.

### Testy i pozostałe stany

- 24/24 rozwinięte sekcje: potwierdzenia kolażu i tablicy oraz pomoc pocztowa.
- 6/6 tych sekcji przy rzeczywistym zoomie 200%, skali tekstu 140%, CSS 320 px,
  DPR 2; 4/4 dodatkowe konfiguracje menu przy zoomie 200%.
- 8/8 dodatkowych stanów menu, w tym zachowanie bez skryptów.
- 9/9 błędnych POST: poprawne wartości i wybory zostają w aktywnym wierszu,
  błędy są przypisane do pól, linki podsumowania przenoszą fokus do nich,
  drugi wiersz nie przejmuje wpisanych danych. Siedem tabel domenowych
  pozostało niezmienionych. To walidacja serwera, nie test natywnego `required`.
- Łącznie 651 przypadków istniejącego zestawu przeglądarkowego oraz
  16 dodatkowych pomiarów bez skryptów, wszystkie zaliczone.
- Początkowy wybór testów panelu, moderacji, 2FA i strażników marki:
  **139 testów, 778 asercji**, 18,66 s.
- Szeroki zestaw PHP: **4393 testy, 83 692 asercje**, 466,41 s, bez porażek.
  Wyłączono `ProbaOdtworzeniaTest` zgodnie z podanym wyjątkiem wspólnej bazy.
  Nie uruchamiano grupy `dwa-polaczenia`, wyłączonej również w domyślnej
  konfiguracji projektu; ten odbiór nie zmienia operacji współbieżnych.
- `vendor/bin/pint --test` oraz końcowe `vendor/bin/pint`: **1155 plików**, bez uwag.
- `npm run build`: zaliczone, w tym 72 pary kontrastów i kontrole Node;
  wynikowe zasoby `app-C9S9kWiN.css` i `app-B4FFWDpj.js`.

Nie uruchamiano opcjonalnych mutacji `--negative-menu` ani
`--negative-details`: nie zmieniano aplikacji ani tych testów. Nie ogłasza
się więc świeżego wyniku ich kontroli ujemnych. Zasada czerwonego testu
przed poprawką nie została zastąpiona samym zielonym wynikiem — w tej sesji
nie ma poprawki, którą należałoby w ten sposób udowodnić.

### Dowody

- [Zapis pomiarów i sumy źródłowych raportów](evidence/flota581-20260920/pomiary.json).
- [Pomiar bez skryptów](evidence/flota581-20260920/bez-javascriptu.json).
- [Walidacja serwerowa](evidence/flota581-20260920/walidacja.json).
- [Porównanie źródeł](evidence/flota581-20260920/zgodnosc-zrodel.json)
  i [sumy adaptera wykonawczego](evidence/flota581-20260920/adapter.json).
- Obejrzane obrazy: [desktop](evidence/flota581-20260920/zgloszenia-desktop.png),
  [potwierdzenie bez skryptów](evidence/flota581-20260920/potwierdzenie-bez-skryptow.png),
  [formularz odwołania bez skryptów](evidence/flota581-20260920/odwolanie-bez-skryptow.png).
  Dwa ostatnie pokazują przewinięty fragment formularza, nie cały ekran.

Pełne lokalne wyniki i pomocnicze skrypty pomiaru pozostały odpowiednio
w `/home/mateusz/flota/gpt-panel-moderacji-marka-evidence` oraz ignorowanym
`output/panel581-20260920`. Do repo nie kopiowano sesji, tokenów ani fixture
z poświadczeniami. Pliki JSON w dowodach są skrótem wyników, nie nowym testem.

## Granice odbioru

Nie wykonano pushowania, PR-a, CI, wdrożenia ani zmian produkcyjnych.
Zalogowane stany produkcji nie są objęte własnym pomiarem. Nie wysyłano
wiadomości do ludzi ani instytucji. Lokalna walidacja nie zastępuje
produkcyjnego oglądu brakujących rzeczywistych spraw z opisu #581.

Nie pojawiło się nowe pytanie produktowe wymagające decyzji właściciela.
Do zamknięcia pozostałego zakresu #581 nadal potrzebny jest odbiór wskazanych
stanów na produkcji, gdy będą dostępne rzeczywiste sprawy. Ten raport go nie
zastępuje i nie oznacza zgłoszenia jako zamkniętego.
