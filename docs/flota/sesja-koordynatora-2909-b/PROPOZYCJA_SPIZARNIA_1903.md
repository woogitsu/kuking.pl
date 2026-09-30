# Propozycja wdrożenia #1903 „Inteligentna spiżarnia z terminami ważności i priorytetem zużycia”

Stan źródeł: `origin/main` po `git fetch` (30.09.2026), czytane przez `git show origin/main:<plik>`. Issue #1903 i jego komentarz czytane przez MCP github. W `/workspace/kuking.pl` niczego nie zmieniałem. Issues i komentarzy nie tworzyłem.

Nie czytałem: `docs/UX_50_PLUS.md`, `docs/brand/COPY_STYLE.md`, `GLOS_MARKI.md`, plików widoków `/home` ani istniejących testów pantry (poza nazwami). Teksty po polsku poniżej trzeba więc przepuścić przez COPY_STYLE i test `WzorceRodzaju` przy implementacji.

## 0. Bramka, zanim ktokolwiek napisze kod

- #1903 stoi na liście „V2, ale nie teraz” w `docs/FEATURES.md`. `AGENTS.md` §12 i D-331 nazywają go wprost: „zakazana zostaje spiżarnia z terminami ważności i priorytetem zużycia (#1903) — wymaga nowej decyzji właściciela”. Komentarz właściciela z 26.09 w issue: „funkcja idzie do V2 i teraz jej nie budujemy”.
- Lista „Nie wcześnie” tej funkcji nie dotyczy, ale bramka z „V2, ale nie teraz” obowiązuje. Bez decyzji D1 (pkt 9) poniżej jest tylko plan.
- D-333 wiersz „AI”: „Nie uruchamiamy nic nowego”. Z issue wypadają więc wszystkie punkty z AI/OCR/skanem kodu kreskowego: normalizacja nazw przez model, „pewność sugestii AI”, OCR paragonu. Zostaje ręczne wpisywanie i istniejąca reguła rdzeni w bazie (`kuking_rdzenie_skladnika`, bez AI).
- Serwis nie ma jeszcze prawdziwych użytkowników (D-333). Nie ma więc żadnego pomiaru, że ktokolwiek używa samego „Co mam w domu”. Issue samo pisze, że przed implementacją warto zrobić test z osobami gotującymi. Dlatego MVP jest małe i cofalne, a etap 2 ma warunek wejścia (pkt 7).
- Nie nazywamy tego w interfejsie „inteligentną spiżarnią”. „Inteligentna” obiecuje AI, którego nie ma. Nazwa ekranu: „Zużyj w pierwszej kolejności”. Wejście zostaje w „Co mam w domu”.

## 1. Co już jest w kodzie (punkty zaczepienia)

| Obszar | Stan na `origin/main` |
|---|---|
| Tabela | `pantry_items`: `id` uuid, `user_id` (FK CASCADE), `name` (2–120), `created_at`, kolumny GENEROWANE `rdzenie text[]` i `klucz` z `kuking_rdzenie_skladnika(name)`. CHECK `pantry_items_name_check`, `UNIQUE (user_id, klucz)`. Migracja `2026_09_28_233700_create_pantry_items_table`, `down()` odmawia przy niepustej tabeli (`KUKING_ROLLBACK_KASUJE_SPIZARNIE=1`). |
| Model | `App\Models\PantryItem`: `UPDATED_AT = null`, `$fillable = ['name']`, `user_id` tylko przez relację `$user->pantryItems()`. |
| Domena | `App\Domain\Pantry\CoMamWDomu` (dodaj, limit 150 pod `lockForUpdate()` na `users`), `CoUgotuje` (dopasowanie + `REGULA`), `PodpowiedziSkladnikow`. |
| Trasy | `/co-mam-w-domu` (GET `pantry.index`, POST `pantry.store`, DELETE `pantry.destroy`, GET `pantry.suggestions`), `/co-ugotuje` (`pantry.cook`). Kontroler `PantryController`, `PantryItemPolicy` (tylko `delete`, tylko właściciel, bez wyjątku dla moderatora). |
| Widoki | `pages/pantry/index.blade.php`, `co-ugotuje.blade.php`, `resources/js/co-mam-w-domu.js` (podpowiedzi jako przyciski, bez skryptu wszystko działa). |
| Paczka danych | `InwentarzDanychKonta`: `'pantry_items.user_id' => [EKSPORT, 'co_mam_w_domu']`. `CollectUserExportData::pantry()` zwraca dziś tylko `produkt` i `dodano`. Wymazanie konta: `EraseAccountData` robi `$fresh->pantryItems()->delete()`. |
| Lista zakupów | `shopping_list_items`, `ListaZakupow`, trasy `/lista-zakupow`, `/przepisy/{recipe}/lista-zakupow`. Składniki kopiowane dosłownie jako tekst, bez sumowania (D-333). Limity `kuking.zakupy.*`. |
| Planer | `meal_plan_entries`, `PlanerTygodnia`, `/planer`. Wpis planu = przepis + dzień. Planer nie liczy zapasów i nie powinien. |
| Dopasowanie do przepisów | `/co-ugotuje` już istnieje. Kolejność: najmniej brakujących składników, potem najkrótszy czas (`CoUgotuje::REGULA`). Nie zależy od reakcji innych ludzi, pilnuje tego `FeedNieSortujePoMierzeReakcjiTest` skanujący `app/Domain/Pantry`. |
| Powiadomienia | `NotifyUser` to jedyne wejście. W serwisie żaden przełącznik nie wycisza powiadomień. Ustawienia są tylko dla kanałów zewnętrznych (D-303): push per urządzenie, cisza nocna 21–8, dzienny limit domyślnie 1. Push idzie wyłącznie dla `KanalPush::TYPY` (cooked, reply, odpowiedź na pytanie), czyli odzewów na własne treści. |
| Digest | Osobna zgoda `users.wants_weekly_digest`. Treść to zamknięta lista w `TrescDigestu` (ugotowali z Twojego przepisu, nowi obserwujący, wpisy obserwowanych). Wysyłka na produkcji wyłączona do poprawki #2237. |
| Wzorzec wpisywania dat bez kalendarza | `pages/settings/birthday.blade.php`: dwa `<select>` (dzień, miesiąc z nazwami), `novalidate`, `x-error-summary`, `old()`, działa bez JS. |
| Wzorzec cichego bloku na `/home` | Wspomnienia, urodziny, rocznica: jedno zdanie, pojawia się tylko gdy jest co pokazać. |

