<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\ZdjeciaDoPrzypiecia;
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
        if ($existing !== null && ! RecipeStatusTransitions::authorMayEdit($existing->status)) {
            throw new BladDlaCzlowieka(
                'Ten przepis został ukryty przez moderację i nie można go teraz zmieniać. '
                .'Jeśli uważasz, że to pomyłka, napisz do nas: '.config('kuking.community.contact_email'),
            );
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

            $payload = [
                'author_id' => $author->getKey(),
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
                $payload['klucz_wyslania'] = $klucz;
                $payload['slug'] = $this->slugs->handle($title);
                $payload['status'] = $publish ? Recipe::STATUS_PUBLISHED : Recipe::STATUS_DRAFT;
                $payload['published_at'] = $publish ? now() : null;

                $recipe = Recipe::create($payload);
            } else {
                $recipe = $existing;

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

            // UPDATE przepisu (a przy tworzeniu INSERT) trzyma blokadę do commitu.
            // Migawka i audyt muszą powstać przed jej zwolnieniem (#895).
            $recipe->refresh();

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
            if ($klucz === null || ! str_contains($e->getMessage(), 'recipes_one_per_klucz_wyslania')) {
                // Bez klucza nie ma jak odbić się o
                // `recipes_one_per_klucz_wyslania` — to inne ograniczenie
                // (np. `recipes.slug`) i nie wolno go tu wyciszyć.
                throw $e;
            }

            // Indeks `recipes_one_per_klucz_wyslania` odbił wiersz: to
            // wysłanie już raz założyło przepis. Oddajemy TEN przepis i nie
            // robimy drugiej wersji w `recipe_versions` ani drugiego wpisu
            // w dzienniku audytowym — oba zapisy zatwierdza ta sama
            // transakcja, która zarezerwowała klucz wysłania.
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
        $recipe->steps()->delete();

        foreach ($steps as $position => $row) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => $row['instruction'],
                'timer_seconds' => $row['timer_seconds'],
                'media_id' => $this->stepMediaId($author, $istniejace, $row, $doPrzypiecia),
            ]);
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
