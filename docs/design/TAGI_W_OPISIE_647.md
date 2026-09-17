# Tagi w opisie wpisu — #647

Stan 17.09.2026: **lokalna implementacja, odbiór w toku**. Bez PR i wdrożenia.
Zintegrowano main b988139 po lokalnym zapisie WIP de82796 (merge 6f0de3d).
Na połączonych źródłach, z roboczą wersją Alfa 0.57, ponowiono build,
PHPStan, testy tagów i wyboru zeszytu oraz poniższy odbiór przeglądarkowy.

## Zachowanie

Wpisanie `#` w opisie uruchamia podpowiedzi istniejących tagów z liczbą
publicznych wpisów dostępnych widzowi. Wyszukiwanie wysyła wyłącznie aktywną
frazę, nie cały opis. Wybór podpowiedzi wstawia token w miejscu kursora.
Publikacja rozpoznaje także hashtagi wpisane lub wklejone bez wyboru sugestii.
Dotychczasowy formularz ręcznych tagów pozostaje dostępny.

Serwer rozstrzyga końcowy zbiór tagów i limit pięciu różnych kanonicznych tagów.
Istniejący slug może mieć 40 znaków; limit nowej nazwy pozostaje 30.
Fragment adresu URL nie staje się tagiem. Alias lub slug istniejącego taga
nie tworzy nowego duplikatu.

`post_tags.dodany_recznie` rozróżnia ręczny wybór od powiązania wyłącznie
z opisu. Usunięcie hashtagu nie usuwa ręcznie dodanego taga. Migracja przypisuje
starym relacjom ręczne pochodzenie. Scalanie zachowuje ręczne pochodzenie, jeśli
miała je którakolwiek relacja. Cofnięcie migracji odmawia utraty tego rozróżnienia,
jeżeli istnieją relacje wyłącznie z opisu, również dla miękko usuniętych wpisów.

Ukryte tagi nie są proponowane ani pokazywane jako odnośniki pod wpisem.
Istniejące powiązania mogą zostać zachowane podczas edycji. Treść autora
nie jest przepisywana. Stare dane formularza ręcznych tagów są przypisane
do konkretnego edytowanego wpisu; błąd innego formularza nie zeruje jego tagów.

## Wykonane kontrole lokalne

- 92 testy / 1747 asercji: publikacja i edycja, schemat, scalanie, podpowiedzi,
  parser, pochodzenie relacji, migracja i eksport. Wszystkie przeszły po poprawkach.
- 2 testy / 7 asercji istniejącej regresji wyścigu tworzenia taga: wstrzyknięcie
  konkurencyjnego INSERT. To nie jest pomiar dwóch niezależnych procesów.
- Dodatkowy pomiar dwóch i pięciu podpowiedzi potwierdza stałą liczbę zapytań
  oraz poprawne liczniki. Po jego dodaniu plik endpointu: 7 testów / 62 asercje,
  wszystkie przeszły.
- Build Vite, 72 pary kontrastu i 7 testów JS (3 tagów, 4 PWA): przeszły.
- PHPStan bez błędów na wcześniejszym snapshotcie; powtórzyć na końcowym źródle.
- Niezależny przegląd domeny oraz poprawki markera i ukrytych odnośników:
  bez pozostałego blokera w odczytanym zakresie. Nie zastępuje testów przeglądarkowych.

Fizyczne kontrole ujemne rzeczywistych źródeł:

1. Błędny pierwszy wybór ArrowUp i przyjęcie tokenu z podkreśleniem — obie
   mutacje JS wykryte. Przywrócono MD5 `3cea1a78363ca598e26fbe2d23c14124`
   i czas modyfikacji; dodatni przebieg 3/3.
2. Zamiana ręcznego pochodzenia na wyłącznie opisowe w resolverze runtime —
   wykryta; przywrócono MD5 `a0228ce05e13c8cf83fad42952901740` i czas modyfikacji.
   Dodatni przebieg 19 testów / 88 asercji.

Kopie kontrolne przechowywano poza repozytorium. Lokalne logi i wyniki są
w katalogu `output` kanonicznego repo: `negative647-js-result.json`,
`negative647-origin-result.json`, `negative647-origin.log`, `positive647-origin.log`.

## Do zakończenia

### Powtórka po integracji main

- 100 testów PHP / 1859 asercji: tagi i wybór zeszytu — wszystkie przeszły.
- PHPStan bez błędów; build, 72 pary kontrastu i 7 testów JS przeszły.
- 24/24 konfiguracje: 320/360/390/414/768/1440, oba motywy, tekst 100/140%.
- 4/4 rzeczywisty zoom 200%: `chrome.tabs.getZoom() = 2`, DPR 2,
  szerokość CSS 320, oba motywy i obie skale tekstu.
- Rzeczywiste kliknięcie, ArrowDown/Enter, publikacja i GET wpisu, edycja
  z usunięciem hashtagu oraz GET bez jego odnośnika — przeszły.
- Wklejenie przez Control+V, Escape, zastąpienie tokenu w środku tekstu,
  wybór nowego taga bez ukrytego ręcznego pola i błąd transportu — przeszły.

Dowody lokalne powtórki: `output/tagi647-browser/integrated/` w repo kanonicznym.
To Chromium na lokalnych danych; nie fizyczny telefon ani odbiór produkcji.
Wybrane wyniki i zrzuty zachowano w `docs/design/evidence/tagi647/`.
Przy wyłączonym JavaScript rzeczywista publikacja hashtagu oraz usunięcie go
przy edycji również przeszły. Osiem uwag Pint poprawiono i sprawdzono ponownie.

Wcześniejszy odbiór przeglądarkowy na snapshotcie sprzed integracji main: przejście
wybór taga → publikacja → odczyt → edycja z usunięciem hashtagu działa lokalnie.
Zebrano 16 konfiguracji (320/390/768/1440, dwa motywy, tekst 100/140%).
Sprawdzono również Escape, zastąpienie tokenu w środku tekstu i brak propozycji
tworzenia nowego taga po błędzie transportu. Nie jest to jeszcze pełny odbiór:
uzupełnienie szerokości 360/414 i rzeczywistego zoomu 200% zapisano osobno.

- Odbiór rzeczywistych podpowiedzi, publikacji i edycji w przeglądarce,
  obu motywów, szerokości mobilnych i desktopowych, powiększenia oraz błędów sieci.
- Pomiar zapytań i adekwatne kontrole współbieżności nowych blokad/scalania.
- Końcowy pełny hook na dokładnym commicie i review kompletnego pakietu.
- Zwykły push, PR i wymagane CI. Wersja Alfa 0.57 i changelog są przygotowane.
- Wdrożenie oraz potwierdzenie rzeczywistego produkcyjnego SHA.
