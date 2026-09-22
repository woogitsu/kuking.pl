# Jedno dekodowanie oryginału — #601

Status: **scalone i wdrożone** — merge `8b4528a049e9100ccfb9c45877c58f8843c2eedd`
(PR #625) z 16 września 2026. Odbiór stanu na produkcji, z tabelą kryteriów
i tym, co pozostaje nieudowodnione: [ODBIOR_JEDNEGO_DEKODOWANIA_601.md](ODBIOR_JEDNEGO_DEKODOWANIA_601.md).
**#601 pozostaje otwarte**: nowa ścieżka nie przetworzyła jeszcze na produkcji
ani jednego zdjęcia.

Poniższy zapis powstał PRZED scaleniem i opisuje stan draftu — zdania o braku
scalenia i o blokadzie wdrożenia (ostatnia sekcja) są już historyczne.
Historia: commit 47cbdebf24b1e0451f7f2f2311e55469e2206174 wysłany zwykłym
pushem; pełny obowiązkowy hook przeszedł, CI 35100722575 dla tego commita
zakończyło się sukcesem (12/12 zadań).
Baza: main `f77bd4d7f16e93469b9a731f80c41e6b151e7faf`.

## Zmiana

`ProcessUploadedImage` dekoduje i orientuje oryginał przed pętlą.
Każdy wariant dostaje osobną ramkę Intervention opartą na tej samej bitmapie GD.
W używanym Intervention 3.11.8 `scaleDown` tworzy docelową bitmapę i podmienia
wyłącznie ramkę wariantu. Nie klonujemy całego źródła ani nie skalujemy kolejnej
miniatury. Cztery wywołania `read()` oznaczają jedno dekodowanie bajtów oraz trzy
opakowania obiektu GD. Synchroniczny `PodgladOdRazu` pozostaje osobną ścieżką.

## Dowody

- Prawdziwe fotografie około 12/24/48 MP: sześć prób wstępnych i 22 przebiegi
  (18 naprzemiennych oraz dwie pary równoległych procesów `handle()`). Wszystkie
  warianty zgodne bajtowo, wymiarowo i rozmiarowo; licznik GD wykazuje 3→1.
- W tej serii CPU było niższe dla każdej próbki. RSS dla 24 MP wzrosło około 6%,
  dla 12 MP spadło, dla 48 MP pozostało podobne. Nie obiecujemy mniejszej pamięci
  dla każdego pliku. Liczby i granice pomiaru: [raport serii](evidence/media601/batch-20260916T145809-db8cb5-report.md).
- Fotografie mają zapisane źródła, autorów, licencje i SHA256 w
  [manifeście](evidence/media601/photo-sources.json). Nie publikowano ich w aplikacji.
- Lokalnie: 17 testów / 377 asercji (nowa regresja i rodziny uploadu, orientacji,
  błędów), osobno `PrzygotowywanieZdjeciaWpisuTest`: 10 / 68. Baza
  `kuking_601_tests`, właściciel `kuking`, 127.0.0.1:55439, pełne migracje.
- Nowa regresja: 16 przypadków JPEG/PNG/WebP/AVIF, dwa rozmiary i dwie orientacje,
  48 dokładnych porównań wariantów. Osobny proces chroni resztę suity przed
  pozostawieniem licznika GD. Kontrola dodatnia wymaga trzech dekodowań baseline.
- Dwie fizyczne kontrole ujemne rzeczywistego joba: przywrócenie wielokrotnego
  dekodowania i pomniejszanie wspólnego źródła. Obie oblały; kopia poza repo,
  przywrócenie MD5/mtime i ponowny wynik dodatni 1 / 325:
  [dowód](evidence/media601/negative.json).
- Pierwsza próba drugiego negatywu ujawniła binarne bajty w komunikacie PHPUnit,
  przez co helper nie dekodował logu UTF-8. Asercja nadal porównuje dokładne
  bajty przez `===`, ale zgłasza tylko opis przypadku. Obie kontrole powtórzono.
- Pint poprawił formatowanie helpera licznika; analiza PHPStan zmienionego joba
  bez błędów. Niezależny przegląd źródeł i vendora nie znalazł blokera.

## Ograniczenia i dalsze kontrole

Pomiary fotografii używały dedykowanego minimalnego schematu i lokalnego
magazynu. To nie był proces workera, R2 ani benchmark przepustowości produkcji.
Maszyna była współdzielona i obciążona; RSS jest szczytem procesu, nie deltą
alokacji joba. Nie sumujemy szczytów procesów jako pomiaru wspólnego maksimum.
Rzeczywisty EXIF/GPS sprawdzono osobną próbą joba, lecz bez parsera uploadu;
nie zbadano wejścia ICC/CMYK. Pełny hook przeszedł: Pint, składnia, PHPStan, pełne testy PHP oraz odwracalność migracji. Wymagane CI kodu 35100722575 przeszło; uzupełnienie tego raportu wymaga osobnego potwierdzenia wysyłki i kontroli.

Rollback: cofnięcie zmiany joba; brak migracji, zmian kluczy magazynu i danych.

## Osobny koszt synchronicznego podglądu

Trzy wywołania `PodgladOdRazu::zrob()` dla każdej z tych samych fotografii:
12 MP — 411–440 ms CPU i peak RSS procesu 211320–211508 KiB;
24 MP — 265–271 ms CPU i 161168–161344 KiB. Przy 48 MP próg 25 MP
poprawnie pomija dekodowanie. Wyniki nie tworzą krzywej kosztu zależnej tylko
od megapikseli: fotografie różnią się także orientacją i zawartością.

To wywołanie po wczytaniu bajtów, na lokalnym dysku. RSS obejmuje bootstrap;
nie zmierzono requestu, R2 ani doświadczenia człowieka przy publikacji.
Źródło ładowane ze współdzielonego vendora porównano z plikiem PR po
normalizacji LF: identyczne. [Surowe wyniki](evidence/media601/preview.json).

Koszt jest mierzalny, lecz ten eksperyment nie uzasadnia usunięcia podglądu
ani zmiany progu. Opóźnienie całej publikacji i pamięć równoczesnych uploadów
wymagają osobnego pomiaru. Ten PR nie zmienia synchronicznego podglądu.

## Blokada wdrożenia

Poprzedni main f77bd4d ma nieudany deployment Railway 6481180494;
przyczyna nie została ustalona. Nie scalać kolejnego pakietu przed jej
wyjaśnieniem. Sukces CI nie jest potwierdzeniem działającej produkcji.