# Instrukcje dla GitHub Copilot — Kuking.pl

**Komplet zasad projektu jest w [`AGENTS.md`](../AGENTS.md) w katalogu głównym.**
Ten plik to skrót; przy jakiejkolwiek rozbieżności obowiązuje `AGENTS.md`.

- Projekt: Laravel 13 · PHP 8.4 · Blade + Livewire 4 (komponenty jednoplikowe) ·
  Tailwind CSS 4 (CSS-first, `@theme`, bez `tailwind.config.js`) · PostgreSQL 18.
- Modularny monolit: reguły domenowe w `app/Domain`, kontrolery cienkie.
- Wszystkie klasy z `declare(strict_types=1);`. Formatowanie: `vendor/bin/pint`.
- Komentarze, komunikaty i dokumentacja **po polsku**; nazwy w kodzie po angielsku.
- Komunikat błędu ma mówić użytkownikowi, **co zrobić**, po polsku.
- Nie proponuj: mikroserwisów, SPA, Redisa, GraphQL, osobnego search engine.
- Nie proponuj `status` ani `role` w `$fillable` modelu User.
- Nie proponuj infinite scrolla — paginacja to przycisk „Pokaż więcej”.
- Ikony i widoczne podpisy: stosuj AGENTS.md, w tym jawny wyjątek menu trzech kropek na karcie wpisu.
- Nie proponuj SQLite w testach — schemat wymaga PostgreSQL.
