# Pomiar #1045 i #1015 — instrukcja dla właściciela

**Po co ten dokument.** Dwa issues proszą o pomiar, nie o funkcję:

- **#1045** — czy przepisy zachowują historię i rodzinne pochodzenie
  („Historie przepisów”);
- **#1015** — czy zapis przepisu w Zeszycie kończy się ugotowaniem w ciągu
  30 dni („Zapis → Ugotowałem”), zanim ktokolwiek uruchomi przypomnienie.

Kod pomiarowy **już istnieje** i jest scalony (nie trzeba nic dopisywać).
Ten plik mówi tylko, jak go uruchomić na produkcji i jak czytać wynik.
Definicje liczników są w [`docs/research/ANALITYKA.md`](../research/ANALITYKA.md)
§1.2, uzasadnienia w komentarzach klas.

| Co | Gdzie |
|---|---|
| Komenda | `php artisan kuking:raport` (`app/Console/Commands/RaportPowrotow.php`) |
| #1045 | `app/Domain/Analytics/HistoriePrzepisow.php`, test `tests/Feature/RaportHistoriiPrzepisowTest.php` |
| #1015 | `app/Domain/Analytics/ZapisDoUgotowania.php`, test `tests/Feature/RaportZapisDoUgotowaniaTest.php` |

## Bezpieczeństwo pomiaru

- **Tylko odczyt.** Komenda wykonuje wyłącznie `SELECT` — niczego nie zapisuje,
  nie wysyła poczty, nie tworzy zdarzeń.
- **Same agregaty.** Wynik to liczniki, mianowniki i procenty. Nie ma w nim
  `source_person`, `source_note`, tytułów, nazw kont, adresów e-mail, URL-i
  ani identyfikatorów (pilnują tego testy `test_komenda_pokazuje_…`).
- **Konta wykluczone** (gospodarz, testowe, zalążkowe, zamknięte) nie
  zawyżają wyniku — to ta sama reguła `CookEligibility`, co w WAC.

## Jak uruchomić (na produkcji, robi właściciel)

```bash
railway ssh -- php artisan kuking:raport
```

