# GEMINI.md

**Przeczytaj najpierw [`AGENTS.md`](./AGENTS.md).** To jest komplet zasad projektu
i jedyne źródło prawdy — dla Gemini, Claude, GPT i każdego innego modelu.

> **Uwaga:** ten plik jest tylko wskaźnikiem. Wszystkie zasady projektu żyją
> w jednym miejscu: [`AGENTS.md`](./AGENTS.md). Nie duplikuj tu treści —
> rozjazd między plikami instrukcji jest gorszy niż brak instrukcji.

## Ściąga (pełne uzasadnienia w AGENTS.md)

- Kuking = **społeczność ludzi, którzy gotują**, nie baza przepisów. Grupa: 50+.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 · PostgreSQL 18 · Railway.
- Modularny monolit. Zero mikroserwisów, SPA, Redisa, osobnego search engine.
- Główna akcja: zdjęcie + kilka słów → Opublikuj. „Ugotowałem” > lajk.
- Feed obserwowanych chronologiczny, bez algorytmu.
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, ikona nigdy sama, bez hover/swipe,
  błędy po polsku mówiące co zrobić, poprawne dane nigdy nie znikają.
- Ważne funkcje działają bez JavaScriptu.
- Zmiana schematu = migracja + test + docs + rollback. Bugfix = test regresyjny.
- `status` i `role` nigdy w `$fillable`. UUID w adresie to nie autoryzacja.
- Testy na PostgreSQL. Przed PR-em: `vendor/bin/pint` i `php artisan test`.
- Dokumentacja, interfejs i komentarze **po polsku**; kod po angielsku.
