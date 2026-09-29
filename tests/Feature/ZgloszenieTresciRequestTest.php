<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Moderation\ZgloszenieTresciRequest;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Walidacja zgłoszenia treści wyjęta z `ReportController::store()`
 * do `ZgloszenieTresciRequest` (issue #970). Zachowanie nie miało się
 * zmienić — test pilnuje tego, co przy przenosinach łatwo zgubić:
 * kolejności (limit → odsyłka konta pod nazwą → pola → cel i Policy)
 * oraz komunikatów.
 */
class ZgloszenieTresciRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_brak_powodu_wraca_na_formularz_z_tekstem_i_bez_zgloszenia(): void
    {
        $autor = $this->user('autor970');
        $widz = $this->user('widz970');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($widz)->from('/zglos/formularz')
            ->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), ['details' => 'Coś jest nie tak.'])
            ->assertRedirect('/zglos/formularz')
            ->assertSessionHasErrors(['reason' => 'Wybierz, co jest nie tak z tą treścią.'])
            ->assertSessionHasInput('details', 'Coś jest nie tak.');

        $this->assertSame(0, Report::count());
    }

    public function test_za_dlugi_opis_dostaje_komunikat_mowiacy_co_zrobic(): void
    {
        $autor = $this->user('autor971');
        $widz = $this->user('widz971');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $opis = str_repeat('a', 2001);

        $this->actingAs($widz)->from('/zglos/formularz')
            ->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), [
                'reason' => array_key_first(Report::REASONS),
                'details' => $opis,
            ])
            ->assertSessionHasErrors(['details' => 'To jest za długie. Zmieść się w 2000 znakach — napisz samo to, co najważniejsze.'])
            ->assertSessionHasInput('details', $opis);

        $this->assertSame(0, Report::count());
    }

    /** Kontrola dodatnia: opis o dokładnie 2000 znaków przechodzi. */
    public function test_opis_o_granicznej_dlugosci_przechodzi(): void
    {
        $autor = $this->user('autor972');
        $widz = $this->user('widz972');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), [
                'reason' => array_key_first(Report::REASONS),
                'details' => str_repeat('a', 2000),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Report::count());
    }

    /** Odsyłka konta pod nazwą jest PRZED regułami pól — puste pola nie zasłaniają jej błędem pola. */
    public function test_konto_pod_nazwa_bez_powodu_dostaje_odsylke_a_nie_blad_pola(): void
    {
        $this->user('ania972', ['display_name' => 'Ania']);
        $widz = $this->user('widz973');

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'user', 'id' => 'ania972']), [])
            ->assertRedirect(route('reports.create', ['type' => 'user', 'id' => 'ania972']))
            ->assertSessionHasErrors(['reason' => 'Sprawdź, czy to na pewno ta osoba, i wyślij zgłoszenie jeszcze raz. Wpisany tekst nie zginął.']);

        $this->assertSame(0, Report::count());
    }

    /** Kontrola ujemna do poprzedniego: pod UUID te same puste pola dają zwykły błąd pola. */
    public function test_konto_pod_uuid_bez_powodu_dostaje_blad_pola(): void
    {
        $ania = $this->user('ania973');
        $widz = $this->user('widz974');

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'user', 'id' => $ania->getKey()]), [])
            ->assertSessionHasErrors(['reason' => 'Wybierz, co jest nie tak z tą treścią.']);
    }

    /**
     * Kolejność: pola są sprawdzane PRZED celem (dziś tak jest i tak
     * zostaje) — nieistniejący cel z błędnymi polami to błąd pola,
     * a z poprawnymi — 404. Żadna odpowiedź nie zależy od istnienia celu
     * w sposób, który ujawniałby prywatną treść.
     */
    public function test_pola_sa_sprawdzane_przed_celem_a_cel_przed_zapisem(): void
    {
        $widz = $this->user('widz975');
        $brak = route('reports.store', ['type' => 'post', 'id' => '00000000-0000-4000-8000-000000000000']);

        $this->actingAs($widz)->post($brak, [])->assertSessionHasErrors('reason');
        $this->actingAs($widz)->post($brak, ['reason' => array_key_first(Report::REASONS)])->assertNotFound();

        $this->assertSame(0, Report::count());
    }

    /** Limit trasy działa przed żądaniem formularza: kolejne (choćby błędne) zgłoszenie ponad limit dostaje 429. */
    public function test_limit_zgloszen_dziala_przed_walidacja(): void
    {
        $autor = $this->user('autor976');
        $widz = $this->user('widz976');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $adres = route('reports.store', ['type' => 'post', 'id' => $post->getKey()]);
        $limit = (int) explode(',', (string) config('kuking.limits.report'))[0];

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($widz)->post($adres, [])->assertSessionHasErrors('reason');
        }

        $this->actingAs($widz)->post($adres, [])->assertStatus(429);
    }

    public function test_reguly_i_rozpoznanie_konta_pod_nazwa(): void
    {
        $podNazwa = $this->zadanie('user', 'ania');
        $this->assertTrue($podNazwa->zgloszenieKontaPodNazwa());
        $this->assertSame([], $podNazwa->rules());

        $podUuid = $this->zadanie('user', '00000000-0000-4000-8000-000000000000');
        $this->assertFalse($podUuid->zgloszenieKontaPodNazwa());

        $post = $this->zadanie('post', 'x');
        $this->assertFalse($post->zgloszenieKontaPodNazwa());
        $this->assertSame(['reason', 'details'], array_keys($post->rules()));
    }

    private function zadanie(string $type, string $id): ZgloszenieTresciRequest
    {
        $zadanie = ZgloszenieTresciRequest::create("/zglos/{$type}/{$id}", 'POST');
        $trasa = new Route('POST', '/zglos/{type}/{id}', fn () => null);
        $trasa->bind($zadanie);
        $zadanie->setRouteResolver(fn () => $trasa);

        return $zadanie;
    }
}
