<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2403 (audyt GPT-6 Astra, DB-004): dwa równoległe zapisy nowego przepisu
 * o tym samym tytule dostają dwa RÓŻNE slugi — i żaden nie kończy się błędem.
 *
 * `GenerateRecipeSlug` sprawdzał wolny slug przez `exists()`, a insert szedł
 * później, więc oba procesy wybierały ten sam slug, a przegrany o `UNIQUE`
 * (`recipes_slug_unique`) dostawał SQLSTATE 23505, czyli błąd 500 zamiast
 * kolejnego adresu.
 *
 * Bariera (blokada doradcza) stoi w zdarzeniu `creating`, po wyliczeniu
 * slugu i przed insertem, więc oba procesy NA PEWNO mają ten sam kandydat.
 *
 * Kontrola ujemna: wyłączenie ponowienia w `GenerateRecipeSlug::zapisz()`
 * daje tu `SQLSTATE[23505] ... recipes_slug_unique` w wyniku jednego z uczestników.
 */
#[Group('dwa-polaczenia')]
final class SlugPrzepisuNieKolidujeTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tytuly = [];

    protected function tearDown(): void
    {
        foreach ($this->tytuly as $tytul) {
            try {
                $ids = DB::table('recipes')->where('title', $tytul)->pluck('id')->all();
                DB::table('recipe_versions')->whereIn('recipe_id', $ids)->delete();
                DB::table('posts')->whereIn('recipe_id', $ids)->delete();
                DB::table('recipes')->whereIn('id', $ids)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać przepisów testu: ".$e->getMessage()."\n");
            }
        }
        $this->tytuly = [];

        parent::tearDown();
    }

    public function test_dwa_rownolegle_nowe_przepisy_o_tym_samym_tytule_dostaja_rozne_slugi(): void
    {
        $tytul = 'Rosół wyścigowy '.bin2hex(random_bytes(5));
        $this->tytuly[] = $tytul;
        $pierwszy = $this->konto();
        $drugi = $this->konto();

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2403, 1)', []);

        $a = $this->wTle('nowy-przepis-z-bariera', ['autor' => (string) $pierwszy->getKey(), 'tytul' => $tytul]);
        $b = $this->wTle('nowy-przepis-z-bariera', ['autor' => (string) $drugi->getKey(), 'tytul' => $tytul]);

        // Oba stoją na barierze, więc oba mają już policzony TEN SAM slug.
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikA = $a->wynik();
        $wynikB = $b->wynik();

        $this->assertBezZakleszczenia($wynikA, 'pierwszy zapis przepisu');
        $this->assertBezZakleszczenia($wynikB, 'drugi zapis przepisu');

        $this->assertSame(
            [true, true],
            [$wynikA['ok'], $wynikB['ok']],
            'Równoległy zapis skończył się błędem zamiast kolejnym slugiem: '
            .$wynikA['wyjatek'].' '.$wynikA['komunikat'].' | '.$wynikB['wyjatek'].' '.$wynikB['komunikat'],
        );

        $slugi = DB::table('recipes')->where('title', $tytul)->orderBy('slug')->pluck('slug')->all();

        $this->assertCount(2, $slugi, 'Oba przepisy muszą istnieć.');
        $this->assertCount(2, array_unique($slugi), 'Slugi muszą być różne.');
        $this->assertStringStartsWith('rosol-wyscigowy-', $slugi[0]);
        $this->assertSame(
            1,
            count(array_filter($slugi, static fn (string $s): bool => (bool) preg_match('/-2$/', $s))),
            'Drugi slug to kolejny deterministyczny kandydat z sufiksem -2.',
        );
    }
}
