<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Domain\Recipes\ExistingStepDuplicates;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\RecipeStatusTransitions;
use App\Domain\Recipes\StepTimer;
use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Zapis przepisu — szkicu albo publikacji.
 *
 * Kreator ma trzy kroki (informacje → składniki → przygotowanie) i po KAŻDYM
 * kroku zapisuje szkic. Reguła nadrzędna: przerwanie kreatora nie może
 * skasować niczego, co człowiek już wpisał. Dlatego szkic da się zapisać
 * z samym tytułem, a walidacja kompletności działa tylko przy publikacji.
 *
 * TOŻSAMOŚĆ KROKU, NIE JEGO POZYCJA (audyt zewnętrzny T12/T24)
 *
 * Krok przepisu ma zdjęcie (`recipe_steps.media_id`). Zdjęcia NIE da się
 * przysłać drugi raz przez POST — przeglądarka nie umie ponownie wysłać
 * pliku, którego człowiek nie wybrał w tym żądaniu. Formularz musi więc
 * powiedzieć serwerowi „ten wiersz to TEN krok, który już ma zdjęcie",
 * a serwer musi to zrozumieć niezależnie od tego, na której POZYCJI wiersz
 * przyszedł.
 *
 * Gdyby zdjęcia dopasowywać po pozycji („wiersz 0 dostaje zdjęcie kroku 0"),
 * to samo przestawienie kroków — albo zwykłe wyczyszczenie jednego wiersza,
 * bo puste wiersze są tu pomijane i pozostałe zjeżdżają o jedną pozycję —
 * przypisałoby zdjęcie „obierz ziemniaki" do kroku „wyjmij z piekarnika".
 * To jest gorsze niż brak funkcji: nie wygląda na awarię, więc nikt tego nie
 * zgłosi — po prostu przepis kłamie obrazkiem.
 *
 * Dlatego każdy wiersz kroku niesie `id` — identyfikator kroku, który JUŻ
 * istnieje w bazie — i zdjęcie rozwiązujemy PO TYM IDENTYFIKATORZE:
 *
 *   1. `media_id` — zdjęcie wgrane W TYM zapisie (wygrywa zawsze),
 *   2. `remove_media` — człowiek jawnie kazał zdjęcie odpiąć,
 *   3. zdjęcie kroku o tym `id` — czyli to, które ten krok już ma,
 *   4. brak zdjęcia.
 *
 * `id`, którego ten przepis nie ma, jest po prostu ignorowane. UUID w POST-cie
 * nie jest autoryzacją (AGENTS.md §7): mapa jest budowana WYŁĄCZNIE z kroków
 * tego przepisu, więc cudzy identyfikator nie ma czego dopasować.
 */
final class PublishRecipe
{
    /**
     * Osiem cyfr przed przecinkiem — tyle, ile mieści `decimal(12, 4)`
     * kolumny `recipe_ingredients.quantity`.
     */
    private const MAKS_ILOSC = 99999999.0;

    private const KOMUNIKAT_ILOSC_NIE_LICZBA = 'Ilość składnika musi być liczbą, na przykład 1,5. '
        .'Jeśli składnik nie ma wymiernej ilości, zaznacz „Bez ilości”.';

    private const KOMUNIKAT_ILOSC_UJEMNA = 'Ilość składnika nie może być ujemna. Wpisz na przykład 1,5.';

    private const KOMUNIKAT_ILOSC_ZA_DUZA = 'Ta ilość jest nierealna. Wpisz mniejszą liczbę.';

    private const KOMUNIKAT_NIEZNANA_JEDNOSTKA = 'Nie znam tej jednostki miary. Zostaw ilość bez jednostki.';

    /**
     * Jedno zdanie na DWIE drogi dojścia do tego samego faktu: kontrolę
     * wstępną (na modelu z zewnątrz) i rewalidację pod blokadą wiersza.
     * Dwie kopie tego tekstu rozjechałyby się przy pierwszej korekcie —
     * ta sama zasada co `JUZ_TRWA` w `DataSettingsController`.
     */
    private const PRZEPIS_ZAMROZONY_PRZEZ_MODERACJE = 'Ten przepis został ukryty przez moderację '
        .'i nie można go teraz zmieniać. Jeśli uważasz, że to pomyłka, napisz do nas: ';

    public function __construct(
        private readonly GenerateRecipeSlug $slugs,
        private readonly SnapshotRecipeVersion $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{text: string, group_name?: ?string, quantity?: mixed, unit_id?: ?string, note?: ?string, no_amount?: bool}>  $ingredients
     * @param  list<array{instruction: string, id?: ?string, timer_minutes?: mixed, media_id?: ?string, remove_media?: bool}>  $steps
     *
     * `timer_minutes` to MINUTY — dokładnie to, co wpisał człowiek, bez
     * przeliczania po drodze. Zamiana na sekundy `recipe_steps.timer_seconds`
     * należy do `StepTimer` i dzieje się TU, raz, dla obu dróg zapisu.
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza; `null` znaczy
     *                                      „nie wiemy, zapisuj normalnie" (ADR §4.3)
     */
    public function handle(
        User $author,
        array $attributes,
        array $ingredients = [],
        array $steps = [],
        bool $publish = false,
        ?Recipe $existing = null,
        ?string $ip = null,
        ?string $kluczWyslania = null,
    ): Recipe {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw new BladDlaCzlowieka('Podaj nazwę przepisu — choćby roboczą, zmienisz ją później.');
        }

        // Statusy moderacyjne są dla autora końcowe (audyt A08). Policy pilnuje
        // wejścia na adres, ale kreator Livewire i formularz bez JavaScriptu
        // kończą w TEJ akcji — więc reguła musi stać także tutaj, żeby nie dało
        // się jej obejść dodaniem drugiego endpointu (AGENTS.md §4).
        //
        // To jest kontrola WSTĘPNA, po to, żeby człowiek dostał zdanie po
        // polsku zamiast ekranu błędu. Gwarancji nie daje — model przyszedł
        // z zewnątrz, a między jego wczytaniem a zapisem moderator może
        // przepis ukryć. Gwarancję daje ta sama kontrola powtórzona POD
        // BLOKADĄ wiersza, w transakcji niżej (D-079 §2).
        if ($existing !== null && ! RecipeStatusTransitions::authorMayEdit($existing->status)) {
            throw new BladDlaCzlowieka(self::PRZEPIS_ZAMROZONY_PRZEZ_MODERACJE.$this->kontakt());
        }

        // Policy nie może być wyłącznie ochroną kontrolera. Tę akcję woła
        // także kreator Livewire, a w przyszłości mogą wołać ją zadania lub
        // importy. Jawny aktor pilnuje konkretnego istniejącego przepisu,
        // zanim odczytamy jego relacje albo zaczniemy transakcję zapisu.
        if ($existing !== null) {
            Gate::forUser($author)->authorize('update', $existing);
        }

        $cleanIngredients = $this->cleanIngredients($ingredients);
        $cleanSteps = $this->cleanSteps($steps);

        // WARUNEK BRZMI „czy po zapisie przepis BĘDZIE publiczny", nie „czy
        // ktoś kliknął Opublikuj".
        //
        // Wcześniej było `if ($publish)`, przez co zapis szkicu na JUŻ
        // OPUBLIKOWANYM przepisie omijał kontrolę kompletności. `syncIngredients()`
        // kasuje i odtwarza wiersze, a status zostawał `published` — więc jedno
        // kliknięcie przycisku, który brzmi jak prywatny zapis roboczy,
        // zamieniało opublikowany przepis w pustą skorupę:
        //
        //     status: published   ingredients: 0   steps: 0   versions: 0
        //
        // Strona publiczna nadal zwracała 200 i pokazywała „Autor jeszcze nie
        // dodał składników". Bez ostrzeżenia i bez wersji do odtworzenia,
        // bo snapshot powstaje tylko przy publikacji (audyt A07).
        $bedziePubliczny = $publish || ($existing !== null && $existing->isPublished());

        /*
         * SKŁADNIKI NIE SĄ WARUNKIEM PUBLIKACJI — ZGODA WŁAŚCICIELA
         * z 11.09.2026 (issue #364).
         *
         * Stało tu:
         *
         *     if ($cleanIngredients === []) {
         *         throw new BladDlaCzlowieka('Dodaj przynajmniej jeden składnik…');
         *     }
         *
         * i to była ostatnia bramka, która kazała człowiekowi rozstrzygnąć
         * strukturę przepisu, zanim wolno mu było cokolwiek opublikować.
         * Zgoda padła świadomie i wprost, w treści zgłoszenia: „przepis wolno
         * opublikować bez ani jednego składnika". Za pół roku nikt nie będzie
         * pamiętał, że była świadoma — dlatego pilnuje jej test regresyjny
         * `DodawaniePrzepisuSzescKontrolekTest`, a nie ten komentarz.
         *
         * KROK ZOSTAJE WARUNKIEM i to nie jest niekonsekwencja: przepis bez
         * składników dalej mówi, CO ZROBIĆ („zalej wodą, gotuj trzy godziny"),
         * a przepis bez ani jednego kroku nie mówi nic i nie da się z niego
         * ugotować — czyli nie jest przepisem, tylko listą zakupów.
         */
        if ($bedziePubliczny) {
            if ($cleanSteps === []) {
                throw new BladDlaCzlowieka('Opisz przynajmniej jeden krok przygotowania — bez tego przepis nie może być opublikowany.');
            }
        }

        /*
         * KLUCZ DOTYCZY ZAKŁADANIA PRZEPISU, NIE JEGO EDYCJI.
         *
         * `recipes.update` pracuje na wierszu, który już istnieje, i nie
         * przysyła klucza. Gdyby edycja kolumnę nadpisywała, pierwsze
         * zapisanie szczegółów zdejmowałoby ochronę z tego przepisu — a przy
         * pustej wartości robiłoby to po cichu.
         */
        $klucz = $existing === null ? $kluczWyslania : null;

        $zapisz = fn (?string $klucz): Recipe => DB::transaction(function () use (
            $author, $attributes, $title, $cleanIngredients, $cleanSteps, $publish, $existing, $klucz, $ip
        ): Recipe {
            /*
             * KROKI, KTÓRE PRZEPIS MA DZIŚ — czytane RAZ, na wejściu do
             * transakcji, i używane w dwóch miejscach: do listy kandydatów
             * do zablokowania (zaraz niżej) i do rozwiązania tożsamości
             * kroku w `syncSteps()`. Przedtem `syncSteps()` czytało to samo
             * u siebie, ale dopiero PO zapisaniu wiersza przepisu — a lista
             * do zablokowania musi być gotowa WCZEŚNIEJ (powód niżej).
             * Dwa odczyty tej samej rzeczy w jednej transakcji to dwie
             * okazje, żeby się rozjechały, więc odczyt jest jeden.
             */
            $istniejaceKroki = $existing === null
                ? new Collection
                : $existing->steps()->get()->keyBy(
                    static fn (RecipeStep $step): string => (string) $step->getKey(),
                );

            $duplicateErrors = ExistingStepDuplicates::errors($cleanSteps, $istniejaceKroki->keys());
            if ($duplicateErrors !== []) {
                throw new BladDlaCzlowieka(reset($duplicateErrors));
            }

            /*
             * WSZYSTKIE ZDJĘCIA TEGO ZAPISU BLOKOWANE JEDNYM ZAPYTANIEM
             * (D-103, dokończenie D-083).
             *
             * Trzy z czterech dróg domykanych w D-103 kończą się tutaj:
             * `recipes.hero_media_id`, `recipes.source_scan_media_id`
             * i `recipe_steps.media_id`. Wszystkie trzy mają w migracji
             * `2026_09_05_000400_create_recipes_tables` `nullOnDelete()`,
             * więc skasowanie wiersza `media` przez sprzątacz osieroconych
             * zdjęć nie zgłaszało konfliktu klucza obcego — po cichu zerowało
             * kolumnę. Przepis zostawał bez zdjęcia, plik znikał z R2 i nie
             * było ani wyjątku, ani wpisu w logu.
             *
             * JEDNO WYWOŁANIE, NIE TRZY. `zablokuj()` sortuje po `id`, więc
             * jedno zapytanie na wszystkie zdjęcia tego zapisu daje jedną,
             * globalnie deterministyczną kolejność blokowania. Trzy osobne
             * wywołania blokowałyby w trzech grupach — a dwa równoległe
             * zapisy, w których to samo zdjęcie raz jest główne, a raz stoi
             * przy kroku, zakleszczyłyby się nawzajem.
             *
             * STOI PRZED `Recipe::create()`/`update()` CELOWO. Zmierzone
             * dwiema sesjami psql na PostgreSQL 18 (pomiar opisany w D-103):
             * `INSERT INTO recipes` czeka i na wiersz `users` (klucz obcy
             * `author_id`), i na wiersz `media` (klucz obcy `hero_media_id`).
             * Blokada zdjęć postawiona wcześniej daje więc kolejność
             * `media` → `users` — tę samą, którą po D-083 biorą `PublishPost`
             * i `RecordCookedEvent`. Odwrócenie jej tutaj byłoby drugą
             * kolejnością blokad w jednym repozytorium, czyli zakleszczeniem
             * (D-079 §1, D-093).
             */
            $doPrzypiecia = ZdjeciaDoPrzypiecia::zablokuj(
                (string) $author->getKey(),
                $this->kandydaciDoPrzypiecia($attributes, $cleanSteps, $istniejaceKroki),
            );

            /*
             * WIERSZ AUTORA ZANIM WIERSZ PRZEPISU — INACZEJ JEST
             * ZAKLESZCZENIE Z EGZEKUCJĄ KASOWANIA KONTA (zmierzone 11.09.2026).
             *
             * `EraseAccountData::handle()` trzyma `users FOR UPDATE` przez
             * całą egzekucję i DOPIERO POTEM kasuje przepisy tej osoby
             * (`usunTresci()`), czyli bierze `users` → `recipes`. Ta akcja
             * szła odwrotnie: blokowała wiersz przepisu, a wiersz autora
             * brała później i mimochodem — `INSERT INTO recipe_versions`
             * sprawdza klucz obcy `editor_id` i zakłada na nim `FOR KEY
             * SHARE`, a ta blokada jest w konflikcie z `FOR UPDATE`
             * kasowania. Dwie kolejności w jednym repozytorium to
             * zakleszczenie, nie zabezpieczenie (D-079 §1, D-093, D-103).
             *
             * Zmierzone na dwóch połączeniach, PRAWDZIWYMI akcjami, nie
             * przepisanym SQL-em —
             * `tests/Dwa/EdycjaPrzepisuNieZakleszczaSieZKasowaniemKontaTest.php`:
             *
             *     SQLSTATE[40P01]: Deadlock detected … CONTEXT: while locking
             *     tuple in relation "users" … insert into "recipe_versions"
             *
             * Ofiarą był zapis autora: człowiek dostawał ekran błędu przy
             * zwykłym zapisie przepisu. Usterka jest STARSZA niż wciągnięcie
             * snapshotu do transakcji (audyt A01) — ten sam pomiar oblewa się
             * tak samo na kodzie sprzed tamtej zmiany, bo `INSERT` wkłada
             * wiersz do sterty PRZED sprawdzeniem klucza obcego, więc
             * kasowanie i tak czekało na niezatwierdzony wiersz
             * `recipe_versions`. To nie jest więc skutek A01, tylko rzecz
             * przy nim znaleziona.
             *
             * `FOR KEY SHARE`, a nie `ZamekKonta` i nie `FOR UPDATE`, z dwóch
             * powodów naraz. Po pierwsze `ZamekKonta` wziąłby `users` PRZED
             * `media`, a kolejność `media` → `users` jest w tym repozytorium
             * ustalona i zmierzona (D-103, komentarz klasy `PrzypnijAwatar`)
             * — byłoby zakleszczenie w drugą stronę. Po drugie to jest
             * DOKŁADNIE ta blokada, którą i tak za chwilę weźmie sprawdzenie
             * klucza obcego przy zapisie wersji; bierzemy ją tylko WCZEŚNIEJ.
             * Nie jest więc silniejsza od tej, którą ta transakcja i tak
             * trzymała na końcu, i nie ustawia w kolejce ani dwóch
             * równoległych edycji (`FOR KEY SHARE` nie jest w konflikcie sam
             * ze sobą), ani czyjegoś „Obserwuj".
             */
            DB::select('SELECT 1 FROM users WHERE id = ? FOR KEY SHARE', [(string) $author->getKey()]);

            $payload = [
                'title' => $title,
                'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
                'servings' => $attributes['servings'] ?? null,
                'prep_minutes' => $attributes['prep_minutes'] ?? null,
                'cook_minutes' => $attributes['cook_minutes'] ?? null,
                'difficulty' => $this->nullIfBlank($attributes['difficulty'] ?? null),
                'visibility' => $attributes['visibility'] ?? 'public',
                'hero_media_id' => $this->zdjecieDoPrzypiecia($attributes['hero_media_id'] ?? null, $doPrzypiecia),
                'source_type' => $attributes['source_type'] ?? Recipe::SOURCE_OWN,
                'source_url' => $this->nullIfBlank($attributes['source_url'] ?? null),
                'source_person' => $this->nullIfBlank($attributes['source_person'] ?? null),
                'source_note' => $this->nullIfBlank($attributes['source_note'] ?? null),
                'family_since_year' => $attributes['family_since_year'] ?? null,
                'source_scan_media_id' => $this->zdjecieDoPrzypiecia($attributes['source_scan_media_id'] ?? null, $doPrzypiecia),
            ];

            if ($existing === null) {
                $payload['author_id'] = $author->getKey();
                $payload['klucz_wyslania'] = $klucz;
                $payload['slug'] = $this->slugs->handle($title);
                $payload['status'] = $publish ? Recipe::STATUS_PUBLISHED : Recipe::STATUS_DRAFT;
                $payload['published_at'] = $publish ? now() : null;

                $recipe = Recipe::create($payload);
            } else {
                /*
                 * WIERSZ PRZEPISU POD BLOKADĄ, I DOPIERO POD NIĄ PYTAMY O STAN
                 * (audyt A01; D-079 §2 i §3).
                 *
                 * Dwie rzeczy naraz, bo obie wynikają z tej jednej linijki.
                 *
                 * PIERWSZA — SERIALIZACJA NUMERU WERSJI. `SnapshotRecipeVersion`
                 * liczy numer jako `max(version_number) + 1`. Przedtem snapshot
                 * stał POZA tą transakcją, więc blokada wiersza, którą bierze
                 * `UPDATE recipes`, była już zwolniona, kiedy numer się liczył —
                 * dwie równoległe edycje odczytywały to samo `max()` i drugiej
                 * odbijało `recipe_versions_recipe_id_version_number_unique`.
                 * Zmierzone na dwóch połączeniach:
                 * `tests/Dwa/NumerWersjiPrzepisuNieKolidujeTest.php`.
                 *
                 * TĘ CZĘŚĆ ZAŁATWIA SAMO WCIĄGNIĘCIE SNAPSHOTU DO TRANSAKCJI
                 * i warto to napisać wprost, bo pierwsza wersja tego komentarza
                 * twierdziła inaczej. `recipes` ma `timestampsTz()`, więc
                 * `$recipe->update()` zawsze przesuwa `updated_at` — zawsze jest
                 * więc pole brudne, zawsze leci `UPDATE` i zawsze bierze on
                 * blokadę wiersza. Kontrola ujemna to potwierdziła: zdjęcie
                 * `lockForUpdate()` niżej NIE oblało testu kolizji numerów.
                 *
                 * DRUGA — I TO JEST TA, KTÓREJ TA LINIJKA JEST POTRZEBNA:
                 * REWALIDACJA. Blokada bez ponownego sprawdzenia stanu niczego
                 * nie pilnuje (D-079 §2): serializuje, ale nie mówi żądaniu, że
                 * świat zmienił się, gdy ono czekało. Bez `lockForUpdate()`
                 * status czytalibyśmy PRZED wzięciem blokady — a wtedy zapis
                 * autora, który stał w kolejce, nadpisuje decyzję moderatora
                 * podjętą w trakcie tego czekania i PRZYWRACA ukryty przepis do
                 * sieci. Zmierzone na dwóch połączeniach, osobnym testem
                 * w tym samym pliku. Od tej chwili pracujemy na wierszu
                 * odczytanym pod blokadą, nie na modelu podanym z zewnątrz
                 * (D-079 §3).
                 *
                 * KOLEJNOŚĆ: `media` → `recipes`, czyli PO `zablokuj()`
                 * i ani chwili wcześniej (D-103 §2). Sprzątacz osieroconych
                 * zdjęć trzyma wiersz `media`, a kasując go, zeruje
                 * `recipes.hero_media_id` przez `nullOnDelete()` — czyli sięga
                 * po wiersz `recipes` JAKO DRUGI. Odwrócenie tego tutaj byłoby
                 * drugą kolejnością blokad w repozytorium, a to jest ta sama
                 * rodzina usterek, którą zamykały D-093 i D-103.
                 */
                $swiezy = Recipe::query()->whereKey($existing->getKey())->lockForUpdate()->first();

                if ($swiezy === null) {
                    throw new BladDlaCzlowieka(
                        'Tego przepisu już nie ma — w tym czasie został usunięty. '
                        .'Twój tekst jest jeszcze w formularzu, więc skopiuj go i zapisz jako nowy przepis.',
                    );
                }

                if (! RecipeStatusTransitions::authorMayEdit($swiezy->status)) {
                    throw new BladDlaCzlowieka(self::PRZEPIS_ZAMROZONY_PRZEZ_MODERACJE.$this->kontakt());
                }

                $recipe = $swiezy;

                // Slug zmieniamy tylko dla szkicu. Po publikacji adres
                // przepisu jest obietnicą — ludzie go zapisują i wysyłają.
                if (! $recipe->isPublished()) {
                    $payload['slug'] = $this->slugs->handle($title, $recipe->getKey());
                }

                // O zmianie statusu decyduje macierz przejść, nie pytanie
                // „czy przepis jest już opublikowany" (audyt A08). Tamto
                // pytanie odpowiadało „nie" także dla przepisu UKRYTEGO przez
                // moderatora, więc autor odzyskiwał go jednym kliknięciem.
                $docelowy = $publish ? Recipe::STATUS_PUBLISHED : Recipe::STATUS_DRAFT;

                if (RecipeStatusTransitions::authorMay($recipe->status, $docelowy)) {
                    $payload['status'] = $docelowy;

                    // Data publikacji jest obietnicą w archiwum — ustawiamy ją
                    // przy PIERWSZEJ publikacji i nie przestawiamy przy edycji.
                    $payload['published_at'] = $docelowy === Recipe::STATUS_PUBLISHED
                        ? ($recipe->published_at ?? now())
                        : null;
                }

                $recipe->update($payload);
            }

            $this->syncIngredients($recipe, $cleanIngredients);
            $this->syncSteps($recipe, $author, $cleanSteps, $istniejaceKroki, $doPrzypiecia);

            /*
             * OPUBLIKOWANY PRZEPIS WCHODZI DO STRUMIENI (issue #368).
             *
             * Wpis WSKAZUJE przepis przez `posts.recipe_id` — nie kopiuje
             * z niego ani tytułu, ani zdjęcia, ani widoczności. Cała reguła
             * (co zapisujemy, dlaczego nie dwa razy, dlaczego pod blokadą)
             * mieszka w `WpisWskazujacyPrzepis`, bo woła ją także komenda
             * uzupełniająca stare przepisy.
             *
             * STOI W TEJ SAMEJ TRANSAKCJI co zapis przepisu i to jest
             * warunek poprawności, nie estetyka: bramka „czy wpis już jest"
             * idzie po blokadzie wiersza `recipes`, a poza transakcją nie
             * byłoby czego blokować.
             */
            WpisWskazujacyPrzepis::dopisz($recipe);

            $recipe = $recipe->refresh();

            /*
             * HISTORIA POWSTAJE W TEJ SAMEJ TRANSAKCJI CO TREŚĆ (audyt A01, P1).
             *
             * Do 11.09.2026 te dwie linijki stały ZA `DB::transaction()`.
             * Skutek zmierzony testem, nie wyobrażony
             * (`tests/Feature/ZapisPrzepisuIHistoriiJestAtomowyTest.php`):
             * awaria zapisu wersji zostawiała
             *
             *     przepis w bazie: 1   wersji w historii: 0   wpisów w audycie: 0
             *
             * czyli człowiek widział błąd, a treść zmieniała się PUBLICZNIE —
             * i nie było wersji, z której dałoby się ją odtworzyć. Przy
             * pierwszej publikacji zostawał opublikowany przepis z pustą
             * historią; przy edycji — nowa treść bez śladu poprzedniej.
             * A `recipe_versions` istnieje w tym projekcie od pierwszego dnia
             * właśnie po to, żeby „przepis, który ktoś poprawił po trzech
             * latach, nie zjadł wersji, z której 40 osób gotowało"
             * (migracja `2026_09_05_000400_create_recipes_tables`).
             *
             * Wpis audytu idzie razem z nimi z tego samego powodu: zapis
             * „ten przepis został wtedy opublikowany" jest bezwartościowy,
             * jeśli może istnieć bez publikacji albo publikacja bez niego.
             *
             * Oba zapisy są BAZODANOWE, więc wejście do transakcji nic nie
             * kosztuje i nie dokłada żadnej nowej blokady: `INSERT INTO
             * recipe_versions` bierze na wierszu `users` tylko `FOR KEY SHARE`
             * (klucz obcy `editor_id`) i brał ją także przedtem — jedyna
             * różnica jest w tym, jak długo jest trzymana.
             *
             * Z KLUCZEM WYSŁANIA (idempotencja zakładania): jeśli indeks
             * `recipes_one_per_klucz_wyslania` odbije wiersz, cofa się cała ta
             * transakcja — razem z wersją i wpisem audytu — a pierwsze
             * wysłanie zapisało swoje we własnej transakcji. Duplikatu
             * historii więc nie ma i nie trzeba go niżej omijać.
             *
             * CZEGO TU NIE MA I NIE MA BYĆ: rzeczy NIEODWRACALNYCH. Wysyłka
             * listu, zapis pliku do R2 ani zadanie w kolejce nie mają prawa
             * stanąć w transakcji, bo cofnięcie transakcji ich nie cofnie
             * (D-083: „pliki znikają dopiero PO commicie").
             */
            if ($publish) {
                $this->snapshots->handle($recipe, $author, $existing === null ? 'Pierwsza publikacja' : 'Aktualizacja przepisu');

                AuditLogEntry::record(
                    action: 'recipe.published',
                    actor: $author,
                    subject: $recipe,
                    metadata: ['ingredients' => count($cleanIngredients), 'steps' => count($cleanSteps)],
                    ip: $ip,
                );
            }

            return $recipe;
        });

        try {
            $recipe = $zapisz($klucz);
        } catch (UniqueConstraintViolationException $e) {
            if ($klucz === null) {
                // Bez klucza nie ma jak odbić się o
                // `recipes_one_per_klucz_wyslania` — to inne ograniczenie
                // (np. `recipes.slug`) i nie wolno go tu wyciszyć.
                throw $e;
            }

            // Indeks `recipes_one_per_klucz_wyslania` odbił wiersz: to
            // wysłanie już raz założyło przepis. Oddajemy TEN przepis i nie
            // robimy drugiej wersji w `recipe_versions` ani drugiego wpisu
            // w dzienniku audytowym — jedno i drugie stoi W transakcji
            // (audyt A01), więc cofnęło się razem z odbitym wierszem,
            // a pierwsze wysłanie zapisało je we własnej.
            $istniejacy = $this->przepisZTegoWyslania($author, $klucz);

            if ($istniejacy !== null) {
                return $istniejacy;
            }

            // Klucz zajęty, a przepisu nie widać (np. został w tym czasie
            // usunięty). Nie odmawiamy — zapisujemy bez klucza, z ryzykiem
            // duplikatu (ADR §4.3).
            $recipe = $zapisz(null);
        }

        return $recipe;
    }

    private function kontakt(): string
    {
        return (string) config('kuking.community.contact_email');
    }

    /**
     * Przepis założony z TEGO wysłania formularza — jeśli został założony.
     *
     * Zawężone do autora, a nie zadane samemu kluczowi: `klucz_wyslania`
     * przychodzi z żądania, a UUID w żądaniu nie jest autoryzacją
     * (`AGENTS.md` §7). Bez `author_id` w zapytaniu klucz podstawiony
     * z cudzej przeglądarki odsyłałby człowieka pod cudzy przepis.
     */
    private function przepisZTegoWyslania(User $author, string $kluczWyslania): ?Recipe
    {
        return Recipe::query()
            ->where('author_id', $author->getKey())
            ->where('klucz_wyslania', $kluczWyslania)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @return list<array<string, mixed>>
     */
    private function cleanIngredients(array $ingredients): array
    {
        $clean = [];

        foreach ($ingredients as $row) {
            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            // „Bez ilości" (issue #44) WYGRYWA z ilością, a nie kłóci się z nią.
            //
            // Baza ma na to CHECK, więc wiersz z `no_amount = true` i wpisaną
            // ilością nie przeszedłby w ogóle — a to znaczy błąd 500 na
            // publikacji przepisu, czyli utratę całej pracy autora. Skoro
            // człowiek powiedział „do smaku", ilość jest tym, co odpada:
            // to jedyna interpretacja, która nie każe mu niczego poprawiać.
            $bezIlosci = (bool) ($row['no_amount'] ?? false);

            $clean[] = [
                // Nazwa grupy („Ciasto", „Farsz") przycięta do 120 znaków
                // kolumny — tak samo jak tekst składnika do 240. Bez tego
                // dłuższa wartość podana z konsoli, z fabryki albo
                // z przyszłego importu kończy się nie komunikatem, tylko
                // wyjątkiem SQL-a „value too long", czyli błędem 500 przy
                // publikacji. Formularze mają na to własne granice
                // (`ingredients.*.group_name` w kontrolerze, `validateRows()`
                // w kreatorze) i mówią o nich PRZY POLU; ta linijka jest
                // ostatnią, nie pierwszą.
                'group_name' => $this->clampOrNull($row['group_name'] ?? null, 120),
                'ingredient_text' => mb_substr($text, 0, 240),
                'quantity' => $bezIlosci ? null : $this->quantityOrNull($row['quantity'] ?? null),
                'unit_id' => $bezIlosci ? null : $this->unitIdOrNull($row['unit_id'] ?? null),
                'note' => $this->nullIfBlank($row['note'] ?? null),
                'no_amount' => $bezIlosci,
            ];
        }

        return $this->ujednolicNazwyGrup($clean);
    }

    /**
     * Jedna grupa — jedna pisownia W OBRĘBIE JEDNEGO PRZEPISU.
     *
     * Autor piszący dziesięć składników wpisze „Farsz" i „farsz", i będzie
     * miał rację: dla niego to jedno słowo. Bez tego przejścia byłyby to dwie
     * grupy w bazie — a stamtąd trafiłyby do eksportu danych i do przyszłego
     * przeliczania porcji jako dwie różne części przepisu.
     *
     * WYGRYWA PIERWSZA PISOWNIA, nie „ładniejsza". To słowo autora, więc
     * poprawiamy powtórzenie, a nie człowieka — i nie ma tu żadnej reguły
     * ortograficznej, którą trzeba by komuś tłumaczyć.
     *
     * NIE PRZESTAWIAMY WIERSZY. Kusi, żeby przy okazji poukładać składniki
     * grupami — ale wtedy „sól" wpisana na końcu wraca w edycji na środek
     * listy, a autor nigdy o to nie prosił. Wiersze zostają tam, gdzie je
     * postawił; sklejaniem grup w jeden nagłówek zajmuje się widok
     * (`App\Domain\Recipes\GrupySkladnikow`).
     *
     * @param  list<array<string, mixed>>  $ingredients
     * @return list<array<string, mixed>>
     */
    private function ujednolicNazwyGrup(array $ingredients): array
    {
        /** @var array<string, string> $pierwszaPisownia */
        $pierwszaPisownia = [];

        foreach ($ingredients as $row) {
            $nazwa = $row['group_name'] ?? null;

            if (! is_string($nazwa)) {
                continue;
            }

            $pierwszaPisownia[GrupySkladnikow::klucz($nazwa)] ??= $nazwa;
        }

        return array_map(
            static function (array $row) use ($pierwszaPisownia): array {
                $nazwa = $row['group_name'] ?? null;

                if (is_string($nazwa)) {
                    $row['group_name'] = $pierwszaPisownia[GrupySkladnikow::klucz($nazwa)];
                }

                return $row;
            },
            $ingredients,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function cleanSteps(array $steps): array
    {
        $clean = [];

        foreach ($steps as $row) {
            $instruction = trim((string) ($row['instruction'] ?? ''));

            // Wiersz bez treści jest pomijany W CAŁOŚCI — razem z minutnikiem
            // i ze zdjęciem. Krok, który nie mówi, co zrobić, nie jest krokiem,
            // a minutnik bez czynności nie ma czego odliczać.
            if ($instruction === '') {
                continue;
            }

            $clean[] = [
                // Tożsamość kroku, nie jego pozycja (patrz komentarz klasy).
                'id' => $this->nullIfBlank($row['id'] ?? null),
                'instruction' => $instruction,
                // Minuty od człowieka → sekundy do bazy, w JEDNYM miejscu.
                'timer_seconds' => StepTimer::secondsFromMinutes($row['timer_minutes'] ?? null),
                'media_id' => $this->nullIfBlank($row['media_id'] ?? null),
                'remove_media' => (bool) ($row['remove_media'] ?? false),
            ];
        }

        return $clean;
    }

    /**
     * Ilość składnika — bramka domenowa, nie pole formularza.
     *
     * FORMULARZ PRZEPISU O ILOŚĆ NIE PYTA I NA RAZIE NIE MA PYTAĆ: D-017
     * rozstrzygnął, że składnik zostaje jednym polem wolnego tekstu
     * („szklanka mąki"), bo „tyle, żeby ciasto było miękkie" nie ma pola
     * na ilość. Kolumny `quantity` i `unit_id` istnieją mimo to i mają CHECK
     * `quantity IS NULL OR quantity >= 0`, a wartość tu przychodząca szła
     * dotąd do bazy bez żadnego sprawdzenia.
     *
     * Ta metoda zamyka tę drogę dla wszystkiego, co NIE jest formularzem:
     * konsoli, fabryki, przyszłego importu i przyszłej podpowiedzi AI
     * (AGENTS.md §9, D-017 „Zmiana wymaga"). Wartość ujemna trafiłaby dziś
     * w CHECK bazy, czyli w błąd 500 zamiast w komunikat; wartość tekstowa —
     * w błąd rzutowania Postgresa.
     *
     * @throws BladDlaCzlowieka
     */
    private function quantityOrNull(mixed $quantity): ?float
    {
        if ($quantity === null) {
            return null;
        }

        if (! is_scalar($quantity) || is_bool($quantity)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ILOSC_NIE_LICZBA);
        }

        // Przecinek dziesiętny jest polskim zapisem i wolno go używać —
        // „1,5 kg" to nie błąd człowieka, to nasza konwencja zapisu liczb.
        $text = str_replace(',', '.', trim((string) $quantity));

        if ($text === '') {
            return null;
        }

        if (! is_numeric($text)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ILOSC_NIE_LICZBA);
        }

        $wartosc = (float) $text;

        if ($wartosc < 0) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ILOSC_UJEMNA);
        }

        // Kolumna to `decimal(12, 4)`, czyli osiem cyfr przed przecinkiem.
        // Bez tej granicy Postgres odrzuca wiersz wyjątkiem „numeric field
        // overflow" — czyli błędem 500 i utratą pracy autora, zamiast zdania
        // o tym, co poprawić.
        if ($wartosc > self::MAKS_ILOSC) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ILOSC_ZA_DUZA);
        }

        return $wartosc;
    }

    /**
     * Jednostka miary — musi być jednostką ZE SŁOWNIKA (`units`, `UnitSeeder`).
     *
     * `unit_id` ma klucz obcy, więc nieznany UUID nie kończy się komunikatem,
     * tylko wyjątkiem SQL-a — czyli błędem 500 na publikacji przepisu. Ta
     * sama uwaga co przy ilości: pytanie „czy ta jednostka istnieje" musi być
     * zadane w warstwie, przez którą przechodzą wszystkie drogi zapisu.
     *
     * @throws BladDlaCzlowieka
     */
    private function unitIdOrNull(mixed $unitId): ?string
    {
        $id = $this->nullIfBlank($unitId);

        if ($id === null) {
            return null;
        }

        if (! Unit::query()->whereKey($id)->exists()) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_NIEZNANA_JEDNOSTKA);
        }

        return $id;
    }

    /** @param  list<array<string, mixed>>  $ingredients */
    private function syncIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();

        foreach ($ingredients as $position => $row) {
            RecipeIngredient::create([
                'recipe_id' => $recipe->getKey(),
                'group_name' => $row['group_name'],
                // Normalizacja jest DODATKIEM do tekstu użytkownika, nigdy go
                // nie zastępuje — tekst zostaje dokładnie taki, jak wpisany.
                'ingredient_id' => Ingredient::findOrCreateByName($row['ingredient_text'])->getKey(),
                'ingredient_text' => $row['ingredient_text'],
                'quantity' => $row['quantity'],
                'unit_id' => $row['unit_id'],
                'note' => $row['note'],
                'no_amount' => $row['no_amount'] ?? false,
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  Collection<string, RecipeStep>  $istniejace  mapa TOŻSAMOŚCI kroków, które
     *                                                      przepis ma DZIŚ — zbudowana
     *                                                      w `handle()`, PRZED skasowaniem
     *                                                      wierszy i wyłącznie z kroków TEGO
     *                                                      przepisu. To jest cała autoryzacja
     *                                                      `id` z POST-a: identyfikator kroku
     *                                                      z cudzego przepisu nie ma tu czego
     *                                                      dopasować, więc nie da się nim
     *                                                      podpiąć cudzego zdjęcia
     *                                                      (AGENTS.md §7)
     * @param  list<string>  $doPrzypiecia  zdjęcia zablokowane na tę transakcję
     */
    private function syncSteps(Recipe $recipe, User $author, array $steps, Collection $istniejace, array $doPrzypiecia): void
    {
        /*
         * TOZSAMOSC KROKU PRZEZYWA ZAPIS (issue #756).
         *
         * Stalo tu `$recipe->steps()->delete()` przed petla, a petla zawsze
         * wolala `RecipeStep::create()` -- czyli KAZDY zapis przepisu, nawet
         * poprawka literowki w jednym kroku, kasowala wszystkie wiersze
         * `recipe_steps` i zakladala je od nowa z nowymi UUID-ami
         * (`HasUuids` losuje identyfikator przy `create()`).
         *
         * Tryb gotowania trzyma "zrobione kroki" w sesji jako liste TYCH
         * identyfikatorow (`CookingModeController::sessionKey()`). Nowy UUID
         * po zapisie znaczyl, ze sesja wskazywala na wiersz, ktorego juz nie
         * ma -- postep znikal po cichu, bez bledu i bez ostrzezenia, mimo ze
         * krok o tej samej tresci nadal tam stal. To jest dokladnie ten
         * rodzaj utraty danych, ktorego AGENTS.md zakazuje wprost:
         * "poprawne dane nigdy nie znikaja".
         *
         * Naprawa: krok o `id`, ktore PRZEPIS MA DZIS (czyli jest w mapie
         * `$istniejace`, zbudowanej w `handle()` przed jakakolwiek zmiana),
         * dostaje `update()` na TYM SAMYM wierszu -- identyfikator zostaje.
         * Wiersz bez znanego `id` (nowy krok dopisany w tym zapisie) dostaje
         * `create()`. Kroki, ktorych w tym zapisie juz nie ma (usuniete przez
         * autora), sa kasowane NAJPIERW, przed przestawieniem pozycji --
         * uzasadnienie kolejnosci nizej.
         */
        // Identyfikatory krokow, ktore ten zapis ZATRZYMUJE -- wyliczone
        // z samego wejscia, bez dotykania bazy, wiec da sie ich uzyc, zeby
        // NAJPIERW skasowac kroki usuniete przez autora. Kolejnosc ma
        // znaczenie: unique(recipe_id, position) jest sprawdzany natychmiast
        // (Postgres nie odklada go do konca transakcji), a skasowanie
        // usunietych wierszy PRZED przestawieniem pozycji zwalnia miejsca,
        // o ktore mogloby sie potkniec przypisanie nizej.
        $trzymaneId = [];

        foreach ($steps as $row) {
            $id = $this->nullIfBlank($row['id'] ?? null);

            if ($id !== null && $istniejace->has($id)) {
                $trzymaneId[] = $id;
            }
        }

        $recipe->steps()->whereNotIn('id', $trzymaneId)->delete();

        /*
         * PRZESTAWIENIE POZYCJI NA TYMCZASOWE, WYSOKIE WARTOSCI.
         *
         * Krok, ktory byl na pozycji 1, a po edycji ma byc na pozycji 0,
         * probowalby wejsc na pozycje zajeta jeszcze przez INNY zatrzymany
         * krok, ktory nie zdazyl jeszcze zejsc ze swojej starej pozycji --
         * `UPDATE ... SET position = 0` na wiersz A, gdy wiersz B wciaz stoi
         * na pozycji 0, konczy sie "duplicate key value violates unique
         * constraint recipe_steps_recipe_id_position_unique" (zlapane
         * testem regresyjnym). Ujemna wartosc odpada -- baza ma CHECK
         * `position >= 0` (zlapane tym samym testem, drugim bledem).
         * Zamiast tego przesuwamy tymczasowo o liczbe wieksza niz liczba
         * krokow w tym zapisie, czyli poza kazdy docelowy zakres 0..N-1 --
         * te wartosci sa zawsze wolne, bo zaden prawdziwy krok nigdy nie
         * dochodzi do tylu pozycji.
         */
        $przesuniecie = count($steps) + count($trzymaneId) + 1;
        $zachowaneKroki = $istniejace->only($trzymaneId)->values();

        foreach ($zachowaneKroki as $i => $krok) {
            $krok->forceFill(['position' => $przesuniecie + $i])->save();
        }

        $zachowane = [];

        foreach ($steps as $position => $row) {
            $id = $this->nullIfBlank($row['id'] ?? null);
            $istniejacyKrok = $id === null ? null : $istniejace->get($id);

            $payload = [
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => $row['instruction'],
                'timer_seconds' => $row['timer_seconds'],
                'media_id' => $this->stepMediaId($author, $istniejace, $row, $doPrzypiecia),
            ];

            if ($istniejacyKrok !== null) {
                $istniejacyKrok->update($payload);
                $zachowane[] = $istniejacyKrok->getKey();
            } else {
                $zachowane[] = RecipeStep::create($payload)->getKey();
            }
        }
    }

    /**
     * Zdjęcie kroku — rozwiązywane po TOŻSAMOŚCI kroku, nigdy po pozycji.
     *
     * @param  Collection<string, RecipeStep>  $istniejace
     * @param  array<string, mixed>  $row
     * @param  list<string>  $doPrzypiecia
     *
     * @throws BladDlaCzlowieka
     */
    private function stepMediaId(User $author, Collection $istniejace, array $row, array $doPrzypiecia): ?string
    {
        $nowe = $row['media_id'] ?? null;

        if ($nowe !== null) {
            // WŁAŚCICIEL, NIE SAM UUID. `media_id` przychodzi ze stanu
            // komponentu Livewire (`$steps` jest zwykłą publiczną właściwością,
            // więc klient umie ją podmienić — `#[Locked]` nie działa na
            // pojedynczy element tablicy). Bez tego sprawdzenia dałoby się
            // przypiąć do własnego przepisu cudze zdjęcie, znając jego
            // identyfikator — a przepis publiczny pokazałby je światu.
            //
            // TO `exists()` ZOSTAJE, MIMO ŻE BLOKADA W `handle()` PYTA O TO
            // SAMO (D-079 §4). Ono służy KOMUNIKATOWI, nie gwarancji: cudze
            // zdjęcie ma dać zdanie po polsku mówiące, co zrobić, a nie ciche
            // zapisanie kroku bez zdjęcia. Gwarancję daje
            // `zdjecieDoPrzypiecia()` niżej — i te dwie odpowiedzi trzeba
            // rozróżnić, bo znaczą co innego: „to nie jest Twoje zdjęcie" to
            // pomyłka do poprawienia, a „to zdjęcie właśnie odchodzi" nie
            // jest niczyją pomyłką i nie ma prawa zabrać człowiekowi
            // wpisanego przepisu.
            $wlasne = Media::query()
                ->where('owner_id', $author->getKey())
                ->whereKey($nowe)
                ->exists();

            if (! $wlasne) {
                throw new BladDlaCzlowieka(
                    'Nie udało się dołączyć zdjęcia do jednego z kroków. Wybierz je jeszcze raz — '
                    .'resztę przepisu masz zapisaną.',
                );
            }

            return $this->zdjecieDoPrzypiecia((string) $nowe, $doPrzypiecia);
        }

        if (($row['remove_media'] ?? false) === true) {
            return null;
        }

        $id = $row['id'] ?? null;

        if ($id === null) {
            return null;
        }

        return $this->zdjecieDoPrzypiecia($istniejace->get((string) $id)?->media_id, $doPrzypiecia);
    }

    /**
     * Wszystkie zdjęcia, które ten zapis MOŻE przypiąć — do zablokowania
     * jednym zapytaniem.
     *
     * Lista obejmuje też zdjęcia PRZENOSZONE: zdjęcie główne i skan, które
     * `RecipeController::update()` przepisuje z poprzedniego stanu przepisu,
     * oraz zdjęcia kroków dziedziczone po tożsamości. Kusi, żeby ich nie
     * blokować — przecież są już przypięte, więc sprzątacz i tak ich nie
     * tknie. Ale `syncSteps()` KASUJE wiersze kroków i tworzy je od nowa,
     * więc odwołanie do zdjęcia kroku przestaje i zaczyna istnieć w tej samej
     * transakcji. Blokada kosztuje tu tyle co nic (te wiersze są już
     * w zapytaniu) i zdejmuje konieczność udowadniania, że akurat ta jedna
     * droga okna nie ma.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $steps
     * @param  Collection<string, RecipeStep>  $istniejace
     * @return list<string>
     */
    private function kandydaciDoPrzypiecia(array $attributes, array $steps, Collection $istniejace): array
    {
        $kandydaci = [
            $this->nullIfBlank($attributes['hero_media_id'] ?? null),
            $this->nullIfBlank($attributes['source_scan_media_id'] ?? null),
        ];

        foreach ($steps as $row) {
            $kandydaci[] = $this->nullIfBlank($row['media_id'] ?? null);

            $id = $this->nullIfBlank($row['id'] ?? null);

            if ($id !== null) {
                $kandydaci[] = $this->nullIfBlank($istniejace->get($id)?->media_id);
            }
        }

        return array_values(array_unique(array_filter($kandydaci)));
    }

    /**
     * Identyfikator zdjęcia, jeśli TA transakcja naprawdę trzyma je pod
     * blokadą — inaczej `null`.
     *
     * `null` ZAMIAST WYJĄTKU jest tu wyborem, nie niedbałością. Zdjęcie
     * wypada z tej listy tylko wtedy, gdy sprzątacz przejął je już do
     * skasowania — czyli nie z winy człowieka, który właśnie zapisuje
     * przepis. Odmowa zapisu zabrałaby mu wszystko, co wpisał, żeby ukarać
     * go za cudze sprzątanie; przepis zapisuje się więc bez tego jednego
     * zdjęcia, dokładnie tak samo jak wpis w `PublishPost` (D-083).
     *
     * Tym różni się ta droga od awatara (`PrzypnijAwatar`), gdzie ekran ma
     * jedno pole i jedną czynność, więc cichy zapis bez zdjęcia byłby
     * kłamstwem, a nie ratunkiem — uzasadnienie stoi w D-103.
     *
     * @param  list<string>  $doPrzypiecia
     */
    private function zdjecieDoPrzypiecia(?string $mediaId, array $doPrzypiecia): ?string
    {
        $mediaId = $this->nullIfBlank($mediaId);

        if ($mediaId === null) {
            return null;
        }

        return in_array($mediaId, $doPrzypiecia, true) ? $mediaId : null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** To samo co `nullIfBlank()`, tylko dodatkowo w granicy kolumny. */
    private function clampOrNull(mixed $value, int $length): ?string
    {
        $trimmed = $this->nullIfBlank($value);

        return $trimmed === null ? null : mb_substr($trimmed, 0, $length);
    }
}
