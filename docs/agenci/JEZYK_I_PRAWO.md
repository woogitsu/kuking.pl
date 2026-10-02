# Język: wyjątki od form rodzajowych i daty wejścia w życie dokumentów prawnych

Przeniesione z `AGENTS.md` §11 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

**Każdy tekst widoczny dla użytkownika piszesz według `docs/brand/COPY_STYLE.md`
i `docs/brand/GLOS_MARKI.md`.** Oba są wiążące, nie są inspiracją: pierwszy mówi,
JAK napisać zdanie, i ma gotowe teksty do wklejenia; drugi mówi, czym ten głos
JEST i gdzie marka mówi głośno, a gdzie milczy.

- **nazwę piszemy dwukolorowo, komponentem `<x-kuking-word/>`, wszędzie — także
  jako nazwę serwisu w tekście bieżącym.** Limitu „raz na ekran” nie ma
  (decyzja właściciela z 11 września 2026, odwraca tę część D-009 i D-015).
  Obowiązuje kryterium: charakter marki wolno tam, gdzie **nie konkuruje
  z zadaniem**, a w jednym akapicie, nagłówku albo punkcie listy nazwa
  pojawia się raz;
- **nigdy** w komunikacie błędu, wiadomości moderacyjnej, tekście prawnym,
  na ekranie bezpieczeństwa, w liście technicznym, w powiadomieniu o cudzej
  aktywności ani w polu formularza, który ktoś właśnie wypełnia;
- **nigdy tam, gdzie koloru nie ma** — `alt`, `title`, `aria-label`, tytuł
  strony, `meta`, temat listu, pliki eksportu. Tam piszemy zwyczajnie „Kuking”;

- unikamy konstrukcji zakładających rodzaj, gdzie da się inaczej
  („Co dziś gotujesz?” zamiast form z „-łeś/-łaś”); formę, którą osoba
  sama wybrała, stosujemy wyłącznie przez helper z wariantem neutralnym — D-332.
  **Jawne wyjątki są frazami, nie słowami**, i pilnuje ich lista `WYJATKI`
  w `tests/Support/WzorceRodzaju.php`: hasło główne („co dziś ugotowałeś”),
  nazwa przycisku „Ugotowałem” oraz etykieta pola wyboru **„Sprawdziłem
  odczytany tekst”** przy szkicu z importu (decyzja właściciela z 26 września
  2026, PR #1899, D-300 — ta sama logika co „Ugotowałem”: nazwa kontrolki
  cytowana w komunikacie). Kolejny wyjątek wymaga decyzji właściciela.

**Dokumenty prawne (polityka prywatności, regulamin): data publikacji to nie
data wejścia w życie** (D-327, decyzja właściciela z 26 września 2026).
Zmiana **istotna** obowiązuje 14 dni po publikacji, a do tego dnia obowiązuje
poprzednia wersja; pasek o zmianie stoi od publikacji i podaje ten dzień.
Poprawka **drobna** (redakcyjna, bez zmiany praw i obowiązków) wchodzi od
razu. Przy każdym podbiciu `kuking.zgody.wersja_*` ustaw jawnie
`kuking.zgody.zmiana_*.istotna` na `true` albo `false` — wartości domyślnej
nie ma. Zgodę zapisuj z wersją obowiązującą (`WersjaDokumentu::…->obowiazujaca()`),
nigdy z samą datą z konfiguracji.
