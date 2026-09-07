<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publiczny zeszyt osoby zbanowanej albo kasującej konto (audyt
 * ZESZYTY/PROFIL, punkty 1-3) — TA SAMA REGUŁA CO `UserPolicy::viewProfile()`,
 * tym razem na poziomie POJEMNIKA, nie pojedynczej treści w środku.
 *
 * `CollectionController::show()` ma już `dostepnyJakoAutor()` dla PRZEPISÓW
 * i WPISÓW wewnątrz zeszytu (audyt W5-08) — ale sam zeszyt jest też treścią
 * czyjegoś konta, a `CollectionPolicy::view()` nie sprawdzała w ogóle statusu
 * WŁAŚCICIELA zeszytu, tylko blokadę i flagę `public`. Skutek: `/@login`
 * właściciela dawało 403, a `/zeszyt/{uuid}` tej samej osoby, zapamiętany
 * z czasu, gdy konto jeszcze działało, dalej wracał 200 — z nazwą zeszytu
 * i (gdyby coś w nim było) z zawartością. To dokładnie zdanie z zadania:
 * treść mniej dostępna przez drzwi frontowe (profil) niż przez okno
 * (bezpośredni adres zeszytu).
 */
class ZeszytOsobyZbanowanejNieJestDostepnyTest extends TestCase
{
    use RefreshDatabase;

    public function test_publiczny_zeszyt_osoby_zbanowanej_daje_403(): void
    {
        $wlasciciel = $this->user('zbanowanywlasciciel');
        $obcy = $this->user('obcyprobny');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt zbanowanego',
            'visibility' => 'public',
        ]);

        $wlasciciel->ban();

        // KONTROLA: profil tej samej osoby już dawał 403 przed tą poprawką —
        // dowód, że to konto NAPRAWDĘ jest zbanowane, a nie że test się myli.
        $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'zbanowanywlasciciel']))
            ->assertForbidden();

        $this->actingAs($obcy)
            ->get(route('collections.show', $zeszyt))
            ->assertForbidden();
    }

    public function test_publiczny_zeszyt_osoby_kasujacej_konto_daje_403(): void
    {
        $wlasciciel = $this->user('kasujesie2');
        $obcy = $this->user('obcyprobny2');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt kasującego się konta',
            'visibility' => 'public',
        ]);

        $wlasciciel->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $this->actingAs($obcy)
            ->get(route('collections.show', $zeszyt))
            ->assertForbidden();
    }

    public function test_moderator_dalej_widzi_zeszyt_osoby_zbanowanej(): void
    {
        $wlasciciel = $this->user('zbanowanywlasciciel2');
        $moderator = $this->user('moderatorka', ['display_name' => 'Moderatorka']);
        $moderator->forceFill(['role' => User::ROLE_MODERATOR])->save();

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt do moderacji',
            'visibility' => 'public',
        ]);

        $wlasciciel->ban();

        // KONTROLA: moderacja musi dalej widzieć treść, żeby móc ją ocenić —
        // to ta sama zasada, którą `UserPolicy::viewProfile()` ma dla profilu.
        $this->actingAs($moderator)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('Zeszyt do moderacji');
    }

    public function test_wlasny_zeszyt_prywatny_zostaje_niezmieniony_ta_poprawka_go_nie_dotyczy(): void
    {
        // KONTROLA NEGATYWNA: poprawka dotyczy WYŁĄCZNIE statusu właściciela,
        // nie samej flagi widoczności — zwykły prywatny zeszyt aktywnej osoby
        // ma się zachowywać dokładnie tak jak przed poprawką.
        $wlasciciel = $this->user('aktywnywlasciciel');
        $obcy = $this->user('obcyprobny3');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt prywatny',
            'visibility' => 'private',
        ]);

        $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertForbidden();
        $this->actingAs($wlasciciel)->get(route('collections.show', $zeszyt))->assertOk();
    }
}
