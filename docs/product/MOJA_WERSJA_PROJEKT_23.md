# „Moja wersja” — projekt i koszt V1-minimum (#23)

**20 września 2026 · propozycja do decyzji właściciela, nie zgoda na wdrożenie.**
Zgłoszenie: https://github.com/woogitsu/kuking.pl/issues/23.
Baza analizy: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/moja-wersja`.
Dokument jest wynikiem zadania; nie dodaje działającej funkcji ani nowych zasad do AGENTS.md.

## 1. Wniosek i bramka

Najmniejsza uczciwa wersja to **osobny przepis z własnym autorem zmian, obowiązkowym wskazaniem oryginału i jego autora oraz osobnymi wykonaniami**. Oryginał dostaje listę „Wersje innych osób” i powiadomienie o pierwszej publikacji nowej wersji. Edycja nie może odłączyć pochodzenia.

`docs/FEATURES.md` umieszcza funkcję w V1. `docs/ROADMAP.md`, „V1 gate”, wymaga powrotów potwierdzonych WAC i D30. P1 zgłoszenia nie otwiera tej bramki. Nie odczytywałem analityki produkcyjnej: **to zadanie nie dostarcza dowodu spełnienia bramki**. Progi, okres obserwacji i termin rozpoczęcia ustala właściciel.

Szacunek: **14–23 osobodni pracy technicznej i odbioru**, przy założeniach poniżej, plus czas rozstrzygnięć właściciela i weryfikacji praw do adaptacji. To estymacja, nie zmierzony czas implementacji. Najwięcej kosztuje trwałe autorstwo, prywatność i usuwanie źródła, nie kopiowanie składników.

## 2. Co sprawdziłem przed projektowaniem

### Odczyt kodu i żywego schematu

| Obszar | Stan sprawdzony samodzielnie | Wpływ na projekt |
|---|---|---|
| Git | Wskazana gałąź i SHA; początkowo czyste drzewo | Analiza tej bazy, bez deklaracji zgodności z późniejszym `main` |
| Historia | `recipe_versions`: UUID, `recipe_id`, `editor_id`, `version_number`, JSONB `snapshot`, `change_note`, `created_at`; UNIQUE(recipe_id, version_number) | Wykorzystujemy historię; fork nie jest kolejną edycją oryginału |
| Snapshot | `SnapshotRecipeVersion::handle`; wywołanie z `PublishRecipe` przy publikacji/aktualizacji publikowanej treści | Fork przypina konkretną wersję źródła; równoległa edycja wymaga atomowego odczytu |
| Relacja forków | Brak tabeli forków, `forked_from_id` i rodzica przepisu w bazie oraz obsługi w domenie przepisów | Relacja jest nowa. Trafienia `parent_id` w modelach dotyczą komentarzy |
| Pochodzenie | `source_type=adaptation`, `source_person`, `source_note`, `source_url` | To deklaracja tekstowa, nie trwałe powiązanie z innym przepisem |
| Wykonania | `cooked_events.recipe_id`, bez `recipe_version_id` i licznika per snapshot | Liczenie pozostaje per przepis, osobno dla oryginału i forka |
| Licznik UI | `RecipeController::show`: `cookedEvents()->widoczneDla(...)->paginate(...)->total()`; karta: „Ugotowane N ×” | Liczymy widoczne zdarzenia, nie unikalne osoby ani rodzinę forków |
| Powiadomienia | `RecordCookedEvent`: wykonanie, `NotifyUser` i audyt w tej samej transakcji; adresat `$recipe->author` | Zachowujemy znaczenie i wyjątki AGENTS.md §1 |
| Feed | Publikacja wiąże wpis z przepisem przez `posts.recipe_id`; `post-card` pokazuje odnośnik | Zmiana samej `recipe-card` nie wystarczy |
| Formularz | D-135/D-136: sześć rzeczy na prostym formularzu, składniki i przygotowanie jako tekst; istnieje też kreator | Osobny tryb „Moja wersja”, bez siódmego pytania przy zwykłym publikowaniu |
| Usuwanie | `EraseAccountData`, zakres `everything`: `recipes()->withTrashed()->forceDelete()` | Trzeba obsłużyć twarde usunięcie, nie tylko soft delete |
| SEO | D-156/D-161, `pages/recipes/show`: `author` to publikujący, `citation` to tekst pochodzenia, `isBasedOn` już jest dla źródła zewnętrznego | Rozszerzamy istniejące mapowanie, nie budujemy drugiego systemu |

**Ograniczenie snapshotu:** w payloadzie `SnapshotRecipeVersion` nie ma `source_url` ani identyfikatorów zdjęć, mimo szerszego opisu w `docs/DATABASE.md`. Nie uznaję go za pełne archiwum pochodzenia i mediów. Przyszła implementacja musi jawnie uzupełnić potrzebne dane, a nie zakładać ich obecność. Teraz niczego nie dopisano.

Odczyt bazy po migracjach: **`kuking_flota_gpt-moja-wersja`, właściciel/użytkownik `kuking`, 127.0.0.1:55439**. `information_schema.columns` zwróciło 43 kolumny łącznie dla `recipes`, `recipe_versions`, `cooked_events`; wyszukanie tabel `%fork%` — zero. FK `cooked_events.recipe_id` na tej bazie ma **ON DELETE CASCADE**. Nie zakładam, że wszystkie scenariusze zachowania wykonań po usunięciu są już rozwiązane.

### Własne wykonanie istniejących testów

Runtime `/home/mateusz/flota/gpt-moja-wersja-run` przygotowano z nietkniętego kodu skryptem floty. Przed zmianą dokumentacji uruchomiono:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-moja-wersja
# Filtr przekazany wewnątrz bash, z zachowaniem cudzysłowów:
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-moja-wersja --filter 'RecipeTest|UgotowalemZawszePowiadamiaAutoraTest|IdempotencjaUgotowalemTest|PrzepisyLicznikiZgadzajaSieZGaleriaTest|AutorPrzepisuWDanychStrukturalnychTest'
# W runtime, z PHP /opt/kuking-php-8.4-avif/bin:
vendor/bin/pint --test
```

