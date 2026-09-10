<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listy obserwujących/obserwowanych a konta zbanowane i kasujące się (audyt
 * ZESZYTY/PROFIL, punkt 2).
 *
 * TA SAMA KLASA BŁĘDU CO W FEEDZIE OBSERWOWANYCH (commit 964b99c) I CO W5-08:
 * `UserPolicy::viewProfile()` daje 403 pod adresem `/@konto-zbanowane`
 * (chyba że patrzy moderator), ale `SocialController::connections()` — czyli
 * zapytanie budujące LISTĘ „kto obserwuje" / „kogo obserwuje" — filtrowało
 * dotąd WYŁĄCZNIE blokady między widzem a osobą na liście. Nie miało pojęcia
 * o `dostepnyJakoAutor()`, czyli o tym, że konto jest zbanowane albo kasuje
 * się (`pending_delete`). Efekt: karta z awatarem, wyświetlaną nazwą i linkiem
 * do profilu, który — kliknięty wprost — daje 403. Treść (a właściwie samo
 * KONTO) była mniej dostępna przez drzwi frontowe niż przez okno.
 *
 * Ten sam rozjazd psuje licznik na profilu (`ProfileController::show()`,
 * `stats.followers`/`stats.following`): liczy WSZYSTKIE wiersze z `follows`,
 * bez filtra — czyli licznik zdradza, że zbanowane/kasujące się konto
 * kiedyś tu było, nawet gdy lista już go nie pokazuje.
 */
class ProfilListyRelacjiUkrywajaZbanowaneKontaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_obserwujacych_nie_pokazuje_konta_zbanowanego(): void
    {
        $wlasciciel = $this->user('wlascicielka');
        $aktywny = $this->user('aktywna', ['display_name' => 'Aktywna Osoba']);
        $zbanowany = $this->user('zbanowany', ['display_name' => 'Zbanowana Osoba']);

        app(FollowUser::class)->handle($aktywny, $wlasciciel);
        app(FollowUser::class)->handle($zbanowany, $wlasciciel);

        // Ban PO nawiązaniu relacji — `ban()` nie kasuje wierszy z `follows`
        // (zmierzone w `app/Models/User.php::ban()`), więc wiersz zostaje.
        $zbanowany->ban();

        $odpowiedz = $this->get(route('social.followers', 'wlascicielka'))->assertOk();

        // KONTROLA: identyczna karta osoby BEZ sankcji musi się pokazać —
        // inaczej „nie widać zbanowanego" przechodziłoby też wtedy, gdy
        // szablon nie pokazuje NICZEGO.
        $odpowiedz->assertSee('Aktywna Osoba');
        $odpowiedz->assertDontSee('Zbanowana Osoba');
    }

    public function test_lista_obserwowanych_nie_pokazuje_konta_ktore_sie_kasuje(): void
    {
        $wlasciciel = $this->user('wlascicielka2');
        $aktywny = $this->user('aktywny2', ['display_name' => 'Aktywny Kontakt']);
        $kasujeSie = $this->user('kasujesie', ['display_name' => 'Kasujace Sie Konto']);

        app(FollowUser::class)->handle($wlasciciel, $aktywny);
        app(FollowUser::class)->handle($wlasciciel, $kasujeSie);

        $kasujeSie->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $odpowiedz = $this->get(route('social.following', 'wlascicielka2'))->assertOk();

        $odpowiedz->assertSee('Aktywny Kontakt');
        $odpowiedz->assertDontSee('Kasujace Sie Konto');
    }

    public function test_licznik_obserwujacych_na_profilu_zgadza_sie_z_lista_gdy_ktos_jest_zbanowany(): void
    {
        $wlasciciel = $this->user('wlascicielka3');
        $aktywny = $this->user('aktywny3');
        $zbanowany = $this->user('zbanowany3');

        app(FollowUser::class)->handle($aktywny, $wlasciciel);
        app(FollowUser::class)->handle($zbanowany, $wlasciciel);
        $zbanowany->ban();

        $odpowiedz = $this->get(route('profile.show', ['username' => 'wlascicielka3']))->assertOk();

        // KONTROLA LICZBOWA: dokładny fragment HTML z liczbą 1, nie samo
        // „mniej niż 2" ani gołe `assertSeeInOrder(['1', 'obserwujących'])'
        // (cyfra „1" pojawia się na stronie profilu wielokrotnie, np. w innych
        // licznikach, więc luźne dopasowanie przechodziłoby również przy
        // liczniku zepsutym w zupełnie inny sposób).
        $odpowiedz->assertSee(
            '<span class="stat-value">1</span> <span class="stat-label">obserwujący</span>',
            false,
        );
    }
}
