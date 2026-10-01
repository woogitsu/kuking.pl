<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wspólne gotowanie na dwóch połączeniach (#2385, kryterium akceptacji):
 *
 *  1. dwie osoby odhaczają TEN SAM krok w tej samej chwili — jeden wiersz,
 *     jedna zmiana rewizji, żadnego błędu (klucz `(session_id, step_id)` plus
 *     blokada wiersza sesji);
 *  2. dwie osoby przyjmują TEN SAM jednorazowy link — dołącza dokładnie jedna.
 */
#[Group('dwa-polaczenia')]
final class WspolneGotowanieNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @return array{0: User, 1: User, 2: CookingSession, 3: list<string>} */
    private function sesjaZPomocnikiem(): array
    {
        $gospodarz = $this->konto();
        $pomocnik = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        $kroki = [];
        foreach ([0, 1] as $i) {
            $kroki[] = (string) RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => 'Krok '.($i + 1).'.',
            ])->getKey();
        }

        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $przepis);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $token);

        return [$gospodarz, $pomocnik, $sesja->refresh(), $kroki];
    }

    public function test_dwie_osoby_odhaczajace_ten_sam_krok_naraz_daja_jeden_wiersz_i_jedna_zmiane(): void
    {
        [$gospodarz, $pomocnik, $sesja, $kroki] = $this->sesjaZPomocnikiem();
        $rewizjaPrzed = (int) $sesja->revision;

        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $pierwszy = $this->wTle('wspolne-krok', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $kroki[0]]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wwTle($pomocnik, $sesja, $kroki[0]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'odhaczenie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Odhaczenie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(
            1,
            DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->where('step_id', $kroki[0])->count(),
            'Ten sam krok musi być odhaczony dokładnie raz.',
        );
        $this->assertSame($rewizjaPrzed + 1, (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision'),
            'Jedna realna zmiana = jedna rewizja więcej; drugie odhaczenie niczego nie zmienia.');
        $wartosci = array_map(fn (array $w) => $w['wartosc'], $wyniki);
        sort($wartosci);
        $this->assertSame(['bez-zmiany', 'zmieniono'], $wartosci, 'Dokładnie jedno odhaczenie coś zmienia.');
    }

    public function test_dwie_osoby_odhaczajace_rozne_kroki_naraz_nic_sobie_nie_gubia(): void
    {
        [$gospodarz, $pomocnik, $sesja, $kroki] = $this->sesjaZPomocnikiem();
        $rewizjaPrzed = (int) $sesja->revision;

        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $pierwszy = $this->wTle('wspolne-krok', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $kroki[0]]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wwTle($pomocnik, $sesja, $kroki[1]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'odhaczenie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Odhaczenie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(2, DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->count());
        // Rewizja rośnie o dwa, bo zmiany są dwie (kontrola dodatnia dla testu wyżej).
        $this->assertSame($rewizjaPrzed + 2, (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision'));
    }

    public function test_dwie_osoby_przyjmujace_ten_sam_link_naraz_daja_jednego_pomocnika(): void
    {
        $gospodarz = $this->konto();
        $pierwsza = $this->konto();
        $druga = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Krok 1.']);
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $przepis);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);

        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $a = $this->wTle('wspolne-dolacz', ['kto' => (string) $pierwsza->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('wspolne-dolacz', ['kto' => (string) $druga->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$a->wynik(), $b->wynik()];
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'przyjęcie '.($numer + 1));
        }

        $this->assertSame(1, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(),
            'Jednorazowy link wpuszcza dokładnie jedną osobę.');
        $udane = array_values(array_filter($wyniki, fn (array $w) => $w['ok']));
        $this->assertCount(1, $udane, 'Dokładnie jedno przyjęcie się udaje; drugie dostaje odmowę.');
    }

    /** Odhaczenie przez drugą osobę — ten sam krok co w pierwszym procesie. */
    private function wwTle(User $kto, CookingSession $sesja, string $krok): ProcesRownolegly
    {
        return $this->wTle('wspolne-krok', ['kto' => (string) $kto->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $krok]);
    }
}
