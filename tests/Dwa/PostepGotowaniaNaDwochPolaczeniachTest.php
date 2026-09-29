<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2016: dwa urządzenia jednego konta odhaczają kroki tego samego przepisu
 * w tej samej chwili. Zapis idzie pod blokadą wiersza (`lockForUpdate()`
 * w `PostepGotowania`), więc żadne odhaczenie nie ginie, a rewizja rośnie
 * o jeden przy każdej zmianie. Bez blokady oba żądania czytają tę samą listę
 * i drugi zapis kasuje krok pierwszego.
 */
#[Group('dwa-polaczenia')]
final class PostepGotowaniaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @return array{0: Recipe, 1: list<string>} */
    private function przepisZKrokami(string $autor): array
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor]);
        $ids = [];
        foreach ([0, 1, 2] as $i) {
            $ids[] = (string) RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => 'Krok '.($i + 1).'.',
            ])->getKey();
        }

        return [$przepis, $ids];
    }

    public function test_dwa_urzadzenia_odhaczajace_rozne_kroki_naraz_nic_sobie_nie_gubia(): void
    {
        $osoba = $this->konto();
        [$przepis, $kroki] = $this->przepisZKrokami((string) $osoba->getKey());

        $postepId = (string) Str::uuid();
        DB::table('cooking_progress')->insert([
            'id' => $postepId,
            'user_id' => $osoba->getKey(),
            'recipe_id' => $przepis->getKey(),
            'done_step_ids' => '[]',
            'revision' => 1,
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bariera = $this->bariera('SELECT 1 FROM cooking_progress WHERE id = ? FOR UPDATE', [$postepId]);
        $pierwszy = $this->wTle('postep-ustaw', ['postep' => $postepId, 'krok' => $kroki[0], 'kroki' => json_encode($kroki)]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('postep-ustaw', ['postep' => $postepId, 'krok' => $kroki[1], 'kroki' => json_encode($kroki)]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'urządzenie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Zapis urządzenia '.($numer + 1).' padł: '.$wynik['komunikat']);
        }

        $wiersz = DB::table('cooking_progress')->where('id', $postepId)->first();
        $this->assertNotNull($wiersz, 'Kontrola dodatnia: wiersz postępu musi istnieć.');
        $zapisane = json_decode((string) $wiersz->done_step_ids, true);
        sort($zapisane);
        $oczekiwane = [$kroki[0], $kroki[1]];
        sort($oczekiwane);

        $this->assertSame($oczekiwane, $zapisane, 'Oba odhaczenia muszą przeżyć.');
        $this->assertSame(3, (int) $wiersz->revision, 'Dwie zmiany = rewizja 1 + 2.');
    }

    public function test_dwa_rownolegle_wlaczenia_daja_jeden_wiersz(): void
    {
        $osoba = $this->konto();
        [$przepis, $kroki] = $this->przepisZKrokami((string) $osoba->getKey());
        $argumenty = ['kto' => (string) $osoba->getKey(), 'przepis' => (string) $przepis->getKey(), 'kroki' => json_encode($kroki)];

        $pierwszy = $this->wTle('postep-wlacz', $argumenty);
        $drugi = $this->wTle('postep-wlacz', $argumenty);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'włączenie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Włączenie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(1, DB::table('cooking_progress')->where('user_id', $osoba->getKey())->where('recipe_id', $przepis->getKey())->count());
    }
}
