<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sharing\Udostepnianie;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Podziel się" przy zeszycie (issue #2000).
 *
 * Ten sam komponent i te same reguły co przy przepisie i wpisie: przycisk
 * istnieje wtedy i tylko wtedy, gdy zeszyt zobaczy ktoś BEZ KONTA. Zeszyt
 * prywatny („Tylko ja"), domyślne „Zapisane" i zeszyt wspólny (D-302) nie
 * dostają go nawet u właściciela.
 *
 * Kontrola ujemna: zamiana `Gate::forUser(null)` w `wolnoWyslac()` na
 * bezwarunkowe `true` dla zeszytu oblewa testy prywatnego i wspólnego;
 * usunięcie `! $tresc->members()->exists()` oblewa test wspólnego.
 */
class PodzielSieZeszytTest extends TestCase
{
    use RefreshDatabase;

    private User $wlascicielka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wlascicielka = $this->user('zeszytowa');
    }

    private function zeszyt(string $widocznosc = 'public', array $atrybuty = []): Collection
    {
        return Collection::create(array_merge([
            'owner_id' => $this->wlascicielka->getKey(),
            'name' => 'Niedzielne obiady',
            'visibility' => $widocznosc,
        ], $atrybuty));
    }

    public function test_gosc_widzi_jawna_liste_drog_przy_publicznym_zeszycie(): void
    {
        $zeszyt = $this->zeszyt();
        $adres = route('collections.show', $zeszyt);

        $html = $this->get($adres)->assertOk()->getContent();

        $this->assertStringContainsString('data-podziel-sie', $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('href="mailto:?subject=', $html);
        $this->assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=', $html);
        $this->assertStringContainsString('data-podziel-tytul="Niedzielne obiady"', $html);
        $this->assertStringContainsString('data-podziel-adres="'.e($adres).'"', $html);
        $this->assertStringContainsString('Wyślij ten zeszyt', $html);
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*data-podziel-pole[^>]*>'.preg_quote(e($adres), '/').'<\/textarea>/',
            $html,
        );
        $this->assertMatchesRegularExpression('/<button[^>]*data-podziel-kopiuj[^>]*\shidden/', $html);
    }

    public function test_adres_do_wyslania_jest_bezwzgledny_i_bez_parametrow(): void
    {
        $zeszyt = $this->zeszyt();
        $udostepnianie = app(Udostepnianie::class);

        $adres = $udostepnianie->adres($zeszyt);

        $this->assertSame(route('collections.show', $zeszyt), $adres);
        $this->assertStringNotContainsString('?', $adres);
        $this->assertStringNotContainsString('utm_', $adres);
    }

    public function test_strona_zeszytu_z_przyciskiem_ma_karte_open_graph(): void
    {
        $html = $this->get(route('collections.show', $this->zeszyt()))->assertOk()->getContent();

        $this->assertStringContainsString('property="og:title" content="Niedzielne obiady', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringNotContainsString('<meta name="robots" content="noindex', $html);
    }

    public function test_zeszyt_prywatny_nie_dostaje_przycisku_nawet_u_wlascicielki(): void
    {
        $zeszyt = $this->zeszyt('private');

        $html = $this->actingAs($this->wlascicielka)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('Zmień widoczność na „wszyscy”', false)
            ->getContent();

        $this->assertStringNotContainsString('data-podziel-sie', $html);
        $this->assertStringNotContainsString('wa.me', $html);
    }

    public function test_obca_osoba_nie_widzi_wyjasnienia_dla_wlascicielki(): void
    {
        $zeszyt = $this->zeszyt('public');
        $obca = $this->user('obcazeszyt');

        $this->actingAs($obca)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('data-podziel-sie', false)
            ->assertDontSee('Zmień widoczność na', false);
    }

    public function test_domyslny_zeszyt_nie_dostaje_przycisku_nawet_gdy_jest_publiczny(): void
    {
        $zeszyt = $this->zeszyt('public', ['name' => 'Zapisane', 'is_default' => true]);

        $this->assertFalse(app(Udostepnianie::class)->wolnoWyslac($zeszyt));

        $html = $this->actingAs($this->wlascicielka)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-podziel-sie', $html);
        $this->assertStringContainsString('zeszyt „Zapisane”', $html);
    }

    public function test_zeszyt_wspolny_nie_dostaje_przycisku(): void
    {
        $zeszyt = $this->zeszyt('public');
        $zeszyt->members()->attach($this->user('wspoltworca')->getKey());

        $this->assertFalse(app(Udostepnianie::class)->wolnoWyslac($zeszyt));

        $html = $this->actingAs($this->wlascicielka)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-podziel-sie', $html);
        $this->assertStringContainsString('To jest wspólny zeszyt', $html);
    }

    public function test_zeszyt_zbanowanej_wlascicielki_nie_jest_do_wyslania(): void
    {
        $zeszyt = $this->zeszyt('public');
        $udostepnianie = app(Udostepnianie::class);

        $this->assertTrue($udostepnianie->wolnoWyslac($zeszyt));

        $this->wlascicielka->ban();
        $zeszyt->refresh()->load('owner');

        $this->assertFalse($udostepnianie->wolnoWyslac($zeszyt));
    }

    public function test_tytul_i_opis_zeszytu(): void
    {
        $zeszyt = $this->zeszyt('public', ['name' => 'Zupy & sosy']);
        $udostepnianie = app(Udostepnianie::class);

        $this->assertTrue($udostepnianie->obslugiwana($zeszyt));
        $this->assertSame('Zupy & sosy', $udostepnianie->tytul($zeszyt));
        $this->assertSame('Zeszyt z Kuking: Zupy & sosy', $udostepnianie->opis($zeszyt));
        $this->assertNull($udostepnianie->powodBrakuPrzycisku($zeszyt));
    }
}
