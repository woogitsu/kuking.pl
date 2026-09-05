<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listy obserwujących / obserwowanych na profilu (issue #16, część 1).
 */
class FollowListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_obserwujacych_pokazuje_kto_obserwuje(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);

        app(FollowUser::class)->handle($marek, $basia);

        $response = $this->actingAs($marek)->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
    }

    public function test_lista_obserwowanych_pokazuje_kogo_ktos_obserwuje(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);

        app(FollowUser::class)->handle($basia, $marek);

        $response = $this->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
    }

    public function test_osoba_ktora_nikogo_nie_obserwuje_pokazuje_pusty_stan(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee('Jeszcze nikogo nie obserwuje');
        $response->assertDontSee('Wystąpił błąd');
    }

    public function test_osoba_bez_obserwujacych_pokazuje_pusty_stan(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee('Jeszcze nikt nie obserwuje');
    }

    public function test_zablokowana_osoba_nie_pojawia_sie_na_liscie_obserwujacych(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);
        $spamer = $this->user('spamer', ['display_name' => 'Spamer Uciazliwy']);

        app(FollowUser::class)->handle($marek, $basia);
        app(FollowUser::class)->handle($spamer, $basia);

        // Marek (który ogląda listę) zablokował spamera — spamer nie może
        // się pojawić na liście, mimo że naprawdę obserwuje Basię.
        app(BlockUser::class)->handle($marek, $spamer);

        $response = $this->actingAs($marek)->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
        $response->assertDontSee($spamer->displayName());
    }

    public function test_zablokowana_osoba_nie_pojawia_sie_na_liscie_obserwowanych(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);
        $ela = $this->user('ela', ['display_name' => 'Ela Zablokowana']);

        app(FollowUser::class)->handle($basia, $marek);
        app(FollowUser::class)->handle($basia, $ela);

        // Osoba oglądająca listę zablokowała Elę — Ela znika z listy,
        // mimo że blokada nie dotyczy Basi ani Marka.
        $ogladajacy = $this->user('ogladajacy');
        app(BlockUser::class)->handle($ogladajacy, $ela);

        $response = $this->actingAs($ogladajacy)->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
        $response->assertDontSee($ela->displayName());
    }

    public function test_listy_maja_naglowek_noindex(): void
    {
        $this->user('basia');

        $this->get(route('social.followers', 'basia'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get(route('social.following', 'basia'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_zablokowany_widz_nie_moze_wejsc_na_liste_zablokowanego_profilu(): void
    {
        $basia = $this->user('basia');
        $spamer = $this->user('spamer');

        app(BlockUser::class)->handle($basia, $spamer);

        $this->actingAs($spamer)->get(route('social.followers', 'basia'))->assertForbidden();
    }

    public function test_liczby_na_profilu_prowadza_do_list(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('profile.show', 'basia'));

        $response->assertOk();
        $response->assertSee(route('social.followers', 'basia'), false);
        $response->assertSee(route('social.following', 'basia'), false);
    }
}
