# Propozycja wdrożenia #1902: alergeny składników i filtr „bez wskazanych alergenów”

Stan: odczyt kodu i dokumentów, bez zmian w repo, bez issues i komentarzy. Piszę jako propozycja do przedstawienia właścicielowi.

## 0. Status i zastrzeżenia (najpierw)

- **#1902 jest dziś ZABLOKOWANE.** Stoi na liście „V2, ale nie teraz” w `docs/FEATURES.md`. D-331 (29.09) odblokował tylko #1997, #2000, #1996, #2024 i #2016. Wiersz „Lista V2, ale nie teraz” w tabeli D-333 mówi: „reszta zostaje”. Żeby budować, potrzebna jest **nowa decyzja właściciela** z wpisem D-xxx. Bez niej ten dokument jest tylko projektem.
- **Lista „Nie wcześnie” nie jest tu dotknięta.** #1902 tam nie należy, więc niczego z niej nie proponuję.
- **D-333 mówi też: „AI — nie uruchamiamy nic nowego”** (#813, #814, #815, #1983 czekają). Dlatego w propozycji **nie ma sugestii AI**. Jest tylko ręczne oznaczanie i, opcjonalnie później, słownik deterministyczny.
- **D-299 (wartości odżywcze) ma wiążącą granicę:** „żadnego profilu diety czy alergii (dane o zdrowiu, art. 9 RODO)”. Filtr musi więc być **bezstanowy** (parametr w adresie, niczego nie zapisujemy o widzu). Nie wolno dodać ustawienia „moje alergeny” w profilu bez osobnej decyzji.
- **Stan kodu.** Lokalny checkout (HEAD z 29.09, `11699845a`) jest **starszy niż `main`**. D-333 czytałem z `main` przez API GitHuba (plik `docs/DECISIONS.md`). Nazwy plików i tabel pochodzą z lokalnego checkoutu. Przed implementacją trzeba je sprawdzić na `main`, bo od tego czasu doszły m.in. migracje (`recipe_versions.hidden_at`).

## 1. Jak jest dziś (ustalenia z kodu)

- **Składnik to wolny tekst.** `recipe_ingredients.ingredient_text varchar(240) NOT NULL` jest źródłem prawdy (`docs/DATABASE.md`). Obok jest `ingredient_id` → `ingredients` (słownik wspólny, `findOrCreateByName` z **całego tekstu wiersza**, np. „2 szklanki mąki”). Słownik nie nadaje się więc do mapowania na alergeny. Istnieje też `aliasy_skladnikow` z D-299, ale to mapa do wartości odżywczych.
- **`PublishRecipe::syncIngredients()` kasuje i zakłada od nowa wszystkie wiersze składników przy KAŻDYM zapisie** (nowe UUID-y). Ma to dwa skutki:
  - Zaznaczenie alergenu **na wierszu składnika** by przepadło. Trzeba by je dokładać do payloadu formularza i parsować z powrotem, czyli rozmiar L bez wyraźnej korzyści.
  - Dlatego proponuję **oznaczenie na poziomie przepisu**, a nie składnika. To świadome odejście od wzorca Tandoor z `docs/research/repos/TandoorRecipes-recipes.md` §2.7, który dziedziczy alergeny po składnikach (#1902 pkt „przy składniku lub przy przepisie” dopuszcza oba warianty).
- **Wszystkie drogi zapisu przechodzą przez `PublishRecipe`:**
  - kreator Livewire (`resources/views/components/recipe-wizard.blade.php`, krok 2),
  - formularz bez JS (`ZapisPrzepisuRequest`, `ZapiszPrzepisZFormularza`),
  - import URL/PDF/zdjęcie (`ZapiszSzkicZImportu`, zawsze prywatny szkic, D-298/D-300).
  Jeden punkt zaczepienia wystarczy.
- **„Moja wersja” (`ZrobWlasnaWersje`)** kopiuje składniki bezpośrednio, poza `PublishRecipe`. Nowy przepis ma więc dostać stan domyślny „nie sprawdzono”. Zaznaczenia oryginału nie dziedziczymy, bo autor kopii może zmienić składniki.
- **Migawki i eksport:**
  - `SnapshotRecipeVersion::migawka()` ma jawną listę pól. Obowiązuje tam zasada „brak klucza = nieznane”, którą trzeba zachować.
  - `CollectUserExportData` (klucz `przepisy[].skladniki`) i `Api\V1\RecipeResource` też mają jawne listy.
  - `TrescPrzepisu::odcisk()` (źródło `dateModified`) ma jawną listę kolumn. Decyzja: alergeny to treść, więc **dopisać do odcisku**.
- **Wyszukiwanie:** `SearchQuery::recipes()` zbiera kandydatów czterema gałęziami `UNION ALL` (tytuł, podsumowanie, `ingredient_text_search LIKE`). Filtry „Do 30 minut” i „Do 20 zł” to parametry `$maksMinut` i `$maksKosztZl` z `SearchController`, włączane sekcjami `sekcja=szybkie|tanie` (wzajemnie wyłączne chipsy w `search.blade.php`). Ich reguła to dobry wzorzec: **brak danych wypada z filtra, nie wpada** („brak kwoty nie znaczy tanio”). Ta sama reguła pasuje do alergenów.
- **Ograniczenie wyszukiwarki:** fraza „bez glutenu” to zwykły tekst i nie wyklucza niczego. Dla alergenów potrzebny jest więc osobny filtr wielokrotnego wyboru, a nie kolejny chip `sekcja`.
- **Uwaga do ORDER BY:** wyrażenia w `ORDER BY` pilnuje `FeedNieSortujePoMierzeReakcjiTest`. Filtr dokładamy tylko jako `WHERE`, nie ruszamy sortowania.
- **Sygnał analityczny** `SEARCH_PERFORMED` zapisuje tylko długość frazy i `has_results`. Filtr w adresie nie trafia więc do analityki (do potwierdzenia na `main`).
- **Sekcja „Zamiast tego” i `note`** to istniejący wzorzec tekstu autora pod składnikiem, zgodny z UX 50+.

## 2. Zakres MVP (najmniejszy krok, który daje uczciwą wartość)

**Autor zaznacza na poziomie przepisu → strona przepisu pokazuje stan → filtr w wyszukiwarce wyklucza zaznaczone alergeny.** Bez słownika, bez AI, bez profilu widza.

Czego w MVP **nie ma**: automatycznego wykrywania, podpowiedzi, alergenów przy poszczególnych składnikach, danych w JSON-LD, filtra na stronie tagu ani w zeszycie, „śladów” (PAL), ustawienia „moje alergeny”.

### Trzy stany przepisu (kluczowe dla uczciwości komunikatu)

| Stan (`allergen_status`) | Znaczenie | Co widzi czytelnik |
|---|---|---|
| `unchecked` (domyślny, także dla wszystkich istniejących przepisów) | autor nic nie zaznaczył | „Alergeny: nie sprawdzono” |
| `declared` | autor zaznaczył listę i potwierdził, że jest pełna (lista może być pusta) | lista albo „autor nie zaznaczył żadnego z 14” |
| `needs_review` | po potwierdzeniu zmieniono składniki | jak `unchecked` (lista nie jest pokazywana) |

Filtr przepuszcza **wyłącznie `declared`**. Przepisy `unchecked` i `needs_review` wypadają (fail-closed).

## 3. Schemat danych

**Decyzja projektowa:** kolumny w `recipes`, a nie nowa tabela. Zamknięty zbiór 14 wartości plus status. Filtr to jedno porównanie tablic bez JOIN-a. Brak JSONB, bo dane są w pełni ustrukturyzowane.

```sql
ALTER TABLE recipes
  ADD COLUMN allergen_status varchar(16) NOT NULL DEFAULT 'unchecked',
  ADD COLUMN allergens text[] NOT NULL DEFAULT '{}',
  ADD COLUMN allergens_declared_at timestamptz NULL;
-- stała domyślna = bez przepisywania tabeli

-- CHECK-i (NOT VALID, potem VALIDATE)
recipes_allergen_status_check:
  allergen_status IN ('unchecked','declared','needs_review')
recipes_allergens_closed_list_check:
  allergens <@ ARRAY['gluten','crustaceans','eggs','fish','peanuts','soy','milk',
                     'nuts','celery','mustard','sesame','sulphites','lupin','molluscs']::text[]
recipes_allergens_only_when_declared_check:
  allergen_status <> 'unchecked' OR cardinality(allergens) = 0
recipes_allergens_declared_at_check:
  (allergen_status = 'unchecked') = (allergens_declared_at IS NULL)
```

- **14 kodów = Załącznik II rozporządzenia 1169/2011.** Wartości w bazie są po angielsku, etykiety polskie w backed enumie PHP `App\Domain\Recipes\Alergeny\Alergen`.
- **Test dwóch źródeł:** lista w enumie musi być równa liście w CHECK-u (odczyt z `pg_get_constraintdef`), inaczej jedna zmieni się po cichu.
- **Brak UNIQUE ani duplikatów:** `allergens` może zawierać powtórzenia. Akcja domenowa robi `array_unique` i sortuje.
- **`$fillable`:** wszystkie trzy kolumny **poza `$fillable`**. To pole sterujące o skutku bezpieczeństwa, tej samej rodziny co `status`, `role` i `kind` (AGENTS.md §7, D-006). Zmienia je jedna nazwana akcja `OznaczAlergenyPrzepisu` przez `forceFill()`. Wpis w rejestrze `WrazliweKolumnyPozaMasowymPrzypisaniemTest` (z podaniem, skąd pochodzi wartość) oraz `tests/mutacje/fillable.txt`.
- **Indeks:** w MVP **bez**. Filtr działa na zbiorze już zawężonym frazą. Dodać GIN `CONCURRENTLY` dopiero po pomiarze (AGENTS.md §6).
- **Przejście `declared` → `needs_review`:**
  - W `PublishRecipe`, w tej samej transakcji co `syncIngredients()`.
  - Porównanie znormalizowanych tekstów składników (`Ingredient::normalize`, pozycja po pozycji) sprzed i po zapisie.
  - Zmiana tylko wielkości liter lub spacji nie unieważnia. Literówka w tekście unieważnia (świadomie: lepiej dopytać niż przeoczyć).
  - Autor wraca do `declared` jednym kliknięciem „Składniki nadal się zgadzają”.
- **Migawka wersji:** dopisać `allergens` i `allergen_status`. Starsze migawki bez klucza oznaczają „nieznane”, **nie** `unchecked` z dzisiaj. Nie uzupełniamy ich wstecz.
- **Eksport danych:** dodać `alergeny` i `status_alergenow`. **API:** zwracać **zawsze razem** status i listę (samej listy bez statusu nie wolno oddawać, bo pusta lista mogłaby zostać odczytana jako „brak alergenów”).
- **D-022 (usunięcie konta):** dane są kolumnami przepisu, więc idą z przepisem (zostają zanonimizowane albo są kasowane razem z nim). Nie zawierają danych osobowych. `InwentarzDanychKonta` nie wymaga zmian, ale test inwentarza trzeba uruchomić.

### Migracja i rollback (AGENTS.md §6, D-088)

- Nowa migracja `2026_10_xx_…_add_allergens_to_recipes`, `public $withinTransaction = false;`.
- `ADD COLUMN` ze stałą domyślną bez przepisywania tabeli. CHECK-i jako `NOT VALID`, potem osobno `VALIDATE CONSTRAINT`. Wzorzec: `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php`. `StraznikNowychMigracji` pilnuje tego testem.
- **`down()` odmawia**, gdy jakikolwiek przepis ma `allergen_status <> 'unchecked'`. Powód: `down()` + kolejny `migrate` przywróciłby kolumnę z domyślnym „nie sprawdzono”. To cicho usuwa deklarację autora. Stan domyślny jest tu bezpieczny, bo wraca do „nie sprawdzono”, ale to jest utrata decyzji człowieka, więc odmowa zgodnie z D-088.
  - Komunikat po polsku mówi, co zrobić: `\copy` (id, status, alergeny) i zmienna `KUKING_ROLLBACK_KASUJ_ALERGENY=true`.
  - Blokada `LOCK TABLE recipes` z `statement_timeout`, jak w `…add_substitutes…`.
  - Test odmowy **i** kontrola dodatnia (wzorzec `CofniecieMigracjiNieKasujeZamiennikowTest`).
- **Dokumentacja:** `docs/DATABASE.md` (sekcja w `recipes`, sekcja o rollbacku), wpis w `CHANGELOG.md` z `[nowa funkcja]` oraz akapit w `resources/nowosci/tresc.md` (wymaga tego `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`). Podbicie wersji w `config/kuking.php`. Nowy D-xxx w `docs/DECISIONS.md`.

## 4. Sposób oznaczania: autor czy słownik

| Opcja | Opis | Ryzyka |
|---|---|---|
| **A. Autor zaznacza (rekomendowane na MVP)** | 14 pól wyboru w kroku 2 kreatora plus osobne potwierdzenie „lista jest pełna” | Autor może się pomylić albo nie znać składu gotowych produktów (sos sojowy ma soję i pszenicę, majonez jajka, ocet winny siarczyny). Zmęczenie formularzem, więc większość przepisów zostanie „nie sprawdzono” (to akceptowalne i uczciwe) |
| **B. Słownik podpowiada, autor zatwierdza (etap 2)** | Deterministyczne dopasowanie nazw składników do alergenów, wynik jako niezaznaczone podpowiedzi („w «mąka pszenna» widzimy gluten. Zaznaczyć?”), nigdy zapis bez kliknięcia | **Fałszywy brak podpowiedzi uspokaja** (autor zaufa, że skoro nic nie wyskoczyło, wszystko gra). Fałszywe trafienia męczą. Polska fleksja (mąki, jajka, śmietanką). Trudne nazwy: „mleko kokosowe” (nie mleko), „masło orzechowe” (orzeszki ziemne), „orzech muszkatołowy” (nie alergen z Załącznika II), „mąka ryżowa” (bez glutenu), „kasza gryczana” (bez glutenu), „olej sezamowy”, „sól selerowa”, „ocet winny”. Wymaga utrzymania słownika i testów regresyjnych dla niejednoznacznych nazw (#1902 pkt 4) |
| **C. Automat zapisuje bez zatwierdzenia** | Wykrycie ze słownika jest od razu deklaracją | **Odrzucam.** Sprzeczne z #1902 pkt 3 i 5, z duchem D-299, a odpowiedzialność za „nie zawiera” spada na serwis |
| **D. Podpowiedzi AI** | Model proponuje alergeny | **Zablokowane** przez D-333 („AI — nic nowego”) oraz D-296/D-297 (zgoda, budżet). Wracać do tematu dopiero po DPA z OpenAI i osobnej decyzji |

**Zasada UI dla B:** ekran nigdy nie pisze „nie wykryto alergenów”. Brak podpowiedzi nic nie znaczy i nie jest komunikowany.

**Import (URL/PDF/zdjęcie):** szkic zawsze dostaje `unchecked`. Żadnego wykrywania przy imporcie. Przy publikacji szkicu z importu **nie dokładamy bramki** (`BramkaPublikacjiSzkicu`), bo publikacja ma się nie blokować (#1902 pkt „Oczekiwane zachowanie”).

## 5. Filtr w wyszukiwaniu

- **UI:** osobny, zwijany formularz GET pod polem frazy: „Bez wskazanych alergenów (według autorów)” z 14 polami wyboru. Jawny przycisk „Szukaj”, bo chipsy `sekcja` wykluczają się wzajemnie i nie nadają się do wielokrotnego wyboru. Zwykłe linki i formularze, bez JS, etykiety zawsze widoczne, pola ≥ 18 px, przyciski ≥ 48 px. Nie używamy koloru ani ikony jako jedynej informacji.
- **Parametr:** `bez[]=gluten&bez[]=milk` w adresie. **Nic nie zapisujemy** o widzu (D-299, art. 9 RODO). Wartości walidowane względem enuma (nieznana = ignorowana z komunikatem po polsku).
- **Backend:** `SearchQuery::recipes(..., array $bezAlergenow = [])`:

```php
->when($bez !== [], fn ($q) => $q
    ->where('recipes.allergen_status', 'declared')
    ->whereRaw('NOT (recipes.allergens && ?::text[])', [pgArray($bez)]))
```

  Sortowanie bez zmian. Parametr trzeba przenieść do linków „Pokaż więcej” (`od_przepisu`, `po_przepisie`, `ile_przepisow`) i do linków chipsów.
- **Zasięg MVP:** tylko wyniki przepisów w wyszukiwarce. Ludzie bez zmian. Strony tagu, zeszyty i „Mój stół” poza zakresem.
- **Komunikaty przy aktywnym filtrze:**
  - Nad wynikami: „Pokazujemy tylko przepisy, w których autor zaznaczył brak: gluten, mleko. Przepisy, w których autor nie sprawdził alergenów, są pominięte.”
  - Pod wynikami: „To zaznaczenia autorów, nie badania. Przy gotowych produktach zawsze czytaj etykietę.”
  - Pusty wynik: „Nie ma przepisów do „…”, w których autor zaznaczył brak: gluten, mleko. Spróbuj odznaczyć jeden alergen albo poszukaj bez filtra i przeczytaj składniki sam.” (Bez formy rodzajowej, zgodnie z `COPY_STYLE`.)
  - Na karcie wyniku (tylko przy aktywnym filtrze) jedna linia: „Autor zaznaczył brak: gluten, mleko”.
- **Koszt:** dodatkowy `WHERE` na `recipes`, bez JOIN-a. Pomiar po wdrożeniu, indeks GIN tylko, jeśli pomiar to uzasadni.

## 6. Ekrany i teksty (PL, bez emoji, bez nazw „bezpieczny”, „dla alergików”)

**Kreator, krok 2, sekcja „Alergeny (nieobowiązkowe)”:**
- Pomoc: „Zaznacz alergeny, które są w składnikach tego przepisu. Jeśli nie wiesz lub nie chcesz, zostaw puste — przy przepisie pojawi się „nie sprawdzono”.”
- 14 pól z nazwami: Zboża zawierające gluten (pszenica, żyto, jęczmień, owies), Skorupiaki, Jaja, Ryby, Orzeszki ziemne, Soja, Mleko (z laktozą), Orzechy (migdały, laskowe, włoskie, nerkowca i inne), Seler, Gorczyca, Sezam, Dwutlenek siarki i siarczyny, Łubin, Mięczaki.
- Osobne pole potwierdzenia: „Składniki sprawdzone — zaznaczone alergeny to wszystkie, o których wiem”. Bez tego pola nic nie zapisujemy jako `declared` (zaznaczenia bez potwierdzenia pokazują komunikat przy polu i w podsumowaniu).
- Uwaga: **nie używamy „Sprawdziłem”**. To forma rodzajowa i wymaga jawnego wyjątku właściciela (`tests/Support/WzorceRodzaju.php`, AGENTS.md §11).

**Strona przepisu (pod składnikami, stały blok ≥ 18 px, nie dymek):**
- `declared` z listą: „Alergeny według autora: gluten, mleko, jaja. To zaznaczenie autora, nie badanie. Gotowe produkty (sosy, kiełbasy, przyprawy, proszek do pieczenia) mogą zawierać alergeny, których tu nie widać — przeczytaj etykiety.”
- `declared` bez alergenów: „Autor nie zaznaczył żadnego z 14 alergenów. To nie jest gwarancja. Przeczytaj etykiety gotowych produktów.”
- `unchecked` i `needs_review`: „Alergeny: nie sprawdzono. Autor nie zaznaczył, co zawiera ten przepis, więc nie wiemy, czy nadaje się dla osoby z alergią.”
- Dla autora przy `needs_review`: „Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.” z przyciskiem „Składniki nadal się zgadzają”.

**Słownictwo zakazane, dopisać do testu negatywnego** (wzorzec z D-299): „bezpieczny”, „bezpieczne”, „dla alergików”, „bez alergenów”, „bezglutenowy”, „wolny od”, „gwarancja”, „zdrowe”. „Bezglutenowy” ma osobną definicję prawną (rozporządzenie 828/2014), więc nie używamy go nigdy jako etykiety. Tytuł issue „bezpiecznego wyboru” nie trafia do interfejsu.

**Polityka prywatności i regulamin:** jedno zdanie w regulaminie, że oznaczenia alergenów pochodzą od autorów, nie są weryfikowane i nie zastępują etykiety ani porady lekarza. Zmiana drobna (wzorzec D-333: serwis nie ma jeszcze prawdziwych użytkowników). Dane widza nie są przetwarzane, więc polityka prywatności bez zmian (potwierdzić).

## 7. Prawo i odpowiedzialność

- **Rozporządzenie 1169/2011** (art. 9 ust. 1 lit. c, art. 21, Załącznik II) nakłada obowiązek informowania o alergenach na **podmioty działające na rynku spożywczym** wobec konsumentów żywności. Autor przepisu publikowanego w serwisie społecznościowym ani Kuking nie są takim podmiotem. Lista 14 jest więc **słownikiem zamkniętym dla spójności**, a nie zobowiązaniem. To moja interpretacja, nie opinia prawna.
- **Realne ryzyko nie jest regulacyjne, tylko wizerunkowe i odpowiedzialnościowe:** funkcja „bez alergenów” tworzy oczekiwanie bezpieczeństwa. Jeśli alergik zaufa błędnemu zaznaczeniu, szkoda jest realna (DSA art. 6 i ustawa o świadczeniu usług drogą elektroniczną chronią hosting treści, ale nie chronią przed zarzutem wprowadzającego w błąd oznaczenia wprowadzonego przez serwis).
- **Ograniczenia ryzyka w projekcie:**
  - Język „według autora”, a nie „bezpieczne”.
  - Stan „nie sprawdzono” jest domyślny i wyraźny.
  - Filtr jest fail-closed (pomija niesprawdzone).
  - Brak automatycznego zapisu.
  - Brak ustawienia „moje alergeny” (brak danych zdrowotnych).
  - Zgłoszenie błędu alergenu trafia do istniejącej ścieżki `zgloszenia` (nowy powód „Błędne oznaczenie alergenów” z priorytetem wysokim do rozważenia).
- **Rekomendacja:** krótka opinia prawnika **przed pierwszymi prawdziwymi użytkownikami**. D-333 mówi, że pytań do prawnika nie zadajemy „teraz”, ale to funkcja o skutku zdrowotnym, więc warto dopisać ją do listy w #8 jako pozycję obowiązkową. Ostatnie słowo należy do właściciela.

## 8. Ryzyko „fałszywego poczucia bezpieczeństwa” i komunikacja dla 50+

Główny błąd użytkownika: „brak ostrzeżenia = bezpieczne”. Dlatego:
1. **Brak danych zawsze mówi „nie sprawdzono”, nigdy ciszą.** Na każdym przepisie jest ten sam blok, zawsze widoczny (w MVP przepisy nie mają „zielonych plakietek”).
2. **Nigdy kolor jako jedyny sygnał.** Tekst w pełnych zdaniach, ≥ 18 px, bez ikon jako jedynej informacji, bez dymków i najechania.
3. **Jedno zdanie o etykietach** obok każdej listy, prostym językiem. Bez żargonu prawnego i bez długich regulaminów.
4. **Brak plakietki na listach i kartach** poza aktywnym filtrem (wtedy jedna linia „Autor zaznaczył brak: …”). Plakietka „bez glutenu” na karcie sugerowałaby certyfikat.
5. **Nie obiecujemy śladów ani zanieczyszczeń krzyżowych**, i piszemy to wprost w jednym zdaniu w „Jak to liczymy”-podobnej sekcji „O alergenach” (zwijana, z tekstem, nie jedyne ostrzeżenie).
6. **Test z ludźmi**, jak chce #1902 pkt 5: 5–8 osób 50+ (w tym 2–3 z dietą eliminacyjną) przed włączeniem dla wszystkich. Kryterium: czy rozumieją różnicę między „autor nie zaznaczył” a „nie zawiera”.

## 9. Testy

Feature i jednostkowe (PostgreSQL, wzorce z `docs/PULAPKI_TESTOW.md`, każdy z kontrolą ujemną):
1. **CHECK-i:** wstawienie nieznanego kodu, `allergens` niepusta przy `unchecked`, `declared` bez daty. Test dwóch źródeł: enum = lista w CHECK.
2. **Akcja `OznaczAlergenyPrzepisu`:** tylko autor (Policy `update`, UUID w adresie nie autoryzuje), zaznaczenie bez potwierdzenia nie zapisuje `declared`, deduplikacja i sortowanie.
3. **`PublishRecipe`:** zmiana tekstu składnika `declared` → `needs_review`; zmiana samej wielkości liter nie zmienia; autozapis nie tworzy fałszywego `declared`; import zawsze `unchecked`; „Moja wersja” zaczyna od `unchecked`.
4. **Filtr `SearchQuery`:** przepis `declared` bez alergenu wchodzi; `declared` z alergenem wypada; `unchecked` i `needs_review` wypadają; kilka alergenów naraz (logika „żaden z”); parametr nie zmienia kolejności (strażnik `FeedNieSortujePoMierzeReakcjiTest` zielony); linki „Pokaż więcej” niosą `bez[]`; widoczność i blokady (`widoczneDla`) bez zmian.
5. **Widok:** cztery warianty bloku, brak słów zakazanych (test negatywny słownictwa), brak formy rodzajowej (`WzorceRodzaju`), etykiety widoczne, błąd po polsku przy polu i w podsumowaniu, dane nie znikają po nieudanej walidacji (`old()`), `novalidate` w formularzu (`FormularzeZWalidacjaMajaNovalidateTest`).
6. **Migawka, eksport, API:** oba pola w migawce nowych wersji, brak klucza w starych = „nieznane”; eksport `alergeny` + status; API nigdy nie oddaje samej listy bez statusu.
7. **Rollback:** odmowa przy choć jednym przepisie `declared` i kontrola dodatnia na pustej bazie (D-088).
8. **Prywatność:** test, że filtr nie zapisuje niczego o widzu (brak nowej kolumny w `users`, brak zapisu w sesji, `SEARCH_PERFORMED` bez filtra).
9. **Etap 2 (słownik):** tabela przypadków niejednoznacznych (lista z punktu 4B), fleksja, „nie wykryto” nigdy nie pokazywane.

## 10. Kroki i szacunek wielkości

| # | Krok | Rozmiar |
|---|---|---|
| 0 | Nowa decyzja właściciela (D-xxx) i odblokowanie #1902; decyzje z punktu 11 | S |
| 1 | Enum `Alergen`, migracja z CHECK-ami i odmową `down()`, `docs/DATABASE.md`, testy 1 i 7 | M |
| 2 | Akcja `OznaczAlergenyPrzepisu`, hak w `PublishRecipe` (`needs_review`), migawka, odcisk treści, eksport, API, testy 2, 3, 6 | M |
| 3 | Kreator (krok 2) i formularz bez JS: 14 pól plus potwierdzenie, błędy po polsku | M |
| 4 | Blok na stronie przepisu (4 warianty), test słownictwa i rodzaju | S |
| 5 | Filtr w `SearchQuery` i `SearchController`, formularz w `search.blade.php`, linki „Pokaż więcej”, testy 4 i 8 | M |
| 6 | Regulamin (jedno zdanie), nowy powód zgłoszenia, CHANGELOG, `nowosci/tresc.md`, wersja | S |
| 7 | Test z ludźmi 50+ (przed włączeniem dla wszystkich; flaga konfiguracyjna) | M (głównie organizacyjnie) |
| 8 | Etap 2: słownik podpowiedzi (B) z testami niejednoznacznych nazw | L |
| 9 | Etap 3 (tylko po osobnej decyzji): AI, profil alergii widza, alergeny przy składniku | L |

**MVP (kroki 1–6) razem: M/L** (rzędu jednego tygodnia pracy agenta plus recenzja), bez kroku 7 i 8.

Rekomendowana flaga `KUKING_ALERGENY_WLACZONE` (domyślnie wyłączona, wzorzec `KUKING_IMPORT_*` z D-333), żeby kod można było scalić przed testem z ludźmi i opinią prawną.

## 11. Decyzje dla właściciela

1. **Czy odblokować #1902?** (warunek wszystkiego)
   - a) Tak, tylko MVP z tego dokumentu (kroki 1–6). **Rekomendacja.**
   - b) Tak, MVP plus etap 2 (słownik podpowiedzi).
   - c) Nie, zostaje na liście „V2, ale nie teraz”.
2. **Poziom oznaczania.**
   - a) Na poziomie przepisu (jedno pole, odporne na kasowanie wierszy przy zapisie). **Rekomendacja.**
   - b) Przy każdym składniku (L, wymaga zmiany `syncIngredients`).
   - c) Oba naraz (najdroższe, dwa źródła prawdy).