## 2. MVP (etap 1)

Cel: człowiek wpisuje do produktu termin z opakowania, widzi u góry listy, co zużyć najpierw, i może zobaczyć przepisy, które to zużywają. Bez AI, bez powiadomień, bez nowej tabeli.

**Zakres etapu 1:**
1. Do istniejącego wiersza `pantry_items` dochodzą cztery opcjonalne pola: termin, rodzaj terminu, ilość jako tekst, flaga „mrożone”.
2. Ekran „Ustaw termin” (osobna strona, zwykły formularz).
3. Lista „Co mam w domu” pogrupowana: „Zużyj w pierwszej kolejności”, „Później”, „Bez terminu”, „Mrożone”.
4. `/co-ugotuje?najpierw=termin` — ten sam mechanizm dopasowania, inna kolejność (domyślny widok bez zmian).
5. Cichy blok na `/home`, gdy są produkty do zużycia w 3 dni.
6. Eksport i wymazanie konta obejmują nowe pola.

**Poza MVP:** powiadomienia, e-mail, push, „zużyte/wyrzucone”, AI/OCR/kod kreskowy, ilości liczbowe i jednostki, odejmowanie ilości przy planie, alergeny (#1902 zostaje zakazany), wspólna spiżarnia domowników.

## 3. Schemat danych (migracja wg §6, rollback wg D-088)

Nowa migracja na istniejącej tabeli, np. `2026_10_01_090000_add_expiry_to_pantry_items.php`, z `public $withinTransaction = false;`. Kolumny:

```sql
ALTER TABLE pantry_items
  ADD COLUMN IF NOT EXISTS expires_on   date,
  ADD COLUMN IF NOT EXISTS expiry_kind  varchar(12),
  ADD COLUMN IF NOT EXISTS quantity_note varchar(40),
  ADD COLUMN IF NOT EXISTS frozen       boolean NOT NULL DEFAULT false;
```

Kolumny nullable albo stały DEFAULT to zmiana samych metadanych w PostgreSQL 11+, bez przepisywania tabeli. `lock_timeout = 5s` ustawia migrator sam.

CHECK-i dodawane wg §6: `ADD CONSTRAINT … NOT VALID`, potem osobno `VALIDATE CONSTRAINT`:
- `pantry_items_expiry_kind_check`: `expiry_kind IS NULL OR expiry_kind IN ('use_by','best_before')`
- `pantry_items_expiry_pair_check`: `(expires_on IS NULL) = (expiry_kind IS NULL)` — nie ma terminu bez rodzaju i odwrotnie
- `pantry_items_expires_on_range_check`: `expires_on IS NULL OR expires_on BETWEEN DATE '2020-01-01' AND DATE '2100-12-31'` — stałe, bez `now()`, żeby CHECK był niezmienny
- `pantry_items_quantity_note_check`: `quantity_note IS NULL OR char_length(btrim(quantity_note)) BETWEEN 1 AND 40`

Decyzje schematowe:
- `date`, nie `timestamptz`: termin z opakowania to dzień kalendarzowy bez strefy. Jedyna strefa wchodzi przy pytaniu „czy dziś”: liczymy datę w `Europe/Warsaw` (jak Urodziny/Rocznice) i podajemy ją jako parametr SQL. Dzięki temu pada problem stref z punktu 5 issue.
- Nowy indeks na MVP niepotrzebny: lista ma najwyżej 150 pozycji na konto i zawsze jest filtrowana po `user_id` (istniejący `UNIQUE (user_id, klucz)` wystarcza). Indeks `(expires_on) WHERE expires_on IS NOT NULL AND NOT frozen` (`CREATE INDEX CONCURRENTLY`, z zdjęciem INVALID przed budową) dołączamy dopiero, gdyby pojawiło się zadanie skanujące wszystkie konta, czyli przypomnienia w etapie 2.
- Kolumny generowane `rdzenie`/`klucz` niezmienione, więc dopasowanie i unikalność działają jak dotąd.
- `$fillable`: dopisać tylko `quantity_note`. Termin, rodzaj i `frozen` ustawia nazwana akcja domenowa (`ZmienTerminProduktu`), nie masowe przypisanie. Uruchomić `WrazliweKolumnyPozaMasowymPrzypisaniemTest`, bo liczy kolumny ze schematu, a nie z listy.
- Casty: `expires_on` jako `immutable_date`, `frozen` jako boolean.

**`down()` (D-088, odmowa wąska):** odmawia wyłącznie wtedy, gdy istnieje wiersz z `expires_on IS NOT NULL OR quantity_note IS NOT NULL OR frozen` (dane wpisane przez człowieka, `up()` ich nie odtworzy). Komunikat mówi, co zrobić: kopia `CREATE TABLE pantry_items_terminy_kopia AS SELECT id, expires_on, expiry_kind, quantity_note, frozen FROM pantry_items WHERE …`, potem ponowne uruchomienie z `KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1` (`getenv()`, nie `env()`). Na pustej i świeżej bazie przechodzi bez pytania. Potem zdjęcie CHECK-ów i kolumn. Wzorzec: istniejąca migracja pantry i `CofniecieMigracjiNieKasujeListCoMamWDomuTest`.

**Dokumentacja:** nowy podpunkt w sekcji `pantry_items` w `docs/DATABASE.md`: kolumny, CHECK-i, brak indeksu z uzasadnieniem, rollback.

**Strażnicy, które muszą przejść bez wyjątków:** `MigracjeMajaLimitBlokadTest`, `NoweMigracjeTrzymajaSieParagrafu6Test`.

## 4. Reguły priorytetu (jawne, pokazane na ekranie)

Dziś = data w `Europe/Warsaw`. Konfiguracja w `config/kuking.php`: `pantry.pilne_dni = 3`.

Grupy, w tej kolejności (klasa `App\Domain\Pantry\PriorytetZuzycia`, jedno źródło reguły, tak jak `CoUgotuje::REGULA`):
1. **Termin minął**: `expires_on < dziś`, nie mrożone.
2. **Do 3 dni**: `dziś ≤ expires_on ≤ dziś + 3`, nie mrożone.
   Grupy 1 i 2 razem tworzą sekcję „Zużyj w pierwszej kolejności”. Wewnątrz: `expires_on` rosnąco, przy remisie „Należy zużyć do” przed „Najlepiej spożyć przed”, potem `name`, `id`.
3. **Później**: termin dalszy niż 3 dni, nie mrożone, sortowane po terminie.
4. **Bez terminu**: alfabetycznie. Nigdy nie opisujemy ich jako świeżych ani bezpiecznych.
5. **Mrożone**: osobna sekcja, bez pilności. Zamrożenie nie zmienia wpisanego terminu, tylko wyłącza z pilnych.

Zdanie z regułą na ekranie: „Na górze są produkty z terminem, który minął albo upływa w ciągu 3 dni, od najwcześniejszego. Niżej te z dalszym terminem, potem bez terminu. Kolejność ustawia tylko data, którą wpisujesz Ty.”

„Najpierw to, co się psuje” w `/co-ugotuje?najpierw=termin`: ta sama baza zapytania co `CoUgotuje::dla()`, z nowym pierwszym kluczem sortowania: liczba Twoich produktów z grup 1–2, które pasują do składników przepisu (malejąco). Dalej bez zmian: najmniej brakujących, najkrótszy czas, `published_at`, `id`. Produkty pilne liczymy podzapytaniem `EXISTS … p.rdzenie <@ kuking_rdzenie_skladnika(ri.ingredient_text)` po `p.user_id = ? AND p.expires_on <= ?`, z pominięciem mrożonych. Przy każdym przepisie zdanie „Zużyjesz: mleko (do 2 października), szynka”. Zdanie z regułą dopisać do `REGULA` dla tego trybu.

Uwaga na §8: zamknięta lista kryteriów doboru mówi o treściach społeczności (czas, równość autorów, wybór gospodarza, bramki, jawne polecenia widza). Dobór po własnej, wpisanej przez widza dacie nie zależy od niczyich reakcji, więc jest w duchu reguły i w jednej linii z D-285 (dobór po własnej liście). Literalnie jednak data z pola nie jest „jawnym poleceniem” z listy, więc rekomenduję zapisać to wprost w nowym wpisie DECISIONS i poprosić właściciela o potwierdzenie (D7). Do wpisu dołożyć strażnika: `FeedNieSortujePoMierzeReakcjiTest` już skanuje `app/Domain/Pantry`, dopisać tam nowe wyrażenie sortowania do rejestru. Sprawdzić też, czy D-285 dopisał zdanie w `JakDobieramyWpisy` dla `/co-ugotuje`. Nie sprawdzałem, a §8 wymaga tego przy każdej nowej regule doboru.

## 5. Ekrany i UX 50+

Zasady stałe: tekst ≥ 18 px, przyciski ≥ 48 px, etykiety widoczne, `novalidate` (D-333 „novalidate wszędzie”) plus `x-error-summary` plus błąd przy polu, `old()`, stan niesie tekst, nie sam kolor, bez hover i swipe, `<noscript>` niepotrzebny, bo wszystko działa bez JS.

**a) `/co-mam-w-domu` (zmieniony).** Formularz dodawania bez zmian. Po dodaniu komunikat z linkiem „Ustaw termin”. Pod spodem sekcje z nagłówkami jak w pkt 4. Karta produktu: nazwa, ilość (jeśli jest), linia stanu słowami, przyciski „Ustaw termin” albo „Zmień termin” oraz „Usuń” (przycisk z `aria-label` z nazwą produktu, jak dziś). Usunięcie nadal bez potwierdzenia: jedna linijka własnej listy, dopisywana jednym polem.