**39 testów, 214 asercji, 4,70 s; Pint PASS, 1155 plików.** Sprawdzono istniejącą publikację z wersją, podpis, prywatność, powiadomienia HTTP/domeny/seedera, idempotencję i zgodność liczników z galerią. To nie jest pomiar działania nieistniejącego forka. Nie dodawano asercji zamrażających proponowane decyzje produktowe.

Pierwsza próba przygotowania runtime zakończyła się błędem WSL `0x8007274c`; ponowienie przeszło. Wystąpił też błąd interpretacji `|` przy przekazywaniu filtra z PowerShell. Poprawny pomiar wykonano przez tymczasowy skrypt bash. Nie są to błędy aplikacji; skrypt nie stanowi produktu zadania.

Materiały przejęte:

- **[pomiar cudzy: komentarz #23 z 9.09.2026]** wcześniejszy brak forka. Obecny stan sprawdziłem niezależnie. Nie przejmuję dawnego zdania o braku `isBasedOn`: dziś istnieje dla zewnętrznego źródła.
- **[pomiar cudzy: #23 / `docs/product/SOUL.md`]** twierdzenie, że `changes_note` jest najczęściej czytaną częścią komentarza. Nie mam własnych danych o częstości.
- Kosztorys, korzyść retencyjna i zachowanie projektowanego UI nie są wynikami wykonanych pomiarów.

## 3. Zakres V1-minimum

### Droga człowieka

1. Przy cudzym dostępnym publicznym przepisie pomocnicza akcja **„Stwórz moją wersję”**, poniżej głównego „Ugotowałem”. Bez nowej pozycji nawigacji; tylko po spełnieniu uprawnień do adaptacji (§5).
2. Osobny stan formularza **„Moja wersja”**, z nieedytowalnym podpisem **nad tytułem**: „Na podstawie przepisu: [tytuł]”, „Autor oryginału: [nazwa]”. Po publikacji osobno „Autor tej wersji: [nazwa]”. Nazw nie odmieniamy (D-153). Link nie jest schowany w menu ani pod rozwijaniem.
3. Prywatny szkic otrzymuje dozwolone do adaptacji składniki, kroki i parametry z przypiętego snapshotu. **Nie kopiujemy cudzych zdjęć, skanu zeszytu ani osobistej historii jako historii nowego autora.** Deklarowane pochodzenie oryginału jest dostępne przy źródle, w granicach uprawnień.
4. Człowiek zmienia treść i wpisuje **„Co zmieniasz?”**. To rzeczywisty dodatkowy koszt poznawczy. Zwykłe sześć pytań z D-135 pozostaje; osobny tryb wymaga odbioru właściciela. Własne zdjęcie opcjonalne, przez istniejący pipeline.
5. Publikacja daje nowy UUID i adres, własne komentarze, Zeszyt oraz wykonania. Autor nie może zmienić forka na „Mój własny” i odłączyć źródła. Podpis zostaje po każdej edycji.
6. Oryginał dostaje **„Wersje innych osób”**, po treści i głównych akcjach: lista chronologiczna, filtrowana per widz, „Pokaż więcej”, bez rankingu i sumowania ugotowań.

### Realna różnica

Publikacja wymaga zmiany składników, proporcji, techniki albo parametrów gotowania oraz opisu różnicy. Sam tytuł, zdjęcie, opis źródła, białe znaki lub interpunkcja nie wystarczają. Komunikat roboczy: **„Zmień składniki lub przygotowanie, żeby opublikować swoją wersję. Jeśli gotujesz bez zmian, wybierz «Ugotowałem».”** Błąd przy polu i w podsumowaniu; wpisane dane i szkic pozostają.

Porównujemy do **snapshotu źródła przypiętego na początku**, nie oryginału zmienionego później. Edycja źródła nie podmienia szkicu. Deterministyczna bariera odrzuca identyczny tekst/parametry po wąskiej normalizacji. Nie dowodzi kulinarnej wartości: przepisane zdanie może ją obejść. Sporne kopie to koszt moderacji. Samo podwojenie porcji albo zmiana czasu wymaga reguły wybranej przez właściciela, nie przypadkowego progu w teście.

| Poza minimum | Powód / koszt dołożenia |
|---|---|
| Fork forka i drzewo | Wielu autorów, wielopoziomowe blokady, usuwanie i komunikaty; zamiast modelu Git człowiek widzi jeden związek z oryginałem |
| Scalanie/synchronizacja | Konflikty składników i kroków, ryzyko zmiany przepisu w trakcie gotowania |
| Edytowanie oryginału przez autora forka | Narusza własność i odpowiedzialność |
| Współautorzy i dziedziczenie rodzinne | Osobne uprawnienia i zgody; wzmianka w SOUL.md nie poszerza automatycznie #23 |
| Liczniki per historyczny snapshot | Brak dzisiejszego powiązania; dodatkowa zmiana „Ugotowałem” i wyboru wersji |
| Źródła prywatne/dla obserwujących | Kopia mogłaby poszerzyć audytorium cudzej treści |
| Kopiowanie zdjęć, import URL, porównywarka AI | Dodatkowe prawa, koszty i ryzyko fałszywego dowodu gotowania |
| Automatyczne indeksowanie po progu tekstowym | Rekomendacja minimum: wszystkie forki noindex; wymaga uzgodnienia rozbieżności z #23 (§7) |

Na forku nie ma akcji tworzenia następnego forka. Można przejść do oryginału i zacząć od niego; nie kopiujemy zmian pośredniego autora, udając pochodzenie wyłącznie od korzenia. Wiele bezpośrednich wersji jednego oryginału jest możliwe, bez drzewa i scalania.

## 4. Autorstwo i cykl życia

**Pochodzenie jest częścią tożsamości przepisu.** Serwer wyznacza je z autoryzowanego źródła. Żądanie nie może podmienić autora, usunąć relacji ani zrobić tego przez autosave, zmianę widoczności lub ponowną publikację. To samo obowiązuje akcję domenową i seeder.

| Stan | Zachowanie projektowane |
|---|---|
| Dostępne źródło | Nad tytułem link do aktualnego oryginału i podpis jego autora, oddzielnie autor zmian |
| Zmieniony tytuł/nazwa/slug | Relacja po UUID, bieżący URL i nazwa z danych; bazowy snapshot nie zmienia się |
| Edycja forka | Własna treść i opis zmian edytowalne, źródło nie; własna historia w recipe_versions |
| Źródło prywatne/ukryte | Nie pokazujemy jego treści, tytułu, zdjęć ani snapshotu osobie bez dostępu. Stała informacja o pochodzeniu nie znika |
| Blokada widza względem autora źródła | Rekomendacja: fork niedostępny temu widzowi, aby nie obchodzić blokady adaptacją. Autor forka zachowuje dostęp do własnego szkicu/edycji bez dostępu do źródła |
| Zwykłe usunięcie oryginału | Nie kasuje forka ani nie zamienia go w „własny”. Zostaje „Oryginalny przepis został usunięty”, informacja o autorze, jeśli konto nadal dostępne, i link do informacji o źródle |
| Anonimizacja konta | „Użytkownik usunięty”; nie trzymamy kopii dawnej nazwy ani awatara. Nadal rozróżniamy oryginał i zmiany |
| Pełne wymazanie / spór prawny | Nie archiwizujemy usuniętej treści pod pretekstem pochodzenia. Zakres zachowania skopiowanych fragmentów wymaga rozstrzygnięcia praw i retencji. Do rozstrzygnięcia fork zachowany dla autora, publiczne serwowanie spornego tekstu wstrzymane |
| Usunięcie forka | Oryginał i jego wykonania pozostają, pozycja na liście wersji znika |

Proponowany dodatkowy ekran `/przepisy/{fork}/oryginal`: przy niedostępnym/usuniętym źródle wyjaśnia stan i daje powrót do wersji. **Odnośnik pozostaje obowiązkowy**, nie staje się martwym 404. Wejście wymaga Policy forka, a pokazanie oryginału osobnej Policy źródła. Nie ujawniamy prywatnego sluga, tytułu ani identyfikatora źródła w odpowiedzi dla nieuprawnionych. Gdy oryginał jest dostępny, główny link prowadzi bezpośrednio do przepisu.

**Otwarta decyzja:** czy późniejsza prywatyzacja/ukrycie źródła wstrzymuje publiczny fork, czy tylko dostęp do oryginału. Rekomenduję wstrzymanie publicznej prezentacji do ustalenia zakresu zgody. To koszt dostępności cudzej pracy, który trzeba objaśnić przed publikacją. Sam zachowany wiersz nie dowodzi prawidłowej widoczności; nie zamrażam rekomendacji asercją.

### Warianty odrzucone wprost

- **Kopia podpisana wyłącznie nowym autorem:** odbiera rozpoznawalność oryginałowi; niedopuszczalna niezależnie od kosztu.
- **Opcjonalny source_url lub ręczne „od Basi”:** łatwe do usunięcia/podmiany, bez potwierdzonego związku z konkretnym źródłem.
- **Podpis tylko w JSON-LD, na dole lub w menu:** człowiek nie widzi pochodzenia przy przepisie.
- **Usuwanie źródła po edycji albo dużej zmianie:** rozmiar modyfikacji nie daje prawa do wymazania pochodzenia.
- **CASCADE od źródła do forka:** zabiera cudzy dorobek i łamie #23. Samo SET NULL, po którym UI pokazuje „przepis własny”, też odpada.
- **Wieczna kopia nazwiska/zdjęcia/tekstu usuniętego autora:** ochrona podpisu nie uzasadnia złamania obietnic wymazania danych.
- **Wspólny licznik wykonań:** sugeruje ugotowanie innej receptury niż faktycznie wybrana.
- **Powiadomienie o ugotowaniu tylko autora źródła:** pomija autora gotowanego przepisu, sprzecznie z AGENTS.md §1.
- **Dwa identyczne „ktoś ugotował Twój przepis”:** jedno ugotowanie udaje dwa różne wydarzenia.
- **Spłaszczenie forka forka do korzenia:** usuwa wkład autora pośredniego. Odmawiamy tej operacji zamiast pozornie upraszczać drzewo.

## 5. Dane i migracje — projekt, nie wykonane zmiany

Wykorzystujemy `recipes` (author_id = autor tej wersji), nowe własne wiersze `recipe_ingredients` i `recipe_steps`, własną historię `recipe_versions`. Wykonania, komentarze, kolekcje, wpisy, powiadomienia, zgłoszenia, moderacja i audyt pozostają istniejącymi bytami, bez równoległego zestawu tabel.

### Jedna nowa tabela recipe_forks

Osobny rekord zachowuje fakt pochodzenia także po SET NULL klucza źródła. Cena: dodatkowa relacja do doładowania. Nie potrzebujemy drzewa ani nowego mechanizmu wersjonowania.

| Kolumna | Typ / ograniczenie | Rola |
|---|---|---|
| `recipe_id` | UUID PK, FK recipes, CASCADE przy usunięciu **forka** | Jeden oryginał dla forka |
| `original_recipe_id` | UUID nullable, FK recipes, SET NULL | Żywy odnośnik do źródła |
| `original_recipe_key` | UUID NOT NULL, niezmienny | Tożsamość pochodzenia po usunięciu; świadomie bez FK do usuwalnego wiersza |
| `original_author_id` | UUID nullable, FK users, SET NULL | Autor oryginału, nie wolny tekst source_person; nazwa czytana z bieżącego konta |
| `original_version_id` | UUID nullable, FK recipe_versions, SET NULL | Snapshot, z którego zaczęto |
| `original_version_number` | integer NOT NULL, CHECK > 0 | Numer źródłowej wersji po usunięciu snapshotu |
| `changes_note` | varchar(1000), nullable w szkicu | Opis zmian wymagany przy publikacji; odrębny od uwagi pojedynczego ugotowania |
| `created_at` | timestamptz NOT NULL | Powstanie pochodnej |
| `first_published_at` | timestamptz nullable | Jednorazowa pierwsza publikacja/powiadomienie |

Indeksy: `(original_recipe_id, first_published_at, recipe_id)` dla listy i `original_author_id` dla obsługi konta. CHECK: brak własnego rodzica (`recipe_id <> original_recipe_key`) i zgodność żywego ID z zachowanym kluczem. Zgodność snapshotu z oryginałem trzeba zabezpieczyć w bazie: dodatkowy UNIQUE na `recipe_versions(id, recipe_id)` i złożony FK albo wąski trigger pary. Nie wystarczy formularz.

Zakaz forka forka i przepięcia korzenia wymaga domenowej reguły pod blokadą wiersza oraz zabezpieczenia spójności w bazie. CHECK nie odpytuje innej tabeli: przyszła implementacja ma wybrać i sprawdzić wąski trigger albo równoważny schemat, z dopuszczeniem SET NULL przy autoryzowanym usunięciu, lecz bez możliwości podmiany źródła. Usunięcie samego rekordu pochodzenia bez usunięcia forka jest zabronione na wszystkich ścieżkach aplikacji.

Nie kopiujemy nazwisk ani kont do JSONB. Historia rodzinna/source_person oryginału nie staje się historią autora zmian; czytelnik widzi ją przy dostępnym źródle. Po usunięciu źródła nie przyznajemy autorowi forka autorstwa tych treści.

### Zgoda na adaptację — założenie wymagające zatwierdzenia

Odczyt `resources/legal/regulamin.md` §5.2: licencja służy działaniu serwisu, wygasa przy usunięciu treści, a udzielenie licencji osobom trzecim wymaga odrębnej zgody. Projekt `docs/legal/LICENCJA_UGC_PROJEKT.md` nie dowodzi zmiany obowiązującego tekstu. **Publiczny przepis nie jest dla tego projektu automatyczną zgodą na kopiowanie.** To luka w uzgodnieniach produktu, nie opinia o prawach konkretnej osoby.

Kosztorys zakłada dobrowolną zgodę autora źródła w „Dopisz szczegóły”: projektowane `recipes.allows_forks boolean DEFAULT false` i `fork_permission_granted_at timestamptz nullable`, spójność CHECK i audyt zmiany. Zgoda dotyczy tekstowej adaptacji w serwisie, nie zdjęć, i nie włącza się na starych przepisach. Tekst, dowód wersji warunków i skutki cofnięcia wymagają zatwierdzenia z osobą odpowiedzialną za regulamin; sam checkbox tego nie rozwiązuje. Zgodę sprawdza się ponownie przy publikacji forka.

Alternatywy: własny tekst od zera z trwałym wskazaniem inspiracji, bez automatycznej kopii; albo prośba o zgodę na każdą wersję. Pierwsza zabiera główne ułatwienie funkcji. Druga dodaje kolejkę, odrzucenia i wiadomości, około 3–5 osobodni poza bazą. Żadna nie została wdrożona.

### Dwie planowane migracje i wycofanie

1. **Zgoda:** dwie kolumny recipes i CHECK. Down odmawia przy zachowanej zgodzie lub zależnych forkach; przechodzi bez takich wartości. Nie przywraca zgody domyślnym true.
2. **Pochodzenie:** recipe_forks, FK, indeksy i zabezpieczenia spójności/niezmienności. Bez zgadywanego backfillu z source_type=adaptation. Down odmawia, jeśli istnieje rekord pochodzenia, także szkic lub po usunięciu źródła; jego utrata zmieniłaby autorstwo. Na pustej tabeli przechodzi.

Każda wymaga testu PostgreSQL, dodatniej i ujemnej kontroli rollbacku, aktualizacji `docs/DATABASE.md` i planu wycofania (AGENTS.md §6, D-088). To prace przyszłe, nie obecna zmiana schematu.

Po pojawieniu się forków wycofanie funkcji oznacza wyłączenie tworzenia, przy zachowaniu czytania, podpisów, moderacji i danych. **Nie wolno cofnąć aplikacji do wersji renderującej fork bez pochodzenia.** Nie cofamy migracji niszczącej autorstwo. Przy wyborze mechanizmu włączenia wraca próg z D-065 dotyczący flag; ten dokument nie zamawia nowego pakietu.

## 6. „Ugotowałem”: komu, ile i za co

**Rozstrzygnięcie projektu: ugotowanie forka powiadamia autora forka. Autor oryginału nie dostaje drugiego TYPE_COOKED.**

AGENTS.md §1 gwarantuje wiadomość autorowi **przepisu, który ugotowano**. Dzisiejsza droga to `cooked_events.recipe_id → recipes.author_id`. Fork jest odrębnym przepisem, więc jego autor ma tę samą gwarancję. Utworzenie forka nie jest ugotowaniem i nie tworzy wykonania. Źródło zyskuje obowiązkowy podpis/link, listę wersji i osobne powiadomienie o adaptacji.

AGENTS.md nie rozstrzyga dodatkowej wiadomości do twórcy źródła o dalszych ugotowaniach. Jej pominięcie jest **wyborem minimum**, nie wymyślonym zakazem. Wariant „obaj” wymaga osobnego prawdziwego komunikatu o wykonaniu pochodnej, filtrów prywatności i akceptacji hałasu; nigdy nie zastępuje wiadomości do autora forka.

| Zdarzenie | Autor forka | Autor oryginału | Licznik |
|---|---|---|---|
| Basia publikuje wersję przepisu Anny | Bez wiadomości o własnej akcji | Jedno nowe `recipe.forked` | Zero ugotowań |
| Marek gotuje wersję Basi | Jedno TYPE_COOKED do Basi | Bez TYPE_COOKED | +1 przy forku |
| Marek gotuje oryginał Anny | Bez wiadomości | Jedno TYPE_COOKED do Anny | +1 przy oryginale |
| Basia gotuje własną wersję | Bez wiadomości o własnej akcji | Bez wiadomości | Własne wykonanie forka, nie cudza rekomendacja |
| Anna gotuje wersję Basi | Jedno TYPE_COOKED do Basi | Bez wiadomości o własnej akcji | +1 przy forku |
| Retry tego samego formularza | Bez duplikatu | Bez duplikatu | Jeden zapis, klucz_wyslania |
| Drugie rzeczywiste gotowanie | Nowa wiadomość właściwego autora | Według gotowanego przepisu | Nowe zdarzenie, bez UNIQUE(user_id, recipe_id) |

Przykład: oryginał ma 5 wykonań, fork 2. Po ugotowaniu forka jest **5 i 3**, nie 6 i 3 ani wspólny licznik 8. Nie tworzymy pozornego wykonania w oryginale. Lista wersji nie wpływa na kolejność feedu.

Trzy wyjątki AGENTS.md §1 obowiązują autora gotowanego przepisu: własna akcja, zamknięte konto, blokada. Zawieszony autor nadal dostaje wiadomość; erased nie dostaje, wykonanie może pozostać zgodnie z obecną Policy. Nowe ograniczenia dostępu do źródła sprawdzamy **przed** wykonaniem, nie przez ciche pominięcie obowiązkowej wiadomości po zapisie.

Nowe `recipe.forked` idzie przez NotifyUser do autora źródła, bez e-maila w minimum. Publikacja, first_published_at, powiadomienie i audyt muszą być atomowe. Blokada wiersza forka i idempotencja publikacji chronią przed duplikatem; edycja, retry i przywrócenie po moderacji nie wysyłają ponownie. Nie dodajemy limitu „raz dziennie”, który wyciszyłby inny rzeczywisty fork.

Wiadomość tylko dla wersji dostępnej odbiorcy. Minimum: prywatny szkic, potem publikacja publiczna. Późniejsze ograniczenie widoczności musi odfiltrować wcześniejszą wiadomość. Roboczy tekst: „Nowa wersja Twojego przepisu”, osobno nazwa konta i „Zobacz wersję”. Bez cytowania potencjalnie obraźliwego opisu zmian.

## 7. Ekrany i integracje

| Powierzchnia | Praca do wykonania |
|---|---|
| Oryginał, pages/recipes/show | Akcja, lista „Wersje innych osób”, stan pusty, paginacja i Policy |
| Nowy tryb tworzenia | `/przepisy/{oryginal}/moja-wersja`, szkic z pochodzeniem, opis zmian, podgląd; autoryzacja także domenowa |
| Prosty formularz, szczegóły, kreator Livewire | Każda edycja i autosave zachowuje relację; zgoda źródła poza podstawowym formularzem |
| Widok forka | Podpis źródła nad tytułem, autor zmian oddzielnie, opis, własne wykonania/komentarze |
| Informacja o źródle | Działający stan usunięcia/niedostępności i powrót bez ujawnienia prywatnych danych |
| recipe-card i post-card | Pochodzenie w feedzie, wyszukiwaniu, profilu, tagach, Zeszycie; wspólny komponent podpisu |
| FollowingFeed, DiscoverFeed, TagFeed i listy | Doładowanie relacji paczką, filtry przed paginacją; bez rankingu i fanout |
| Gotowanie, druk, udostępnianie i eksport | Widoczny podpis; w druku/eksporcie czytelny adres lub informacja o usunięciu; bez cudzych zdjęć |
| Powiadomienia i liczniki | Nowy typ, tekst, URL, filtr widoczności; licznik per przepis zgodny z galerią |
| Moderacja | Istniejący cel recipe, z pochodzeniem i opisem zmian w panelu; ukrycie, przywrócenie, odwołanie |
| Usuwanie konta/treści | Anonimizacja, zgody, skopiowany tekst i snapshoty, bez odzyskiwania dawnej nazwy z payloadu/cache |
| SEO i sitemap | noindex, canonical, isBasedOn, brak prywatnych danych i forków noindex w sitemapie |

Minimum to co najmniej dwa nowe stany (tworzenie i strona forka), dodatkowa informacja o niedostępnym źródle oraz zmiany w kilkunastu powierzchniach. To nie „jeden przycisk”. Liczbę plików należy ustalić z aktualnych użyć komponentów przy implementacji.

Odbiór: 18 px tekst/pola, 48 px główne kontrolki, klawiatura, widoczne etykiety, 320 px i 200% zoom, bez hover/swipe. Podpis zawija się zamiast znikać przez skracanie. Brak zagnieżdżonych linków w karcie. Szkic i formularz zachowują wpisane dane po walidacji i cofnięciu zgody źródła. D-053: brak martwego przycisku przy awarii skryptu.

### Rozbieżność SEO wymagająca decyzji

#23 wymaga własnego canonical i domyślnego noindex do przekroczenia progu. `docs/seo/SEO_TECHNICAL.md` §1.4 dla mało odmiennych treści proponuje sekcję oryginału zamiast własnej strony oraz 30% unikalnego tekstu. Odsyłacz zgłoszenia do „punktu 10” jest niedokładny: właściwe zasady są w §1.4, obecny dokument ma sekcje do 8.

**Rekomendacja minimum:** wszystkie opublikowane forki mają własny URL, self-canonical i noindex,follow; isBasedOn wskazuje dostępny publicznie oryginał. Autor JSON-LD pozostaje autorem wersji (D-156), niezależnie od widocznego podpisu oryginału. Po usunięciu źródła można wskazać publiczną informację o pochodzeniu, zgodną z widokiem; nie ujawniamy starego prywatnego adresu. Prywatne szkice nie emitują Recipe JSON-LD.

To **jawne zawężenie do uzgodnienia**, nie pełne spełnienie automatycznego progu z #23. Jeden zmieniony składnik może dawać dużą wartość i mało odmiennego tekstu; przestawienie zdań odwrotnie. Dopuszczenie publikacji i indeksacji to osobne decyzje.

Sprawdzone 20.09.2026 źródła zewnętrzne:

- [Google: noindex](https://developers.google.com/search/docs/crawling-indexing/block-indexing): robot musi móc odczytać dyrektywę; nie blokujemy publicznego forka w robots.txt, by „wzmocnić” noindex. To nie ochrona prywatności.
- [Schema.org: isBasedOn](https://schema.org/isBasedOn): relacja dopuszcza URL źródła i nie wymaga podszywania się pod autora.
- [Google: zasady spamu](https://developers.google.com/search/docs/essentials/spam-policies#scaled-content): ryzyko dotyczy masowego tworzenia stron dla manipulowania rankingiem; autentyczne warianty same nie dowodzą naruszenia. Wewnętrzne 30% nie jest progiem gwarantującym bezpieczeństwo w Google.

## 8. Koszt

Założenia: wykonawca znający repo, obecny stack bez nowej usługi, decyzje podjęte przed implementacją, jeden poziom, własne zdjęcia, publiczna publikacja i noindex bez promocji automatycznej. Osobodzień to dzień pracy nad zmianą i jej weryfikacją, nie czas procesora ani obietnica kalendarzowa.

| Pakiet | Osobodni | Zakres |
|---|---:|---|
| Reguły i makieta przepływu | 1–2 | Autorstwo, różnica, cofnięcie zgody, utrata źródła, przegląd właściciela |
| Schemat i domena | 3–4 | Dwie migracje, snapshot, niezmienność, zgoda, Policy, blokady/idempotencja |
| Tworzenie i widoki | 2–4 | Formularz, odzyskiwanie, podpisy, lista wersji, niedostępność |
| Powiadomienia, listy, SEO | 2–3 | recipe.forked, filtry, liczniki, doładowania, sitemap i JSON-LD |
| Moderacja, eksport, usuwanie, dokumentacja | 2–4 | Cykl życia, retencja, instrukcje i wycofanie |
| Testy i odbiór UI | 4–6 | Macierz stanów, kontrole ujemne, równoległość, dostępność i poprawki |
| **Razem** | **14–23** | Bez oczekiwania na właściciela, prawnika i uczestników badań |

Koszt pieniężny = `14–23 × uzgodniona stawka dzienna` plus weryfikacja prawna; stawki nie znam. Badanie z osobami 50+ wymaga rekrutacji i sesji: orientacyjnie dodatkowe 1–2 dni organizacyjne, nie wynik przeprowadzonego badania. Inny model praw do pochodnych może wymusić przeprojektowanie, nie drobną poprawkę.

Opcje poza bazą: automat progu indeksacji **+2–4 dni**, ugotowania per snapshot **+3–5 dni**, dodatkowe wiadomości do źródła o ugotowaniu forka **+1–2 dni**, zgoda per fork **+3–5 dni**. Wielopoziomowe drzewo/scalanie wymagają osobnego projektu.

Dane na fork: jeden recipes, jeden recipe_forks, I składników, S kroków i V snapshotów własnych edycji. Przy publikacji także istniejący wpis feedu, audyt i jedno powiadomienie, o ile odbiorca spełnia warunki. Własne media osobno. Przyrost tekstu proporcjonalny do liczby i długości wersji; brak darmowej deduplikacji. Lista wymaga doładowania relacji stałą liczbą zapytań zamiast SELECT na kartę. Czasy, rozmiary i plan zapytania trzeba zmierzyć podczas implementacji: **nie wykonano benchmarku**.

## 9. Co to zabiera

- **Uwagę:** kolejna akcja konkuruje z Ugotowałem; pozostaje pomocnicza i wymaga sprawdzenia rozpoznawalności.
- **Miejsce:** dwa autorstwa i opis zmian wydłużają kartę/stronę. Nie odzyskujemy go kosztem podpisu ani czytelności.
- **Prostotę:** trzeba odróżnić gotowanie, własny przepis i adaptację; dochodzą opis zmian i odmowa publikacji.
- **Ciszę autora:** nowe powiadomienie potrzebuje idempotencji, filtrów i ochrony przed spamem.
- **Czas moderacji:** opis zmian może oczerniać oryginał/autora, zawierać dane osobowe, reklamę albo niebezpieczną poradę. Dochodzą spory o kopie i prawa. Moderator widzi pochodzenie; ukrycie forka nie karze automatycznie oryginału. Bez automatycznego bana.
- **Niezależność autora forka:** zmiana dostępności lub spór o źródło może wstrzymać publiczny tekst pochodnej; trzeba to wyjaśnić przed publikacją.
- **Ruch organiczny:** noindex świadomie rezygnuje z pozyskiwania użytkowników przez forki w minimum.
- **Prosty rollback:** po powstaniu danych nie można usunąć relacji ani wrócić do widoku bez podpisu.
- **Czas zespołu:** 14–23 osobodni nie idzie w upload, onboarding ani działające pętle powrotu. Bez bramki retencji ten koszt nie jest uzasadniony.

Pilotaż mierzy rozpoczęte/opublikowane wersje, odrzucenia identycznych kopii, wejścia do oryginału, wykonania konkretnego przepisu i zgłoszenia na fork. Osobno minuty pracy moderatora; koszt utrzymania = liczba zgłoszeń × średni czas sprawy. Nie zakładamy częstości ani poprawy WAC/D30 bez danych.

## 10. Plan przyszłej weryfikacji — nie wykonane testy forka

Po zatwierdzeniu reguł: testy PostgreSQL, kontrola dodatnia i ujemna; nie samo wyszukanie napisu w pliku. Bugfix wymaga zobaczenia czerwieni przed poprawką.

1. HTTP, akcja domenowa i seeder tworzą właściwe pochodzenie/snapshot; retry nie mnoży forków ani wiadomości.
2. Próby odłączenia/przepięcia przez edycję, autosave, model i bazę; odmowa self-forka i forka forka, zwykłe przepisy nadal edytowalne.
3. Identyczna kopia/sam tytuł odrzucane, wybrana realna zmiana przyjmowana; pola odtwarzane po błędzie.
4. Równoległa edycja źródła, dwie publikacje i cofnięcie zgody: spójny snapshot i uprawnienia.
5. Podpis w renderowanym widoku, wszystkich kartach, druku, gotowaniu i eksporcie; usunięcie podpisu jako sabotaż oblewa test danego ekranu.
6. Matryca źródło/fork/widz: widoczności, draft/hidden/removed, soft/hard delete, active/suspended/banned/pending_delete/erased, blokady w obu kierunkach. Brak wycieku w HTML, JSON-LD, liczbach i powiadomieniach.
7. Liczniki 5/2 → 5/3, właściwy odbiorca, trzy wyjątki AGENTS.md, nowe realne gotowanie versus retry.
8. recipe.forked tylko przy pierwszej publikacji, nie edycji; błąd wiadomości wycofuje całość. Brak wiadomości o prywatnym szkicu i brak e-maila.
9. Usunięcie źródła zachowuje fork/pochodzenie, wymazanie nie odtwarza nazwiska ani tekstu ze snapshotu. Usunięcie forka nie usuwa oryginału.
10. Rollback pustej bazy przechodzi; przy zgodach/szkicach/forkach odmawia. Wyłączenie tworzenia pozostawia podpisy.
11. Noindex, canonical, isBasedOn, sitemap; także po edycji, blokadzie i usunięciu źródła.
12. Osoby 50+ wskazują autora oryginału i zmian, odróżniają Ugotowałem od Mojej wersji, wracają do źródła i nie tracą szkicu. Klawiatura, 320 px, zoom 200%, duży tekst, długie nazwy, oba motywy. Dopiero sesje potwierdzą zrozumiałość.

## 11. Decyzje właściciela przed implementacją

| Pytanie | Rekomendacja | Alternatywa / koszt |
|---|---|---|
| Kiedy WAC/D30 otwierają V1? | Liczby i okres na rzeczywistych kohortach; dziś brak dowodu przejścia | Start bez dowodu odbiera czas retencji |
| Na czym opiera się zgoda i co po cofnięciu? | Jawna zgoda źródła, tekst i zasady zatwierdzone przed wdrożeniem | Własny tekst od zera albo kolejka próśb; różne produkty |
| Co po prywatyzacji/usunięciu/sporze? | Zachować dorobek i pochodzenie, wstrzymać publiczny sporny tekst | Dalsza publiczna dostępność wymaga jasnych praw i komunikatu |
| Jaka różnica wystarczy, np. same porcje/czas? | Reguła kulinarna, opis, bariera identyczności; nie procent jako dowód jakości | Ostrzejsza selekcja = więcej odmów i moderacji |
| Osobny formularz z opisem zmian? | Tak, osobny odbiór; sześć pytań zwykłej publikacji zostaje | Dodatkowy ekran zmniejsza gęstość, ale dodaje krok |
| Wszystkie forki noindex? | Tak w minimum; uzgodnić #23 i §1.4 SEO | Automat +2–4 dni i osobny pomiar |
| Odbiorca Ugotowałem na forku? | Autor forka; źródło ma osobne zdarzenie utworzenia wersji | Obaj: nowy typ/tekst, +1–2 dni i hałas; nigdy kosztem autora forka |
| Blokada widza ze źródłem? | Ukryć fork temu widzowi, zachować autorowi własny szkic | Pokazanie adaptacji mimo blokady wymaga decyzji prywatności |

To pytania produktowe, nie usterki do domknięcia asercją. Dokument rekomenduje i wycenia; nie nadaje obowiązujących numerów decyzji.

## 12. Zakres dostarczenia i ograniczenia

Wyłącznie dokument, lokalny commit na wskazanej gałęzi. Zero zmian kodu produkcyjnego, testów, migracji, tras, modeli i widoków. Bez komentarza na GitHubie, zamknięcia #23, push i PR. Bez zmian w repozytorium kanonicznym i na produkcji.

Wykonano testy celowane i Pint. **Nie wykonano pełnego zestawu, builda ani scripts/check.sh**: wynik jest projektem, bez implementacji i bez PR. Nie są oznaczone jako zaliczone. ProbaOdtworzeniaTest nie uruchamiano ani nie diagnozowano. Nie ma testów UI forka, benchmarku i badania z użytkownikami, bo funkcja nie powstała. Nie dostarczono prototypu wykonawczego.

Podstawy: AGENTS.md §1/5/6/7/10/11; PRODUCT, FEATURES, UX_50_PLUS, ARCHITECTURE, DATABASE, ROADMAP; DECISIONS D-018/D-022 (usuwanie), D-027 (idempotencja), D-065 (pakiety/flagi), D-088 (rollback), D-135/D-136 (formularz), D-153 (nazwy), D-156/D-161 (SEO), D-194 (Ugotowałem); KONSTYTUCJA_MARKI, COPY_STYLE, GLOS_MARKI, DESIGN_SYSTEM, SOUL, MODERATION i źródła SEO wskazane wyżej. Stan przypisany do SHA na początku dokumentu.
