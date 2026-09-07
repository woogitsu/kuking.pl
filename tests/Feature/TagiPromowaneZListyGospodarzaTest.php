<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use Database\Seeders\TagPromotionSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Startowa lista tagów promowanych (`TagPromotionSeeder`, decyzja właściciela
 * z 7 września 2026: „zrób to za mnie kodem").
 *
 * DWIE RZECZY, KTÓRE MUSZĄ BYĆ PRAWDĄ NARAZ
 *   1. Na PUSTEJ liście seeder wpisuje dwanaście tagów, żeby krok onboardingu
 *      „co Cię interesuje" przestał być pomijany.
 *   2. Na NIEPUSTEJ liście nie robi NIC — bo lista promowanych jest wyborem
 *      gospodarza z panelu, a seeder, który przywraca zdjęty przez niego tag,
 *      jest gorszy niż seeder, którego nie ma: gospodarz nie ma jak się
 *      zorientować, dlaczego tag wraca.
 *
 * Punkt 2 jest tu ważniejszy i dlatego ma trzy testy, nie jeden.
 */
class TagiPromowaneZListyGospodarzaTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> nazwy z pliku danych */
    private function nazwyZPliku(): array
    {
        $dane = json_decode(
            (string) file_get_contents(database_path('seeders/dane/tagi-promowane.json')),
            true, 512, JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($dane);
        $this->assertIsArray($dane['tagi'] ?? null);

        return array_map(
            fn (array $wpis): string => Tag::znormalizujNazwe((string) $wpis['nazwa']),
            $dane['tagi'],
        );
    }

    /**
     * KONTROLA. Każda nazwa z listy naprawdę istnieje w słowniku — bez tego
     * seeder pomijałby wszystko po cichu, a test niżej mierzyłby zero.
     */
    public function test_kontrola_kazda_nazwa_z_listy_jest_w_slowniku(): void
    {
        $this->seed(TagSeeder::class);

        $brakujace = [];

        foreach ($this->nazwyZPliku() as $nazwa) {
            if (! Tag::query()->where('normalized_name', $nazwa)->exists()) {
                $brakujace[] = $nazwa;
            }
        }

        $this->assertSame([], $brakujace, 'Lista promowanych zawiera nazwy, których nie ma w słowniku tagów.');
    }

    /** WŁAŚCIWY POMIAR. Na pustej liście powstaje dwanaście promocji, w kolejności z pliku. */
    public function test_na_pustej_liscie_seeder_wpisuje_liste_startowa(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $oczekiwane = $this->nazwyZPliku();

        $this->assertSame(count($oczekiwane), TagPromotion::query()->count());

        // Kolejność na ekranie bierze się z `position`, nie z kolejności
        // wstawiania — `Tag::promowane()` sortuje podzapytaniem.
        $promowane = Tag::promowane()->pluck('normalized_name')->all();

        $this->assertSame($oczekiwane, $promowane, 'Kolejność tagów promowanych nie zgadza się z plikiem.');
    }

    /**
     * Notatki gospodarza są widoczne dla ludzi, więc muszą przejść do bazy
     * dosłownie — a tam, gdzie ich nie ma, musi zostać `null`, nie pusty
     * ciąg.
     */
    public function test_notatki_trafiaja_do_bazy_bez_zmian(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $przetwory = Tag::query()->where('normalized_name', 'przetwory')->firstOrFail();

        $this->assertSame(
            'Wrzesień to szczyt sezonu na przetwory. Pokaż, co zamykasz w słoikach.',
            TagPromotion::query()->where('tag_id', $przetwory->getKey())->value('note'),
        );

        $rosol = Tag::query()->where('normalized_name', 'rosół')->firstOrFail();

        $this->assertNull(
            TagPromotion::query()->where('tag_id', $rosol->getKey())->value('note'),
            'Brak notatki zapisał się jako pusty ciąg zamiast `null`.',
        );
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU. Gospodarz zdjął tag z listy — seeder
     * nie ma prawa go przywrócić.
     */
    public function test_seeder_nie_przywraca_tagu_zdjetego_przez_gospodarza(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $dynia = Tag::query()->where('normalized_name', 'dynia')->firstOrFail();

        // Gospodarz zdejmuje „dynię" w panelu.
        TagPromotion::query()->where('tag_id', $dynia->getKey())->delete();
        $poZdjeciu = TagPromotion::query()->count();

        // Kolejny deploy, kolejne `db:seed`.
        $this->seed(TagPromotionSeeder::class);

        $this->assertSame($poZdjeciu, TagPromotion::query()->count(), 'Seeder przywrócił tag zdjęty przez gospodarza.');
        $this->assertFalse(TagPromotion::query()->where('tag_id', $dynia->getKey())->exists());
    }

    /** I nie nadpisuje pozycji ani notatki, którą gospodarz zmienił. */
    public function test_seeder_nie_nadpisuje_zmian_gospodarza(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $rosol = Tag::query()->where('normalized_name', 'rosół')->firstOrFail();

        TagPromotion::query()->where('tag_id', $rosol->getKey())->update([
            'position' => 1,
            'note' => 'Rosół na niedzielę — pokażcie swoje.',
        ]);

        $this->seed(TagPromotionSeeder::class);

        $promocja = TagPromotion::query()->where('tag_id', $rosol->getKey())->firstOrFail();

        $this->assertSame(1, $promocja->position);
        $this->assertSame('Rosół na niedzielę — pokażcie swoje.', $promocja->note);
    }

    /**
     * Seeder pomija nazwę, której nie ma w bazie — i NIE tworzy pod nią tagu.
     * Promowanie tagu, którego nikt nie używa, jest odwrotnością sensu tej
     * listy.
     */
    public function test_seeder_nie_tworzy_tagow_pod_promocje(): void
    {
        // Bez `TagSeeder`: w bazie nie ma ŻADNEGO tagu z listy.
        $this->seed(TagPromotionSeeder::class);

        $this->assertSame(0, TagPromotion::query()->count());
        $this->assertSame(0, Tag::query()->count(), 'Seeder utworzył tag, żeby móc go wypromować.');
    }

    /**
     * Skutek dla człowieka, nie dla bazy: krok onboardingu przestaje być
     * pomijany i pokazuje te tagi.
     */
    public function test_onboarding_pokazuje_liste_startowa(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $odpowiedz = $this->actingAs($this->user('nowa'))->get(route('onboarding.interests'))->assertOk();

        $odpowiedz->assertSee('przetwory', escape: false);
        $odpowiedz->assertSee('przepis po babci', escape: false);
    }
}
