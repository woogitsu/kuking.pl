# Odbiór paczki P — 3 października 2026

Koordynator przejrzał osiem lokalnych poprawek istniejących issues.
P bazuje na O i zachowuje oba podziały dokumentacji oraz naprawę kotwicy.
Nie jest jeszcze wydana ani uznana za pełną zieleń CI.

## Zakres i dowody agentów

- **#2806**, `9cd7acd5a` i `ac0b8360e`: powrót do nazwanej listy po błędzie
  dodawania zakupów do spiżarni zachowuje poprawione nazwy i zaznaczenia.
  Świeży wybór listy i przynależność pozycji sprawdzane pod blokadą konta.
  Lista usunięta po wstępnym SELECT kieruje bezpośrednio na istniejący
  ekran; nie przenosi danych na listę domyślną. Własny PG18: 18 / 116
  PASS. Dwie fizyczne mutacje oblały właściwymi markerami, dokładny restore.
  Przeplot usunięcia listy jest deterministyczną iniekcją na prawdziwym
  PostgreSQL w jednym połączeniu, nie dowodem protokołu Dwa. Niezależny ACCEPT.
- **#2842**, `002b2b030`: błąd filtra „Bez składnika” trafia do osobnego
  podsumowania razem z błędem frazy, bez zatrzymania poprawnego wyszukiwania.
  Link prowadzi do istniejącego `f-bez-skladnika`. Własny PG18/ICU:
  19 / 72 PASS; fizyczne usunięcie błędu i podmiana kotwicy oblały właściwie,
  bajty i mtime przywrócone. Pint i analiza typów PASS. Niezależny ACCEPT.
- **#2846**, `0bdcd63a2`: błędna fraza „Szukaj w moich planach” ma własny
  MessageBag i odnośnik do pola; sesyjny błąd innego formularza pozostaje.
  Nowe HTTP: 3 / 26 PASS, fizyczny mutant oblał, restore PASS. Pełna klasa
  na Windows miała starą porażkę daty także na bazowym widoku. Root sprawdził
  pełny Planer w złożonej P na Linux/PG18: 14 / 14 PASS, bez wyciszania testu.
  DOM kotwicy zmierzony, brak osobnego dowodu automatycznego
  fokusu czy wizualnego 320 px dla tego formularza. Niezależny ACCEPT.
- **#2822**, `95446511b`: tekst odróżnia koniec współtworzenia od możliwości
  czytania dostępnego publicznego zeszytu. Policy i dane bez zmian. PG18:
  WspolnyZeszyt 36 / 251 PASS, cztery kombinacje publiczny/prywatny oraz
  odejście/odebranie 4 / 50 PASS. Dwie fizyczne mutacje oblały właściwie,
  dokładne przywrócenie oraz czyszczenie własnych widoków. Niezależny ACCEPT.
- **#2850**, `cda406def`: błąd wyszukiwania w zeszytach w podsumowaniu
  z żywym linkiem `f-szukaj`; wpisana fraza, filtry i osobny błąd POST zostają.
  Nowe HTTP na PG18: 3 / 32 PASS, fizyczny mutant potwierdzony i przywrócony.
  Pełna klasa Windows 8 / 9, ten sam stary test nie przeszedł także na
  oryginalnym widoku O. Root uruchomił pełną klasę w Linux/PG18: 9 / 9 PASS,
  bez wyciszania testu. Pełne CI nadal jest osobną bramką.
  Analiza typów/Pint PASS, odczytowy przegląd root bez blokera w nowym zakresie.
- **#2839**, `f9a00e50a`: rzeczywisty POST pobiera ZIP z czytelną kopią HTML
  zachowującą wiersze kroków i historii. Blade nadal escapuje treść.
  PG18: 12 / 195 oraz sąsiednie 23 / 169 PASS; fizyczny pre-line→normal
  oblał właściwie, dokładny restore i PASS. Istniejący Chromium: rzeczywisty
  wygenerowany HTML, 320 px / tekst 100% i 200% / emulowany print PASS,
  scrollWidth 320 i zero elementów script; długi wyraz się łamie, puste
  wiersze są widoczne. Root obejrzał zrzut screen-200. Jeden proces zamknięty,
  niczego nie instalowano. To nie PDF ani fizyczna drukarka.
- **#2843**, `b8dc92e`: wybór wersji w odzyskiwanym zeszycie wraca po błędzie
  tylko dla tego samego konta i paczki. Zapisany wybór jest przecinany ze
  świeżo dostępnymi wersjami; ukryte pole nie nadaje prawa do cudzej paczki.
  HTTP/PG18: 22 / 184 PASS; fizyczny mutant oblał markerem
  `WYBOR_2843_NIE_WRACA_WYKLUCZONA`, dokładny restore. Niezależny ACCEPT.
- **#2853**, `add0f7de`: niepewna nazwa grupy składnika po OCR blokuje
  pierwszą publikację. Kreator i zwykłe podsumowanie wskazują poprawny
  składnik także po pustym wierszu. Dosłowne `[?` poza OCR i prywatne źródło
  pozostają bez zmian. Własny PG18: 41 / 389 OCR i 20 / 78 kreator PASS;
  fizyczny mutant oblał markerem `OCR_2853_GRUPA_NIEPEWNA_PUBLIKACJA`.
  Kontrolowany transport HTTP, bez płatnego wywołania AI. Root ACCEPT.

Każda poprawka ma wpis CHANGELOG, dokumentację i rejestr mutacji CI.
Konflikty wspólnych rejestrów i CHANGELOG rozwiązano sumą obu dopisków.
P nie dodaje migracji, zależności, wywołań AI ani kosztu. Zawarte migracje
N wymagają odbioru i rollbacku opisanego w ODBIOR-N-CODEX-20261003.md.

## Bramka końcowa

Root uruchomił własną kopię Linux, dokładny lock, osobny klucz i PostgreSQL 18
na `127.0.0.1:55488`, baza `kuking_test_p_20261003`, rola
`kuking_pg18_owner`. Na `3a0bffcc71b314477400ddfe7c296f1983ec3bce` całe
zmienione klasy i strażnicy: **214 / 3470 PASS**. Fizyczna zmiana
`pre-line` na `normal` oblała właściwym `KOPIA_2839_WIERSZE`; dokładne bajty
i mtime przywrócone, cała KopiaDanych: **12 / 198 PASS**. Linux i Windows
różnie normalizują CRLF w DOM, więc asercja DOM porównuje kanoniczne LF;
liczba pustych wierszy i oryginalne bajty w JSON nadal są sprawdzane.

Po dołożeniu OCR, na `5f177fb5542cd156453ac300a1329fd2b7af3ac7` całe klasy
OCR, kreatora i strażników tekstu: **76 / 2231 PASS**, pełny PHPStan:
**0 błędów**. To pomiar zmienionego zakresu, nie pełny hook ani CI.
Następne kroki: zwykły push z niezmienionym hakiem i pełne CI dokładnego
końcowego heada. Nie zaliczać pominiętych opcji jako sukcesu.
P1 #2851 i #2854 otrzymały osobną naprawę od wdrożonego main i niezależną
recenzję; nie są częścią P ani odebrane tylko na podstawie analizy kodu.

Przed zamknięciem zastąpionych PR i issues: końcowy zielony PR do main
wraz z CodeQL, merge z expectedHeadSha, terminalne CI push, SUCCESS web,
workera i harmonogramu dla tego samego SHA, produkcyjne /wydanie oraz
/health. Czynności #2025, #2708, #2713 i pilot z osobami 50+ pozostają otwarte.
