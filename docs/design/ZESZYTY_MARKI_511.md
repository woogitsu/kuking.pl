# Zeszyty zgodne z kompozycją marki — #511

Stan: lokalne kontrole zakończone; przed CI i wdrożeniem.
Baza: `d17bfd3bed824967cbfe05a6c582f1a2577ac14a`, Alfa 0.18.
Zmiana: Alfa 0.19, decyzja D-211, konstytucja 1.7.

## Potwierdzony brak

Porównano oryginalny HTML z archiwum D-209, produkcyjny `/zeszyt`
Alfa 0.17 oraz lokalny szczegół zeszytu. Produkcja miała jasną kartę
folderu i ostatnie zapisy w bocznej szynie. Lokalny szczegół pokazywał
małą fotografię obok tytułu. Oryginał ma ciemne foldery, zapisy w głównej
kolumnie oraz zdjęcia nad tytułami. Prywatnych danych produkcyjnych
nie umieszczono w raporcie ani zrzutach repozytorium.

## Implementacja i granice

- `pages/collections/index.blade.php`: ciemne karty, ostatnie zapisy pod
  folderami, zachowany formularz i poprawiona instrukcja pustego stanu.
- `pages/collections/show.blade.php` i `components/recipe-card.blade.php`:
  osobny wariant kafla przepisu; pozostałe użycia zachowują układ wiersza.
  Kafel ma jeden krótki odnośnik „Zobacz przepis” z pełnym tytułem w nazwie
  dostępnej. Pseudo-element rozszerza kliknięcie na zdjęcie i treść.
- `components/szyna-ostatnio-zapisane.blade.php`: jeden krótki link
  „Zobacz”, rozszerzony przez CSS na kartę. Pełna nazwa
  pozostaje w nagłówku i nazwie dostępnej odnośnika. Brak zdjęcia nie
  pozostawia pustej kolumny, a kafel bez fotografii nie rozciąga pustej
  powierzchni do wysokości sąsiedniego długiego przepisu.
- `resources/css/marka-zeszyt.css`: układ zależny od dostępnego miejsca,
  pełne tytuły i czytelne zdjęcia. Brak globalnego ukrywania overflow.
- `components/szyna-linki.blade.php`: opcjonalna krótka akcja użyta dla
  zeszytów na profilu i w szczególe folderu. Pozostałe listy skrótów
  zachowują dotychczasową strukturę. Nazwa i opis zeszytu pozostają pełne.

Kontrolery, polityki i dane pozostają bez zmian. Brak zdjęcia nie dostaje
fikcyjnej fotografii. Zachowano odrębne paginatory wpisów i przepisów,
liczbę niedostępnych zapisów oraz filtrowane odnośniki innych zeszytów.

## Macierz odbioru

| Ekran / stan | Wygląd i tekst | Mobile / zoom | Dowód |
|---|---|---|---|
| Lista zeszytów i ostatnie zapisy | Ciemne foldery, zapisy w głównej kolumnie | 6 szerokości, 2 motywy, 4 skale pisma | Przeglądarka i PHP |
| Pusty indeks | Poprawiona instrukcja | Oczekuje | PHP dodatnie |
| Zeszyt: fotografia, brak zdjęcia, pełny długi tytuł | Kafle z pełnymi tytułami i krótką akcją | 6 szerokości, 2 motywy, 4 skale pisma | Przeglądarka i PHP |
| Skróty zeszytów na profilach i w szczególe | Pełne nazwy i opisy, krótki link | Rzeczywisty zoom 200%, fokus widoczny | Test klawiatury i ujemna kontrola Blade |
| Prywatność, wpisy, paginacja i liczba zapytań | Zachowane kontrakty | Nie dotyczy pomiaru PHP | 41 testów, 241 asercji |

## Testy i wdrożenie

Pierwszy pomiar klawiatury wykrył zasłonięcie końcówki długiego linku
tytułu przy 320 px i tekście 140%: 12 wierszy, około 522 px wysokości.
Pełny tytuł pozostaje widoczny, ale nie jest już wysokim obszarem fokusu;
fokus trafia na krótką akcję. Nie zmniejszono tekstu ani nie osłabiono
pomiaru widoczności. Regresję PHP powtórzono po tej korekcie.

Kolejny pomiar wykrył dolną krawędź fokusu ostatniego zapisu poza ekranem
przy 1440 px. Ostatnie zapisy również otrzymały krótką akcję. Poszerzony
zestaw danych ujawnił następnie zasłonięcie obrysu linku zeszytu na profilu
przez dolną nawigację przy rzeczywistym zoomie: nazwa wraz z opisem miała
około 289 px wysokości. Opcjonalny wariant krótkiej akcji rozwiązuje ten
sam problem w obu miejscach prezentacji skrótów zeszytów.

