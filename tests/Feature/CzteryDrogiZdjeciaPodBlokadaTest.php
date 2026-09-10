<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\PrzypnijAwatar;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CZTERY DROGI, KTÓRE D-083 ZOSTAWIŁO BEZ BLOKADY (issue #285, D-103).
 *
 * D-083 przepuściło przez `ZdjeciaDoPrzypiecia` dwie drogi — wpis
 * („Opublikuj") i wykonanie („Ugotowałem") — a w sekcji „Co zostaje otwarte"
 * wypisało cztery, które zostają bez blokady:
 *
 *   1. awatar               `profiles.avatar_media_id`
 *   2. zdjęcie główne       `recipes.hero_media_id`
 *   3. skan zeszytu         `recipes.source_scan_media_id`
 *   4. zdjęcie kroku        `recipe_steps.media_id`
 *
 * STAN SPRZED ZMIANY — SPRAWDZONY W PLIKACH, NIE PRZEPISANY Z ISSUE
 *
 *  - `AvatarSettingsController::update()` robił `$profile->update([...])`
 *    bez transakcji i bez blokady wiersza `media`;
 *  - `PublishRecipe::handle()` wkładał `hero_media_id` i `source_scan_media_id`
 *    prosto z `$attributes` do `$payload`, a `stepMediaId()` zwracał
 *    `media_id` po sprawdzeniu samej WŁASNOŚCI (`exists()` bez blokady).
 *
 * Wszystkie cztery kolumny mają `nullOnDelete()` (migracje
 * `2026_09_05_000200_create_profiles_table` i
 * `2026_09_05_000400_create_recipes_tables`), więc skasowanie wiersza `media`
 * przez sprzątacz osieroconych zdjęć NIE zgłaszało konfliktu klucza obcego —
 * po cichu zerowało kolumnę. Przepis albo profil zostawał bez zdjęcia, plik
 * znikał z R2, i nie było ani wyjątku, ani wpisu w logu.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO TE TESTY NIE PILNUJĄ — I DLACZEGO
 * ══════════════════════════════════════════════════════════════════════
 *
 * Tak samo jak `ZdjecieNieZnikaPrzyPrzypinaniuTest`: NIE odtwarzają
 * wymuszonego przeplotu na dwóch połączeniach do PostgreSQL.
 * `RefreshDatabase` trzyma dane testu w NIEZATWIERDZONEJ transakcji, więc
 * drugie połączenie nie zobaczyłoby ani konta, ani zdjęcia
 * (`docs/PULAPKI_TESTOW.md`, pułapka 6).
 *
 * Testowany jest KONTRAKT, i to na każdej z czterech dróg OSOBNO: zdjęcie
 * przejęte do skasowania (`status = deleted`, znacznik z D-083 „kasowanie
 * trwa") nie ma prawa trafić do kolumny tej drogi. Każdy z czterech testów
 * ma przy tym własną KONTROLĘ DODATNIĄ — zdrowe zdjęcie w tej samej
 * kolumnie, w tym samym teście (`docs/PULAPKI_TESTOW.md`, pułapka 4).
 * Bez niej „kolumna jest pusta" przechodziłoby także wtedy, gdyby
 * przypinanie nie działało wcale.
 *
 * Serializacja `FOR UPDATE` z blokadą, którą PostgreSQL bierze sam przy
 * sprawdzaniu klucza obcego, NIE jest tu mierzona — ale w odróżnieniu od
 * D-083 nie jest już też przyjęta z dokumentacji: została zmierzona dwiema
 * sesjami psql przy pisaniu D-103 i pomiar jest w tamtym wpisie.
 */
class CzteryDrogiZdjeciaPodBlokadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    /** Zdjęcie gotowe do przypięcia. */
    private function zdrowe(User $wlasciciel): Media
    {
        return Media::factory()->create(['owner_id' => $wlasciciel->getKey()]);
    }

    /**
     * Zdjęcie PRZEJĘTE przez sprzątacza do skasowania — znacznik z D-083.
     *
     * To jest zatwierdzona, widoczna dla innych transakcji deklaracja „to
     * zdjęcie odchodzi": pliki są w tej chwili kasowane albo zaraz będą.
     * Kolumna, do której takie zdjęcie trafi, zostanie wyzerowana przez
     * `ON DELETE SET NULL`, a plik nie będzie już istniał nigdzie.
     */
    private function przejete(User $wlasciciel): Media
    {
        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);
    }

    /**
     * Najkrótszy komplet danych, jakiego `PublishRecipe` wymaga do
     * PUBLIKACJI — bo dopiero publikacja przechodzi przez kontrolę
     * kompletności.
     *
     * @return list<array<string, mixed>>
     */
    private function skladniki(): array
    {
        return [['text' => 'mąka', 'no_amount' => true]];
    }

    /** @return list<array<string, mixed>> */
    private function kroki(?string $mediaId = null): array
    {
        return [[
            'instruction' => 'Wymieszaj wszystko i odstaw na godzinę.',
            'media_id' => $mediaId,
        ]];
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     * @param  list<array<string, mixed>>  $kroki
     */
    private function zapisz(User $autor, array $atrybuty, array $kroki): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => 'Placki babci', ...$atrybuty],
            ingredients: $this->skladniki(),
            steps: $kroki,
            publish: true,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  DROGA 1 — AWATAR (`profiles.avatar_media_id`)
    // ═══════════════════════════════════════════════════════════════

    public function test_awatara_nie_da_sie_ustawic_na_zdjeciu_przejetym_do_skasowania(): void
    {
        $basia = $this->user('basia');

        // KONTROLA DODATNIA: zdrowe zdjęcie NAPRAWDĘ się przypina. Bez tego
        // asercja niżej przechodziłaby też wtedy, gdyby przypinanie awatara
        // nie działało w ogóle (pułapka 4).
        $zdrowe = $this->zdrowe($basia);
        app(PrzypnijAwatar::class)->handle($basia, $zdrowe);

        $this->assertSame(
            (string) $zdrowe->getKey(),
            (string) Profile::query()->where('user_id', $basia->getKey())->value('avatar_media_id'),
            'Zdrowe zdjęcie musi dać się ustawić jako awatar.',
        );

        // A teraz to samo zdjęcie przejęte przez sprzątacza.
        $przejete = $this->przejete($basia);

        try {
            app(PrzypnijAwatar::class)->handle($basia, $przejete);
            $this->fail('Zdjęcie przejęte do skasowania nie ma prawa zostać awatarem.');
        } catch (BladDlaCzlowieka $e) {
            // Komunikat po polsku, mówiący CO ZROBIĆ (AGENTS.md §11).
            $this->assertStringContainsString('Wybierz je jeszcze raz', $e->getMessage());
        }

        // Poprzednie zdjęcie zostaje na miejscu — odmowa nie ma prawa
        // zabrać człowiekowi tego, co już miał.
        $this->assertSame(
            (string) $zdrowe->getKey(),
            (string) Profile::query()->where('user_id', $basia->getKey())->value('avatar_media_id'),
        );
    }

    /**
     * ODMIENNOŚĆ TEJ DROGI (D-103): awatar ZASTĘPUJE poprzednie zdjęcie,
     * więc traci się je nie tylko przez przypięcie, ale i przez ODPIĘCIE.
     *
     * Kontroler czytał `$profile->avatar` i zerował kolumnę bezwarunkowo,
     * czyli działał na modelu podanym z zewnątrz (D-079 §3). Dwie karty tej
     * samej strony wystarczały, żeby kliknięcie „Usuń zdjęcie" w karcie
     * otwartej wcześniej odpięło zdjęcie wgrane później — a odpięte zdjęcie
     * zabiera po dobie sprzątacz osieroconych, razem z plikami.
     */
    public function test_usuniecie_awatara_nie_odpina_zdjecia_wgranego_w_miedzyczasie(): void
    {
        $basia = $this->user('basia');
        $przypinanie = app(PrzypnijAwatar::class);

        $stare = $this->zdrowe($basia);
        $przypinanie->handle($basia, $stare);

        // KONTROLA DODATNIA: odpięcie zdjęcia, które NAPRAWDĘ wisi
        // w kolumnie, działa i zwraca `true`.
        $this->assertTrue(
            $przypinanie->odepnij($basia, $stare),
            'Odpięcie aktualnego awatara musi się udać — inaczej „Usuń zdjęcie" nigdy nic nie robi.',
        );
        $this->assertNull(Profile::query()->where('user_id', $basia->getKey())->value('avatar_media_id'));

        // Teraz przeplot dwóch kart: karta A trzyma model zdjęcia `stare`,
        // karta B w międzyczasie wgrywa `nowe`.
        $przypinanie->handle($basia, $stare);
        $nowe = $this->zdrowe($basia);
        $przypinanie->handle($basia, $nowe);

        $this->assertFalse(
            $przypinanie->odepnij($basia, $stare),
            'Odpięcie zdjęcia, na które profil już nie wskazuje, musi zostać odmówione.',
        );

        $this->assertSame(
            (string) $nowe->getKey(),
            (string) Profile::query()->where('user_id', $basia->getKey())->value('avatar_media_id'),
            'Zdjęcie wgrane w drugiej karcie nie ma prawa zniknąć przez „Usuń zdjęcie" w pierwszej.',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  DROGA 2 — ZDJĘCIE GŁÓWNE PRZEPISU (`recipes.hero_media_id`)
    // ═══════════════════════════════════════════════════════════════

    public function test_zdjecia_przejetego_do_skasowania_nie_da_sie_ustawic_jako_glowne_przepisu(): void
    {
        $basia = $this->user('basia');

        // KONTROLA DODATNIA w tej samej kolumnie i tym samym teście.
        $zdrowe = $this->zdrowe($basia);
        $zeZdjeciem = $this->zapisz($basia, ['hero_media_id' => (string) $zdrowe->getKey()], $this->kroki());

        $this->assertSame(
            (string) $zdrowe->getKey(),
            (string) $zeZdjeciem->hero_media_id,
            'Zdrowe zdjęcie musi dać się ustawić jako główne zdjęcie przepisu.',
        );

        $przejete = $this->przejete($basia);
        $bezZdjecia = $this->zapisz($basia, ['hero_media_id' => (string) $przejete->getKey()], $this->kroki());

        $this->assertNull(
            $bezZdjecia->hero_media_id,
            'Zdjęcie przejęte do skasowania nie ma prawa trafić do `recipes.hero_media_id`.',
        );

        // Przepis powstaje MIMO WSZYSTKO — poprawnie wpisane dane nie znikają
        // człowiekowi z powodu cudzego sprzątania (AGENTS.md §5).
        $this->assertTrue($bezZdjecia->isPublished());
        $this->assertSame('Placki babci', $bezZdjecia->title);
        $this->assertSame(1, $bezZdjecia->steps()->count());
    }

    // ═══════════════════════════════════════════════════════════════
    //  DROGA 3 — SKAN ZESZYTU (`recipes.source_scan_media_id`)
    // ═══════════════════════════════════════════════════════════════

    public function test_zdjecia_przejetego_do_skasowania_nie_da_sie_ustawic_jako_skan_zeszytu(): void
    {
        $basia = $this->user('basia');

        $zdrowe = $this->zdrowe($basia);
        $zeSkanem = $this->zapisz($basia, ['source_scan_media_id' => (string) $zdrowe->getKey()], $this->kroki());

        $this->assertSame(
            (string) $zdrowe->getKey(),
            (string) $zeSkanem->source_scan_media_id,
            'Zdrowy skan zeszytu musi dać się zapisać.',
        );

        $przejete = $this->przejete($basia);
        $bezSkanu = $this->zapisz($basia, ['source_scan_media_id' => (string) $przejete->getKey()], $this->kroki());

        $this->assertNull(
            $bezSkanu->source_scan_media_id,
            'Zdjęcie przejęte do skasowania nie ma prawa trafić do `recipes.source_scan_media_id`.',
        );

        $this->assertTrue($bezSkanu->isPublished());
        $this->assertSame('Placki babci', $bezSkanu->title);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DROGA 4 — ZDJĘCIE KROKU (`recipe_steps.media_id`)
    // ═══════════════════════════════════════════════════════════════

    public function test_zdjecia_przejetego_do_skasowania_nie_da_sie_dolaczyc_do_kroku(): void
    {
        $basia = $this->user('basia');

        $zdrowe = $this->zdrowe($basia);
        $zeZdjeciem = $this->zapisz($basia, [], $this->kroki((string) $zdrowe->getKey()));

        $this->assertSame(
            (string) $zdrowe->getKey(),
            (string) $zeZdjeciem->steps()->first()->media_id,
            'Zdrowe zdjęcie musi dać się dołączyć do kroku przepisu.',
        );

        $przejete = $this->przejete($basia);
        $bezZdjecia = $this->zapisz($basia, [], $this->kroki((string) $przejete->getKey()));

        $krok = $bezZdjecia->steps()->first();

        $this->assertNull(
            $krok->media_id,
            'Zdjęcie przejęte do skasowania nie ma prawa trafić do `recipe_steps.media_id`.',
        );

        // Treść kroku zostaje — tracimy zdjęcie, nie przepis.
        $this->assertSame('Wymieszaj wszystko i odstaw na godzinę.', $krok->instruction);
    }

    /**
     * DRUGA GAŁĄŹ TEJ SAMEJ DROGI — zdjęcie ODZIEDZICZONE po tożsamości
     * kroku, a nie wgrane w tym żądaniu.
     *
     * `stepMediaId()` ma dwa wyjścia oddające `media_id`: nowo wgrane
     * zdjęcie i zdjęcie kroku o tym samym `id`. Każda gałąź warunku
     * potrzebuje własnego testu i własnego sabotażu, inaczej dwa testy
     * trafiają w tę samą linijkę i drugi nie sprawdza niczego
     * (`docs/PULAPKI_TESTOW.md`, pułapka 3b).
     *
     * UWAGA, KTÓRA ZŁAPAŁA TEN TEST PRZY KONTROLI UJEMNEJ. `syncSteps()`
     * KASUJE wiersze kroków i tworzy je od nowa, więc **po każdym zapisie
     * krok ma NOWY identyfikator**. Pierwsza wersja tego testu wołała drugą
     * edycję ze starym `id` — a `id`, którego przepis nie ma, po prostu nie
     * ma czego odziedziczyć (i tak ma być, patrz docblock `PublishRecipe`).
     * Asercja „zdjęcia nie ma" przechodziła więc z zupełnie innego powodu,
     * niż się wydawało, i przeżywała sabotaż. Dlatego tożsamość kroku jest
     * tu odczytywana PO każdym zapisie.
     */
    public function test_odziedziczone_zdjecie_kroku_odpada_gdy_sprzatacz_je_przejal(): void
    {
        $basia = $this->user('basia');
        $zdjecie = $this->zdrowe($basia);

        $przepis = $this->zapisz($basia, [], $this->kroki((string) $zdjecie->getKey()));

        $edytuj = fn (string $krokId): Recipe => app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Placki babci'],
            ingredients: $this->skladniki(),
            // Dokładnie to, co przysyła formularz przy edycji samego tekstu:
            // `media_id` pusty, tożsamość kroku w `id`.
            steps: [['instruction' => 'Wymieszaj wszystko i odstaw na godzinę.', 'id' => $krokId]],
            publish: true,
            existing: $przepis,
        );

        // KONTROLA DODATNIA: dopóki zdjęcie jest zdrowe, edycja tekstu ma je
        // przy kroku ZOSTAWIĆ. Bez tego asercja niżej przechodziłaby też
        // wtedy, gdyby dziedziczenie zdjęcia kroku nie działało wcale.
        $poPierwszej = $edytuj((string) $przepis->steps()->first()->getKey());

        $this->assertSame(
            (string) $zdjecie->getKey(),
            (string) $poPierwszej->steps()->first()->media_id,
            'Edycja tekstu kroku nie ma prawa zgubić zdjęcia, które ten krok już ma.',
        );

        // A teraz sprzątacz przejmuje to zdjęcie między jedną edycją a drugą.
        $zdjecie->update(['status' => Media::STATUS_DELETED]);

        $this->assertNull(
            $edytuj((string) $poPierwszej->steps()->first()->getKey())->steps()->first()->media_id,
            'Zdjęcie przejęte do skasowania nie ma prawa zostać przy kroku tylko dlatego, '
            .'że było przy nim wcześniej.',
        );
    }

    /**
     * ZDJĘCIE ODZIEDZICZONE PO TOŻSAMOŚCI KROKU też idzie pod blokadę.
     *
     * `syncSteps()` KASUJE wiersze kroków i tworzy je od nowa, więc przy
     * każdej edycji przepisu odwołanie do zdjęcia kroku przestaje i zaczyna
     * istnieć w tej samej transakcji. Kandydat do zablokowania musi więc
     * obejmować także zdjęcia PRZENOSZONE, a nie tylko te wgrane w tym
     * żądaniu — inaczej edycja tytułu przepisu byłaby oknem na skasowanie
     * zdjęcia, które od dawna wisi przy kroku.
     */
    public function test_zdjecie_kroku_przenoszone_przy_edycji_tez_idzie_pod_blokade(): void
    {
        $basia = $this->user('basia');
        $zdjecie = $this->zdrowe($basia);

        $przepis = $this->zapisz($basia, [], $this->kroki((string) $zdjecie->getKey()));
        $krokId = (string) $przepis->steps()->first()->getKey();

        $zapytania = $this->zapytaniaPodczas(function () use ($basia, $przepis, $krokId): void {
            app(PublishRecipe::class)->handle(
                author: $basia,
                attributes: ['title' => 'Placki babci, poprawione'],
                ingredients: $this->skladniki(),
                // `media_id` = null i `id` kroku: dokładnie to, co przysyła
                // formularz przy zwykłej edycji tekstu (RecipeController).
                steps: [['instruction' => 'Wymieszaj wszystko i odstaw na godzinę.', 'id' => $krokId]],
                publish: true,
                existing: $przepis,
            );
        });

        $zBlokada = array_values(array_filter(
            $zapytania,
            static fn (string $sql): bool => str_contains($sql, 'from "media"') && str_contains($sql, 'for update'),
        ));

        $this->assertNotEmpty(
            $zBlokada,
            'Edycja przepisu musi wziąć `FOR UPDATE` także na zdjęciu PRZENOSZONYM przy kroku.',
        );

        $this->assertStringContainsString(
            (string) $zdjecie->getKey(),
            implode(' ', $this->wiazaniaPodczasBlokady()),
            'Zdjęcie przenoszone przy kroku musi być wśród wierszy branych `FOR UPDATE`.',
        );

        // Kontrola dodatnia: zdjęcie faktycznie zostało przy kroku.
        $this->assertSame(
            (string) $zdjecie->getKey(),
            (string) $przepis->refresh()->steps()->first()->media_id,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  KOLEJNOŚĆ BLOKAD — `media` PRZED wierszem przepisu
    // ═══════════════════════════════════════════════════════════════

    /**
     * Zmierzone przy pisaniu D-103 dwiema sesjami psql: `INSERT INTO recipes`
     * czeka i na wiersz `users` (klucz obcy `author_id`), i na wiersz `media`
     * (klucz obcy `hero_media_id`). Blokada zdjęć musi więc stać PRZED tym
     * `INSERT`-em, żeby kolejność brzmiała `media` → `users` — tak samo jak
     * w `PublishPost` po D-083.
     *
     * Odwrotna kolejność w jednym repozytorium to zakleszczenie, a nie
     * zabezpieczenie (D-079 §1) — ta rodzina usterek zabrała już dwa PR-y
     * (D-093 i #311).
     */
    public function test_przepis_blokuje_zdjecia_zanim_wstawi_wiersz_przepisu(): void
    {
        $basia = $this->user('basia');
        $zdjecie = $this->zdrowe($basia);

        $zapytania = $this->zapytaniaPodczas(function () use ($basia, $zdjecie): void {
            $this->zapisz($basia, ['hero_media_id' => (string) $zdjecie->getKey()], $this->kroki());
        });

        $blokada = null;
        $wstawienie = null;

        foreach ($zapytania as $i => $sql) {
            if ($blokada === null && str_contains($sql, 'from "media"') && str_contains($sql, 'for update')) {
                $blokada = $i;
            }

            if ($wstawienie === null && str_contains($sql, 'insert into "recipes"')) {
                $wstawienie = $i;
            }
        }

        $this->assertNotNull($blokada, 'Zapis przepisu w ogóle nie wziął wiersza `media` `FOR UPDATE`.');
        $this->assertNotNull($wstawienie, 'Zapis przepisu nie wstawił wiersza `recipes` — test mierzy nie to, co trzeba.');

        $this->assertLessThan(
            $wstawienie,
            $blokada,
            'Blokada wierszy `media` musi stać PRZED wstawieniem wiersza `recipes` (kolejność `media` → `users`).',
        );

        // Rewalidacja POD blokadą, a nie tylko sama blokada (D-079 §3):
        // warunki własności i stanu stoją w TYM SAMYM zapytaniu, a kolejność
        // blokowania jest deterministyczna.
        $sqlBlokady = $zapytania[$blokada];
        $this->assertStringContainsString('"owner_id"', $sqlBlokady);
        $this->assertStringContainsString('"status"', $sqlBlokady);
        $this->assertStringContainsString('order by "id" asc', $sqlBlokady);
    }

    // ---------------------------------------------------------------
    //  Narzędzia
    // ---------------------------------------------------------------

    /** @var list<array{sql: string, bindings: array<int, mixed>}> */
    private array $slad = [];

    /**
     * Zapytania SQL wykonane w trakcie działania `$co`, w kolejności.
     *
     * @return list<string>
     */
    private function zapytaniaPodczas(callable $co): array
    {
        $this->slad = [];

        DB::listen(function ($zdarzenie): void {
            $this->slad[] = [
                'sql' => strtolower($zdarzenie->sql),
                'bindings' => $zdarzenie->bindings,
            ];
        });

        $co();

        return array_map(static fn (array $wiersz): string => $wiersz['sql'], $this->slad);
    }

    /**
     * Wiązania zapytań blokujących wiersze `media` — czyli identyfikatory,
     * które NAPRAWDĘ poszły pod `FOR UPDATE`.
     *
     * Sam SQL ma w tym miejscu znaki zapytania, więc szukanie w nim UUID-a
     * przechodziłoby zawsze i nie mierzyłoby niczego
     * (`docs/PULAPKI_TESTOW.md`, pułapka 1 — asercja na całości łapie co
     * innego, niż się myśli).
     *
     * @return list<string>
     */
    private function wiazaniaPodczasBlokady(): array
    {
        $wiazania = [];

        foreach ($this->slad as $wiersz) {
            if (str_contains($wiersz['sql'], 'from "media"') && str_contains($wiersz['sql'], 'for update')) {
                foreach ($wiersz['bindings'] as $wiazanie) {
                    $wiazania[] = (string) $wiazanie;
                }
            }
        }

        return $wiazania;
    }
}