3. **Jak nazwać stan „niesprawdzony” i czy jest domyślny?**
   - a) „Alergeny: nie sprawdzono”, domyślny dla wszystkich przepisów, także starych. **Rekomendacja.**
   - b) Ukrywać blok, gdy autor nic nie zaznaczył (ryzyko: cisza wygląda jak „bezpieczne”).
4. **Jak zachować się przy zmianie składników po potwierdzeniu?**
   - a) `needs_review`: lista znika z widoku czytelnika do ponownego potwierdzenia. **Rekomendacja.**
   - b) Lista zostaje widoczna z dopiskiem (ryzyko nieaktualnej deklaracji).
   - c) Brak kontroli (najprostsze, najgorsze).
5. **Czy filtr wyklucza przepisy niesprawdzone?**
   - a) Tak, fail-closed, z komunikatem „pominięte”. **Rekomendacja.**
   - b) Nie, pokazuje je z oznaczeniem „nie sprawdzono” (więcej wyników, ale przy 50+ łatwo pomylić).
6. **Profil alergii widza** (zapamiętane „moje alergeny”).
   - a) Nie, filtr bezstanowy w adresie (spójne z D-299 i art. 9 RODO). **Rekomendacja.**
   - b) Tak, zapisany w koncie (wymaga nowej decyzji, podstawy prawnej i zmiany polityki, dane szczególne).
