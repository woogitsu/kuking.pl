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
 *  2. dwie osoby przyjmują TEN SAM wielorazowy link (są dwa wolne miejsca) —
 *     wchodzą obie, bo link nie jest zużywany przez pierwszą;
 *  3. WIELU POMOCNIKÓW (do trzech): dwie RÓŻNE osoby przyjmują link, gdy zostało
 *     jedno miejsce — wchodzi dokładnie jedna i limit nie zostaje przekroczony;
 *  4. przyjęcie linku i odhaczenie kroku naraz — rewizja rośnie o DWA (żadna
 *     zmiana nie ginie): przyjęcie czyta rewizję dopiero pod blokadą sesji;
 *  5. odhaczenie kroku przez gospodarza w chwili, gdy blokuje on pomocnika —
 *     bez zakleszczenia (konta przed sesją także w odhaczeniu), a po wyścigu
 *     nikt zablokowany nie zostaje w sesji;
 *  6. tworzenie nowego linku przez gospodarza i przyjęcie starego w tej samej
 *     chwili nie zakleszczają się (jedna kolejność blokad: konta, sesja, zaproszenie);
 *  7. odwołanie linku przez gospodarza i przyjęcie go naraz: kto stoi w kolejce
 *     po wiersz sesji za odwołaniem, nie wchodzi.
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

    public function test_dwie_osoby_przyjmujace_ten_sam_link_naraz_wchodza_obie_gdy_sa_dwa_miejsca(): void
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
            $this->assertTrue($wynik['ok'], 'Przyjęcie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(2, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(),
            'Link wielorazowy wpuszcza obie osoby, gdy są dwa wolne miejsca.');
        $this->assertSame('pending', DB::table('cooking_session_invitations')->where('session_id', $sesja->getKey())->value('status'),
            'Przyjęcie nie zużywa linku.');
    }

    public function test_przyjecie_linku_stojace_za_odwolaniem_go_przez_gospodarza_nie_wpuszcza(): void
    {
        $gospodarz = $this->konto();
        $osoba = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Krok 1.']);
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $przepis);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);

        // Odwołanie jest pierwsze w kolejce po wiersz sesji, przyjęcie drugie.
        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $odwolanie = $this->wTle('wspolne-odwolaj-link', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey()]);
        $this->czekajNaZablokowane(1);
        $przyjecie = $this->wTle('wspolne-dolacz', ['kto' => (string) $osoba->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikOdwolania = $odwolanie->wynik();
        $wynikPrzyjecia = $przyjecie->wynik();
        $this->assertBezZakleszczenia($wynikOdwolania, 'odwołanie linku');
        $this->assertBezZakleszczenia($wynikPrzyjecia, 'przyjęcie linku');
        $this->assertTrue($wynikOdwolania['ok'], 'Odwołanie padło: '.$wynikOdwolania['komunikat']);

        $this->assertFalse($wynikPrzyjecia['ok'], 'Przyjęcie po odwołaniu linku musi dostać odmowę.');
        $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count());
        $this->assertSame('revoked', DB::table('cooking_session_invitations')->where('session_id', $sesja->getKey())->value('status'));
    }

    /** Odhaczenie przez drugą osobę — ten sam krok co w pierwszym procesie. */
    private function wwTle(User $kto, CookingSession $sesja, string $krok): ProcesRownolegly
    {
        return $this->wTle('wspolne-krok', ['kto' => (string) $kto->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $krok]);
    }

    public function test_dwie_rozne_osoby_przyjmujace_link_gdy_zostalo_jedno_miejsce_daja_dokladnie_jednego_pomocnika(): void
    {
        [$gospodarz, , $sesja] = $this->sesjaZPomocnikiem();   // pomocnik nr 1
        $drugi = $this->konto();
        [, $tokenDrugiego] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($drugi, $tokenDrugiego);   // pomocnik nr 2, zostało jedno miejsce z trzech
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        $ewa = $this->konto();
        $filip = $this->konto();

        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $a = $this->wTle('wspolne-dolacz', ['kto' => (string) $ewa->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('wspolne-dolacz', ['kto' => (string) $filip->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$a->wynik(), $b->wynik()];
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'przyjęcie '.($numer + 1));
        }

        $this->assertSame(3, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(),
            'Zostało jedno miejsce z trzech: wchodzi dokładnie jedna osoba, nie dwie.');
        $this->assertCount(1, array_filter($wyniki, fn (array $w) => $w['ok']), 'Dokładnie jedno przyjęcie się udaje.');
    }

    public function test_nowy_link_gospodarza_i_przyjecie_starego_naraz_sie_nie_zakleszczaja(): void
    {
        [$gospodarz, , $sesja] = $this->sesjaZPomocnikiem();
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        $nowa = $this->konto();

        // Gospodarz jest pierwszy w kolejce po wiersz sesji, przyjmujący drugi:
        // gdyby przyjęcie trzymało już blokadę zaproszenia, a gospodarz
        // unieważniał to samo zaproszenie pod blokadą sesji, byłby cykl.
        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $tworzenie = $this->wTle('wspolne-utworz-link', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey()]);
        $this->czekajNaZablokowane(1);
        $przyjecie = $this->wTle('wspolne-dolacz', ['kto' => (string) $nowa->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$tworzenie->wynik(), $przyjecie->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'tworzenie linku');
        $this->assertBezZakleszczenia($wyniki[1], 'przyjęcie linku');
        $this->assertTrue($wyniki[0]['ok'], 'Tworzenie linku padło: '.$wyniki[0]['komunikat']);

        $this->assertSame(1, DB::table('cooking_session_invitations')->where('session_id', $sesja->getKey())->where('status', 'pending')->count(),
            'Po wyścigu czeka dokładnie jeden link.');
        $this->assertLessThanOrEqual(3, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count());
    }

    public function test_przyjecie_linku_i_odhaczenie_kroku_naraz_nie_gubia_zadnej_zmiany_rewizji(): void
    {
        [$gospodarz, , $sesja, $kroki] = $this->sesjaZPomocnikiem();
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        $nowa = $this->konto();
        $rewizjaPrzed = (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision');

        // Odhaczenie jest pierwsze w kolejce po wiersz sesji. Przyjęcie, które
        // odczytałoby rewizję PRZED blokadą sesji, zapisałoby ją po cudzej
        // zmianie jako „starą + 1” i zgubiło jedną zmianę.
        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $odhaczenie = $this->wTle('wspolne-krok', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $kroki[0]]);
        $this->czekajNaZablokowane(1);
        $przyjecie = $this->wTle('wspolne-dolacz', ['kto' => (string) $nowa->getKey(), 'token' => $token]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$odhaczenie->wynik(), $przyjecie->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'operacja '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Operacja '.($numer + 1).' padła: '.$wynik['komunikat']);
        }

        $this->assertSame(
            $rewizjaPrzed + 2,
            (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision'),
            'Odhaczenie i przyjęcie to dwie realne zmiany — rewizja rośnie o dwa.',
        );
    }

    public function test_odhaczenie_przez_gospodarza_i_jego_blokada_pomocnika_naraz_sie_nie_zakleszczaja(): void
    {
        [$gospodarz, $pomocnik, $sesja, $kroki] = $this->sesjaZPomocnikiem();

        // Odhaczenie jest pierwsze po wiersz sesji. Blokada bierze oba konta,
        // a potem sesję; odhaczenie musi więc czekać na konto PRZED sesją,
        // bo jego klucz obcy `done_by_id` potrzebuje wiersza gospodarza.
        $bariera = $this->bariera('SELECT 1 FROM cooking_sessions WHERE id = ? FOR UPDATE', [(string) $sesja->getKey()]);
        $odhaczenie = $this->wTle('wspolne-krok', ['kto' => (string) $gospodarz->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $kroki[0]]);
        $this->czekajNaZablokowane(1);
        $blokada = $this->wTle('zablokuj', ['kto' => (string) $gospodarz->getKey(), 'kogo' => (string) $pomocnik->getKey()]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$odhaczenie->wynik(), $blokada->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'odhaczenie');
        $this->assertBezZakleszczenia($wyniki[1], 'blokada');
        $this->assertTrue($wyniki[0]['ok'], 'Odhaczenie padło: '.$wyniki[0]['komunikat']);
        $this->assertTrue($wyniki[1]['ok'], 'Blokada padła: '.$wyniki[1]['komunikat']);

        $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(),
            'Zablokowany pomocnik nie zostaje w sesji.');
        $this->assertSame(1, DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->count());
    }
}
