# Podział pomiarów portu marki — #577

## Odtworzony problem

CI main 34975700310 dla 91c8b4106fb96b4447ecc5db8ae39eb94996b49d
zakończyło się anulowaniem zadania 104402800738. Adnotacja check-run:
„The job has exceeded the maximum execution time of 25m0s”. Sam krok
port-projektu trwał około 24 min 28 s; limit przerwał następny pomiar kreatora.
Pozostałe dziewięć zadań zakończyło się sukcesem. To ustalona przyczyna tego
przebiegu, nie ogólna diagnoza każdego czerwonego pomiaru.

Railway 6460017818 otrzymało stan inactive. Nie potwierdzono produkcyjnej
Alfy 0.37. Ostatni potwierdzony odbiór produkcji to Alfa 0.36, d50183e.

## Zmiana

Zachowano limit 25 minut i nazwę dotychczasowego zadania. Dwa zadania mają
osobny checkout, PostgreSQL, serwer aplikacji i artefakty:

- `port_marki`, `PORT_GRUPA=baza`: podstawowe kompozycje, ich fizyczne
  kontrole ujemne i pomiar po przywróceniu źródeł;
- `port_funkcje`, `PORT_GRUPA=rozszerzenia`: istniejące rodziny ekranów,
  rzeczywisty zoom i pomiar trzech kroków kreatora.

Każdy dotychczasowy pomiar pozostał w kodzie. Nie zmieniono asercji,
progów, liczby wariantów ani zachowania kontroli ujemnych. Domyślne
uruchomienie `port-projektu.mjs` nadal wykonuje całość w tej samej kolejności.
Nieznana grupa kończy proces błędem przed przygotowaniem danych.
Log `PORT_CZAS` pozwala ocenić faktyczny czas poszczególnych części w CI.

Filtry zmian obejmują także moduł wyboru grup i jego test oraz wcześniejsze
skrypty szybkiego wyglądu, przewijania paska i zwartych kolumn. Zmiana samych
tych pomiarów uruchamia odpowiednie kontrole.

## Wykonane kontrole lokalne

- 4 testy Node: pełna kolejność, wybór obu grup i odrzucenie błędnej nazwy.
- 2 testy PHP / 67 asercji: oba zadania, zakres, izolacja, artefakty,
  zachowane limity i przypisanie kreatora.
- Fizyczny negatyw prawdziwego workflow: zamiana grupy bazowej na rozszerzenia
  została wykryta; po przywróceniu 2 testy / 67 asercji przeszły.
- Fizyczny negatyw prawdziwego modułu: usunięcie selekcji grup powoduje
  niepowodzenie 2 z 4 testów. Po przywróceniu 4 testy przeszły.
- Kopie obu źródeł wykonano poza repo, MD5 i mtime przed i po przywróceniu
  są identyczne. Dowody: `evidence/ci577/`.
- Niezależny odczyt podziału nie wykazał blokera.

## Historyczny stan przed końcowym CI PR

Poniższy stan poprzedza odbiór 11/11 opisany na końcu raportu.

W tym momencie zmiana była przygotowana w PR #575 bez CI dwóch nowych zadań;
nie deklarujemy, że zmieszczą się w limicie bez pomiaru. Przed scaleniem
wymagany sukces wszystkich zadań, także nowego `port_funkcje`.
Odczyt API ochrony gałęzi zwrócił 403. Nie zmieniano ochrony ani jej wymagań.

Późniejszy CI PR #575, 34978064181, zakończył się innymi błędami:
zasłonięciem fokusu szybkiego wyglądu oraz brakiem namalowanego fragmentu
obrysu linku zmiany awatara przy rzeczywistym zoomie. Te wyniki wymagały
osobnej diagnozy, zakończonej w SZYBKI_WYGLAD_574.md; podział czasu sam
ich nie naprawia ani nie pomija.

Brak zmian danych i migracji. Rollback: zwykły revert zmian podziału;
przywróci dłuższe pojedyncze zadanie i jego znane ryzyko przekroczenia limitu.

## Odbiór CI PR — 15 września 2026

Końcowy head `7cbdef8a5d1236d36542008527ee4b322d3506e6`: zwykły pełny
hook zakończony sukcesem; CI 34983616697 — 11/11 success. Log PHP
104430040169 potwierdza 3834 testy / 76883 asercje pełnej rodziny.
Wcześniejsze niepowodzenia kontrolowane w tym logu należą do negatywów,
nie do końcowego przebiegu pełnego PHP. Port bazowy: 261 s całego zadania;
rozszerzony: 1295 s. Sam pomiar rozszerzeń: 1226,32 s, kreator: 15 s.
Oba zadania mieszczą się w niezmienionym limicie 25 minut; pojedynczy wynik
nie stanowi gwarancji czasu każdego przyszłego przebiegu.

PR #575 scalono jako `0e1bdbe80ffe25d72accf2f773a3a533675ef458`.
Main CI 34986762320 i Railway 6462041446 wymagają odrębnego odbioru.
Na tym etapie nie deklarujemy Alfy 0.38 na produkcji.

## Zakończony odbiór wdrożenia

Alfa0.38/0e1bdbe potwierdzona na produkcji: mainCI34986762320
11/11success, Railway6462041446 i Deploy34989544692 success.
[Szczegóły, zakres i ograniczenia odbioru](ODBIOR_PRODUKCJI_ALFA_038.md).
Powyższe oczekiwanie na main/produkcję jest stanem historycznym.
