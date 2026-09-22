# Obserwowanie tagów — naprawy i pomiar listy

Data: 20 września 2026. Stan początkowy: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`, gałąź `gpt/tagi`.

## Pochodzenie dowodów

Przejąłem za zgodą właściciela niezacommitowane zmiany w dwóch kontrolerach,
widoku ustawień i dwóch nowych testach. Przepisałem niepełne zabezpieczenia
i rozszerzyłem testy. Wszystkie wyniki poniżej uruchomiłem sam; nie przyjmuję
cudzego zielonego wyniku jako dowodu. Czerwony stan zmierzyłem na trzech
oryginalnych plikach produkcyjnych wyeksportowanych przez `git archive` z SHA
powyżej do izolowanego runtime, bez cofania plików worktree.

Źródłowe logi lokalne: `C:\Users\matma\Documents\kuking-flota\_tagi-dowody`.
Mały zestaw dowodów do przenoszenia z gałęzią: katalog
[`tagi-2026-09-20`](tagi-2026-09-20/).

## Wynik zgłoszeń

| Issue | Pomiar przed poprawką | Zmiana i potwierdzenie |
|---|---|---|
| #854 | Stary formularz usuwał nowe obserwowanie; mógł też przywrócić nowsze odznaczenie. | Zaszyfrowany, uwierzytelniony stan otwarcia formularza, związany z kontem. Zapisuje wyłącznie różnicę względem tego stanu. Testy obu kierunków, pustego wyboru, podmiany tokenu i obcego konta; także dwie karty prawdziwej przeglądarki. |
| #856 | Awaria przy usuwaniu pozostawiała częściowo zapisane relacje. | Jedna transakcja akcji domenowej. Test bez transakcji otaczającej fixture: kontrolowany wyjątek po wykonaniu DELETE, ponowne połączenie i odczyt zatwierdzonego stanu A; po udanej próbie wyłącznie B. |
| #857 | Powtórzenie obserwowania przepisywało `created_at`. | `insertOrIgnore` zachowuje datę istniejącej relacji, nadaje ją nowej. Sprawdzone dla akcji pojedynczej, ustawień i onboardingu. |
| #853 | Formularz otwarty przed ukryciem/scaleniem mógł dopisać niedostępny tag. | Ponowne sprawdzenie statusu wewnątrz transakcji, blokady wierszy i istniejąca blokada mutacji tagów. Odmowa całej zmiany z instrukcją po polsku. Nadal można usunąć już obserwowany, ukryty tag. Test wykorzystuje rzeczywistą akcję scalania. |
| #855 | Po odmowie nowy wybór znikał; brakowało wyjaśnienia przy grupie. | Odtworzenie wysłanych zaznaczeń i odznaczeń, także pustej listy; zachowanie pierwotnego punktu odniesienia po błędzie. Podsumowanie, odnośnik do grupy, komunikat i powiązanie ARIA. Sprawdzone po rzeczywistym przekierowaniu z ciasteczkiem sesji oraz w przeglądarce. |
| #859 | Kod wybierał jedno źródło: obserwowani → tagi → publiczne. Opis sugerował inne działanie. | Poprawiony opis, bez zmiany feedu. Test przechodzi przez wszystkie trzy źródła i sprawdza treści listy obserwowanych. |
| #861 | Błędny UUID powodował PostgreSQL `22P02`/HTTP 500, przekroczenie limitu nie odcinało dalszej walidacji. | Kolejne fazy: typ tablicy/elementów, normalizacja i deduplikacja, liczba unikalnych wyborów, UUID, jedno zbiorcze `exists`. Zero zapytań `exists` dla złego formatu i przekroczonej liczby; jedno dla 50 poprawnych UUID. Puste wejście i 500 powtórzeń jednego UUID działają. |
| #858 | Potrzeba wyszukiwania na długiej liście wymaga pomiaru produktu. | Wyłącznie pomiary i warianty decyzji poniżej. Nie dodano filtra ani asercji wymagającej nowej funkcji. |

Limit w ustawieniach wynika z liczby opcji rzeczywiście pokazanych w danym
formularzu. Nie ma nowego limitu 250 obserwowań konta; test przechodzi dla 251.
Onboarding zachowuje dotychczasowy limit 50 unikalnych wyborów i wybiera tylko
tagi promowane. Sprawdzenie istnienia UUID nie zastępuje sprawdzenia aktywności
tagu w akcji domenowej.

Stan formularza zawiera identyfikatory pokazanych tagów i daty istniejących
relacji. Nie ma dodatkowej tabeli, zależności ani migracji. Brak/uszkodzenie
tokenu wymaga przejrzenia odświeżonego formularza i ponownego zapisu.

## Kontrole regresji i ograniczenia

- Oryginalny kod z nowymi testami: **14 porażek, 4 przejścia**. Część testów
  obejmuje nowy kontrakt tokenu; osobno zmierzono pierwotne usterki stanu,
  daty, walidacji i atomowości. Log `baseline-atomowosc.txt` pokazuje awarię
  po zatwierdzonym zapisie, bez maskującej transakcji testowej.
- Siedem kontroli ujemnych: #854, #856, #857, #853, #855, #859, #861.
  Każda: zielony test → celowe uszkodzenie → czerwień z oczekiwaną przyczyną
  → przywrócenie → zielony test. Narzędzie porównało MD5 i mtime źródła.
  W JSON narzędzia pole `przywrocenie` pozostaje „nie wykonane”, ponieważ
  JSON powstaje przed końcowym trapem. Faktyczne przywrócenie potwierdza
  odpowiadający plik TXT; nie traktuję tego pola JSON jako dowodu.
  Dla mutacji Blade czyszczono skompilowane widoki między etapami.
- Testy ukierunkowane: **42 przejścia, 210 asercji**.
- Pełny uruchomiony zestaw: **4401 przejść, 83 625 asercji, 379,47 s**.
  Jawnie pominięto **`ProbaOdtworzeniaTest`**, zgodnie z wyjątkiem floty:
  używa współdzielonej bazy próby odtworzenia. Nie ma zgłoszonej porażki
  uznanej bez odtworzenia za „zastaną”.
- Pint: **1161 plików, PASS**. PHPStan: **bez błędów**. Budowanie assetów:
  **PASS**, wraz z **20 testami JavaScript**. `git diff --check`: bez uwag.
- Przeglądarka: zapis, zachowanie nowego obserwowania z drugiej karty,
  odmowa błędnego UUID, zachowane zaznaczenie/odznaczenie i udana ponowna próba.
  Zrzut błędu przy 320 px obejrzany wizualnie; podsumowanie otrzymało fokus.
- Nie uruchomiono dwóch równoczesnych procesów scalania i zapisu. Test
  „scalono po otwarciu formularza” jest sekwencyjny. Blokady opisują mechanizm
  kodu, a nie pomiar rzeczywistego wyścigu dwóch procesów.
- `created_at` ma dokładność sekundy. Warunkowe usuwanie chroni relację
  wznowioną z inną datą, ale **nie stanowi wersjonowania** ponownego
  odznaczenia/zaznaczenia tego samego tagu w tej samej sekundzie.
- Nie wykonano pełnego audytu WCAG ani badań z uczestnikami. Pomiary układu
  i odtworzenie błędu nie są dowodem pełnej dostępności.

## #858 — pomiar i decyzja właściciela

Fixture: 10, 40 i 100 syntetycznych tagów `Temat kulinarny NNN`, co trzeci
obserwowany. Brak cudzych danych i zapisów produkcyjnych. Pomiar bazowego
HTML przed poprawką: odpowiednio 27,8 / 36,9 / 54,9 KB i pojedyncze czasy
16,64 / 18,51 / 17,77 ms. To próby lokalne, nie benchmark ani statystyka
czasu odpowiedzi produkcji. Token formularza zwiększa HTML po poprawce
(w próbie ukierunkowanej 29,7 / 42,9 / 69,4 KB).

Poniższy układ zmierzono **po poprawkach** w Chromium, na własnej instancji:

| Liczba tagów | Wysokość listy przy 320 px | Przy 320 px i tekście 200% | Przy 1440 px, tekst zwykły |
|---|---:|---:|---:|
| 10 | 827 px | 2730 px | 435 px, 3 kolumny |
| 40 | 3344 px | 10 992 px | 1553 px, 3 kolumny |
| 100 | 8377 px | 27 515 px | 3789 px, 3 kolumny |

Macierz 30 przypadków: szerokości 320/360/390/414/1440 × tekst zwykły/200%
× trzy liczby tagów. Bez poziomego przepełnienia; podstawowy tekst 18/36 px;
cele wyboru i przycisk zapisu co najmniej 48 px wysokości. Dodatkowo prawdziwy
zoom przeglądarki `chrome.tabs.setZoom(2)`, potwierdzony przez `getZoom`:
8 kombinacji szerokości okna 640/1440, ustawienia tekstu serwisu 100/140%
i obu motywów. Efektywna szerokość 320/720 px, bez poziomego przepełnienia.
Zoom przeglądarki i zmiana rozmiaru czcionki to osobne pomiary.

Wniosek ograniczony: lista 100 tagów wymaga długiego przewijania. Nie
zmierzyłem, czy ludzie mają trudność ze znalezieniem konkretnego tematu,
ile tagów rzeczywiście widzą konta produkcyjne ani jak często wracają do
tego ekranu. Nie ma podstaw do ogłoszenia wybranego filtra rozwiązaniem.

| Wariant do decyzji | Koszt i konsekwencje |
|---|---|
| Zostawić listę, najpierw badanie | Brak nowego interfejsu; trzeba zorganizować 5–8 prób z osobami z grupy docelowej i zmierzyć odnalezienie, zmianę oraz zapis tagu na listach różnej długości. |
| Prosty filtr tekstowy | Jedno dodatkowe pole z widoczną etykietą i komunikatem o braku wyników; implementacja oraz testy zachowania ukrytych zaznaczeń, walidacji i klawiatury. Cały wybór musi być nadal wysyłany. |
| „Tylko obserwowane” | Dodatkowy stan i przełącznik; ułatwia odznaczanie, nie wyszukiwanie nowych tematów. Potrzebuje osobnego sprawdzenia zrozumiałości i powrotu do całej listy. |

Rekomendacja: najpierw ustalić rzeczywisty rozkład długości list i wykonać
krótkie badanie. Nie wysyłano zaproszeń, wiadomości ani żądań do produkcji.

## Odtworzenie i przekazanie

Runtime: `/home/mateusz/flota/gpt-tagi-run`; baza testów
`kuking_flota_gpt-tagi`, właściciel `kuking`, **127.0.0.1:55439**.
W skryptach floty użyto nazwy `gpt-tagi`, odpowiadającej rzeczywistemu
katalogowi, zamiast nieistniejącego stanowiska `tagi` z przykładu polecenia.
Przed testami przygotowano runtime z aktualnego worktree; żadnych testów na SQLite.

```bash
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-tagi
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-tagi --filter TagiObserwowanieIntegralnoscTest
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-tagi --filter TagiAtomowyZapisTest
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-tagi --filter '/^(?!.*ProbaOdtworzeniaTest)/'
```

Polecenia są dla Bash w WSL; przy wywołaniu WSL z Git Bash obowiązuje
`MSYS_NO_PATHCONV=1`. Z PowerShell nie zachodzi konwersja ścieżek MSYS.
Fixture przeglądarkowa `scripts/fixtures/obserwowanie-tagow.php` przyjmuje
10/40/100 i odmawia poza `local/testing` oraz osobną bazą
`kuking_flota_gpt_tagi_a11y` na tym samym hoście i porcie. Wymaga wcześniej
zmigrowanej własnej bazy i zbudowanych assetów. Nie uruchamiać testów
niszczących dane na bazie aktualnie oglądanej w przeglądarce.

Nie wykonano `scripts/check.sh` jako jednego polecenia: skrypt zawiera próby
domyślnego PostgreSQL i pełny test odtworzenia; w tej flocie zastosowano
jawne parametry oraz powyższe kontrole osobno. Nie deklaruję zaliczenia
nieuruchomionych kontroli CI. Bez push, PR, wdrożenia i zmian produkcyjnych.

Wycofanie: odwrócić lokalne commity zmiany kodu i dokumentacji. Brak zmian
schematu i brak operacji na danych do cofnięcia. Powrót do starego kodu
przywraca opisane usterki zapisu; nie jest neutralnym bezpieczeństwowo
rollbackiem. Przed przekazaniem do kolejki integracyjnej potrzebne są jej
standardowe kontrole i CI, bez równoległego pushowania z tego stanowiska.
