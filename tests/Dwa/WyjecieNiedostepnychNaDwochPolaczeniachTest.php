<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2205: zbiorcze „Wyjmij niedostępne zapisy” bierze blokadę doradczą partii
 * powiadomienia `recipe.saved` (ta sama, co zapis i zwykłe wyjęcie) i wycofuje
 * udział osoby z nieprzeczytanego agregatu — także wtedy, gdy w tej samej
 * chwili do agregatu dopisuje się ktoś inny.
 *
 * BARIERA to sama blokada doradcza partii, trzymana przez test. Ustawienie się
 * OBU uczestników w kolejce po nią dowodzi, że czyszczenie naprawdę sięga po
 * blokadę partii (kontrola dodatnia). Po zwolnieniu, niezależnie od kolejności,
 * w agregacie ma zostać wyłącznie osoba, której zapis trwa.
 */
#[Group('dwa-polaczenia')]
final class WyjecieNiedostepnychNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_czyszczenie_pierwsze_potem_dopisanie_innej_osoby(): void
    {
        [$autor, $osoba, $inna, $przepis, $zeszyt] = $this->uklad();

        $this->wyscig($autor, $przepis, [
            fn () => $this->wTle('wyjmij-niedostepne', ['kto' => (string) $osoba->getKey(), 'zeszyt' => (string) $zeszyt->getKey()]),
            fn () => $this->wTle('zapisz-przepis', ['kto' => (string) $inna->getKey(), 'przepis' => (string) $przepis->getKey()]),
        ]);

        $this->assertSavers($autor, [(string) $inna->getKey()]);
    }

    public function test_dopisanie_innej_osoby_pierwsze_potem_czyszczenie(): void
    {
        [$autor, $osoba, $inna, $przepis, $zeszyt] = $this->uklad();

        $this->wyscig($autor, $przepis, [
            fn () => $this->wTle('zapisz-przepis', ['kto' => (string) $inna->getKey(), 'przepis' => (string) $przepis->getKey()]),
            fn () => $this->wTle('wyjmij-niedostepne', ['kto' => (string) $osoba->getKey(), 'zeszyt' => (string) $zeszyt->getKey()]),
        ]);

        $this->assertSavers($autor, [(string) $inna->getKey()]);
    }

    /** @return array{0: User, 1: User, 2: User, 3: Recipe, 4: Collection} */
    private function uklad(): array
    {
        $autor = $this->konto();
        $osoba = $this->konto();
        $inna = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create(['visibility' => 'public']);

        app(SaveRecipeToCollection::class)->handle($osoba, $przepis);

        // Kontrola dodatnia układu: partia istnieje i zawiera osobę.
        $this->assertSavers($autor, [(string) $osoba->getKey()]);

        // Przepis staje się dla osoby niedostępny (blokada), ale nie dla `innej`.
        app(BlockUser::class)->handle($autor, $osoba);

        return [$autor, $osoba, $inna, $przepis, $osoba->defaultCollection()];
    }

    /** @param  list<callable(): ProcesRownolegly>  $starty */
    private function wyscig(User $autor, Recipe $przepis, array $starty): void
    {
        $bariera = $this->bariera(
            'SELECT pg_advisory_xact_lock(906, hashtext(?))',
            [$autor->getKey().':'.$przepis->getKey()],
        );

        $procesy = [];
        foreach ($starty as $numer => $start) {
            $procesy[] = $start();
            $this->czekajNaZablokowane($numer + 1);
        }

        $this->zwolnijBariere($bariera);

        foreach ($procesy as $numer => $proces) {
            $wynik = $proces->wynik();
            $this->assertBezZakleszczenia($wynik, 'uczestnik '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Uczestnik '.($numer + 1).' padł: '.$wynik['komunikat']);
        }
    }

    /** @param  list<string>  $oczekiwani */
    private function assertSavers(User $autor, array $oczekiwani): void
    {
        $partie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->get();

        $this->assertCount(1, $partie, 'Powinna istnieć dokładnie jedna nieprzeczytana partia.');
        $this->assertSame($oczekiwani, $partie->first()->data['savers']);
        $this->assertSame(count($oczekiwani) - 1, $partie->first()->data['others_count']);
    }
}