7. **Wykrywanie ze słownika** (etap 2).
   - a) Nie teraz, dopiero po teście z ludźmi. **Rekomendacja.**
   - b) Tak, od razu jako podpowiedź do zatwierdzenia.
   - c) Nigdy.
8. **AI do podpowiedzi alergenów.**
   - a) Nie, blokada D-333 zostaje. **Rekomendacja.**
   - b) Później, po DPA z OpenAI i osobnej decyzji (z limitem i budżetem wg D-297).
9. **Opinia prawnika przed uruchomieniem dla prawdziwych użytkowników.**
   - a) Tak, dopisać do #8 jako pozycję obowiązkową dla tej funkcji. **Rekomendacja.**
   - b) Nie, wystarczy zastrzeżenie w regulaminie i komunikaty z tego dokumentu.
10. **Test z osobami 50+ przed włączeniem.**
    - a) Tak, włączenie flagą po teście. **Rekomendacja.**
    - b) Włączyć od razu, test po fakcie.
11. **Zakres widoczności filtra.**
    - a) Tylko wyszukiwarka przepisów. **Rekomendacja na MVP.**
    - b) Także strony tagów, „Mój stół” i zeszyty (więcej pracy, więcej miejsc do pomyłek).
12. **Nazewnictwo w interfejsie** (zamiast „bezpieczny wybór” z tytułu issue).
    - a) „Bez wskazanych alergenów (według autorów)”. **Rekomendacja.**
    - b) „Bez alergenów” (skrót, ale sugeruje gwarancję; odradzam).
