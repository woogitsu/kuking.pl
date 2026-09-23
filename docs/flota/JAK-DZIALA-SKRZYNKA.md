# Skrzynka — jak rozmawiamy bez interfejsu

Koordynator (Claude) i stanowiska (sesje GPT) wymieniają się przez PLIKI
w tym katalogu. Powód jest prosty: oba końce mają dostęp do dysku, więc
nie musimy się widzieć przez okno aplikacji.

## Twoje dwa pliki

Podstaw swoją nazwę stanowiska, np. `gpt-widmo-zamkniec`:

- `zlecenia/<stanowisko>.md` — **CZYTASZ**. Tu koordynator pisze, co masz
  robić. Nowe polecenia dopisuje na KOŃCU pliku, z datą i godziną.
- `meldunki/<stanowisko>.md` — **PISZESZ**. Tu zdajesz wynik, zadajesz
  pytania i zgłaszasz, że się zatrzymałeś.

## Kiedy zaglądać do zleceń

- gdy skończysz zadanie,
- gdy się zatrzymasz i potrzebujesz decyzji,
- gdy nie wiesz, co dalej.

Nie odpytuj pliku w pętli co minutę — to marnuje Twój limit. Zajrzyj,
gdy naprawdę masz powód.

## Jak pisać meldunek

Dopisuj na KOŃCU pliku, nigdy nie kasuj cudzej ani własnej historii.
Zacznij nagłówkiem `## <data godzina> — <jedno zdanie o czym to jest>`.

W meldunku podawaj:
- SHA commitów, jeśli jakieś zrobiłeś,
- co zmierzyłeś SAM, a co przejąłeś (cudze oznacz `[pomiar cudzy: źródło]`),
- czego NIE zrobiłeś i dlaczego — to jest najcenniejsza część,
- co wymaga decyzji właściciela, z wariantami i kosztem.

## Gdy się zatrzymujesz

Napisz w meldunku wprost `ZATRZYMANIE:` i opisz, co widzisz. Nie
improwizuj naprawy na współdzielonym repozytorium. Zatrzymanie z
uczciwym opisem jest w tym projekcie warte więcej niż praca na oślep.

## Czego skrzynka NIE zastępuje

Nie zastępuje raportu w `docs/` Twojej gałęzi. Raport zostaje tam, gdzie
był — skrzynka służy do rozmowy i do wskazywania, gdzie ten raport leży.

## Gdy zajrzysz, a nowego zlecenia nie ma

Dopisz do meldunku JEDNO zdanie i przestań sprawdzać. Nie zapisuj kolejnych
odczytów co pół minuty — to pali Twój limit i zaśmieca meldunek tak, że
trudno w nim znaleźć rzeczy istotne. Koordynator trąci Cię ponownie, gdy
coś będzie czekać.

Jeśli trącenie przyszło, a w zleceniach nic nie przybyło — to znaczy, że
koordynator się pomylił albo trącenie poszło zbiorczo do wszystkich.
Jedno zdanie o tym wystarczy.
