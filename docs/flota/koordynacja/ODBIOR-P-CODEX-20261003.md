# Odbiór paczki P — 3 października 2026

Koordynator przejrzał sześć lokalnych poprawek istniejących issues.
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
  bazowy Planer na O579 w Linux: 11 / 11 PASS; pełne złożenie P nadal wymaga
  własnego pomiaru. DOM kotwicy zmierzony, brak osobnego dowodu automatycznego
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
  oryginalnym widoku O. Nie wyciszano testu; Linux/CI musi rozstrzygnąć.
  Analiza typów/Pint PASS, odczytowy przegląd root bez blokera w nowym zakresie.
- **#2839**, `f9a00e50a`: rzeczywisty POST pobiera ZIP z czytelną kopią HTML
  zachowującą wiersze kroków i historii. Blade nadal escapuje treść.
  PG18: 12 / 195 oraz sąsiednie 23 / 169 PASS; fizyczny pre-line→normal
  oblał właściwie, dokładny restore i PASS. Istniejący Chromium: rzeczywisty
  wygenerowany HTML, 320 px / tekst 100% i 200% / emulowany print PASS,
  scrollWidth 320 i zero elementów script; długi wyraz się łamie, puste
  wiersze są widoczne. Root obejrzał zrzut screen-200. Jeden proces zamknięty,
  niczego nie instalowano. To nie PDF ani fizyczna drukarka.

Każda poprawka ma wpis CHANGELOG, dokumentację i rejestr mutacji CI.
Konflikty wspólnych rejestrów i CHANGELOG rozwiązano sumą obu dopisków.
P nie dodaje migracji, zależności, wywołań AI ani kosztu. Zawarte migracje
N wymagają odbioru i rollbacku opisanego w ODBIOR-N-CODEX-20261003.md.

## Bramka końcowa

Root przygotowuje własną kopię Linux, dokładny lock, osobny klucz i PG18
z jawnym hostem/portem/nazwą. Najpierw całe zmienione klasy oraz strażnicy,
potem normalny push z istniejącym hakiem i pełne CI dokładnego heada.
Nie zaliczać starej porażki Windows ani pominiętych opcji jako sukcesu.
P1 #2851 i #2854 otrzymały osobną naprawę od wdrożonego main i niezależną
recenzję; nie są częścią P ani odebrane tylko na podstawie analizy kodu.

Przed zamknięciem zastąpionych PR i issues: końcowy zielony PR do main
wraz z CodeQL, merge z expectedHeadSha, terminalne CI push, SUCCESS web,
workera i harmonogramu dla tego samego SHA, produkcyjne /wydanie oraz
/health. Czynności #2025, #2708, #2713 i pilot z osobami 50+ pozostają otwarte.
