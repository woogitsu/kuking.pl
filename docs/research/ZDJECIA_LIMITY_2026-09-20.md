# Limity i podgląd zdjęć — 20 września 2026

Zakres: #883, #884 i #891. Stan początkowy: czyste drzewo `gpt/zdjecia-limity`,
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

## Zależność i kolejka

Przed edycją przeczytano `ZDJECIA_PUBLIKACJA_2026-09-20.md` z gałęzi
`gpt/zdjecia-publikacja`. Tych zmian nie było jeszcze w podanej bazie.
Lokalny commit `b03621e7` jest cherry-pickiem `6718d527`: zachowanie zdjęć,
idempotencja i kotwice błędów z #871–#874. Nie kopiowano całej cudzej gałęzi
ani nie cofano nowszych zmian bazy. Przy przenoszeniu do kolejki najpierw
musi znaleźć się implementacja #871–#874; jeśli już jest na main, nie trzeba
ponownie przenosić `b03621e7`.

[pomiar cudzy: `gpt/zdjecia-publikacja:docs/research/ZDJECIA_PUBLIKACJA_2026-09-20.md`]
Raport poprzedniego stanowiska opisuje 4428 testów i pomiary kosztu ponowień.
Nie są to wyniki tej pracy. Własne ponowne uruchomienie jego regresji opisano niżej.

## Pomiar przed poprawką — własny

Przed zmianą kodu aplikacji uruchomiono nowe regresje na bazie PostgreSQL.
Po poprawieniu błędu w przygotowaniu fixture (fabryka przepisu nie ma metody
`published()`) uzyskano 3 oczekiwane porażki: dwa limity (1 i 6) oraz odrzucony
awatar bez wariantów; 7 kontroli przeszło. Następnie rozszerzono macierz
na osobne przypadki dla obu formularzy i walidację serwera.

Rzeczywisty Chromium wykonał istniejący handler podglądu na HTML formularza
wyrenderowanym przez kernel HTTP: wybór A/B/C dał trzy obrazy i **zero**
przycisków usuwania. Asercja oczekująca trzech przycisków oblała się przed
implementacją. Nie był to model FileList w Node ani sam odczyt kodu.

## Zmiana zachowania

- Oba formularze pokazują „Łącznie najwyżej …”, rozmiar pojedynczego pliku
  i informację, że zachowane zdjęcia wliczają się do limitu. Liczby pochodzą
  z `LimityZdjec`, a odmiana z istniejącego `Odmiana`. Pomoc pozostaje
  powiązana przez `aria-describedby`, razem z błędami poprzedniej gałęzi.
- Nowe lokalne pliki mają nazwę i przycisk „Usuń zdjęcie”. Usunięcie zmienia
  rzeczywisty `input.files`, pozostawia kolejność pozostałych plików i tekst.
  Fokus przechodzi do następnego przycisku (albo poprzedniego przy końcu),
  po ostatnim usunięciu wraca do pola wyboru. Osobny region `role=status`
  ogłasza liczbę nowych zdjęć i nadmiar liczony wraz z zachowanymi.
- Usuwanie jest włączone tylko przy dwóch oznaczonych polach. Nie usuwa
  mediów serwera i nie włącza się w kreatorze Livewire. Brak DataTransfer
  daje podgląd oraz instrukcję ponownego wyboru bez niedziałających przycisków.
  Odmowa zmiany FileList nie udaje sukcesu i pozostawia widoczny wybór.
- Object URL są zwalniane po `load` i `error`, przy zastąpieniu wyboru,
  resecie i opuszczeniu strony. Nie trzeba czekać na wczytanie usuwanego obrazu.
- Awatar bez obrazu w `rejected` proponuje ponowny wybór i zapis.
  `pending`/`processing` bez wariantu nadal oznaczają oczekiwanie;
  `rejected` z bezpiecznym podglądem nadal pokazuje zdjęcie. Brak relacji
  i `deleted` pozostają stanami bez zdjęcia.

Nie zmieniono schematu bazy, limitów konfiguracji ani polityk dostępu.
Serwer nadal egzekwuje limit niezależnie od JavaScriptu.

## Kontrole ujemne — własne

Każdą mutację potwierdzono obecnością zmienionego warunku i zmianą MD5.
Każdy przypadek synchronizowano do runtime przed pomiarem.

| Mutacja | Wynik |
|---|---|
| Usunięcie pomocy z formularza wpisu | 2 porażki renderu, pozostałe 26 przypadków przechodzi |
| Usunięcie pomocy z formularza wykonania | 2 porażki renderu, pozostałe 26 przypadków przechodzi |
| Przywrócenie dawnego warunku „status inny niż deleted” | 1 porażka dla rejected bez wariantów, 27 przypadków przechodzi |
| Usunięcie przypisania `input.files` | Chromium widzi A/B/C zamiast oczekiwanych A/C; proces kończy się kodem 1 |