**b) `/co-mam-w-domu/{pantryItem}/termin` (nowy, GET `pantry.edit`, PUT `pantry.update`).** Jeden formularz:
- grupa radio „Jaki to termin?”: „Należy zużyć do (termin przydatności)”, „Najlepiej spożyć przed”, „Nie znam terminu”;
- szybkie przyciski submit „Za 3 dni”, „Za tydzień”, „Za 2 tygodnie”, „Za miesiąc” (`name="za"`, data liczona na serwerze od dziś, działają bez JS);
- albo trzy listy wyboru Dzień / Miesiąc (z nazwami) / Rok, jak w `birthday.blade.php`; rok od poprzedniego do dziś + 5, domyślnie bieżący;
- pole „Ilość (nie trzeba)” jako zwykły tekst, np. „pół kostki”;
- pole wyboru „Mam to w zamrażarce”;
- „Zapisz”, „Wyczyść termin”, link „Wróć do listy” (bez potwierdzenia: to nic nieodwracalnego).

Terminy z przeszłości wolno wpisać, bo ktoś dopisuje produkt już po terminie. Serwer odrzuca tylko daty nieistniejące.

**c) `/home`:** jedno zdanie tylko gdy są produkty w grupach 1–2: „Do zużycia w ciągu 3 dni: mleko, szynka i jeszcze 2 produkty.” plus przycisk „Zobacz, co ugotować”. Bez licznika czerwonego, bez ikon.

