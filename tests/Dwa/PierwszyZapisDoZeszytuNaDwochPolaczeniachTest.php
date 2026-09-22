<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** Regresja #1095: dwa pierwsze zapisy nie mogą ścigać się o domyślny zeszyt. */
#[Group('dwa-polaczenia')]
final class PierwszyZapisDoZeszytuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_ten_sam_przepis_zapisany_rownolegle_daje_jeden_zeszyt_i_jedno_powiadomienie(): void
    {
        $autor = $this->konto();
        $zapisujacy = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        $wyniki = $this->uruchomDwaZapisy(
            'zapisz-przepis',
            ['kto' => (string) $zapisujacy->getKey(), 'przepis' => (string) $przepis->getKey()],
            (string) $zapisujacy->getKey(),
        );

        $this->assertSame($wyniki[0]['wartosc'], $wyniki[1]['wartosc']);
        $this->assertSame(1, DB::table('collections')->where('owner_id', $zapisujacy->getKey())->where('is_default', true)->count());
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $autor->getKey())->where('type', Notification::TYPE_SAVED)->count());
    }

    public function test_ten_sam_wpis_zapisany_rownolegle_daje_jeden_zeszyt_i_jeden_zapis(): void
    {
        $autor = $this->konto();
        $zapisujacy = $this->konto();
        $wpis = Post::factory()->for($autor, 'author')->create();

        $wyniki = $this->uruchomDwaZapisy(
            'zapisz-wpis',
            ['kto' => (string) $zapisujacy->getKey(), 'wpis' => (string) $wpis->getKey()],
            (string) $zapisujacy->getKey(),
        );

        $this->assertSame($wyniki[0]['wartosc'], $wyniki[1]['wartosc']);
        $this->assertSame(1, DB::table('collections')->where('owner_id', $zapisujacy->getKey())->where('is_default', true)->count());
        $this->assertSame(1, DB::table('collection_items')->where('post_id', $wpis->getKey())->count());
    }

    public function test_dwa_rozne_przepisy_zapisane_rownolegle_trafiaja_do_jednego_zeszytu(): void
    {
        $autor = $this->konto();
        $zapisujacy = $this->konto();
        $pierwszyPrzepis = Recipe::factory()->for($autor, 'author')->create();
        $drugiPrzepis = Recipe::factory()->for($autor, 'author')->create();

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $zapisujacy->getKey()]);
        $pierwszy = $this->wTle('zapisz-przepis', ['kto' => (string) $zapisujacy->getKey(), 'przepis' => (string) $pierwszyPrzepis->getKey()]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('zapisz-przepis', ['kto' => (string) $zapisujacy->getKey(), 'przepis' => (string) $drugiPrzepis->getKey()]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];
        $this->assertUdane($wyniki);
        $this->assertSame($wyniki[0]['wartosc'], $wyniki[1]['wartosc']);
        $this->assertSame(1, DB::table('collections')->where('owner_id', $zapisujacy->getKey())->where('is_default', true)->count());
        $this->assertSame(2, DB::table('collection_items')->whereIn('recipe_id', [$pierwszyPrzepis->getKey(), $drugiPrzepis->getKey()])->count());
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function uruchomDwaZapisy(string $scenariusz, array $argumenty, string $konto): array
    {
        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [$konto]);
        $pierwszy = $this->wTle($scenariusz, $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle($scenariusz, $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];
        $this->assertUdane($wyniki);

        return $wyniki;
    }

    /** @param array{0: array<string, mixed>, 1: array<string, mixed>} $wyniki */
    private function assertUdane(array $wyniki): void
    {
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'równoległy zapis '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Równoległy zapis '.($numer + 1).' padł: '.$wynik['komunikat']);
        }
    }
}
