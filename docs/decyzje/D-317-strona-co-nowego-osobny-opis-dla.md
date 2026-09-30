## D-317 — Strona „Co nowego”: osobny opis dla czytelników, oznaczenie „nowa funkcja” w CHANGELOGU (issue #1909, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela

### Problem
Wersja w stopce (`App\Support\Wersja`, D-051) mówi CO jest wdrożone
(etap, data, skrót commita), ale nie mówi, co to NAPRAWDĘ zmienia dla
człowieka. `CHANGELOG.md` to ma, ale pisze do wszystkich zmian naraz,
technicznym tonem repozytorium, i nie jest z niczego wprost linkowany.

### Decyzja właściciela
1. **Osobna, publiczna strona** `/co-nowego` (nazwa trasy `nowosci`),
   pod adresem, do którego prowadzi kliknięcie wersji w stopce. Treść leży
   w `resources/nowosci/tresc.md` — jeden plik z sekcjami na wydania,
   renderowany tym samym bezpiecznym Markdownem co `resources/legal/*.md`
   (wydzielonym do `App\Support\ZaufanyMarkdown`), bez JavaScriptu.
2. **Podział na wydania „Alfa 0.xx”**, z sekcją „Najnowsze zmiany” na
   górze — to, co już działa, a nie ma jeszcze numeru wydania (odpowiednik
   sekcji „## Nieopublikowane” w CHANGELOGU). Kliknięcie wersji w stopce
   otwiera stronę przy kotwicy BIEŻĄCEGO wydania
   (`App\Support\Wersja::kotwicaWydania()`), nie od góry dokumentu.
3. **CHANGELOG.md zostaje pełną, techniczną listą zmian.** Strona nowości
   ma OSOBNY, krótki opis pisany dla czytelników, nie automat z CHANGELOGU:
   nowa funkcja jest rozpisana (gdzie ją znaleźć, jak działa, co daje),
   a poprawki, zmiany kosmetyczne i porządek za kulisami są zebrane w jedno
   zdanie na wydanie.
4. **Strona jest publiczna**, widoczna także dla gości — jak `/zasady`
   i `/regulamin`. Bez `Policy`: nie ma tu cudzego zasobu do chronienia.
5. **Oznaczenie „nowa funkcja” w CHANGELOGU.** Najmniej uciążliwy sposób:
   dopisek `[nowa funkcja]` na końcu wiersza, tylko przy wpisach, które
   dostają rozpisany akapit na stronie nowości. Bez osobnej kolumny, bez
   drugiego pliku. Zasada trafiła do `AGENTS.md` §10 („Pull Request
   zawiera”): PR z takim wpisem w sekcji „## Nieopublikowane” musi mieć
   odpowiadający akapit (`### ...`) w sekcji „## Najnowsze zmiany” pliku
   nowości — i odwrotnie.

### Dowody
`tests/Feature/StronaCoNowegoTest.php` (200 gościowi, stopka linkuje
z kotwicą bieżącego wydania, kotwica istnieje, spis wydań prowadzi do
kotwic) i `tests/Feature/StraznikNowosciKazdaNowaFunkcjaMaAkapitTest.php`
(kontrola ujemna w `scripts/kontrole-negatywne-alfa08.py`: zdjęcie znacznika
`[nowa funkcja]` z CHANGELOGA ma zapalić strażnika).

### Wycofanie
Usunąć trasę `nowosci`, kontroler, plik treści i odnośnik w stopce (wraca
do zwykłego `<span>`). CHANGELOG.md nie traci nic — dopiski
`[nowa funkcja]` zostają nieszkodliwym tekstem, jeśli nikt ich nie sprząta.
Schemat bazy się nie zmienia.
