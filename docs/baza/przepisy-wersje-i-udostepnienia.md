# Wersje, udostępnienia i odzyskiwanie szkicu

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### recipe_versions
Snapshot po istotnych zmianach.

- `recipe_id`, `editor_id` (`ON DELETE RESTRICT` — wersji nie wolno osierocić
  przez skasowanie konta edytora);
- `version_number integer` (CHECK `> 0`, `UNIQUE(recipe_id, version_number)`) —
  numer kolejny w obrębie jednego przepisu, nie w całym serwisie;
- `snapshot jsonb NOT NULL` — pełna treść przepisu w chwili zapisu, składana
  przez `App\Domain\Recipes\Actions\SnapshotRecipeVersion` (tytuł, opis,
  czasy, wszystkie cztery kolumny pochodzenia i `family_since_year`,
  składniki z `no_amount`, kroki). `source_url` i `ingredients[].no_amount` są
  w migawce od issue #896 — **w starszych migawkach tych kluczy nie ma
  i brak znaczy „nieznane"**; nie uzupełniamy ich dzisiejszą wartością
  z przepisu. Numer wersji i migawka powstają w transakcji zapisu treści,
  pod blokadą wiersza `recipes` (issue #895). Zmiana kształtu JSON, nie
  schematu — bez migracji;
- `change_note varchar(500) NULL` — **wolny tekst od człowieka**: czym ta
  wersja różni się od poprzedniej. `NULL` znaczy „nic nie napisał" i jest
  stanem normalnym;
- `created_at`.

Porównanie dwóch migawek (#2451) sprawdza również **widoczną kolejność**
grup i składników po zastosowaniu `GrupySkladnikow::ulozyc()`. Porównuje
wspólne wiersze, nie numery `position`: dodanie albo usunięcie składnika nie
oznacza każdej późniejszej pozycji jako zmienionej. Nie pobiera dzisiejszej
treści przepisu do odtwarzania starszej wersji. To zmiana odczytu historii,
bez zmiany schematu i bez migracji; cofnięcie samego kodu przywracałoby błędny
komunikat „brak różnic” przy przestawieniu.

**Kiedy powstaje wersja (issue #1316).** Przy każdej publikacji
(„Pierwsza publikacja", „Aktualizacja przepisu") oraz przy ŚWIADOMYM zapisie
BEZ publikacji na przepisie, który jest opublikowany — „Zapisz zmiany",
wyjście z kreatora („Nie teraz"), `action=draft` w formularzu bez
JavaScriptu (`SnapshotRecipeVersion::poprawka()`):

- treść równa ostatniej wersji → nowej wersji nie ma;
- inaczej → nowa wersja z opisem „Poprawka opublikowanego przepisu".

Autozapis kreatora (pauza w pisaniu, „Dalej", „Wstecz") zapisuje treść, ale
wersji nie tworzy. **Istniejącej wersji nie zmienia się nigdy** (decyzja
właściciela z 24.09.2026): model `RecipeVersion` odmawia `update()` wyjątkiem
— jedynym wyjątkiem są dwie kolumny ukrycia (niżej, #2270), które nie są
treścią wersji.
Szkic przed pierwszą publikacją nie ma wersji. Zmiana zachowania, nie
schematu — bez migracji.

**Retencja (#2024, D-333 — wartości potwierdzone przez właściciela 30.09).**
`kuking:sprzataj-wersje-przepisow` (codziennie 06:40, `routes/console.php`)
kasuje wersję, która jest **starsza niż 24 miesiące** (próg to początek dnia
w Polsce sprzed 24 miesięcy, `config('kuking.strefa')`, bez przepełnienia
końca miesiąca) **i nie należy do 3 najnowszych wersji swojego przepisu**
(`config('kuking.przepisy.version_retention_months')`, `version_keep_latest`,
minimum 2). Pierwsza wersja NIE jest chroniona — historia jest publiczna,
a w najstarszych wersjach zostaje treść, którą autor później usunął.
Nie kasujemy wersji przepisu, na który wskazuje `reports` albo
`moderation_actions`. Wersje usuniętego przepisu idą razem z nim
(`PrzedawnioneUsunieteTresci`, 30 dni). Kasowanie idzie partiami po 500,
budżet przebiegu to 20 000 wierszy; błąd partii daje kod wyjścia ≠ 0, który
harmonogram zamienia w wyjątek. **Luki w `version_number` są normalne**:
numer nowej wersji to `max + 1`, ekrany historii liczą sąsiadów z faktycznej
listy. Eksport danych (`wersje_przepisow`) niesie to, co zostało — kształt
bez zmian. Bez zmiany schematu; rollback to wyłączenie zadania (skasowanych
wersji żaden rollback nie przywróci — to cel zmiany).

**Ukrycie pojedynczej wersji (#2270, D-333 — decyzja właściciela 30.09).**
Migracja `2026_09_30_201700_add_hidden_at_to_recipe_versions`:

```sql
ALTER TABLE recipe_versions ADD COLUMN hidden_at timestamptz NULL;
ALTER TABLE recipe_versions ADD COLUMN hidden_by_role varchar(10) NULL;
ALTER TABLE recipe_versions ADD CONSTRAINT recipe_versions_hidden_spojny_check CHECK (
    (hidden_at IS NULL AND hidden_by_role IS NULL)
    OR (hidden_at IS NOT NULL AND hidden_by_role IS NOT NULL
        AND hidden_by_role IN ('author','moderator'))) NOT VALID;
ALTER TABLE recipe_versions VALIDATE CONSTRAINT recipe_versions_hidden_spojny_check;
```

- `hidden_at` — od kiedy wersja jest ukryta; `NULL` = widoczna jak dotąd.
  Wersję ukrytą widzi wyłącznie autor przepisu i czynna moderacja
  (`HistoriaWersji::widziUkryte`), z oznaczeniem; dla reszty jej adres daje
  404, lista jej nie pokazuje, a porównanie bierze za poprzednika najbliższą
  widoczną wersję i mówi, ile ukrytych pominęło.
- `hidden_by_role` — **strona**, nie konto: `author` albo `moderator`.
  Rozstrzyga, kto może ukrycie cofnąć (autor nie cofa ukrycia moderacji,
  moderacja nie odsłania tego, co autor ukrył sam — `RecipeVersionPolicy`).
  **Które konto** ukryło, stoi wyłącznie w `audit_log`
  (`recipe_version.hidden` / `recipe_version.restored`). Kolumny z `uuid`
  konta świadomie nie ma: weszłaby do inwentarza danych konta, eksportu
  i wymazywania, a reguła jej nie potrzebuje.
- Obie kolumny są poza `$fillable` (pola sterujące widocznością) i zmieniają
  je tylko `RecipeVersion::ukryj()` / `odkryj()`. Strażnik `updating` nadal
  odrzuca każdą inną zmianę istniejącej wersji — ukrycie nie jest furtką
  do poprawiania treści.
- **Najnowszej wersji nie da się ukryć** (`UkrywanieWersji`, sprawdzane pod
  blokadą wiersza `recipes`, tą samą co przy nadawaniu numeru): to treść
  przepisu widoczna na jego stronie. Żeby usunąć z niej tekst, autor poprawia
  przepis — powstaje nowa wersja, a poprzednią da się ukryć.
- Ukrycie przez **moderację** jest decyzją moderacyjną (DSA): obok
  `hidden_by_role = 'moderator'` powstaje wiersz `moderation_actions`
  (`target_type = 'recipe_version'`, sekcja `moderation_actions`), autor
  dostaje powiadomienie z drogą odwołania, a uznane odwołanie zdejmuje
  ukrycie. Ukrycie przez autora wiersza w `moderation_actions` nie tworzy.
  **Przejęcie (decyzja 30.09.2026, bez migracji):** moderacja może przejąć
  ukrycie zrobione przez autora — ta sama droga co zwykłe ukrycie, pod tą samą
  blokadą: `hidden_by_role` zmienia się z `author` na `moderator`, powstaje
  wiersz `moderation_actions` (`hide`), a `audit_log` (`recipe_version.hidden`)
  niesie dodatkowo `przejeto_od = author`. Reguła CHECK dopuszcza obie
  wartości, więc schemat się nie zmienia.
- Retencja (akapit wyżej) ukrycia nie patrzy: stara ukryta wersja spoza
  3 najnowszych znika tak samo jak widoczna. Eksport (`wersje_przepisow`)
  niesie ukrytą wersję całą, z `ukryto` (data) i `ukryl` (`autor` |
  `moderacja` | `null`).
- Bez indeksu: każde zapytanie idzie po `recipe_id` (indeks unikalny
  `recipe_id, version_number`), a wersji jednego przepisu jest kilka.

Zapytanie kontrolne (CHECK odmówiłby walidacji):
`SELECT id FROM recipe_versions WHERE (hidden_at IS NULL) <> (hidden_by_role IS NULL);`

**Rollback: `down()` ODMAWIA, gdy choć jedna wersja jest ukryta (D-088).**
Zdjęcie kolumny odsłania ukryte wersje publicznie, a ponowne `up()` wraca
z `NULL` — czyli nic nie ukrywa, bez śladu błędu. Komunikat odmowy mówi, co
zrobić ręcznie (kopia `id, hidden_at, hidden_by_role`, rollback, po ponownym
`migrate` przywrócenie z kopii). Bez ukrytych wersji (świeża baza, CI)
`down()` zdejmuje CHECK i obie kolumny bez pytania. Pilnuje
`tests/Feature/UkrywanieWersjiPrzepisuTest.php` (odmowa + kontrola dodatnia).

### recipe_shares — udostępnienie jednego przepisu wskazanej osobie (#2650)

Migracja `2026_10_03_120000_create_recipe_shares_table` (nowa tabela).
Decyzja i granice: wiersz #2650 w **D-333**. Jeden wiersz = „autor pozwolił
tej osobie CZYTAĆ ten przepis". Udostępnienie **nie zmienia**
`recipes.visibility`: przepis „Tylko ja" dalej nie wychodzi w feedzie,
wyszukiwarce, mapie strony, JSON-LD ani na profilu.

- `id uuid` (`gen_random_uuid()`);
- `recipe_id uuid NOT NULL` → `recipes` `ON DELETE CASCADE` (trwałe
  usunięcie); zwykłe usunięcie przepisu przez autora kasuje udostępnienia
  jawnie (`OdbierzDostepDoPrzepisu::wszystkieDlaPrzepisu()`);
- `recipient_id uuid NOT NULL` → `users` `ON DELETE CASCADE`; wymazanie konta
  (wiersz `users` zostaje jako `erased`) kasuje udostępnienia obu stron
  jawnie (`KoniecUdostepnienPrzepisow::przyWymazaniu()`);
- `created_at`, `updated_at timestamptz` — od kiedy osoba ma dostęp.

Autor nie ma kolumny — jest nim `recipes.author_id` (druga kopia mogłaby się
rozjechać). Ograniczenia:

- `recipe_shares_recipe_recipient_unique` — `UNIQUE (recipe_id, recipient_id)`:
  jedno udostępnienie na parę, także przy dwóch równoległych kliknięciach
  (`insertOrIgnore`);
- `recipe_shares_recipient_idx` — lista „Przepisy udostępnione mi";
- **wyzwalacz `recipe_shares_guard`** (`BEFORE INSERT OR UPDATE`, funkcja
  `recipe_shares_guard()`) odmawia (`check_violation`, 23514) wpisania autora
  jako odbiorcy jego własnego przepisu — CHECK tego nie wyrazi, bo warunek
  dotyczy wiersza `recipes` (wzór: `collection_members_guard`).

**Brak stanu „odebrane".** Odebranie dostępu, rezygnacja odbiorcy, blokada
(`ZerwijUdostepnieniaPrzepisow::miedzy()` pod `ZamekPary`) i usunięcie
przepisu KASUJĄ wiersz — nic nie może go po cichu przywrócić (odblokowanie,
zmiana widoczności). Zawieszenie, ban, zamykanie konta i ukrycie przepisu
przez moderację wiersza nie kasują; dostęp wstrzymuje
`RecipePolicy::readShared()`, która pyta bazę przy każdym żądaniu (bez cache).
Lista odbiorcy zachowuje wtedy anonimowy wiersz grantu z potwierdzoną
rezygnacją po jego `id` (#2859). Nie pokazuje tytułu, autora, zdjęcia,
adresu przepisu ani powodu niedostępności. Strona czytania nadal odmawia.
Każde potwierdzenie dotyczy jednego grantu; zmiana widoczności między
wyświetleniem listy a wysłaniem formularza nie rozszerza zakresu usunięcia.
Bez zmian schematu; wycofanie tej poprawki przywraca dawną listę, na której
odbiorca nie mógł sam zrezygnować podczas czasowej niedostępności.

`$fillable` modelu `RecipeShare` jest puste — klucze ustawia wyłącznie
`UdostepnijPrzepis` (pod `ZamekPary`, potem blokada doradcza `2650` na przepis
dla limitu `kuking.udostepnienia.max_osob`; kolejność blokad: konta, potem
przepis — D-079 §1).

Drugi krok formularza (#2790) niesie zaszyfrowane potwierdzenie z UUID
odbiorcy, UUID autora i przepisu, nazwą widoczną przy potwierdzeniu, wersją
formularza i terminem 15 minut. Dwie otwarte karty zachowują osobne
potwierdzenia. Przy zapisie `UdostepnijPrzepis::poPotwierdzeniu()` ponownie
sprawdza tę samą osobę i jej nazwę pod `ZamekPary`; zmiana nazwy wymaga
ponownego pierwszego kroku. Dowolny identyfikator przesłany przez klienta
nie przyznaje dostępu. Nie zmienia to schematu `recipe_shares` ani zasad
odczytu przepisu.

**Rollback (D-088).** `down()` kasuje tabelę i funkcję wyzwalacza, więc
**odmawia**, gdy w tabeli jest choć jeden wiersz: decyzja autora o tym, komu
pokazał przepis, nie wróci po ponownym `up()`. Komunikat mówi, co zrobić
(kopia `CREATE TABLE recipe_shares_kopia AS SELECT * FROM recipe_shares`,
potem `KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1`). Na pustej tabeli
(CI, `migrate:refresh`) przechodzi bez pytania. Pilnuje
`tests/Feature/UdostepnieniePrzepisuSchematTest.php` (odmowa, wymuszenie,
kontrola dodatnia).

### draft_restore_points — kopia tekstu szkicu do odzyskania po pomyłce (issue #2512, D-333)

Migracja `2026_10_07_212512_create_draft_restore_points_table`. JEDEN
ograniczony punkt odzyskania na szkic: tekst sprzed sesji edycji, robiony
przy otwarciu istniejącego szkicu w kreatorze (`PunktOdzyskaniaSzkicu::zachowajPrzedEdycja`),
gdy szkic nie ma jeszcze ważnej kopii. To NIE jest wersja przepisu
(`recipe_versions` bez zmian; autozapis nadal nie tworzy wersji). Ponowne
otwarcie nie podmienia kopii; przywrócenie podmienia ją na tekst, który
zastąpiono.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `recipe_id` | `uuid NOT NULL UNIQUE` → `recipes` (`ON DELETE CASCADE`) | jeden punkt na szkic |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | autor szkicu; poza `$fillable` (model ma pusty `$fillable`) |
| `snapshot` | `jsonb NOT NULL` | `CHECK jsonb_typeof(snapshot) = 'object'`; tekst: tytuł, opis, porcje, sztuki, czasy, trudność, pochodzenie, składniki (grupa, uwaga, zamiennik, „Bez ilości”), kroki (etap, minutnik, `media_id` tylko jako wskazanie); bez zdjęć, alergenów, kosztu, widoczności, rodzaju i adresu źródła |
| `taken_at` | `timestamptz NOT NULL DEFAULT now()` | początek okna `kuking.przepisy.szkic_punkt_odzyskania_dni` (14) |

Indeksy: `recipe_id` (UNIQUE), `taken_at` (nocne sprzątanie), `user_id`.

Przywrócenie idzie przez `PublishRecipe` (zapis szkicu, `publish: false`), z
kontrolą rewizji treści i znacznika kopii z podglądu. Odmawia, gdy musiałoby
odpiąć obecne zdjęcie kroku. Sprzątanie: `PrzedawnionePunktyOdzyskaniaSzkicu`
(wołane przez `kuking:sprzataj-usuniete-tresci`) kasuje punkty starsze niż okno
oraz punkty szkiców opublikowanych/usuniętych miękko. Wymazanie konta kasuje
punkty jawnie (`EraseAccountData`). Paczka danych: sekcja `kopie_tekstu_szkicow`;
rejestr czynności: §3.32.

Sprzątanie wybiera najwyżej 1000 kandydatów, ale przy kasowaniu ponownie
sprawdza termin i stan przepisu w warunku `DELETE` (#2849). Przywrócenie
odnawia `taken_at` tego samego punktu: stary odczyt listy kandydatów nie może
usunąć świeżej kopii, która pozwala wrócić do tekstu sprzed przywrócenia.
Tryb `--dry-run` pozostaje tylko odczytem.

Rollback (D-088): `down()` usuwa tabelę, ale ODMAWIA, gdy jest choć jeden punkt
w oknie odzyskania. Na pustej tabeli, przy samych przedawnionych punktach i w CI
przechodzi bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU=1`. Testy:
`tests/Feature/OdzyskanieTekstuSzkicuTest.php`.
