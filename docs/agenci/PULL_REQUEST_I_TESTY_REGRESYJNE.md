# Pull Request, CHANGELOG i testy regresyjne — szczegóły

Przeniesione z `AGENTS.md` §10 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

## Wpis w `CHANGELOG.md` przy nowej funkcji

- **wpis w `CHANGELOG.md`, jeśli PR dodaje nową funkcję albo nowe zachowanie
  widoczne dla użytkownika** — oznaczony na końcu wiersza dopiskiem
  `[nowa funkcja]` (issue #1909). Poprawka, zmiana kosmetyczna i porządek za
  kulisami tego dopisku NIE dostają — dla nich CHANGELOG zostaje zwykłym
  wpisem bez znacznika. **Każdy wpis `[nowa funkcja]` w sekcji
  „## Nieopublikowane” ma odpowiadający akapit** (nagłówek `### ...` i kilka
  zdań prostym językiem: gdzie znaleźć, jak działa, co daje) **w sekcji
  „## Najnowsze zmiany” pliku `resources/nowosci/tresc.md`** — strony „Co
  nowego” pod numerem wersji w stopce. Pilnuje tego
  `tests/Feature/StraznikNowosciKazdaNowaFunkcjaMaAkapitTest.php`
  (kontrola ujemna w `scripts/kontrole-negatywne-alfa08.py`, wzorzec
  z issue #1909).

## Test regresyjny — kontrola ujemna i kontrola mutacyjna

**Test bez kontroli ujemnej nie jest dowodem.** Zepsuj to, czego test pilnuje,
sprawdź, że OBLEWA, przywróć. Pomyłki, które w tym repozytorium przeszły
przez zielone CI — razem z gotowymi wzorcami, jak ich uniknąć — są zebrane
w [`docs/PULAPKI_TESTOW.md`](../PULAPKI_TESTOW.md). Przeczytaj to raz, zanim
napiszesz pierwszy test w tym projekcie; każda z tych pułapek wróci.

**Test czytający kod źródłowy dostaje kontrolę mutacyjną w CI.** Wpis w `checks`
w `scripts/kontrole-negatywne-alfa08.py` + **wzorzec oczekiwanej porażki** w
`scripts/kontrole_oczekiwana_przyczyna.py` (klucz: nazwa kontroli; fragment
komunikatu asercji, którą mutacja ma zapalić — czerwień z innego powodu nie jest
dowodem, #1011). Kontrola bez wzorca jest raportowana jako `BEZ_WZORCA`, czyli
dowód niepełny. Wyjątek tylko przez `@bez-kontroli-dodatniej <powód>` w docbloku
klasy — pilnuje tego `StraznikTekstuMaKontroleDodatniaTest`.