**Teksty po polsku (do przeglądu wg COPY_STYLE, forma neutralna rodzajowo, D-332):**

| Miejsce | Tekst |
|---|---|
| Nagłówek sekcji | „Zużyj w pierwszej kolejności” |
| Pusta sekcja | „Nic nie wymaga pilnego zużycia. Dodaj terminy do produktów, a pokażemy je tutaj.” |
| Stan | „Termin minął 2 dni temu. Sprawdź produkt przed użyciem albo usuń go z listy.” · „Termin dziś.” · „Termin za 2 dni (3 października).” · „Bez terminu.” · „W zamrażarce.” |
| Rodzaj terminu | „Należy zużyć do” / „Najlepiej spożyć przed” |
| Uwaga pod formularzem | „Termin to Twoja notatka z opakowania. Kuking nie ocenia, czy produkt nadaje się do jedzenia. Sprawdź go przed użyciem.” |
| Błąd daty | „W tym miesiącu nie ma takiego dnia. Wybierz inny dzień albo miesiąc.” |
| Błąd braku rodzaju | „Zaznacz, jaki to termin: „Należy zużyć do” albo „Najlepiej spożyć przed”, albo wybierz „Nie znam terminu”.” |
| Błąd ilości | „Skróć opis ilości do 40 znaków, na przykład „pół kostki” albo „1 litr”.” |
| Sukces | „Zapisano termin: mleko, do 3 października.” |
| Reguła (pod listą) | zdanie z pkt 4 |
| `/co-ugotuje` tryb | przycisk „Najpierw to, co się psuje”, nagłówek „Przepisy na produkty z krótkim terminem” |
| Brak dopasowań | „Żaden przepis nie pasuje do produktów z krótkim terminem. Zobacz wszystkie propozycje.” |

