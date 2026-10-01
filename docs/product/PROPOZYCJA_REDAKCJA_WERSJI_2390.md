# Propozycja: redakcja fragmentu migawki wersji (#2390)

Status: propozycja do decyzji właściciela. Nic z tego nie jest zbudowane.

## Stan zweryfikowany (1.10.2026)

- Autor i moderacja mogą ukryć dowolną wersję poza najnowszą (#2270, D-333).
  Gość dostaje 404 pod `/przepisy/{slug}/historia/{numer}` i pod `.../zmiany`.
- Porównanie sąsiedniej wersji nie pokazuje treści ukrytej wersji: poprzednikiem
  jest najbliższa widoczna wersja (test `HistoriaWersjiPrywatnoscTest`).
- Ekrany historii mają `noindex` w meta; od tej zmiany także `X-Robots-Tag`.
  Nie są na liście tras HTML cache'owanych na brzegu (landing, przepis, profil),
  więc ukrycie działa od razu, bez czyszczenia cache.
- Zgłoszenie (Report) NIE może wskazać wersji (`reports.target_type` jej nie
  zna). Moderator ukrywa wersję z urzędu, po zgłoszeniu całego przepisu.
- Wymazanie konta: zakres `minimum` zostawia przepisy z wersjami (tekst jest
  zanonimizowanym dorobkiem, D-022); zakres `everything` kasuje przepisy,
  a wersje znikają kaskadą. Retencja wersji: D-333.
- Najnowszej wersji nie można ukryć; dane osobowe z niej usuwa się edycją
  przepisu, co tworzy nową wersję, i dopiero wtedy ukrywa się starą.

## Luka, której nie zamyka ukrycie

Ukrycie jest „wszystko albo nic": autor, który chce zachować dobrą historię
zmian (np. 40 wersji) i usunąć jeden numer telefonu z wersji 3, musi ukryć
całą wersję 3. Dla trzeciej osoby (nie autora) nie ma ścieżki zgłoszenia
konkretnej wersji.

## Warianty

A. Tylko ukrycie całej wersji (stan obecny) + zgłoszenie wskazujące wersję.
   Niezmienność snapshotu zachowana. Koszt: utrata czytelności historii.

B. Redakcja pola z audytem. Nowa kolumna `recipe_versions.redactions` (jsonb:
   pole, zakres, kto, kiedy, podstawa); widok i porównanie nakładają redakcję
   przy odczycie, snapshot w bazie pozostaje. Minus: dane osobowe nadal leżą
   w bazie i w eksporcie/kopiach — to nie jest usunięcie w rozumieniu art. 17.

C. Redakcja z fizycznym nadpisaniem fragmentu snapshotu + wpis w `audit_log`
   (bez starej wartości). Spełnia prawo do usunięcia, ale łamie niezmienność
   (blokada `updating` w `RecipeVersion` dopuszcza dziś tylko `hidden_at`
   i `hidden_by_role`) i wymaga zmiany D-333/D-xxx o historii wersji
   oraz migracji z rollbackiem, który może odmówić (D-088).

## Rekomendacja

Krótkoterminowo wariant A, rozszerzony o możliwość zgłoszenia konkretnej
wersji (nowy `target_type`, migracja + `docs/DATABASE.md`). Redakcja (C) tylko
jeśli właściciel uzna, że sama niemożność zachowania reszty historii jest
realnym problemem; wtedy jako wąska, audytowana operacja moderacji
(nie edycja swobodna), na pojedyncze pole tekstowe, z jawnym wyjątkiem
w D-xxx o niezmienności. Wariant B odradzam: daje pozór usunięcia.

## Pytania do właściciela

1. Czy trzecia osoba ma móc zgłosić konkretną wersję (zmiana decyzji „wersji
   nie da się zgłosić")?
2. Czy akceptujemy, że usunięcie danych z historii = ukrycie całej wersji?
3. Jeśli redakcja: C (fizyczna) czy B (nakładka)?
