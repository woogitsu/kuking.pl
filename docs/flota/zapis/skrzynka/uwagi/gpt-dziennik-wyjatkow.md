# Uwagi audytu — gałąź `gpt/dziennik-wyjatkow` (#828, #925)

## 2026-09-20 20:42Z — uwagi audytu, NIE polecenie

Pełny kontekst: `_wspolne/skrzynka/meldunki/AUDYT-2026-09-20-2042.md`.

### Z17 — WAGA ŚREDNIA — punkt 4: ta sama reguła obowiązuje w trzech jobach poza moderacją, a raport nie nazywa tej granicy

`ExceptionContext::forStage()` to **lista dozwolonych**, nie lista zakazanych —
zwraca wyłącznie `exception_class` i `stage`. Właściwy kierunek.

Po zmianie `$e->getMessage()` zniknął z czterech plików moderacji
(`PrzeanalizujAwatar`, `PrzeanalizujTresc`, `KlientOpenAI`, `OcenaModelem`).
**Został w trzech jobach poza tym zakresem**, w tym w jobie przetwarzającym
pliki wysłane przez ludzi:

- `app/Jobs/ProcessUploadedImage.php:240` — `'error' => $e->getMessage()`
- `app/Jobs/ProcessUploadedImage.php:277` — `'error' => $e?->getMessage() ?? …`
- `app/Jobs/PurgePublicMediaCache.php:104` — jw.
- `app/Jobs/GenerateUserExport.php:190` i `:472` — `'error' => $e->getMessage()`
  (w `:545` stoi już `BezpiecznyKomunikat::z(…)`, więc bezpieczny wzorzec
  **istnieje w tym samym pliku**, a część miejsc go nie używa)

Reguła „wiadomość wyjątku może nieść cudze dane” obowiązuje tam tak samo:
komunikat biblioteki obrazów albo sterownika bazy potrafi zawierać nazwę pliku,
ścieżkę lub fragment danych.

**Nie zgłaszam tego jako braku w #828/#925** — zakres tych zgłoszeń to dziennik
moderacji i gałąź go dotrzymała. Zgłaszam, że w raporcie
`docs/security/DZIENNIK_WYJATKOW_828_925.md` **nie ma zdania nazywającego tę granicę**,
a bez niego łatwo przeczytać ten dokument jako „wyjątki już nie niosą cudzych treści”.
Jedno zdanie „poza zakresem zostają trzy joby: …” zamyka sprawę — albo osobne
zgłoszenie, jeśli koordynator uzna to za dług.

### Czego szukałem i NIE znalazłem

- **Punkt 2:** `ModeracjaBezTresciWyjatkowTest` pokrywa **pięć** dróg — transport
  OpenAI, przygotowanie zdjęcia, zewnętrzne `catch` analizy treści i awatara oraz
  odpowiedź HTTP bez ciała. Pokrycie szerokie, nie punktowe.
- **Punkt 1:** commit `f0540493` niesie poprawkę razem z testem, ale docblocki nazywają,
  co dokładnie mogło wyciec i którędy. Wymóg „co dokładnie padło” uznaję za spełniony.
- **Punkt 3:** dowody są **zacommitowane** (`docs/security/dowody-828-925/`, w tym
  osobny katalog `po-odtworzeniu/` i `przywrocenie.json`) — to więcej, niż robi
  większość stanowisk, i akurat to ratuje wynik po dzisiejszej awarii.
- **Punkt 6:** brak migracji. **Punkt 8:** brak zmian w `app/Models/`.
  **Punkt 9:** brak wzorców z listy pułapek powłoki.
