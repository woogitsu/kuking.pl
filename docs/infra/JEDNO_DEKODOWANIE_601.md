# Jedno dekodowanie oryginału — #601

Status: przygotowane lokalnie, bez push, CI tego pakietu i wdrożenia.
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
nie zbadano wejścia ICC/CMYK. Pełny hook i wymagane CI pozostają do wykonania.

Rollback: cofnięcie zmiany joba; brak migracji, zmian kluczy magazynu i danych.