Czego nie mówimy: „świeże”, „bezpieczne”, „zepsute”, „marnujesz jedzenie”, „uratuj”, bez wyrzutów sumienia i bez gamifikacji (AGENTS §12: streaki i punkty zakazane).

## 6. Prywatność (dane gospodarstwa domowego)

- Policy: dopisać `update` (i `edit`) do `PantryItemPolicy`, tylko właściciel, bez moderatora. UUID w adresie nie autoryzuje. Trasy `->whereUuid('pantryItem')`, ten sam koszyk limitów co `pantry.store`. Test z cudzym UUID daje 403.
- Terminy i ilości nie trafiają nigdzie poza konto właściciela: nie są w profilu, wyszukiwarce, feedzie, digeście ani powiadomieniach publicznych.
- Eksport: `CollectUserExportData::pantry()` dopisać `termin` (data), `rodzaj_terminu`, `ilosc`, `mrozone`. Nie ruszać `WersjaFormatuPaczki::AKTUALNA`: zmienia się tylko sekcja, której importer nie czyta. Sprawdzić, czy `InwentarzDanychKonta` ma test na poziomie kolumn, bo dochodzą cztery.
- Wymazanie konta i retencja: `EraseAccountData` już kasuje cały `pantry_items`. Nowych tabel nie ma, więc nie ma nic nowego do kasowania. Produkty po terminie nie znikają same (człowiek decyduje), co jest świadome: nic nie kasujemy po cichu.
- Dokumenty: sprawdzić, czy polityka prywatności i rejestr czynności przetwarzania opisują „Co mam w domu”. Dopisanie „terminów i ilości” to najpewniej poprawka drobna, bo serwis nie ma prawdziwych użytkowników (wzór D-333 z 29.09). Do decyzji właściciela przy wydaniu, ustawić jawnie `zmiana_*.istotna`.
- Analityka (pomiar z punktu 6 issue): własna serwerowa, zdarzenia `snake_case`, np. `pantry_expiry_set`, `pantry_priority_viewed`, `pantry_cook_priority_opened`, `pantry_item_removed`. Bez nazw produktów, bez dat, bez identyfikacji poza tym, co analityka już robi. Metryki na start: odsetek kont z co najmniej jednym terminem, odsetek wejść w „Zużyj w pierwszej kolejności”, liczba wejść w przepisy z trybu „najpierw termin”. Nie mierzymy odsłon jako celu (AGENTS §1, retencja > pageviews).

## 7. Powiadomienia i przypomnienia: jak bez spamu

Rekomendacja: w MVP bez żadnych powiadomień. Uzasadnienie z kodu i decyzji:

