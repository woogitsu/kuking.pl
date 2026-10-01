<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wskazówki od gotujących na dwóch połączeniach (#2352, D-333): prośba kontra
 * prośba, „Zgadzam się” kontra „Nie”, wycofanie kontra wycofanie. Każdy
 * uczestnik to osobny proces z PRAWDZIWĄ akcją domenową (`ZamekPary`, potem
 * wiersz wskazówki `FOR UPDATE`).
 */
#[Group('dwa-polaczenia')]
final class WskazowkiNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @return array{User, User, CookedEvent} autor przepisu, kucharz, wykonanie z notatką */
    private function swiat(): array
    {
        $autor = $this->konto();
        $kucharz = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create(['visibility' => 'public']);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Dodałem chrzan.',
        ]);

        return [$autor, $kucharz, $wykonanie];
    }

    public function test_dwie_rownolegle_prosby_o_to_samo_wykonanie_daja_jedna_wskazowke_i_jedno_powiadomienie(): void
    {
        [$autor, $kucharz, $wykonanie] = $this->swiat();

        // Bariera na wierszu wykonania: oba procesy przechodzą zamek pary po
        // kolei i ustawiają się w kolejce po ten sam wiersz.
        $bariera = $this->bariera('SELECT 1 FROM cooked_events WHERE id = ? FOR UPDATE', [(string) $wykonanie->getKey()]);
        $argumenty = ['kto' => (string) $autor->getKey(), 'wykonanie' => (string) $wykonanie->getKey()];
        $pierwszy = $this->wTle('zaproponuj-wskazowke', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('zaproponuj-wskazowke', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'prośba '.($numer + 1));
        }

        $udane = array_values(array_filter($wyniki, fn (array $w): bool => $w['ok']));
        $this->assertCount(1, $udane, 'Dokładnie jedna prośba ma się udać, druga dostaje odmowę.');
        $przegrana = array_values(array_filter($wyniki, fn (array $w): bool => ! $w['ok']))[0];
        $this->assertSame(BladDlaCzlowieka::class, $przegrana['wyjatek'], 'Przegrana prośba ma dostać odmowę dla człowieka, nie błąd bazy: '.$przegrana['komunikat']);
        $this->assertNull($przegrana['sqlstate']);
        $this->assertSame(1, DB::table('recipe_hints')->where('cooked_event_id', $wykonanie->getKey())->count());
        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $kucharz->getKey())
            ->where('type', Notification::TYPE_HINT_PROPOSED)
            ->count());
    }

    /**
     * Obie kolejności startu: „Nie” w kolejce PRZED „Zgadzam się” łapie
     * zgodę, która czytałaby stan bez zamka (zgubiona aktualizacja), a
     * odwrotna kolejność łapie to samo po stronie „Nie”.
     *
     * @return array<string, array{bool}>
     */
    public static function kolejnosci(): array
    {
        return ['zgoda pierwsza' => [true], 'odmowa pierwsza' => [false]];
    }

    #[DataProvider('kolejnosci')]
    public function test_zgadzam_sie_kontra_nie_konczy_sie_jednym_stanem_a_przegrany_dostaje_odmowe(bool $zgodaPierwsza): void
    {
        [, $kucharz, $wykonanie] = $this->swiat();
        $wskazowka = RecipeHint::factory()->dlaWykonania($wykonanie)->create();

        $bariera = $this->bariera('SELECT 1 FROM recipe_hints WHERE id = ? FOR UPDATE', [(string) $wskazowka->getKey()]);
        $argumenty = ['kto' => (string) $kucharz->getKey(), 'wskazowka' => (string) $wskazowka->getKey()];
        $pierwszy = $this->wTle($zgodaPierwsza ? 'przyjmij-wskazowke' : 'odrzuc-wskazowke', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle($zgodaPierwsza ? 'odrzuc-wskazowke' : 'przyjmij-wskazowke', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikZgody = ($zgodaPierwsza ? $pierwszy : $drugi)->wynik();
        $wynikOdmowy = ($zgodaPierwsza ? $drugi : $pierwszy)->wynik();
        $this->assertBezZakleszczenia($wynikZgody, 'zgoda');
        $this->assertBezZakleszczenia($wynikOdmowy, 'odmowa');

        $stan = (string) DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('status');
        $this->assertContains($stan, [RecipeHint::STATUS_ACCEPTED, RecipeHint::STATUS_DECLINED]);
        // Dokładnie jedna odpowiedź wygrywa; druga widzi stan z cudzej odpowiedzi.
        $this->assertSame(1, (int) $wynikZgody['ok'] + (int) $wynikOdmowy['ok'], 'Obie odpowiedzi przeszły albo żadna.');
        $this->assertSame($stan === RecipeHint::STATUS_ACCEPTED, $wynikZgody['ok']);
        $this->assertNotNull(DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('decided_at'));
        $this->assertNull(DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('withdrawn_at'));
    }

    public function test_dwa_rownolegle_wycofania_zgody_sa_idempotentne(): void
    {
        [, $kucharz, $wykonanie] = $this->swiat();
        $wskazowka = RecipeHint::factory()->dlaWykonania($wykonanie, RecipeHint::STATUS_ACCEPTED)->create();

        $bariera = $this->bariera('SELECT 1 FROM recipe_hints WHERE id = ? FOR UPDATE', [(string) $wskazowka->getKey()]);
        $argumenty = ['kto' => (string) $kucharz->getKey(), 'wskazowka' => (string) $wskazowka->getKey()];
        $pierwsze = $this->wTle('wycofaj-wskazowke', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugie = $this->wTle('wycofaj-wskazowke', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwsze->wynik(), $drugie->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'wycofanie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Wycofanie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('status'));
    }

    public function test_prosba_kontra_wycofanie_zgody_nie_wskrzesza_wskazowki(): void
    {
        [$autor, $kucharz, $wykonanie] = $this->swiat();
        $wskazowka = RecipeHint::factory()->dlaWykonania($wykonanie, RecipeHint::STATUS_ACCEPTED)->create();

        // Kucharz wycofuje zgodę, autor w tej samej chwili próbuje poprosić
        // jeszcze raz: prośba MUSI paść (jedno wykonanie — jedna prośba w życiu),
        // a wskazówka zostaje wycofana.
        $bariera = $this->bariera('SELECT 1 FROM recipe_hints WHERE id = ? FOR UPDATE', [(string) $wskazowka->getKey()]);
        $wycofanie = $this->wTle('wycofaj-wskazowke', ['kto' => (string) $kucharz->getKey(), 'wskazowka' => (string) $wskazowka->getKey()]);
        $this->czekajNaZablokowane(1);
        $prosba = $this->wTle('zaproponuj-wskazowke', ['kto' => (string) $autor->getKey(), 'wykonanie' => (string) $wykonanie->getKey()]);
        $this->zwolnijBariere($bariera);

        $wynikWycofania = $wycofanie->wynik();
        $wynikProsby = $prosba->wynik();
        $this->assertBezZakleszczenia($wynikWycofania, 'wycofanie');
        $this->assertBezZakleszczenia($wynikProsby, 'prośba');
        $this->assertTrue($wynikWycofania['ok'], 'Wycofanie padło: '.$wynikWycofania['komunikat']);
        $this->assertFalse($wynikProsby['ok'], 'Ponowna prośba o to samo wykonanie przeszła.');
        $this->assertSame(1, DB::table('recipe_hints')->where('cooked_event_id', $wykonanie->getKey())->count());
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, DB::table('recipe_hints')->where('cooked_event_id', $wykonanie->getKey())->value('status'));
    }
}
