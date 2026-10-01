## D-299 — Wartości odżywcze przepisu: szacunek z tabel CIQUAL/USDA, w PHP, bez AI (V2, etap 7; 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela
(źródło danych, próg, widoczność) · Projekt:
`docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §8, etap 7, zadania I-9 i I-10,
pytanie P-8.

**Decyzja właściciela (26.09.2026, odpowiedź na P-8).** Źródło: darmowe,
otwarte tabele CIQUAL i/lub USDA — licencje sprawdzone i zapisane
w repozytorium, dane jako plik w repozytorium plus komenda importu, żadnego
pobierania na produkcji. Wynik (kcal, białko, tłuszcz, węglowodany na
porcję) tylko przy pokryciu ≥ 90% masy, z podpisem „Szacunek na podstawie
tabel CIQUAL/USDA”, bez oświadczeń zdrowotnych i bez filtrów dietetycznych;
przy mniejszym pokryciu — brak liczb i uczciwy komunikat. Autor może ukryć
sekcję w swoim przepisie; **domyślnie widoczna**.

**Co wdrożono.**

1. **Dane.** `database/data/odzywcze/skladniki.csv` (218 pozycji: 200 z
   CIQUAL 2025 — Licence Ouverte / Etalab 2.0, DOI 10.57745/RDMHWY; 18 z USDA
   FoodData Central SR Legacy — CC0) i `miary.csv` (364 miary domowe). Źródła,
   wersje, licencje i znane przybliżenia: `database/data/odzywcze/ZRODLA.md`.
   Człowiek wybiera w pliku tylko źródło i identyfikator pozycji; wartości
   dopisuje `scripts/odzywcze/uzupelnij_wartosci.py` z pobranych plików
   źródłowych — każdą liczbę da się sprawdzić.
2. **Baza.** `skladniki_odzywcze`, `miary_domowe`, `aliasy_skladnikow`
   (CHECK-i na wartości ≥ 0, na źródło i na gramy) i
   `recipes.pokazuj_wartosci_odzywcze` — `docs/DATABASE.md`. Import:
   `php artisan kuking:importuj-wartosci-odzywcze`, idempotentny, w jednej
   transakcji. Od poprawki #1961 (26.09.2026) komenda stoi w
   `preDeployCommand` obok `migrate`/`db:seed` i leci przy każdym wdrożeniu;
   pomija zapis, gdy hash źródeł oraz odcisk wszystkich trzech tabel są zgodne
   z poprzednim udanym importem (#2130); deploy bez zmiany danych nie przepisuje
   słownika, a częściowy restore odbudowuje go.
3. **Liczenie** (`app/Domain/Recipes/Odzywcze`). Składnik jest wolnym
   tekstem (D-017), więc `ParserSkladnika` czyta ilość, jednostkę i nazwę
   z tekstu w chwili liczenia i niczego nie zapisuje („2 szklanki mąki”,
   „mąka – 500 g”, „pół kostki masła”, „2–3 ząbki”, „1 puszka (400 g)”).
   Kolumny `quantity`/`unit_id`, jeśli kiedyś będą wypełnione, mają
   pierwszeństwo. `SlownikSkladnikow` dopasowuje najdłuższy fragment nazwy
   do słownika polskich form (bez zgadywania: „mleko kokosowe” to nie
   „mleko”). `KalkulatorWartosci` przelicza na gramy (nawias › g/dag/kg ›
   szczypta 0,5 g › miara domowa składnika › ml × gęstość).
4. **Zasada 90%.** Liczby tylko wtedy, gdy znamy masę każdego składnika,
   którego nie pomijamy jawnie, a składniki z tabel to ≥ 90% tej masy.
   Pomijamy jawnie: „Bez ilości” (`no_amount`), „do smaku / do podania /
   ile weźmie / na oko”, a sól, pieprz, zioła i wodę — tylko gdy nie mają
   ilości. „Olej do smażenia”, „trochę śmietany”, „kilka łyżek” blokują
   wynik: nie wiemy, ile tego trafia do garnka. Komunikat mówi dlaczego,
   podaje przykład wiersza autora i nigdy nie prosi o dopisanie gramów.
5. **Widok.** Sekcja pod składnikami na stronie przepisu: nagłówek ze
   słowem „szacunkowe”, energia zaokrąglona do 10 kcal, reszta do 1 g,
   podpis „Szacunek na podstawie tabel CIQUAL/USDA.”, „Jak to liczymy”
   (źródła z licencją i datą, miary, próg). Autor ma przycisk „Ukryj tę
   sekcję w moim przepisie” (PATCH `recipes.wartosci-odzywcze`, Policy
   `update`, limit `wartosci_odzywcze`). Ukrycie nie zmienia `updated_at`
   ani wersji przepisu.
6. **Skalowanie porcji.** Wartości są na jedną porcję z `recipes.servings`;
   przeliczenie przepisu na inną liczbę porcji mnoży wszystko tym samym
   mnożnikiem, więc liczby na porcję się nie zmieniają. Sekcja nie zależy od
   kodu skalowania i nie dotyka jego plików.

**Czego świadomie nie robimy.** Żadnych słów „zdrowe”, „dietetyczne”,
„lekkie”, „fit”, „dla cukrzyków” (test negatywny słownictwa), żadnego
filtrowania ani sortowania po kaloriach, żadnego profilu diety czy alergii
(dane o zdrowiu, art. 9 RODO). Żadnego modelu AI — ani do liczenia, ani do
dopasowań. Polskie tabele IŻŻ zostają poza zakresem (licencja płatna).

**Odstępstwo od szkicu projektu.** Projekt (§8.1) wiązał tabelę wartości
kluczem obcym z `ingredients`. `ingredients` dostaje z formularza cały tekst
wiersza („2 szklanki mąki” i „mąka” to dwa hasła), więc zamiast tego jest
słownik aliasów wspólny dla serwisu. Projekt proponował też sekcję
„zwiniętą” — jest rozwinięta (cztery liczby są krótsze niż przycisk, który
by je chował); zwinięte jest tylko „Jak to liczymy”.

**Znane przybliżenia.** Twaróg (brak polskiego twarogu w CIQUAL i USDA —
użyty cottage cheese, wynik serników raczej zaniżony), jedna pozycja dla
wszystkich kiełbas, kurczak w całości jako 900 g części jadalnej. Szczegóły
i propozycje poprawy: `ZRODLA.md`.

**Pilnują tego:** `ParserSkladnikaTest`, `KalkulatorWartosciOdzywczychTest`
(przepis wzorcowy z ręcznym rachunkiem, próg 90%, fikstura przepisów
z seedów), `WartosciOdzywczeImportTest`,
`WartosciOdzywczeNaStroniePrzepisuTest`,
`CofniecieMigracjiNiePokazujeUkrytychWartosciTest` (D-088).

### Wycofanie
Usunąć `<x-wartosci-odzywcze>` z `pages/recipes/show.blade.php` — sekcja
znika, dane zostają. Pełne cofnięcie schematu: migracja tabel cofa się
bezstratnie; migracja kolumny `recipes.pokazuj_wartosci_odzywcze` odmawia,
gdy którykolwiek autor sekcję ukrył (D-088) — komunikat mówi, co zapisać
przed cofnięciem.