Źródła przywracał `finally`; MD5 i dokładny `LastWriteTimeUtc` sprawdzono
po przywróceniu. Pierwsza próba narzędzia w Bash została zatrzymana przed
mutacją: kopiowanie z ext4 do NTFS obcinało ułamki sekundy w mtime.
Przywrócono dokładny czas z pierwszych kopii i przeprowadzono kontrole przez
PowerShell, który zachował go poprawnie. Nie używano stasha ani odtwarzania
plików z commita.

## Przeglądarka — własny pomiar i granice

Skrypt: `scripts/zdjecia-limity.mjs`. HTML pochodzi z testowych żądań Laravel
na PostgreSQL; arkusz jest z rzeczywistego buildu. Przeglądarka wykonuje
rzeczywisty fragment podglądu z `app.js`. Kontrola ujemna izolowała ten fragment; końcowy dodatkowy przebieg z
`PHOTO_FULL_BUNDLE=1` wykonał cały zbudowany `app.js` i też przeszedł.
Żądania sieciowe są przechwycone — nie jest to odbiór zalogowanej produkcji.

Dla obu formularzy sprawdzono:

- A/B/C → usunięcie B przez Enter → FormData **i multipart POST** zawierają
  tylko A/C; opis pozostaje bez zmian;
- usunięcie wszystkich, fokus na polu, ponowny wybór i reset;
- nadmiar nowych + zachowanych zdjęć, cofnięcie ostrzeżenia po usunięciu,
  zachowanie ukrytego identyfikatora;
- brak DataTransfer i odmowę zapisu FileList: bez utraty plików;
- JavaScript wyłączony: zwykły POST wysyła tekst i trzy zdjęcia;
- 320 px: brak przewijania w bok, przyciski 50,5 px, tekst 18 px;
- nazwy dostępne rozróżniają pliki; testowano klawiaturę i drzewo dostępności,
  nie odsłuch sprzętowym czytnikiem ekranu.

Dodatkowe sprawdzenia: brak usuwania przy polu bez opt-in, zwalnianie URL
uszkodzonego obrazu, wymiana przed `load`, `pagehide`, powiększenie CSS 200%
przy efektywnej szerokości 320 px. Nie jest to pomiar fizycznego telefonu,
natywnej galerii ani systemowego powiększenia przeglądarki.

## Środowisko i odtworzenie

Runtime: `/home/mateusz/flota/gpt-zdjecia-limity-run`.
Baza: `kuking_flota_gpt-zdjecia-limity`, użytkownik `kuking`,
PostgreSQL `127.0.0.1:55439`. Zależności skopiowane, bez symlinków.

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-zdjecia-limity
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- env PHOTO_BROWSER_FIXTURES=1 bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-zdjecia-limity --filter LimityIPodgladZdjecTest
# W przygotowanym runtime:
npm run build
node scripts/zdjecia-limity.mjs
```

## Ryzyka, wycofanie i decyzje

W starych przeglądarkach, które nie pozwalają zmienić FileList, pozostaje
ponowny wybór całego zestawu. Ostrzeżenie przeglądarkowe nie blokuje wysłania;
nie zastępuje serwera. Bez skryptu nie ma podglądu ani usuwania pojedynczych
nowych plików, ale publikacja działa.

Wycofanie: odwrócić własny commit implementacji, pozostawiając zależność
#871–#874. Nie cofać bazy. Przywróci to brak limitów na ekranie, brak
usuwania nowych plików i mylący komunikat odrzuconego awatara.

W tym zakresie nie pozostała decyzja produktowa właściciela. Nie wykonywano
push, PR, zdalnego CI, zmian produkcji ani wysyłki wiadomości.
## Wynik końcowy — własny

- Pełny standardowy zestaw: **4466 testów, 84166 asercji, 442,69 s**, bez porażek.
  Filtr: `^(?!.*ProbaOdtworzeniaTest)`. `ProbaOdtworzeniaTest` pominięto zgodnie
  z jawnym wyjątkiem zlecenia; grupa `dwa-polaczenia` pozostaje wyłączona
  zgodnie z domyślnym `phpunit.xml`. Nie deklarujemy wykonania tej grupy.
- Zestaw celowany obejmujący także regresje #871–#874: **80 testów,
  520 asercji**, bez porażek.
- `vendor/bin/pint` wykonany na zmienionych plikach PHP; końcowy
  `vendor/bin/pint --test`: **1159 plików, PASS**.
- PHPStan całego projektu: **bez błędów**. `npm run build`: **PASS**.
- Końcowa próba Chromium po kontrolach ujemnych: **PASS**, wraz z pustym
  wyborem, ponownym wyborem, resetem, oboma wariantami awaryjnymi, POST bez
  JavaScriptu, cyklem życia URL i powiększeniem CSS.

Dowody: [wyniki kontroli](zdjecia-limity-dowody/kontrole.txt),
[wyniki Chromium](zdjecia-limity-dowody/zdjecia-limity.json),
[podgląd wpisu przy 320 px](zdjecia-limity-dowody/post-wybor.png),
[podgląd wykonania przy 320 px](zdjecia-limity-dowody/cooked-wybor.png).
Zrzuty pokazują syntetyczne zdjęcia testowe, nie treści użytkowników.