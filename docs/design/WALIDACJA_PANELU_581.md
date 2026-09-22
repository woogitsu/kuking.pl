# Walidacja panelu — odbiór w toku

Baza pracy: `13712d303eab63ff594341fe2a01479be3538538` (Alfa 0.52).
Poniższa poprawka jest lokalna, jeszcze bez PR i odbioru produkcji.

## Potwierdzony błąd odwołań

Po wysłaniu formularza bez wyniku decyzji podsumowanie wskazywało
`#f-outcome-{id}`, ale żadne radio nie miało takiego identyfikatora.
Rzeczywisty test przeglądarkowy zatrzymał się na `ERROR_LINK_FOCUS`.
Przy grupie wyboru brakowało także komunikatu błędu.

Poprawka w `resources/views/pages/admin/appeals.blade.php` nadaje pierwszemu
radio właściwe ID, wiąże oba wybory z komunikatem przez `aria-describedby`
i pokazuje błąd tylko w formularzu wskazanym przez `_wiersz`. Nie zmienia
uprawnień, decyzji ani logiki zapisu.

## Dowody lokalne

- Rzeczywiste logowanie z 2FA, CSRF i dziewięć błędnych POST: zgłoszenia,
  przywrócenie oraz odwołania. Po odpowiedzi serwera sprawdzono komunikaty,
  zachowane wartości i izolację sąsiedniego formularza.
- Osiemnaście przypadków przy 320 px, tekście 140% i obu motywach przeszło
  kontrolę odczytania końca podsumowania oraz kliknięcia linku błędnego pola.
- Pełne porównanie danych siedmiu tabel przed i po odrzuceniu formularzy
  potwierdziło brak zmian domenowych. To lokalna baza demonstracyjna
  `kuking_581_validation` na porcie 55439, bez produkcyjnych zapisów.
- PHP: `KolejkaOdwolanFormularzeNiezalezneTest` — 3 testy, 52 asercje; Pint PASS.
  Osobna baza PHP: `kuking_581_validation_tests`.
- Fizyczny negatyw usuwający ID radia powoduje porażkę nowej regresji.
  Osobny negatyw wyłączający komunikat przy polu również powoduje porażkę.
  Kopie znajdowały się poza repo; po obu próbach przywrócono MD5 i mtime,
  wyczyszczono lokalny cache widoków i otrzymano ponowny wynik dodatni.
- Niezależny przegląd Blade i testu PHP nie wykazał blokera. Test PHP
  sprawdza strukturę HTML; rzeczywisty fokus wymaga osobnego testu przeglądarki.
- Nowy moduł izolacji przyjął dwie dozwolone konfiguracje i odrzucił siedem
  nieprawidłowych, w tym produkcyjne środowisko, lokalny port 5432 i mailer SMTP.
- Nowy wrapper snapshotu oraz PHP wykonały rzeczywisty odczyt lokalnej bazy:
  4 zgłoszenia, 3 odwołania, 6 wpisów, 4 konta, 3 decyzje, 1 powiadomienie
  i 1 rekord audytu. Do raportu trafiły wyłącznie liczby; pełne rekordy
  pozostały w pamięci procesu. To nie jest jeszcze test całej integracji CI.

## Ograniczenia i pozostałe kontrole

Macierz 216 przypadków zakończyła się bez błędów funkcjonalnych. Pierwsza seria wykonała
120 poprawnych przypadków, po czym zadziałał limit moderacji 120 operacji
na 10 minut. Kolejna instrumentowana próba potwierdziła HTTP 429 oraz
`Retry-After: 486`. Nie wyłączono ograniczenia; pozostałe konfiguracje
wykonano po upływie TTL. Raport `evidence/validation581/matrix.json` obejmuje
24 kompletne konfiguracje po dziewięć przypadków, bez podwójnego zaliczania
trzech powtórzonych przypadków z przerwanej konfiguracji.

Dodatkowe 18 przypadków przy prawdziwym zoomie 200% przeszło pomiary DOM,
kliknięcia i kontrolę fokusu klawiatury. Zwykły zapis Playwright dał jednak
pusty zrzut końcowy. Nie zaliczono go jako dowodu wizualnego. Powtórzenie
z `Page.captureScreenshot`, używanym już przez testy details, zakończyło
18 przypadków wynikiem dodatnim. Obejrzano końcowy fokus radia w obu
motywach oraz kontrolny desktop 1440 px. Raport: `evidence/validation581/zoom200.json`.

Końcowy moduł przeglądarkowy przeszedł dziewięć przypadków. Trzy fizyczne
negatywy wykryły brak celu odnośnika, brak odnośników w podsumowaniu
odwołania i brak komunikatu przy polu. Po każdym przywróceniu MD5/mtime
wszystkie dziewięć przypadków ponownie przeszło. Dowody:
`evidence/validation581/browser-negatives.json` i `browser-final.json`.
Końcowy niezależny przegląd kompletności odnośników oraz raportowania
wyniku nie wykazał blokera.

Pozostają odbiór pozostałych ścieżek klawiatury oraz wykonanie integracji CI,
zwykły hook, PR, wymagane kontrole i odbiór wdrożenia. Inne rodziny
formularzy wymienione w `PANEL_STANY_ROZWINIETE_581.md` pozostają osobnym
zakresem; ten raport ich nie zalicza. Status #581 i #492: otwarte.
