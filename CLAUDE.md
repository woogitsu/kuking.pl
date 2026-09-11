# CLAUDE.md

**Przeczytaj najpierw [`AGENTS.md`](./AGENTS.md).** To jest komplet zasad projektu
i jedyne źródło prawdy — dla Claude, GPT, Gemini i każdego innego modelu.

> **Uwaga:** ten plik jest tylko wskaźnikiem. Wszystkie zasady projektu żyją
> w jednym miejscu: [`AGENTS.md`](./AGENTS.md). Nie duplikuj tu treści —
> rozjazd między plikami instrukcji jest gorszy niż brak instrukcji.

## Ściąga (pełne uzasadnienia w AGENTS.md)

> **Sprostowanie, 11 września 2026.** Ta ściąga sama była rozjazdem, przed którym
> ostrzega akapit wyżej — a jest pierwszą rzeczą, którą agent czyta. Nieprawdą było:
>
> - **„Ważne funkcje działają bez JavaScriptu"** — zasadę unieważnił właściciel
>   9 września (**D-053**, `AGENTS.md` §5). Rejestracja i logowanie stoją za
>   Turnstile (**D-050**), a Turnstile bez skryptu nie istnieje. Punkt był więc
>   nieprawdziwy od 9 września, a plik nie był ruszany od 5 września;
> - **„Przed PR-em: `vendor/bin/pint` i `php artisan test`"** — to są pojedyncze
>   kroki, nie kontrola przed PR-em. Pełną kontrolą jest `./scripts/check.sh`
>   (`AGENTS.md` §10) i skrypt leży w repozytorium od 5 września, czyli punkt był
>   niepełny od pierwszego dnia.
>
> Dwa dalsze punkty podawały regułę bez nazwanego w `AGENTS.md` wyjątku:
> **D-051** przy UX 50+ i **D-088** przy rollbacku. Zostały uzupełnione o odesłanie.
> Nie odkręcaj tego bez zmiany w `AGENTS.md` — źródłem prawdy jest on, nie ten plik.

- Kuking = **społeczność ludzi, którzy gotują**, nie baza przepisów.
- Grupa: **50+**, ale produkt nie jest oznaczany jako „dla seniorów”.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 + Alpine.js · Tailwind 4 ·
  PostgreSQL 18 · Railway.
- Modularny monolit. **Zero mikroserwisów, SPA, Redisa i osobnego search engine.**
- Główna akcja: **Co dziś ugotowałeś?** → zdjęcie + kilka słów → Opublikuj.
- **„Ugotowałem” jest ważniejsze niż lajk** i zawsze powiadamia autora przepisu.
- Feed obserwowanych: **chronologiczny**, bez algorytmu.
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, ikona nigdy sama, bez hover/swipe,
  błędy po polsku mówiące co zrobić, poprawne dane nigdy nie znikają.
  Jedyny wyjątek od „≥ 18 px” i „ikona nigdy sama”: stopka, **D-051**.
- **Newralgiczne formularze mogą wymagać JavaScriptu** (rejestracja i logowanie
  za Turnstile — D-050, D-053). Czego nie wolno nigdy: **martwego przycisku** —
  `<noscript>` ma powiedzieć po polsku, co zrobić.
- Zmiana schematu = migracja + test + `docs/DATABASE.md` + opis rollbacku;
  przy wartościach semantycznych `down()` **odmawia**, zamiast zgadywać (**D-088**).
- Bugfix = test regresyjny, a test bez kontroli ujemnej nie jest dowodem.
- `status` i `role` użytkownika **nigdy** w `$fillable`.
- **UUID w adresie to nie autoryzacja** — każde wejście przez Policy.
- Testy chodzą na **PostgreSQL**, nie na SQLite.
- Przed PR-em: `./scripts/check.sh` (formatowanie + składnia + testy + migracje
  + assety). Osobno: `vendor/bin/pint`, `php artisan test`, `npm run build`.
- Brak destrukcyjnych operacji na produkcji bez jawnej zgody.

## Zanim zaczniesz implementować

Sprawdź `docs/ROADMAP.md`, żeby nie budować funkcji z V2 podczas prac nad MVP.
Pracuj z issues po kolei: `P0` → `P1` → `P2`.