13. **Nowy powód zgłoszenia „Błędne oznaczenie alergenów”** w moderacji.
    - a) Tak. **Rekomendacja.**
    - b) Nie, wystarczy „Napisz do nas” (wolniejsza reakcja na błąd zdrowotny).
14. **JSON-LD / SEO.**
    - a) Nie publikować alergenów w danych strukturalnych (schema.org nie ma wiarygodnego pola, a to claim widoczny w wyszukiwarce). **Rekomendacja.**
    - b) Publikować (odradzam).

## 12. Największe niewiadome (do zweryfikowania przed startem)

- Stan `main` względem lokalnego checkoutu: sprawdzić `PublishRecipe`, `SnapshotRecipeVersion`, `RecipeResource`, `CollectUserExportData` i `SearchQuery::recipes()` oraz numerację migracji.
- Czy powstały już zmiany w `resources/views/components/recipe-wizard.blade.php` (plik duży, Livewire 4) i czy formularz bez JS (`ZapisPrzepisuRequest`) nadal ma te same pola.
- Czy rejestr `WrazliweKolumnyPozaMasowymPrzypisaniemTest` i `tests/mutacje/fillable.txt` wymagają wpisu tym samym kształtem, co dla `kind` wpisu.
- Kluczowe ograniczenie produktowe, którego nie rozstrzyga kod: większość autorów prawdopodobnie zostawi „nie sprawdzono”. Filtr będzie wtedy zwracał mało wyników. To właściwy efekt uczciwości, ale warto uprzedzić właściciela, że wartość funkcji rośnie dopiero z liczbą oznaczonych przepisów (etap 2 z podpowiedziami ma to poprawić).

