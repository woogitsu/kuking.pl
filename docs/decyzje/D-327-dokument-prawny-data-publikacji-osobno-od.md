## D-327 — Dokument prawny: data publikacji osobno od daty wejścia w życie; zmiana istotna po 14 dniach (#1811, #1781, 26 września 2026)

**Data:** 26 września 2026 · Decyzja właściciela (26.09.2026) · Status: **obowiązuje** ·
Rozstrzyga pytanie otwarte w D-306 · Dotyczy D-072 (dziennik zgód), #1816

### Decyzja

Przy **istotnej** zmianie polityki prywatności albo regulaminu data wejścia
w życie jest osobna od daty publikacji. Pasek o zmianie pokazuje się od
publikacji, a nowa wersja obowiązuje **14 dni później**; do tego dnia
obowiązuje poprzednia. **Drobne** poprawki (redakcyjne, bez zmiany praw
i obowiązków) wchodzą od razu.

### Jak to jest zapisane

- `kuking.zgody.wersja_polityki` / `wersja_regulaminu` — bez zmian: data
  PUBLIKACJI, ta sama co w nagłówku dokumentu („opisuje stan serwisu na …”);
- `kuking.zgody.zmiana_polityki` / `zmiana_regulaminu` — **jawne**
  oznaczenie: `istotna` (true/false, bez wartości domyślnej — brak klucza
  albo inna wartość to wyjątek), `poprzednia` (data dotychczasowej wersji,
  wymagana przy istotnej) i `obowiazuje_od` (null = publikacja + 14 dni;
  wolno później, nigdy wcześniej; przy drobnej zabronione);
- `kuking.zgody.okres_istotnej_zmiany_dni` = 14, w repozytorium, nie w `.env`.
  `WersjaDokumentuTest` pilnuje, że regulamin §11 obiecuje tę samą liczbę;
- logika w jednym miejscu: `App\Domain\Zgody\WersjaDokumentu`
  (`obowiazujeOd()`, `wOkresiePrzejsciowym()`, `obowiazujaca()`).

### Co z tego wynika

- **Pasek regulaminu** (D-306) pokazuje się od dnia publikacji jak dotąd.
  Przy zmianie istotnej mówi „Nowa wersja obowiązuje od <data>. Do tego dnia
  obowiązuje poprzednia.”, a od dnia wejścia w życie — samo „Nowa wersja
  obowiązuje od <data>.”. Przy drobnej nie podaje żadnego terminu.
- **Zgoda** (`dziennik_zgod.wersja_polityki`, D-072) zapisuje wersję
  OBOWIĄZUJĄCĄ w chwili zdarzenia (`WersjaDokumentu::polityka()->obowiazujaca()`),
  nie ostatnio opublikowaną — we wszystkich zapisach: digest, życzenia mailem
  i zgoda „odczyt AI” (`PrzestawZgodeNaOdczytAi`). **Akceptację regulaminu przy rejestracji**
  zapisuje od #2217 ten sam dziennik (cel `regulamin`, kolumna
  `wersja_regulaminu` = `WersjaDokumentu::regulamin()->obowiazujaca()`,
  źródło `rejestracja_haslo` / `rejestracja_google` / `rejestracja_facebook`,
  `App\Domain\Zgody\ZapiszAkceptacjeRegulaminu` w transakcji `ZalozKonto`).
  Konta sprzed tej zmiany nie mają wiersza — to „brak dowodu”, bez backfillu.
- Obecne wersje: polityka 2026-09-10 (sprzed rozróżnienia) i regulamin
  2026-09-26 (opis doboru wpisów, D-305) są oznaczone jako **drobne** —
  opisują działanie serwisu, nie zmieniają praw i obowiązków.

### Przy następnym podbiciu (np. #1816)

1. Podbij datę w nagłówku dokumentu i `wersja_*` na dzień publikacji.
2. Ustaw `zmiana_*.istotna` jawnie. Przy `true` wpisz `poprzednia`,
   a we wpisie „Co się zmieniło” podaj dzień wejścia w życie.
3. **Polityka nie ma dziś własnego paska**, a §9 polityki obiecuje przy
   zmianie istotnej powiadomienie w serwisie. `WersjaDokumentuTest`
   oblewa, gdy `zmiana_polityki.istotna` = true bez komponentu
   `pasek-zmiany-polityki` — pasek polityki trzeba dołożyć razem z #1816.
4. Poprzedni tekst nie jest dziś osobno publikowany; w okresie przejściowym
   strona dokumentu pokazuje już nowy. Jeśli prawnik uzna, że poprzednia
   wersja musi być dostępna do przeczytania, to osobna zmiana.

### Wycofanie

Bez migracji. Wycofanie kodu przywraca D-306 (pasek bez terminu, zgoda
z `config('kuking.zgody.wersja_polityki')`). Wpisy dziennika zgód zapisane
w okresie przejściowym zostają z wersją poprzednią — to prawda o chwili
zgody, nie błąd do poprawienia.

📄 `app/Domain/Zgody/WersjaDokumentu.php` · `app/Domain/Zgody/ZmianaRegulaminu.php` ·
`resources/views/components/pasek-zmiany-regulaminu.blade.php` · `config/kuking.php` (`zgody`) ·
`tests/Feature/WersjaDokumentuTest.php` · D-072 · D-306
