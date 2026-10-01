## D-296 — Wyjątek od D-240: zdjęcie kartki wychodzi do OpenAI na osobną zgodę „odczyt AI” (26 września 2026)

**Data:** 26 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
Wąski wyjątek od **D-240** · Dotyczy: D-298 (import/OCR), D-072 (dziennik zgód),
projekt `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §5

**Decyzja właściciela dosłownie:** OCR prywatnego zdjęcia — wyjątek od D-240
przez OSOBNĄ ZGODĘ „odczyt AI” w dzienniku zgód (wzór awatarów z D-240), do
cofnięcia; na start dla WSZYSTKICH zalogowanych.

### Problem

D-240: do OpenAI wychodzi wyłącznie pomniejszona (≤ 320 px), publiczna treść;
treść bez potwierdzonej zgody — nie. Odczyt pisma z kartki łamie oba warunki
naraz: kartka jest prywatna, a 320 px nie wystarcza do przeczytania pisma.
D-240 sam wskazał drogę (przy awatarze): „osobna decyzja: cel zgody, ekran
udzielania i wycofania, sprawdzenie przed każdą wysyłką”.

### Co obowiązuje

- **Nowy cel w `dziennik_zgod`: `odczyt_ai`** i nowe źródło `ekran_importu`
  (migracja `2026_09_26_100200_dziennik_zgod_cel_odczyt_ai`). Stanem zgody
  jest OSTATNI wpis osoby dla tego celu — bez kolumny na `users`, więc nie ma
  dwóch prawd (flaga i dowód). Zapisuje i czyta wyłącznie
  `App\Domain\Zgody\PrzestawZgodeNaOdczytAi`; zdarzenie powstaje tylko przy
  realnej zmianie.
- **Ekran udzielania** przed pierwszym odczytem (`/dodaj/przepis/z-kartki`):
  kto odczyta (OpenAI, USA), co wyślemy (samo zdjęcie kartki, bez imienia,
  e-maila i danych z aparatu), prośba o zasłonięcie cudzych danych, jak
  wycofać. Dwa przyciski: „Zgadzam się, odczytujcie moje kartki” / „Nie,
  wpiszę przepis ręcznie”.
- **Wycofanie** w `/ustawienia/prywatnosc` (osobny formularz), dostępne także
  podczas zawieszenia konta (`EnsureAccountIsActive`, RODO art. 7 ust. 3).
  Anonimizacja konta dopisuje `wycofana` / `usuniecie_konta`.
- **Sprawdzenie przed każdą wysyłką:** zadanie `OdczytajPrzepis` czyta zgodę
  świeżo z bazy jako OSTATNI krok przed żądaniem (po rezerwacji budżetu).
  Wycofanie między zleceniem a wysyłką = zero wysłanych bajtów, rezerwacja
  zwolniona (test).
- **Co wychodzi:** wyłącznie zdjęcie kartki, na wyraźne żądanie jej autora,
  przy każdym zleceniu z osobna — wariant przekodowany u nas do JPEG, dłuższy
  bok ≤ 2000 px zmierzony z bajtów, bez EXIF/XMP/GPS; `store: false`; bez
  e-maila, nazwy, IP, identyfikatorów i pola `user`.
- **Poza tym wyjątkiem D-240 obowiązuje bez zmian** — moderacja dalej wysyła
  tylko treść publiczną ≤ 320 px, awatar dalej nie wychodzi.
- **Dla kogo:** wszyscy zalogowani (bez grupy testowej).

### Warunki włączenia na produkcji (poza kodem)

Umowa powierzenia (DPA) z OpenAI wpisana do `docs/legal/REJESTR_UMOW_POWIERZENIA.md`;
nowa wersja polityki prywatności z drugim celem OpenAI (projekt tekstu:
`docs/legal/projekty/POLITYKA_ODCZYT_AI.md`, wersję podbija osobny PR razem
z pozostałymi zmianami polityki); nowa czynność w rejestrze czynności
przetwarzania. Do tego czasu `OPENAI_IMPORT_KEY` zostaje pusty.

### Dowód

`tests/Feature/Import/OdczytZdjeciaKartkiTest.php` (ekran zgody, brak
wysyłki bez zgody, wycofanie w trakcie kolejki, wycofanie przy zawieszeniu,
usunięcie konta), `tests/Feature/Import/CofniecieZgodyOdczytuAiOdmawiaTest.php`
(rollback odmawia przy istniejących zgodach — D-088).

### Wycofanie

Wyłączenie funkcji: usunąć `OPENAI_IMPORT_KEY`. Zgody w dzienniku zostają
jako dowód i niczego nie uruchamiają. Migracja CHECK-ów cofa się tylko przy
braku wpisów `odczyt_ai` (dziennik jest append-only).
