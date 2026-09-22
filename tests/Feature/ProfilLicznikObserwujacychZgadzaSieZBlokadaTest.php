<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licznik obserwujących a blokada MIĘDZY WIDZEM A OSOBĄ NA LIŚCIE (audyt
 * ZESZYTY/PROFIL, punkt 1) — druga połowa tego samego rozjazdu co konta
 * zbanowane, tym razem bez potrzeby banowania nikogo.
 *
 * `SocialController::connections()` już filtrował blokady między widzem
 * a osobą na liście (patrz komentarz w kontrolerze: „na liście może się
 * znaleźć ktoś, kogo zablokował akurat OSOBA OGLĄDAJĄCA listę"), ale
 * `ProfileController::show()` liczył `stats.followers` gołym `->count()`,
 * bez tego warunku. Widz, który zablokował jednego z obserwujących, widział
 * więc licznik o jeden za wysoki względem listy, którą klika zaraz potem.
 */
class ProfilLicznikObserwujacychZgadzaSieZBlokadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_licznik_obserwujacych_nie_liczy_osoby_zablokowanej_przez_widza(): void
    {
        $wlasciciel = $this->user('wlascicielka4');
        $widoczny = $this->user('widoczny4');
        $zablokowanyPrzezWidza = $this->user('zablokowany4');
        $widz = $this->user('widzaca4');

        app(FollowUser::class)->handle($widoczny, $wlasciciel);
        app(FollowUser::class)->handle($zablokowanyPrzezWidza, $wlasciciel);

        // Widz blokuje jedną z osób obserwujących właściciela — blokada nie
        // ma nic wspólnego z właścicielem profilu, tylko z widzem i tą osobą.
        app(BlockUser::class)->handle($widz, $zablokowanyPrzezWidza);

        $odpowiedzProfil = $this->actingAs($widz)
            ->get(route('profile.show', ['username' => 'wlascicielka4']))
            ->assertOk();

        // KONTROLA LICZBOWA: dokładnie 1 (tylko `widoczny4`), nie „mniej niż 2".
        $odpowiedzProfil->assertSee(
            '<span class="stat-value">1</span> <span class="stat-label">obserwujący</span>',
            false,
        );

        // Licznik musi się zgadzać z tym, co ta sama osoba widzi po kliknięciu.
        $this->actingAs($widz)
            ->get(route('social.followers', 'wlascicielka4'))
            ->assertOk()
            ->assertSee('widoczny4')
            ->assertDontSee('zablokowany4');
    }

    public function test_licznik_obserwujacych_bez_zalogowania_liczy_wszystkich_aktywnych(): void
    {
        $wlasciciel = $this->user('wlascicielka5');
        $a = $this->user('osobaa5');
        $b = $this->user('osobab5');

        app(FollowUser::class)->handle($a, $wlasciciel);
        app(FollowUser::class)->handle($b, $wlasciciel);

        // KONTROLA: bez widza (gość, nie zalogowany) blokady nikogo nie
        // dotyczą — licznik ma liczyć OBIE aktywne osoby, nie zero i nie jedną.
        $this->get(route('profile.show', ['username' => 'wlascicielka5']))
            ->assertOk()
            ->assertSee(
                '<span class="stat-value">2</span> <span class="stat-label">obserwujących</span>',
                false,
            );
    }
}
