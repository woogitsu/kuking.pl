# CLAUDE.md

**Przeczytaj najpierw [`AGENTS.md`](./AGENTS.md).** To jest komplet zasad projektu
i jedyne źródło prawdy — dla Claude, GPT, Gemini i każdego innego modelu.

> **Uwaga:** ten plik jest tylko wskaźnikiem. Wszystkie zasady projektu żyją
> w jednym miejscu: [`AGENTS.md`](./AGENTS.md). Nie duplikuj tu treści —
> rozjazd między plikami instrukcji jest gorszy niż brak instrukcji.

## Ściąga (pełne wymagania i uzasadnienia w AGENTS.md)

- Kuking = **społeczność ludzi, którzy gotują**, nie baza przepisów.
- Grupa: **50+**, ale produkt nie jest oznaczany jako „dla seniorów”.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 · PostgreSQL 18 · Railway.
- Modularny monolit. **Zero mikroserwisów, SPA, Redisa i osobnego search engine.**
- Główna akcja: **Co dziś ugotowałeś?** → zdjęcie + kilka słów → Opublikuj.
- **„Ugotowałem” jest ważniejsze niż lajk** i powiadamia autora przepisu z trzema granicami opisanymi w AGENTS.md.
- Feed obserwowanych: **chronologiczny**, bez algorytmu.
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, ikony zgodnie z jawnymi wyjątkami w AGENTS.md (menu trzech kropek), bez hover/swipe,
  błędy po polsku mówiące co zrobić, poprawne dane nigdy nie znikają.
- JavaScript: stosuj AGENTS.md i D-053 — newralgiczne formularze mogą wymagać JS; nie zostawiaj martwych przycisków.
- Zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback,
  a rollback **może odmówić** — patrz D-088 i AGENTS.md §6.
- Bugfix = test regresyjny.
- Pola **sterujące** nigdy w `$fillable`: `status` i `role` użytkownika,
  `kind` wpisu. Pełna reguła i powody w AGENTS.md §7 oraz D-006.
- **UUID w adresie to nie autoryzacja** — każde wejście przez Policy.
- Testy chodzą na **PostgreSQL**, nie na SQLite.
- Przed PR-em: `./scripts/check.sh` — jedna komenda (AGENTS.md §10).
  Sam `pint` i `artisan test` pomijają składnię, migracje i assety.
- Brak destrukcyjnych operacji na produkcji bez jawnej zgody.

## Zanim zaczniesz implementować

Sprawdź `docs/ROADMAP.md` i `docs/FEATURES.md` (sekcja „V2”), żeby nie budować
funkcji z V2 podczas prac nad MVP.
Pracuj z issues po kolei: `P0` → `P1` → `P2`.
