<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KANDYDAT (audyt zewnętrzny, grupa 1): dwa niemal jednoczesne żądania
 * publikujące wpis z NOWYM tagiem o tej samej nazwie — `ResolveTagsForPost::
 * znajdzAlboUtworz()` woła `Tag::firstOrCreate(['normalized_name' => ...])`.
 *
 * METODA: identyczna jak `IngredientRaceTest` — podkładamy konkurencyjny
 * wiersz DOKŁADNIE między SELECT-em a INSERT-em `firstOrCreate()`, przez
 * `DB::listen()` na zapytaniu odczytującym `tags` po `normalized_name`.
 *
 * `tags.normalized_name` ma UNIQUE (migracja `create_tags_tables`), więc
 * to jest ten sam mechanizm co przy `Ingredient` — `Builder::createOrFirst()`
 * łapie `UniqueConstraintViolationException` i oddaje wiersz, który wygrał.
 *
 * WNIOSEK: kandydat OBALONY.
 */
class ResolveTagsForPostRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_niemal_jednoczesne_wpisy_z_nowym_tagiem_nie_tworza_duplikatu_ani_bledu(): void
    {
        $znormalizowana = Tag::znormalizujNazwe('Sernik');
        $konkurencyjnyWstawiony = false;

        $listener = function ($query) use (&$konkurencyjnyWstawiony, $znormalizowana): void {
            if ($konkurencyjnyWstawiony) {
                return;
            }

            $sql = strtolower($query->sql);

            if (! str_starts_with(trim($sql), 'select') || ! str_contains($sql, '"tags"') || ! str_contains($sql, 'normalized_name')) {
                return;
            }

            $konkurencyjnyWstawiony = true;

            DB::table('tags')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Sernik',
                'normalized_name' => $znormalizowana,
                'slug' => 'sernik',
                'status' => Tag::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        DB::listen($listener);

        $tagi = app(ResolveTagsForPost::class)->handle(['Sernik']);

        $this->assertSame(1, Tag::query()->where('normalized_name', $znormalizowana)->count(), 'Wyścig nie ma prawa utworzyć drugiego tagu o tej samej znormalizowanej nazwie.');
        $this->assertCount(1, $tagi);
        $this->assertSame('sernik', $tagi[0]->slug, 'Wywołujący ma dostać tag, który wygrał wyścig (slug bez sufiksu), nie wyjątek ani duplikat.');
    }

    /**
     * WARIANT DOKŁADNIEJSZY: poprzedni test podkłada wiersz przy PIERWSZYM
     * odczycie w `znajdzAlboUtworz()` — a to zmienia też wynik sprawdzenia
     * kolizji slugu (`wolnySlug()`), więc `firstOrCreate()` w praktyce
     * znajduje wiersz swoim WŁASNYM pierwszym `first()` i nigdy nie próbuje
     * `INSERT`-u. To wciąż poprawnie odzwierciedla wyścig, ale nie dowodzi
     * wprost, że przechwycenie `UniqueConstraintViolationException` naprawdę
     * działa.
     *
     * Tu podkładamy konkurencyjny wiersz przy odczycie po `slug`
     * (`wolnySlug()`), tuż przed `INSERT`-em. Wtedy `INSERT` testowanego kodu naprawdę zderza się
     * z UNIQUE w bazie, a `createOrFirst()` musi złapać wyjątek i oddać
     * wiersz, który wygrał — to jest dokładnie mechanizm, o który pyta audyt.
     */
    public function test_wstawienie_tuz_przed_insertem_firstorcreate_nie_konczy_sie_bledem(): void
    {
        $znormalizowana = Tag::znormalizujNazwe('Barszcz');
        $ileTrafien = 0;

        $wstawiony = false;

        $listener = function ($query) use (&$ileTrafien, &$wstawiony, $znormalizowana): void {
            $sql = strtolower($query->sql);

            if (! str_starts_with(trim($sql), 'select') || ! str_contains($sql, '"tags"')) {
                return;
            }

            if (str_contains($sql, 'normalized_name')) {
                $ileTrafien++;

                return;
            }

            // SELECT po `slug` (`wolnySlug()`) to ostatni odczyt tuż przed
            // `INSERT`-em nowego tagu. Wstawiamy konkurencyjny wiersz TERAZ —
            // testowany kod za chwilę spróbuje utworzyć dokładnie ten sam,
            // znormalizowany tag i zderzy się z UNIQUE.
            if ($wstawiony || ! str_contains($sql, '"slug"')) {
                return;
            }

            $wstawiony = true;

            DB::table('tags')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Barszcz',
                'normalized_name' => $znormalizowana,
                'slug' => 'barszcz-konkurencyjny',
                'status' => Tag::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        DB::listen($listener);

        $tagi = app(ResolveTagsForPost::class)->handle(['Barszcz']);

        // Drugi odczyt po `normalized_name` to odzyskanie wiersza w CATCH-u
        // `zapiszNowyTag()` — dowód, że `INSERT` naprawdę zderzył się z UNIQUE
        // i wyjątek naprawdę został złapany, a nie że ścieżka z wyjątkiem
        // w ogóle się nie wykonała.
        $this->assertSame(2, $ileTrafien, 'Test zakłada dokładnie dwa odczyty po normalized_name (sprawdzenie + odzyskanie po zderzeniu) — jeśli to się zmieniło, scenariusz już nie mierzy tego, co miał.');
        $this->assertTrue($wstawiony, 'Konkurencyjny wiersz musiał zostać wstawiony przed insertem.');
        $this->assertSame(1, Tag::query()->where('normalized_name', $znormalizowana)->count(), 'UNIQUE w bazie ma domknąć wyścig: nie może powstać drugi wiersz.');
        $this->assertCount(1, $tagi, 'Wywołujący ma dostać jeden tag, nie wyjątek z bazy.');
        $this->assertSame('barszcz-konkurencyjny', $tagi[0]->slug, 'Wygrywa wiersz, który zdążył pierwszy — `zapiszNowyTag()` łapie zderzenie i oddaje ISTNIEJĄCY wiersz.');
    }
}