## Ścieżki, które warto mieć pod ręką

- `/workspace/kuking.pl/AGENTS.md` (§5, §6, §7, §10, §11)
- `/workspace/kuking.pl/docs/FEATURES.md` (V2 i „V2, ale nie teraz”)
- `/workspace/kuking.pl/docs/DATABASE.md` (sekcja `recipe_ingredients`, około linii 2155)
- `/workspace/kuking.pl/app/Domain/Recipes/Actions/PublishRecipe.php` (`syncIngredients`, około linii 903)
- `/workspace/kuking.pl/app/Domain/Recipes/Actions/SnapshotRecipeVersion.php`
- `/workspace/kuking.pl/app/Domain/Recipes/TrescPrzepisu.php`
- `/workspace/kuking.pl/app/Domain/Recipes/Actions/ZrobWlasnaWersje.php`
- `/workspace/kuking.pl/app/Domain/Search/SearchQuery.php` (`recipes()`, `KANDYDACI_SQL`)
- `/workspace/kuking.pl/app/Http/Controllers/SearchController.php`
- `/workspace/kuking.pl/resources/views/pages/search.blade.php`
- `/workspace/kuking.pl/resources/views/pages/recipes/show.blade.php` (lista składników, około linii 629)
- `/workspace/kuking.pl/resources/views/components/recipe-wizard.blade.php` (krok 2)
- `/workspace/kuking.pl/app/Domain/Users/Exports/CollectUserExportData.php`, `/workspace/kuking.pl/app/Http/Resources/Api/V1/RecipeResource.php`
- `/workspace/kuking.pl/docs/research/repos/TandoorRecipes-recipes.md` (§2.7)
- Wpis D-333 i D-331 pochodzą z `docs/DECISIONS.md` na `main` (w lokalnym checkoucie ich nie ma); D-299 i D-284 są w lokalnym `docs/DECISIONS.md` (około linii 18813 i 19420).