Lokalny PostgreSQL, 41 testów PHP / 241 asercji: sukces (4,34 s).
Pint trzech zmienionych plików testów: sukces.
Pełne lokalne `scripts/check.sh --szybko`: kod wyjścia 0. Przeszły
pełny zestaw PHP, Pint, PHPStan, kontrola składni PHP i powłoki, testy
skryptów oraz odwracalność migracji. Ten przebieg nie uruchamia buildu,
przeglądarki ani opcjonalnej grupy wyścigów; nie przypisujemy mu tych wyników.
Wykonano cztery kontrole ujemne rzeczywistych widoków: przeniesienie
ostatnich zapisów do szyny, duplikacja sekcji, zastąpienie linku tekstem
oraz skrócenie tytułu. Każda zakończyła się właściwym błędem asercji,
a nie błędem kompilacji. Po każdym przywróceniu przeszły 4 testy / 65 asercji.
Kopie poza repo, `cp -p`, identyczny MD5 i czas modyfikacji przed i po:

| Źródło | MD5 przed i po |
|---|---|
| `pages/collections/index.blade.php` | `0b6016f910e06b2c69bb1d6ee9adfba6` |
| `components/szyna-ostatnio-zapisane.blade.php` | `eee1a6e7579a8423dd8565b8af708b5b` |
| `components/recipe-card.blade.php` | `6c22c9152a2aa826f4bc417dca463156` |

Przeglądarka: 96 konfiguracji listy i szczegółu oraz pięć rzeczywistych
negatywów CSS zakończyły się sukcesem. Pomiar obejmuje 320, 360, 390,
414, 768 i 1440 px, oba motywy, tekst 100% i 140%, podwojoną czcionkę
przeglądarki oraz jej połączenie z tekstem 140%. To ostatnie nie jest
rzeczywistym zoomem: osobny pomiar potwierdził 64 warianty zoomu 200%
i sześć istniejących kontroli ujemnych fokusu.
Cały `scripts/port-projektu.mjs` zakończył się kodem 0 na końcowych
źródłach: także 432 warianty kompozycji #509 i wcześniejsze kontrole
landingu, nawigacji, tablicy oraz szerokości kolumn pozostały zielone.
Rzeczywisty zoom potwierdzały `chrome.tabs.getZoom=2`, DPR=2 i połowa
szerokości viewportu. Obejrzano zrzuty listy i szczegółu z końcowego
przebiegu; dane pomiarowe są wyłącznie lokalne.

Dodatkowe przywrócenie starego wysokiego linku w rzeczywistym
`szyna-profilu.blade.php` wywołało `ZOOM_FOCUS_OCCLUDED`. Po przywróceniu
MD5 `82a6799406403fc54c2d30ea2f62becc` i mtime przeszło osiem wariantów
profilu. Pięć negatywów CSS powtórzono w pełnym przebiegu na końcowym
MD5 `35b47482c05ab1dfa5e209fa579c3276`: jasne foldery, jedna kolumna
przepisów, ucięty tytuł oraz niedziałające kliknięcia zdjęć w obu rodzajach
kart. Wszystkie wykryto właściwym kodem błędu. Po każdej próbie
przywrócono MD5 i mtime, przebudowano CSS i sprawdzono dodatnio oba motywy.

Pozostałe wykonane kontrole lokalne:

- `kafel-dodawania-bramka.test.mjs` i `kafel-dodawania.mjs`: sukces,
  zero naruszeń we wszystkich 15 wariantach kafla.
- `fokus-karty-dania.mjs`: sukces, zachowane rzeczywiste kliknięcia,
  kompletność przejścia Tab i trzy kontrole ujemne CSS.
- `dostepnosc.mjs`: axe 44/44 ekranów, układ 49/49, zero naruszeń,
  poziomych przepełnień i zasłoniętego fokusu.
- `wydajnosc.mjs`: osiem z ośmiu ekranów powyżej progów; wydajność
  91–98, SEO 100 na stronach indeksowanych. Logowanie ma celowy noindex.
  Pierwsze uruchomienie nie znalazło binarki Chromium; po podaniu
  właściwej zmiennej `CHROME_PATH` rzeczywisty pomiar przeszedł.

Ponowienie pełnego PHP przed wysłaniem wykryło zapis konkretnego sluga
przepisu w raporcie #509, którego test tras dokumentacji nie rozpoznaje.
Zastąpiono go pełnym, zweryfikowanym odnośnikiem produkcyjnym. Nie dodano
wyjątku w teście ani nie osłabiono kontroli odnośników.

CI, PR oraz wdrożenie są jeszcze niepotwierdzone.
Tego dokumentu nie należy traktować jako odbioru
całej marki ani dowodu wersji produkcyjnej.
