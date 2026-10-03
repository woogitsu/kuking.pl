<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** #2790: formularz potwierdza konkretną osobę, nie aktualnego właściciela nazwy. */
class StalyOdbiorcaUdostepnieniaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $pierwotny;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka_2790');
        $this->pierwotny = $this->user('odbiorca_2790');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(), 'visibility' => 'private',
        ]);
    }

    private function pierwszyKrok(string $nazwa = 'odbiorca_2790', ?Recipe $przepis = null): string
    {
        $przepis ??= $this->przepis;
        $this->actingAs($this->autor)
            ->post(route('recipes.shares.store', $przepis), ['nazwa' => $nazwa])
            ->assertRedirect(route('recipes.shares.index', $przepis));
        $token = session('udostepnij_potwierdzenie');
        self::assertIsString($token);
        $this->actingAs($this->autor)->get(route('recipes.shares.index', $przepis))
            ->assertOk()->assertSee('name="potwierdzenie"', false)
            ->assertSee(e($token), false);

        return $token;
    }

    private function potwierdz(string $token, ?Recipe $przepis = null): TestResponse
    {
        return $this->actingAs($this->autor)->post(route('recipes.shares.store', $przepis ?? $this->przepis), [
            'potwierdzam' => '1', 'potwierdzenie' => $token,
        ]);
    }

    public function test_zmieniona_nazwa_i_nowy_wlasciciel_nazwy_nie_dostaja_udostepnienia(): void
    {
        $token = $this->pierwszyKrok();
        Profile::query()->where('user_id', $this->pierwotny->getKey())->update(['username' => 'po_zmianie_2790']);
        $nowy = $this->user('odbiorca_2790');

        $odpowiedz = $this->potwierdz($token);
        self::assertSame(0, RecipeShare::query()->where('recipient_id', $nowy->getKey())->count(), 'ODBIORCA_2790_NIE_PRZECHODZI_NA_NOWE_KONTO');
        $odpowiedz->assertRedirect()->assertSessionHasErrors([
            'nazwa' => UdostepnijPrzepis::PONOW_POTWIERDZENIE,
        ]);
        self::assertSame('odbiorca_2790', session()->getOldInput('nazwa'));
        $this->actingAs($this->autor)->get(route('recipes.shares.index', $this->przepis))
            ->assertOk()->assertSee('value="odbiorca_2790"', false);
        self::assertSame(0, RecipeShare::query()->count());
        self::assertSame(0, DB::table('notifications')->where('user_id', $nowy->getKey())->count());

        // Nowe jawne potwierdzenie aktualnej nazwy pozwala wybrać B.
        $nowyToken = $this->pierwszyKrok('po_zmianie_2790');
        $this->potwierdz($nowyToken)->assertSessionHas('status');
        self::assertSame(1, RecipeShare::query()->where('recipient_id', $this->pierwotny->getKey())->count());
    }

    public function test_potwierdzenie_wygasa_przy_zmianie_nazwy_bez_przejecia_i_nie_ujawnia_stanu(): void
    {
        $token = $this->pierwszyKrok();
        Profile::query()->where('user_id', $this->pierwotny->getKey())->update(['username' => 'inna_nazwa_2790']);

        $this->potwierdz($token)->assertSessionHasErrors(['nazwa' => UdostepnijPrzepis::PONOW_POTWIERDZENIE]);
        self::assertSame(0, RecipeShare::query()->count());
    }

    public function test_potwierdzenie_jest_przypiete_do_autora_przepisu_i_nie_ufa_polom_klienta(): void
    {
        $token = $this->pierwszyKrok();
        $inny = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'visibility' => 'private']);
        $this->potwierdz($token, $inny)->assertSessionHasErrors('nazwa');
        $this->potwierdz(substr_replace($token, 'X', 8, 1))->assertSessionHasErrors('nazwa');
        $this->actingAs($this->user('cudzy_2790'))
            ->post(route('recipes.shares.store', $this->przepis), ['potwierdzam' => '1', 'potwierdzenie' => $token])
            ->assertForbidden();
        self::assertSame(0, RecipeShare::query()->count());
    }

    public function test_dwie_karty_maja_odrebne_potwierdzenia_i_nie_mieszaja_odbiorcow(): void
    {
        $druga = $this->user('druga_2790');
        $pierwszyToken = $this->pierwszyKrok();
        $drugiToken = $this->pierwszyKrok('druga_2790');
        $this->potwierdz($pierwszyToken)->assertSessionHas('status');
        $this->potwierdz($drugiToken)->assertSessionHas('status');

        self::assertSame(1, RecipeShare::query()->where('recipient_id', $this->pierwotny->getKey())->count());
        self::assertSame(1, RecipeShare::query()->where('recipient_id', $druga->getKey())->count());
    }

    public function test_swieze_potwierdzenie_dziala_a_po_pietnastu_minutach_wygasa_bez_zapisu_i_powiadomienia(): void
    {
        $wygasle = $this->pierwszyKrok();

        Carbon::setTestNow(now()->addMinutes(16));
        try {
            $this->potwierdz($wygasle)->assertSessionHasErrors([
                'nazwa' => UdostepnijPrzepis::PONOW_POTWIERDZENIE,
            ]);
            self::assertSame(0, RecipeShare::query()->count(), 'ODBIORCA_2790_WYGASLE_BEZ_ZAPISU');
            self::assertSame(0, DB::table('notifications')->where('user_id', $this->pierwotny->getKey())->count());

            $swieze = $this->pierwszyKrok();
            $this->potwierdz($swieze)->assertSessionHas('status');
            self::assertSame(1, RecipeShare::query()->where('recipient_id', $this->pierwotny->getKey())->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_zawieszenie_po_pierwszym_kroku_odmawia(): void
    {
        $token = $this->pierwszyKrok();
        $this->pierwotny->suspend(now()->addDay());
        $this->potwierdz($token)->assertSessionHasErrors('nazwa');
        self::assertSame(0, RecipeShare::query()->count());

    }

    public function test_nowa_blokada_po_pierwszym_kroku_odmawia(): void
    {
        $token = $this->pierwszyKrok();
        app(BlockUser::class)->handle($this->pierwotny, $this->autor);
        $this->potwierdz($token)->assertSessionHasErrors('nazwa');
        self::assertSame(0, RecipeShare::query()->count());
    }
}
