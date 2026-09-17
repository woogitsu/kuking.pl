# Wybór zeszytu przy zapisie — #644

Stan: robocza Alfa 0.56, niewdrożona. Podstawa: `b83d7c070b9725f117464d0ec75241e10c6cfb8d`.

## Problem i zakres

Osoba mogła założyć własny zeszyt, ale przy przepisie i wpisie nie miała
formularza wyboru miejsca zapisu. Szybki zapis trafiał do „Zapisanych”.
Nowy rozwijany formularz pozwala wybrać własny zeszyt również wtedy, gdy
treść została już zapisana. Szybki zapis pozostaje dostępny osobno.

Lista pobierana jest raz na żądanie, wyłącznie dla bieżącej osoby. Formularz
korzysta z istniejącej akcji POST, CSRF, walidacji i kontroli właściciela.
Nie dodano migracji ani publicznego API. Nie zmieniono zasad widoczności
treści i zeszytów.

## Sprawdzone lokalnie

- Test odtwarzający brak wyboru przed implementacją: 4 porażki, 22 asercje.
- Końcowe testy funkcji: 7 testów, 102 asercje. Zapis przepisu i wpisu,
  zapis już odłożonej treści, idempotencja, izolacja błędów między kartami,
  usunięcie ostatniego zeszytu przed wysłaniem oraz izolacja list między
  osobami i żądaniami.
- Szersza regresja zeszytów i potwierdzeń: 102 testy, 717 asercji.
- Pint: trzy zmienione pliki PHP przechodzą po korekcie importów testu.
- Kontrola ujemna pomocnika szybkiego zapisu: w prawdziwym Blade dodano
  błędny przycisk „Zapisuję” również do zapisanej karty. Test upadł dokładnie
  na asercji zakazującej tego przycisku. Przywrócono MD5
  `c633e0bbd3ad63c118318b6b8bcefc92` i mtime; 4 testy / 19 asercji przeszły.
  Kopia: `/home/mateusz/kuking644-quick-aoeupi_y/post-card.blade.php`.
- Trzy fizyczne kontrole ujemne rzeczywistego Blade wykryte: uszkodzona
  nazwa pola, cel odnośnika błędu i przypisanie błędu wszystkim kartom.
  Przywrócono MD5 `d970ba5426c5517bd68ff7aafd6ec3b3` i mtime; przebieg
  dodatni ponownie przeszedł. Kopia poza repo:
  `/home/mateusz/kuking644-negative-l9tqqmlb/component.blade.php`.
- Rzeczywisty lokalny formularz: zapis przepisu i wpisu do własnego zeszytu,
  potwierdzenie nazwy oraz odczyt obu treści z zeszytu.
- Klawiatura: rozwinięcie, Tab do radia, strzałka zmienia wybór, Tab do
  przycisku, Enter zapisuje i pokazuje właściwe potwierdzenie.
- 320 px, ciemny motyw, tekst 140%: długa nazwa zawija się bez obcięcia;
  przycisk po Tab ma widoczny obrys i nie jest zasłonięty nawigacją.
  Zapis do zeszytu o długiej nazwie rzeczywiście wykonano.
- Formularz przepisu: 24 pomiary (320, 360, 390, 414, 768, 1440 px × oba
  motywy × tekst 100/140%). Żaden nie wykazał poziomego przepełnienia strony
  ani etykiet zeszytów. Zmiany motywu i skali wykonano przez rzeczywiste
  ustawienia konta testowego. Obejrzano też szeroki jasny układ przy 140%.
- Osobno karta wpisu: te same 24 konfiguracje, bez poziomego przepełnienia
  strony lub etykiet. Obejrzano szeroki ciemny układ przy 100%.
- Końcowy niezależny przegląd poprawki pustej listy i izolacji pamięci
  żądania: bez pozostałych blokerów funkcjonalnych. Review nie zastępuje
  nieukończonych pomiarów przeglądarkowych.

Pierwszy wariant używał selecta. Ogląd przy 320 px i 140% wykazał obcinanie
nazwy, dlatego zastąpiono go natywnymi radiami z zawijanymi etykietami.
Niezależny review wykrył też pustą listę po usunięciu ostatniego zeszytu:
odnośnik błędu nie miał celu. Poprawiono widoczny, fokusowalny cel i dodano
regresję POST → przekierowanie → render.

## Pozostałe warunki dostarczenia

Próba uruchomienia zoomu skrótem Ctrl+plus w dostępnej przeglądarce aplikacji
nie zmieniła powiększenia: przed i po `innerWidth=1280`, `innerHeight=720`,
`devicePixelRatio=1`. Nie zaliczono jej jako testu 200%. Zmiana szerokości
ani skali tekstu nie zastępuje tego warunku.

- Rzeczywisty zoom 200%.
- Pełny hook, zwykły push, PR i wymagane CI.
- Normalny merge, potwierdzenie wdrożenia i odbiór produkcyjny.

Nie jest to dowód ukończenia całego portu marki ani odbioru produkcji.
Pomiary 48 konfiguracji zapisano w `evidence/zeszyt644/geometria.json`.
Zrzuty `wpis-ciemny-140.png` i `wpis-320-ciemny-140-fokus.png` pochodzą
z rzeczywistego lokalnego widoku. Drugi obejrzano: cały obrys przycisku
zapisu pozostaje nad dolną nawigacją. Dodano również pełne zrzuty przepisu
`przepis-ciemny-140.png` i `przepis-jasny-140.png`; jasny układ obejrzano.
Testy korzystały z odrębnej bazy `kuking_644_tests`, odbiór
przeglądarkowy z `kuking_492_zeszyt_success`, wyłącznie port 55439.