Wynik jest tekstem na ekranie; sekcja „Zapis → „Ugotowałem” w 30 dni” stoi
w nim przed „Historiami przepisów”, a między nimi jest sekcja „Plan →
„Ugotowałem”” (#27, nie należy do tego pomiaru). Zapisz **datę uruchomienia**
i wklej obie sekcje do `docs/research/ANALITYKA.md` §1.2 — #1015 w miejsce
„Wynik pierwszej pełnej rzeczywistej kohorty: jeszcze niezmierzony”, #1045
w miejsce „Pierwszy rzeczywisty wynik: jeszcze nie odczytany”. Bez ruchu (#29) liczby
niczego nie rozstrzygają — patrz „Kiedy wynik znaczy coś”.

Lokalnie, na danych testowych: `php artisan kuking:raport` (na pustej bazie
komenda mówi wprost „jeszcze nie da się tego policzyć”, nie wywala się).

## Jak czytać: „Zapis → Ugotowałem” (#1015)

Przykład wiersza: `Ugotowane w 30 dni po zapisie: 12 z 60 zapisów · 20,0% (cel co najmniej 15%)`.

- **Mianownik** — pary *osoba–przepis* z pierwszego zapisu do Zeszytu, którego
  data wypada w przedziale (teraz − 60 dni, teraz − 30 dni]. Kilka zeszytów
  i ponowne zapisy tego samego przepisu to jedna para. Własne przepisy autora
  i wpisy (posty) nie wchodzą.
- **Licznik** — pary, w których ta sama osoba ugotowała ten przepis **po**
  zapisie i nie później niż 30 dni po nim. Ugotowanie sprzed zapisu nie liczy się.
- **Zapisy młodsze niż 30 dni** stoją w osobnym wierszu i nie wchodzą do
  mianownika — jeszcze nie miały pełnego okna. Nie zaniżają wyniku.
- **Trzy stany, których nie wolno pomylić:**
  1. „Brak zapisanych przepisów” — nikt jeszcze niczego nie zapisał;
  2. „Za wcześnie na wniosek” — zapisy są, ale wszystkie młodsze niż 30 dni;
  3. „za mało danych (mniej niż 20 zapisów w kohorcie)” — procent celowo
     ukryty; to liczba, nie wniosek.
- **Cel:** ≥ 15% (`docs/product/RETENTION_LOOPS.md`).
- **Znane ograniczenie:** usunięcie przepisu z zeszytu kasuje wiersz zapisu,
  więc taka para znika z pomiaru, a zapis po usunięciu liczy się jako nowy
  pierwszy zapis (bez nowej tabeli — tak chce #1015).

**Co dalej (bramka #1015, etap 2).** Tylko przy kohorcie ≥ 20 par z realnych
kont: porównaj z 15%, zapytaj kilku prawdziwych osób, czy zapominają o zapisie,
czy przepis im nie odpowiada (zestaw z „Zrobię ponownie”, #1509, **bez** łączenia
w jeden wskaźnik). Przypomnienie w tygodniowym podsumowaniu wymaga wpisu
właściciela (aktualizacja D-057) — do tego czasu **nie jest włączone**.

## Jak czytać: „Historie przepisów” (#1045)

Przykład: `Choć jeden konkretny ślad pochodzenia: 18 z 40 (45,0%)`.

- **Mianownik** — przepisy `published`, nieusunięte, opublikowane w ostatnich
  90 dniach, autor spoza wykluczeń. **Liczą się wszystkie widoczności**
  (publiczne, dla obserwujących, prywatne) — prywatny przepis po babci to
  wzorcowy przypadek tych pól. Ile z mianownika jest publicznych, mówi wiersz
  „w tym publicznych”.
- **Liczniki** (każdy osobno): „od kogo albo skąd” (`source_person`), historia
  (`source_note`), „w rodzinie od roku”, gotowy skan kartki, oraz rodzaj źródła
  „Rodzinny”. Pusty napis i same spacje nie są historią; skan liczy się tylko,
  gdy zdjęcie istnieje i jest w stanie `ready`.
- **Dwa zbiorcze:**
  - „Choć jeden konkretny ślad” = od kogo LUB historia LUB rok LUB skan;
  - „Rodzinny i choć jeden ślad” = „Rodzinny” ORAZ ślad.
  Różnica między „Rodzinny” a „Rodzinny i ślad” to przepisy oznaczone jako
  rodzinne **bez żadnej zachowanej historii**.
- **Mała próba:** poniżej 20 przepisów są same liczniki i napis „Za mało danych
  na procenty”; pusty okres jest opisany słowami, nie jako 0%.
- **Progi:** RETENTION_LOOPS §5.3 — „skąd ten przepis” ≥ 30–35%, „po kim ten
  przepis” (licznik „od kogo albo skąd”) ≥ 25–35%; alarm poniżej 20%.

**Interpretacja (bramka diagnostyczna z #1045):**

| Wynik | Wniosek |
|---|---|
| pola używane, procenty w normie | nie dokładać nowych zachęt |
| „Rodzinny” częsty, ale „Rodzinny i ślad” dużo niższy | sprawdzić kreator z osobami 50+ i opis pól (nie budować nic nowego) |
| wszystkie liczniki rzadkie | rodzinny temat tygodnia (#18) i przykłady gospodarza przed przebudową formularza |

Rodzinnej książki, współautorów i OCR **nie** budujemy na podstawie tego
wyniku (V1/V2, wymagają decyzji właściciela).

## Historia wersji przepisu (#2024) a ten pomiar

Historia wersji (`recipe_versions`, ekrany „Historia zmian” i „Porównaj”) **nie
zmienia definicji ani wyniku pomiaru**. Sprawdzone w kodzie:

- Miernik liczy wiersze `recipes` (aktualny stan), **nie** wiersze
  `recipe_versions` — kolejne wersje tego samego przepisu nie mnożą mianownika.
- Ślady pochodzenia są czytane z aktualnego przepisu; migawka
  (`SnapshotRecipeVersion`) kopiuje je do wersji (`source_person`,
  `source_note`, `family_since_year`, `source_type`), ale pomiar do nich nie
  sięga. Dopisanie historii do starego przepisu poprawia jego wynik, o ile
  przepis mieści się w oknie 90 dni od pierwszej publikacji (`published_at`
  nie zmienia się przy edycji — `PublishRecipe`, `$recipe->published_at ?? now()`;
  zeruje je dopiero cofnięcie do szkicu, więc ponowna publikacja po szkicu
  liczy się od nowej daty).
- „Zrób własną wersję” (`ZrobWlasnaWersje`) **nie kopiuje** pochodzenia
  (`source_person`, `source_note`, `family_since_year`, skan) i zakłada szkic
  z `published_at = null`, więc wersja cudzego przepisu nie zawyża wyniku
  cudzą historią.
- Skan kartki nie trafia do migawki wersji — pomiar sprawdza go wyłącznie
  na aktualnym przepisie (`source_scan_media_id` → zdjęcie `ready`).

Jeżeli kiedyś zajdzie potrzeba pytania „ile przepisów **kiedykolwiek** miało
historię” (także po jej usunięciu), trzeba będzie dodać `source_scan_media_id`
do migawki wersji — to osobna zmiana schematu, nie część tego pomiaru.

## Weryfikacja kodu pomiarowego (bez produkcji)

```bash
APP_BASE_PATH=$(pwd) php artisan test \
  tests/Feature/RaportHistoriiPrzepisowTest.php \
  tests/Feature/RaportZapisDoUgotowaniaTest.php
```

Testy obejmują kontrole ujemne wymagane w issues: szkic, ukryty i usunięty
przepis, puste pola i same spacje, sam typ „Rodzinny”, odłączony lub niegotowy
skan, ugotowanie przed zapisem i po 30 dniach, ponowne zapisy, kohortę
niepełną (zapisy młodsze niż 30 dni) oraz małą próbę.

## Kiedy wynik znaczy coś

Dopóki nie ma realnego ruchu (#29), przy mianowniku poniżej progu (20) raport
sam mówi „za mało danych”. Nie decyduj o produkcie na podstawie liczb z kilku
kont testowych i znajomych — poczekaj na pełną kohortę z co najmniej 20 parami
(#1015) i 20 przepisami (#1045), a wynik z datą dopisz do
`docs/research/ANALITYKA.md`.
