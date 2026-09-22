# Poradźcie — pomiar i uzupełnienie #372

Pomiar własny: 20 września 2026. Stanowisko `gpt-pytania-poradzcie`, gałąź
`gpt/pytania-poradzcie`, baza kodu `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

## Co już działało

Na nietkniętym drzewie uruchomiono 74 testy pytań: **461 asercji, PASS**.
Obejmują publikację i edycję, zachowanie formularza i zdjęcia po walidacji,
odpowiedzi główne i zagnieżdżone, powiadomienia, QAPage, widoczność,
blokady, flagę, paginację oraz kolejkę gospodarza. To własne wykonanie testów,
a nie przepisanie liczby z wcześniejszego raportu.

Kod #372 istnieje; otwarte zgłoszenie nie oznacza braku implementacji.
Przeczytano zgłoszenie z komentarzami i #881. Nie sprawdzano flagi produkcji.

Przeczytano gałęzie wskazane w zadaniu:

- `gpt/pytania-widoki`, ostatni odczytany commit `e63ba828`: poprawki kontekstu
  powiadomień (`651604fe`) i Pomocy (`a037236f`) oraz raport z pomiarami.
  Nie kopiowano ani nie nadpisywano tych zmian — nie są potrzebne do wejścia
  do kolejki; mają trafić własną drogą przez kolejkę integracji.
- `gpt/pytania-eksport` wskazywała `534e0a51`, bez własnych commitów względem
  tej bazy. Nie badano niezacommitowanego stanu cudzego stanowiska.
- [pomiar cudzy: `gpt/pytania-widoki:docs/qa/pytania-widoki/RAPORT.md`]
  wcześniejsze wyniki powiadomień, Pomocy i retencji traktowano jako kontekst,
  nie własny odbiór. Historyczny `ODBIOR_PORADZCIE_372.md` również nie zastępuje
  wyników tej sesji.

## Uzupełnienie widoczności kolejki

Przed zmianą publiczne `/pytania` dawało filtr „Bez odpowiedzi” dopiero
w rozwijanym polu. Napis „Czeka na odpowiedź (N)” był w panelu gospodarza,
nie na stronie działu. Nowe testy przed poprawką: dwie czerwienie tego wejścia
oraz czerwień Wspomnień opisana niżej; test treści nazwy był już zielony.

Teraz przed filtrami i pytaniami stoi sekcja „Pomóż odpowiedzieć” z linkiem
**„Czeka na odpowiedź (N)”**. Liczba obejmuje cały bieżący wybór, nie jedną
stronę wyników, i respektuje widoczność osoby oraz opcjonalny tag. Link zachowuje
tag i zaczyna listę bez starego kursora. Karty bez odpowiedzi mają widoczną
akcję „Otwórz i odpowiedz”, prowadzącą do istniejącej rozmowy pod pytaniem.

Licznik używa tego samego `QuestionList` co filtr: nie ma drugiej definicji
widoczności. Koszt implementacji to jeden dodatkowy zbiorczy COUNT na ekranie
pytań; nie ma zapytania na każdą kartę ani licznika na wszystkich stronach serwisu.
Nie jest to pomiar wydajności na wielkiej bazie produkcyjnej.

**Kolejka to porządkowanie zadania pomocy, nie ranking treści.** Feed pozostaje
nietknięty i chronologiczny. Publiczna lista, także po wybraniu filtra, zachowuje
`published_at DESC, id DESC` i paginację kursorową. Istniejąca kolejka gospodarza
pokazuje najstarsze pytania najpierw — kolejność pracy człowieka, nie mechanizm
podbijania wpisów w feedzie. Nie powstają score, promocje ani automatyczne wiadomości.

Zachowane rozróżnienie D-221: publiczna lista liczy widoczne dla oglądającego
odpowiedzi główne; kolejka gospodarza czeka na główną odpowiedź **innej osoby**
widoczną dla autora pytania. Własny dopisek autora nie kończy obowiązku gospodarza.
Nie zmieniono tych reguł przy okazji wyglądu licznika.

## Flaga i Wspomnienia — #881

Odtworzono błąd na zamrożonym zegarze: własne prywatne pytanie sprzed roku
było renderowane na `/home`, mimo wyłączonego działu. Test był czerwony przed
poprawką na obecności treści pytania w prawdziwej odpowiedzi HTTP.

`Wspomnienia` korzystają teraz z istniejącego `enabledKinds()`. Nie zastąpiono
zapytania publiczną widocznością: własny prywatny zwykły wpis nadal jest
wybierany i renderowany przy obu stanach flagi. Ukrywanie pojedynczego wspomnienia
przez rzeczywistą trasę oraz wyłącznik wszystkich wspomnień nadal działają.

Nie ustanowiono asercji, że pytania MUSZĄ być wspomnieniami przy włączonym dziale.
To pozostaje decyzją produktową, zgodnie z #881. Poprawka usuwa wyłącznie
obchodzenie wyłączonej funkcji i zachowuje dotychczasowe zachowanie po jej włączeniu.

## Weryfikacja własna

- Przed poprawką: 74 testy / 461 asercji PASS; nowe próby: 3 FAIL i 1 PASS.
- Nowe testy i dotychczasowe Wspomnienia po poprawce: 18 testów / 76 asercji PASS.
- Pełny przebieg po formatowaniu: **4397 testów / 83 727 asercji PASS**, 506,82 s.
  Pominięto `ProbaOdtworzeniaTest` zgodnie z wyjątkiem floty; grupa
  `dwa-polaczenia` wyłączona domyślnie przez konfigurację projektu.
- PostgreSQL wyłącznie `127.0.0.1:55439`, rola `kuking`, baza
  `kuking_flota_gpt-pytania-poradzcie`. Runtime odświeżany po zmianach kodu.
- `vendor/bin/pint`: cztery pliki PHP. `npm run build`: PASS, w tym
  72 pary kontrastu i 20 testów JavaScript.
- Kontrole przez `scripts/kontrola-ujemna.sh`: usunięcie identyfikatora wejścia,
  usunięcie zdania wyjaśniającego z treści (meta pozostaje) i usunięcie filtra
  flagi. Wszystkie zakończyły **PASS → FAIL → PASS**, mutacja weszła, MD5 oraz
  mtime przywrócono i porównano. Dowody JSON obok raportu.
- Pierwsza próba kontroli Wspomnień miała niewłaściwy wzorzec komunikatu:
  oczekiwał formatu Artisan, a uruchomiono PHPUnit. Nie zaliczono tej próby;
  źródło przywrócono, poprawiono wzorzec i powtórzono pełną kontrolę.
  Zachowano `kontrola-wspomnien-proba1.json`. Pole `przywrocenie` w JSON
  narzędzie zapisuje przed końcowym trapem; konsola potwierdziła później oba
  porównania. Nie poprawiano ręcznie wygenerowanych wyników.
- Pierwsza wersja dodatkowej kontroli ukrywania wspomnienia używała `update`
  na polu poza `$fillable`; poprawiono próbę na rzeczywisty endpoint ukrywania.
  To wada próby, nie usterka produktu. Czerwień flagi poprzedzała tę korektę.

## Przeglądarka lokalna

Osobna kopia aplikacji `gpt-pytania-poradzcie-browser-run`, port HTTP 8937,
oddzielna baza `kuking_flota_gpt-pytania-poradzcie_browser` na tym samym porcie
PostgreSQL 55439. Przed migracją odczytano nazwę bazy, rolę, adres i port.
Żadnych fixture na produkcji. Poczta ustawiona na atrapę `array`.

Rzeczywiste kliknięcie licznika otworzyło listę dwóch pytań; akcja karty otworzyła
odpowiednią sekcję rozmowy. Po zalogowaniu lokalnej osoby testowej rzeczywisty
formularz zapisał odpowiedź. Powrót do kolejki pokazał **1 zamiast 2**, a pytanie,
na które odpowiedziano, zniknęło. Gość otrzymuje przy rozmowie drogę logowania.

Macierz `przegladarka.json`: **24 konfiguracje** — 320/360/390/414/1440 px,
jasny i ciemny motyw, skala aplikacji 100/140%; dodatkowo rzeczywisty zoom
Chromium 200% przez `chrome.tabs.setZoom`, okna 640 i 1440, oba motywy,
tekst 140%. Potwierdzono zoom=2, DPR=2 i rzeczywistą szerokość 320/720 CSS px.
Brak poziomego przepełnienia. Nowe kontrolki mają co najmniej 48 px wysokości
oraz 18 px tekstu; po przewinięciu i fokusie środek trafia w kontrolkę.
Motyw i skala były ustawiane w DOM do pomiaru CSS — nie jest to test zapisu
preferencji. Obejrzano zrzuty małego ekranu i rzeczywistego zoomu.

To nie jest pełny odbiór całej ramy serwisu ani badanie z osobami 50+.
Przypięte belki mogą zasłaniać treść w pośredniej pozycji przewijania; pomiar
potwierdza dostęp do nowych kontrolek po przewinięciu i fokusie, nie każdą pozycję
tekstu pod belkami. Nie rozszerzano tej poprawki na przebudowę nawigacji.

## Decyzje właściciela i granice

1. **Włączenie działu, beta i odbiór produkcyjny** nadal pozostają do wykonania
   według #372. Nie zmieniono flagi produkcji ani jej wartości domyślnej.
2. **Pytania jako wspomnienia przy włączonym dziale (#881):** pozostawić obecne
   przypominanie (mały koszt doprecyzowania copy i kontraktu) albo przypominać
   tylko gotowanie (mała zmiana selekcji, ale świadome wykluczenie części archiwum).
   Żaden wariant nie został tu zatwierdzony asercją.
3. **Menu z D-163:** obecne wejście do działu jest przez Odkrywaj i Dodaj.
   Starszy zapis „MENU: Poradźcie” nie określa miejsca w nowszej kompozycji D-207,
   a dolny pasek ma już pięć pozycji. Warianty: dodatkowe wejście w menu konta
   (mały koszt, nadal wymaga otwarcia menu) albo zmiana głównej nawigacji
   (większy koszt i ponowny odbiór ramy). Nie dodano szóstej pozycji.
   Strażnik nagłówka wraz z kontekstem w `<main>` został natomiast uzupełniony.

Bez zmian schematu, nowych zależności, pushowania, PR, zamykania zgłoszeń,
wdrożenia ani wiadomości do ludzi. Wynik nadaje się do szeregowej integracji
lokalnych commitów, nie jest deklaracją zamknięcia etapów uruchomienia #372.

Wycofanie: odwrócić commit kodu; nie ma migracji ani danych do odtwarzania.
Cofnięcie filtra Wspomnień przywróci opisany błąd flagi. Cofnięcie wejścia
przywróci dostęp do publicznej listy bez odpowiedzi wyłącznie przez stary filtr.

## Uzupełniające obserwacje i odtworzenie

`obserwacja-wspomnien.json` zapisuje pomiar wyboru dla dwóch rodzajów × dwóch
stanów flagi: własny prywatny zwykły wpis jest wybierany w obu stanach,
pytanie wyłącznie przy włączonej fladze. To obserwacja obecnego działania,
nie asercja zatwierdzająca produktowy kontrakt włączonych pytań.
Fixture próby powstały w transakcjach i zostały wycofane.

Przeglądarka z `javaScriptEnabled=false`, szerokość 320 px i istniejąca sesja
lokalnego konta: kliknięcie licznika przeniosło na `filtr=bez-odpowiedzi`,
a kliknięcie akcji karty otworzyło właściwe pytanie z `#komentarze` oraz
formularzem „Napisz odpowiedź”. Oba stany potwierdzono świeżym odczytem strony.
Klient CLI nie zakończył oczekiwania po nawigacji bez JS; przerwano oczekiwanie
po potwierdzeniu skutku. Nie ponawiano kliknięć i nie przypisano tej próbie
zapisu odpowiedzi bez JavaScriptu. Zapis odpowiedzi opisany wyżej odbył się z JS.

Z PowerShell, z katalogu stanowiska:

```powershell
$env:MSYS_NO_PATHCONV='1'
wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-pytania-poradzcie
wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-pytania-poradzcie --filter Question
```

Pełny przebieg wykonano przez ten sam skrypt z filtrem PHPUnit
`^(?!.*ProbaOdtworzeniaTest)` przekazanym wewnątrz skryptu Bash, aby Windows
nie zmienił znaków specjalnych. W lokalnym `output/` pozostają pomocniki,
pełny wynik `pelne-testy372.txt` i zrzuty; katalog jest ignorowany przez Git.
Do raportu dołączono dwa zrzuty z fokusem na nowym wejściu. Pliki sesji
przeglądarkowej pozostały wyłącznie w ignorowanym katalogu.

Końcowa kontrola po przywróceniu mutacji i odświeżeniu runtime:
**78 testów pytań / 496 asercji PASS**. Końcowy Pint w trybie kontroli:
**4 pliki PASS**. `git diff --check` bez błędów.
