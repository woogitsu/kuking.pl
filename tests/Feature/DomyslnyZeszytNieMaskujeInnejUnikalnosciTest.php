<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DomyslnyZeszytNieMaskujeInnejUnikalnosciTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolizja_nazwy_nie_jest_udawana_za_wyscig_o_domyslny_zeszyt(): void
    {
        $osoba = $this->user('kolizjanazwy');
        $wstawiono = false;

        DB::listen(function ($query) use ($osoba, &$wstawiono): void {
            if ($wstawiono || ! str_contains($query->sql, 'select "name" from "collections"')) {
                return;
            }

            $wstawiono = true;
            DB::table('collections')->insert([
                'id' => fake()->uuid(),
                'owner_id' => $osoba->getKey(),
                'name' => 'Zapisane',
                'visibility' => 'private',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->expectException(UniqueConstraintViolationException::class);
        $osoba->defaultCollection();
    }

    /**
     * Druga połowa kontraktu #1095, mierzona na JEDNYM połączeniu.
     *
     * Prawdziwy wyścig pilnuje `tests/Dwa/PierwszyZapisDoZeszytuNaDwochPolaczeniachTest`,
     * ale ta grupa jest wyłączona ze zwykłego `php artisan test` (D-105), więc
     * bez tego testu codzienny przebieg nie sprawdzałby złapania 23505 w ogóle.
     *
     * Podstawiamy cudzy zapis dokładnie w tym oknie, w którym zdarza się on
     * naprawdę: zaraz PO sprawdzeniu „czy jest domyślny” i przed INSERT-em.
     * Moment ma znaczenie — wstawienie wiersza dopiero wewnątrz
     * `DB::transaction()` znikałoby razem z wycofaniem do savepointu, czyli
     * test opisywałby sytuację, która na dwóch połączeniach nie zachodzi.
     *
     * Bez poprawki `defaultCollection()` wypuszcza
     * `UniqueConstraintViolationException` na
     * `collections_one_default_per_owner_idx` — czyli 500 przy pierwszym
     * kliknięciu „Zapisuję”.
     */
    public function test_domyslny_zeszyt_zalozony_w_oknie_wyscigu_jest_zwracany_zamiast_bledu(): void
    {
        $osoba = $this->user('wyscigjednopolaczeniowy');
        $idPodstawiony = fake()->uuid();
        $wstawiono = false;

        DB::listen(function ($query) use ($osoba, $idPodstawiony, &$wstawiono): void {
            $toSprawdzenieCzyJestDomyslny = str_contains($query->sql, 'from "collections"')
                && str_contains($query->sql, '"is_default" = ?');

            if ($wstawiono || ! $toSprawdzenieCzyJestDomyslny) {
                return;
            }

            $wstawiono = true;
            DB::table('collections')->insert([
                'id' => $idPodstawiony,
                'owner_id' => $osoba->getKey(),
                'name' => 'Zeszyt drugiego żądania',
                'visibility' => 'private',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $zeszyt = $osoba->defaultCollection();

        $this->assertTrue($wstawiono, 'Okno wyścigu się nie otworzyło — test stracił przedmiot.');
        $this->assertSame($idPodstawiony, $zeszyt->getKey(), 'Wygrać ma zeszyt, który zdążył wejść do bazy.');
        $this->assertSame(1, DB::table('collections')->where('owner_id', $osoba->getKey())->count());
    }
}