1. W serwisie nie ma wyłączników powiadomień (AGENTS §1, D-303). Cykliczne „twoje mleko się kończy” nie mogłoby być wyciszone przez użytkownika. To jest definicja spamu, a wyłącznik wymagałby zmiany AGENTS §1 i `UgotowalemZawszePowiadamiaAutoraTest`.
2. Push idzie tylko dla odzewów na własne treści (D-303 pkt 5, `KanalPush::TYPY`). Przypomnienie o terminie to nie odzew. Do tego domyślny dzienny limit pushy to 1, więc przypomnienie o mleku konkurowałoby o jedyny slot z najcenniejszym powiadomieniem w serwisie, czyli „Ugotowałem” od innej osoby. To argument przeciw pushowi bez względu na D-303.
3. RETENTION_LOOPS §1: „powrót ma mieć powód, nie przypomnienie”. E-maile nietransakcyjne: co najwyżej 1 tygodniowo na typ i 2 łącznie. Digest jest na produkcji jeszcze wyłączony (#2237).
4. E-mail z listą zawartości lodówki trafia często do wspólnej skrzynki domowników, co jest gorsze prywatnościowo niż ekran.

Zamiast tego: pasywny blok na `/home` i sekcja na liście (pull, nie push). Jeśli właściciel zechce przypomnień (etap 2, opcja B w D4): osobna, domyślnie wyłączona zgoda „Sobotnie przypomnienie o produktach do zużycia”, jeden list tygodniowo, tylko gdy jest co wymienić (pusty nie wychodzi, reguła z digestu), zapis w `dziennik_zgod`, dodatkowy indeks częściowy `CONCURRENTLY`, własny sufit w `DziennyBudzetListow`, deduplikacja przez `PrzypomnienieDobowe`. Push dla tej funkcji wymaga zmiany D-303 pkt 5 i `KanalPush::TYPY`, więc osobnej decyzji i nie polecam.

Wyścigi i strefy, zgodnie z punktem 5 issue:
- „dziś” liczone raz na żądanie w `Europe/Warsaw`; test graniczny na północy (23:59 i 00:01 czasu polskiego) i na zmianie czasu.
- Edycja terminu równolegle z usunięciem: drugie żądanie dostaje 404 i komunikat po polsku „Tego produktu już nie ma na liście.” Dwie edycje naraz: wygrywa ostatnia, bez utraty danych innych pól (UPDATE wyłącznie kolumn z formularza).
- Limit 150 zostaje egzekwowany istniejącą blokadą w `CoMamWDomu::dodaj()`; edycja terminu limitu nie dotyka.

## 8. Lista zakupów i planer

- Bez zmian w MVP. `shopping_list_items` to wolny tekst, `pantry_items` też, kolumna `quantity_note` jest wolnym tekstem z tego samego powodu co `ingredient_text` (D-333): liczby i jednostki wymagałyby parsera, konwersji i potwierdzania wyniku.
- Etap 2, tani: na stronie potwierdzenia „Dodaj składniki” (`shopping.recipe.confirm`) przy liniach, które pasują do listy „Co mam w domu” (ten sam `MAM_SQL`), dopisek „Masz to w domu”. Tylko etykieta, pozycja nadal jest kopiowana dosłownie (nie zmieniamy D-333), człowiek sam decyduje. Przepis niewidoczny dla osoby dalej nie ujawnia treści (istniejąca reguła).
- Nie robimy w żadnym etapie: automatycznego „kupione → do spiżarni” (tekst „2 cebule” nie jest nazwą produktu, a potwierdzanie każdej pozycji to dokładnie ręczny koszt, który issue chce ograniczyć), odejmowania ilości po „Ugotowałem” ani po dodaniu do planu (zaplanowanie nie tworzy wykonania, ta zasada jest w dokumencie #27).
- Planer: z `/co-ugotuje?najpierw=termin` użyć istniejących wejść „Dodaj do planu” z karty przepisu. Nowego kodu planera w MVP nie ma.

## 9. Decyzje dla właściciela

**D1. Czy odblokować #1903 z listy „V2, ale nie teraz”?** (wymagane przez AGENTS §12 i D-331)
- A. Nie teraz, czekać na użycie samego „Co mam w domu” w alfie. Koszt: zero. Ryzyko: funkcja zostaje w backlogu.
- B. Tak, tylko MVP etapu 1 z tego dokumentu (bez AI, bez powiadomień), etap 2 po pomiarze.
- C. Tak, całość z issue razem z przypomnieniami.
- **Rekomendacja: B.** Zmiana jest addytywna, cofalna i M. Warunek przejścia do etapu 2 po 4 tygodniach alfy: co najmniej 30% kont, które mają listę, ustawiło choć jeden termin. Jeśli mniej, zatrzymać. A jest uczciwym wyborem, gdy priorytetem jest pomiar, bo nie mamy żadnego sygnału o popycie.

**D2. Ilość i jednostka.**
- A. Wolny tekst do 40 znaków, bez liczenia (rekomendacja, spójne z listą zakupów i składnikami).
- B. Liczba + jednostka ze słownika (g, ml, szt., opak.): wymaga konwersji, walidacji i z czasem pokusy AI do normalizacji.
- C. Bez ilości.
- **Rekomendacja: A.**

**D3. Rodzaje terminu.**
- A. Dwa: „Należy zużyć do” i „Najlepiej spożyć przed” (rekomendacja; odpowiada opakowaniom, priorytet liczony jednakowo).
- B. Jeden „termin”, prościej, ale gubi różnicę, która na opakowaniu jest prawnie istotna.
- C. Dodatkowo przedział „od–do” z issue: więcej pól i wyjątków, mała korzyść.
- **Rekomendacja: A.**

**D4. Powiadomienia o terminach.**
- A. Żadnych, tylko pasywne sekcje i blok na `/home` (rekomendacja).
- B. Sobotni e-mail za osobną zgodą, 1 tygodniowo, pusty nie wychodzi (etap 2, L: zgoda, dziennik zgód, sufit, polityka prywatności).
- C. Web Push. Wymaga zmiany D-303 pkt 5 i konkuruje o limit 1/dzień z „Ugotowałem”.
- **Rekomendacja: A w MVP, B tylko jeśli etap 2 pokaże, że ludzie ustawiają terminy, a nie wracają.**

**D5. Blok na `/home`.**
- A. Brak.
- B. Jedno zdanie, tylko gdy są produkty do zużycia w 3 dni, bez przełącznika (rekomendacja).
- C. Jak B plus wyłącznik w `/ustawienia/prywatnosc` (kolumna na `users`, `down()` odmawia wg D-088).
- **Rekomendacja: B.** Jeśli właściciel woli wyłącznik jak przy Wspomnieniach, C dodaje S.

**D6. „Zużyte” i „wyrzucone” z issue.**
- A. Zostaje samo „Usuń” (rekomendacja w MVP; brak metryki marnowania, którą issue chce, ale też brak tonu oceniającego w marce „spokojnej”).
- B. Dwa przyciski „Zużyte” i „Już tego nie mam” z anonimowym zdarzeniem w analityce, bez zapisu w tabeli.
- C. Historia w bazie (zamknięcie wiersza). Wymaga zmiany `UNIQUE (user_id, klucz)` na indeks częściowy, retencji i polityki prywatności, więc L i nie teraz.
- **Rekomendacja: A w MVP, B w etapie 2, jeśli potrzebny jest pomiar „odsetka zużytych” z issue.**

**D7. Wpływ na kolejność przepisów w `/co-ugotuje` i wykładnia §8.**
- A. Osobny tryb `?najpierw=termin`, domyślna kolejność D-285 bez zmian (rekomendacja).
- B. Zmienić domyślną kolejność na „najpierw produkty z terminem”.
- C. Tylko oznaczenia przy przepisach, bez zmiany kolejności.
- **Rekomendacja: A, a w nowym wpisie DECISIONS zapisać wprost, że dobór po własnej wpisanej dacie jest dozwolony jak dobór po własnej liście w D-285** (nie zależy od niczyich reakcji). To wymaga potwierdzenia właściciela, bo literalnie nie ma tego w zamkniętej liście z §8.

**D8. Mrożone w MVP.**
- A. Tak, flaga `frozen` wyłącza produkt z pilnych (rekomendacja, koszt S, bez niej mrożony kurczak z terminem sprzed miesiąca zalewa górę listy).
- B. Nie; to sygnał, żeby nie ufać terminom.
- **Rekomendacja: A.**

**D9. Skan kodu kreskowego i OCR paragonu z issue.**
- A. Nie budować (rekomendacja; D-333 „AI — nic nowego”, a kod kreskowy wymaga zewnętrznej bazy produktów, czyli przesyłania zapytań o zawartość kuchni na zewnątrz).
- B. Po DPA i osobnej decyzji prywatności.
- **Rekomendacja: A.**

**D10. „Masz to w domu” na potwierdzeniu listy zakupów (etap 2).**
- A. Tak, tylko etykieta (rekomendacja, S).
- B. Nie, żeby nie zmieniać D-333.
- **Rekomendacja: A, po udanym etapie 1.**

## 10. Testy (PostgreSQL, nie SQLite; CI na 18)

Nowe lub rozszerzone:
- `PriorytetZuzyciaTest`: granice grup (wczoraj / dziś / dziś+3 / dziś+4), remis dat (use_by przed best_before), mrożone poza pilnymi, brak terminu nigdy w „pilnych”, kolejność stabilna po `id`.
- `PriorytetZuzyciaStrefaTest`: „dziś” w `Europe/Warsaw` o 23:59 i 00:01, zmiana czasu wiosną i jesienią (`Carbon::setTestNow`).
- `TerminProduktuTest` (HTTP): ustawienie z list wyboru, z szybkich przycisków, wyczyszczenie, 31 lutego daje błąd przy polu i w podsumowaniu, `old()` zachowane, brak rodzaju przy dacie, ilość > 40 znaków.
- `PantryItemPolicyTest`: cudzy UUID → 403, moderator → 403, gość → przekierowanie.
- `MigracjaTerminowSpizarniTest`: CHECK-i odrzucają `expires_on` bez `expiry_kind`, zły rodzaj, datę spoza zakresu, pustą ilość.
- `CofniecieMigracjiNieKasujeTerminowSpizarniTest`: odmowa przy danych, kontrola dodatnia na pustej, wymuszenie zmienną środowiskową (wzór: `CofniecieMigracjiNieKasujeListCoMamWDomuTest`).
- `CoUgotujeNajpierwTerminTest`: przepis z dwoma pilnymi produktami przed przepisem z jednym; domyślny widok nie zmienia kolejności; zdanie „Zużyjesz: …” zawiera tylko produkty właściciela; widoczność przepisów jak dziś (`published()` przed `widoczneDla()`, blokady); wynik bez wpływu „Ugotowałem” i zapisów (rozszerzenie strażnika `FeedNieSortujePoMierzeReakcjiTest`).
- `BlokPilnychNaHomeTest`: pojawia się tylko gdy są pilne produkty, nie pokazuje cudzych danych, nie pokazuje mrożonych.
- Eksport: `DataExportTest` (nowe pola w sekcji `co_mam_w_domu`), test wymazania konta (brak wierszy po `EraseAccountData`).
- Istniejące, które muszą dalej przechodzić: `CoMamWDomuTest`, `CoUgotujeTest`, `PantryLimitNaDwochPolaczeniachTest`, `WrazliweKolumnyPozaMasowymPrzypisaniemTest`, `FormularzeZWalidacjaMajaNovalidateTest`, strażnik rodzaju (`WzorceRodzaju`).
- Testy JS: nie są potrzebne, bo funkcja działa bez skryptu. Nie dokładać nowego JS w MVP.
- Przed PR: `./scripts/check.sh`. Lokalnie kontener ma PostgreSQL 16, więc test wersji oblewa środowiskowo, a rozstrzyga CI na 18. Rozstrzygnąć `EXPLAIN` zapytania trybu „najpierw termin” na kilkuset przepisach i podać `SELECT version()` w opisie PR.

## 11. Dokumenty i zmiana widoczna dla człowieka

- `docs/DECISIONS.md`: nowy wpis (następny wolny numer), „Spiżarnia z terminami — etap 1”, z odwołaniem do D-285, D-303, D-333 i D-331; zapisuje D1–D10 w wersji przyjętej przez właściciela.
- `docs/FEATURES.md`: przenieść #1903 z „V2, ale nie teraz” do wdrożonych V2 (analogicznie do wpisu o #2227). `AGENTS.md` §12: zmienić zdanie o „Zakazana zostaje spiżarnia z terminami…” (tylko na polecenie właściciela, AGENTS.md jest jedynym źródłem prawdy).
- `docs/DATABASE.md`: pkt 3.
- `CHANGELOG.md`: wpis z `[nowa funkcja]` plus akapit w „Najnowsze zmiany” w `resources/nowosci/tresc.md` (pilnuje `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`), podbicie dużego numeru wersji w `config/kuking.php` (nowy ekran).

## 12. Szacunki i kolejność

| Element | Rozmiar |
|---|---|
| Decyzje i dokumenty (DECISIONS, FEATURES, AGENTS §12, CHANGELOG, nowości) | S |
| Migracja, model, rollback z odmową, `DATABASE.md`, testy schematu | S |
| Ekran „Ustaw termin”, akcja domenowa, Policy, walidacja, teksty | M |
| Lista pogrupowana + `PriorytetZuzycia` + zdanie reguły | M |
| Tryb `?najpierw=termin` w `CoUgotuje` + `EXPLAIN` + strażnik | M |
| Blok na `/home` | S |
| Eksport, wymazanie, inwentarz, polityka prywatności (drobna) | S |
| **Razem MVP etapu 1** | **M/L, dwa PR-y: PR 1 schemat + ekran + lista (M), PR 2 tryb przepisów + `/home` + eksport (M)** |
| Etap 2: „Masz to w domu” na liście zakupów | S |
| Etap 2: „Zużyte / Już tego nie mam” jako zdarzenie analityki | S |
| Etap 2: sobotni e-mail opt-in | L |
| Push, historia zamknięć, liczby i jednostki, kod kreskowy/OCR | L każdy, nie polecam |

## 13. Ryzyka do zapisania w opisie PR

- Brak użytkowników i brak pomiaru popytu: dlatego warunek 30% przed etapem 2.
- Ryzyko prawne/wizerunkowe „bezpieczne do jedzenia”: stała uwaga pod formularzem, brak słów „świeże/bezpieczne”, termin przedstawiony jako notatka właściciela; teksty do przeglądu przez właściciela (i prawnika w ramach #8).
- §8: wykładnia doboru po własnej dacie (D7).
- Wydajność zapytania trybu „najpierw termin”: podzapytania korelowane na 150 produktach i setkach przepisów, do zmierzenia `EXPLAIN` przed scaleniem.
- Wycofanie: zdjąć trasy `pantry.edit`/`pantry.update`, tryb w `/co-ugotuje` i blok na `/home` bez ruszania bazy. Kolumny zostają (bezpiecznie); cofnięcie schematu odmawia przy wpisanych terminach (D-088).

Najważniejsze pliki w repozytorium (odczyt): `AGENTS.md`, `docs/FEATURES.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` (D-285, D-303, D-331, D-333), `database/migrations/2026_09_28_233700_create_pantry_items_table.php`, `database/migrations/2026_09_30_090000_create_shopping_list_items_table.php`, `app/Models/PantryItem.php`, `app/Domain/Pantry/CoMamWDomu.php`, `app/Domain/Pantry/CoUgotuje.php`, `app/Http/Controllers/PantryController.php`, `app/Policies/PantryItemPolicy.php`, `resources/views/pages/pantry/index.blade.php`, `resources/views/pages/settings/birthday.blade.php`, `app/Domain/Users/Exports/CollectUserExportData.php`, `app/Domain/Users/Exports/InwentarzDanychKonta.php`, `app/Domain/Notifications/Actions/NotifyUser.php`, `app/Domain/Notifications/Push/KanalPush.php`, `app/Domain/Digest/TrescDigestu.php`, `app/Domain/Zakupy/ListaZakupow.php`, `app/Domain/Planer/PlanerTygodnia.php`.