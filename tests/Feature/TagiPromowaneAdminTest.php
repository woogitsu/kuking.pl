<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel gospodarza: tagi promowane — etap 5/5 D-021 (`docs/DECISIONS.md`,
 * „tag promowany — lista gospodarza").
 *
 * Ta sama bramka co „kuKINGi na dziś" (`Gate` `moderate`): zwykłe,
 * zalogowane konto dostaje 404 (nie 403) z `EnsureUserIsModerator` —
 * istnienie panelu moderacji nie jest informacją wartą potwierdzania.
 * Gość, który nie jest jeszcze zalogowany, dostaje zwykłe przekierowanie
 * na logowanie (`auth` idzie PRZED `moderator` w tej grupie tras) — patrz
 * uzasadnienie przy `test_gosc_trafia_na_logowanie` niżej.
 */
class TagiPromowaneAdminTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    // -----------------------------------------------------------------
    // Dostęp — POZYTYWNY i NEGATYWNY (AGENTS.md wymaga obu)
    // -----------------------------------------------------------------

    public function test_moderator_widzi_panel(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.tag-promotions'))
            ->assertOk()
            ->assertSee('Tagi promowane', false);
    }

    public function test_zwykly_uzytkownik_dostaje_404_nie_403(): void
    {
        // 404, nie 403 — ten sam powód co przy reszcie panelu moderacji:
        // istnienie panelu nie jest informacją wartą potwierdzania.
        $this->actingAs($this->user('basia'))
            ->get(route('admin.tag-promotions'))
            ->assertNotFound();
    }

    /**
     * UWAGA PRZY PISANIU TEGO TESTU: `PanelBezOdpowiedziTest` ma test
     * o nazwie sugerującej, że GOŚĆ dostaje 404 z `EnsureUserIsModerator`
     * — ale jego drugie wywołanie `$this->get(...)` idzie PO
     * `$this->actingAs(...)` w tym samym teście, a `actingAs()` zostaje
     * aktywne dla WSZYSTKICH kolejnych żądań w danym teście, dopóki nic go
     * nie zmieni. Ten „gość” to w rzeczywistości dalej zalogowany
     * `basia` — zmierzone wprost (`$this->get(...)->getStatusCode()`
     * w izolowanym probe zwraca 302, nie 404, dla PRAWDZIWEGO gościa).
     *
     * Prawdziwy gość dostaje więc zwykłe przekierowanie na logowanie
     * (`Authenticate` middleware, standardowe zachowanie Laravela) —
     * to jest poprawne zachowanie, nie luka: dopiero PO zalogowaniu
     * `EnsureUserIsModerator` w ogóle dostaje szansę odpowiedzieć 404.
     */
    public function test_gosc_trafia_na_logowanie(): void
    {
        $this->get(route('admin.tag-promotions'))->assertRedirect(route('login'));
    }

    public function test_zwykly_uzytkownik_nie_moze_dodac_tagu_do_promowanych(): void
    {
        $tag = $this->tag('sernik', 'Sernik');

        $this->actingAs($this->user('basia'))
            ->post(route('admin.tag-promotions.store'), ['nazwa_tagu' => 'Sernik'])
            ->assertNotFound();

        $this->assertNull($tag->fresh()->promotion);
    }

    public function test_zwykly_uzytkownik_nie_moze_usunac_promocji(): void
    {
        $tag = $this->tag('sernik', 'Sernik');
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0]);

        $this->actingAs($this->user('basia'))
            ->delete(route('admin.tag-promotions.destroy', $tag))
            ->assertNotFound();

        $this->assertNotNull($tag->fresh()->promotion, 'Zwykły użytkownik zdołał usunąć promocję tagu.');
    }

    public function test_zwykly_uzytkownik_nie_moze_zmienic_notatki(): void
    {
        $tag = $this->tag('sernik', 'Sernik');
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0, 'note' => 'Oryginalna notatka']);

        $this->actingAs($this->user('basia'))
            ->put(route('admin.tag-promotions.update', $tag), ['note' => 'Podmieniona przez obcego'])
            ->assertNotFound();

        $this->assertSame('Oryginalna notatka', $tag->fresh()->promotion->note);
    }

    // -----------------------------------------------------------------
    // Dodawanie
    // -----------------------------------------------------------------

    public function test_moderator_dodaje_istniejacy_tag_do_promowanych(): void
    {
        $tag = $this->tag('sernik', 'Sernik');

        $this->actingAs($this->moderator())
            ->post(route('admin.tag-promotions.store'), ['nazwa_tagu' => 'Sernik'])
            ->assertRedirect(route('admin.tag-promotions'));

        $this->assertNotNull($tag->fresh()->promotion);
        $this->assertSame(1, AuditLogEntry::where('action', 'tag_promotion.added')->count());
    }

    public function test_dodanie_nieistniejacego_tagu_pokazuje_czytelny_blad(): void
    {
        $response = $this->actingAs($this->moderator())
            ->post(route('admin.tag-promotions.store'), ['nazwa_tagu' => 'Coś, czego nie ma']);

        $response->assertSessionHasErrors('nazwa_tagu');
        $this->assertSame(0, TagPromotion::count());
    }

    public function test_dodanie_juz_promowanego_tagu_pokazuje_blad(): void
    {
        $tag = $this->tag('sernik', 'Sernik');
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0]);

        $this->actingAs($this->moderator())
            ->post(route('admin.tag-promotions.store'), ['nazwa_tagu' => 'sernik'])
            ->assertSessionHasErrors('nazwa_tagu');

        $this->assertSame(1, TagPromotion::count());
    }

    public function test_nowo_dodany_tag_trafia_na_koniec_listy(): void
    {
        $pierwszy = $this->tag('zupy', 'Zupy');
        TagPromotion::create(['tag_id' => $pierwszy->getKey(), 'position' => 5]);
        $drugi = $this->tag('sernik', 'Sernik');

        $this->actingAs($this->moderator())
            ->post(route('admin.tag-promotions.store'), ['nazwa_tagu' => 'Sernik'])
            ->assertRedirect();

        $this->assertSame(['Zupy', 'Sernik'], Tag::promowane()->pluck('name')->all());
    }

    // -----------------------------------------------------------------
    // Notatka, kolejność, usunięcie
    // -----------------------------------------------------------------

    public function test_moderator_zapisuje_notatke(): void
    {
        $tag = $this->tag('sernik', 'Sernik');
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0]);

        $this->actingAs($this->moderator())
            ->put(route('admin.tag-promotions.update', $tag), ['note' => 'Temat tygodnia'])
            ->assertRedirect();

        $this->assertSame('Temat tygodnia', $tag->fresh()->promotion->note);
    }

    public function test_moderator_zmienia_kolejnosc(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        TagPromotion::create(['tag_id' => $zupy->getKey(), 'position' => 0]);
        TagPromotion::create(['tag_id' => $ciasta->getKey(), 'position' => 1]);

        $this->assertSame(['Zupy', 'Ciasta'], Tag::promowane()->pluck('name')->all());

        $this->actingAs($this->moderator())
            ->put(route('admin.tag-promotions.update', $zupy), ['w_dol' => '1'])
            ->assertRedirect();

        $this->assertSame(['Ciasta', 'Zupy'], Tag::promowane()->pluck('name')->all());
    }

    public function test_moderator_usuwa_tag_z_promowanych_bez_kasowania_tagu(): void
    {
        $tag = $this->tag('sernik', 'Sernik');
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0]);

        $this->actingAs($this->moderator())
            ->delete(route('admin.tag-promotions.destroy', $tag))
            ->assertRedirect(route('admin.tag-promotions'));

        $this->assertNull($tag->fresh()->promotion);
        // Sam tag zostaje — usunięcie promocji nie jest usunięciem tagu.
        $this->assertNotNull(Tag::find($tag->getKey()));
    }

    public function test_panel_pokazuje_promowane_tagi_w_kolejnosci(): void
    {
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $zupy = $this->tag('zupy', 'Zupy');
        TagPromotion::create(['tag_id' => $ciasta->getKey(), 'position' => 5]);
        TagPromotion::create(['tag_id' => $zupy->getKey(), 'position' => 1]);

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.tag-promotions'))
            ->assertOk()
            ->getContent();

        $pozycjaZup = strpos($html, 'Zupy');
        $pozycjaCiast = strpos($html, 'Ciasta');

        $this->assertIsInt($pozycjaZup);
        $this->assertIsInt($pozycjaCiast);
        $this->assertLessThan($pozycjaCiast, $pozycjaZup, 'Zupy (position 1) powinno wystąpić przed Ciasta (position 5).');
    }
}
